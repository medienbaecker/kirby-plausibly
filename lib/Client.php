<?php

namespace Medienbaecker\Plausibly;

use DateInterval;
use DateTime;
use Kirby\Exception\Exception;
use Kirby\Http\Remote;
use Throwable;

class Client
{
	protected string $url;
	protected string $site;
	protected string $token;

	public function __construct()
	{
		$this->url   = rtrim((string) option('medienbaecker.plausibly.url'), '/');
		$this->site  = (string) option('medienbaecker.plausibly.site');
		$this->token = (string) option('medienbaecker.plausibly.token');
	}

	public static function isConfigured(): bool
	{
		return option('medienbaecker.plausibly.url')
			&& option('medienbaecker.plausibly.site')
			&& option('medienbaecker.plausibly.token');
	}

	public function query(array $body): array
	{
		$body['site_id'] = $this->site;

		$response = Remote::request($this->url . '/api/v2/query', [
			'method'  => 'POST',
			'headers' => [
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			],
			'data'    => json_encode($body),
		]);

		$data = $response->json() ?? [];

		if ($response->code() !== 200) {
			throw new Exception($data['error'] ?? 'Plausible API error (HTTP ' . $response->code() . ')');
		}

		return $data;
	}

	public function aggregate(string $period, array $metrics, ?string $date = null): array
	{
		$current = $this->query([
			'metrics'    => $metrics,
			'date_range' => $this->range($period, $date),
		]);

		$values = $current['results'][0]['metrics'] ?? [];
		$range  = $current['query']['date_range'] ?? null;

		$previous = [];
		if ($period !== 'all' && $range && $prevRange = $this->previousRange($range)) {
			$prev = $this->query([
				'metrics'    => $metrics,
				'date_range' => $prevRange,
			]);
			$previous = $prev['results'][0]['metrics'] ?? [];
		}

		$result = [];
		foreach ($metrics as $i => $metric) {
			$value = $values[$i] ?? 0;
			$base  = $previous[$i] ?? null;

			$result[$metric] = [
				'value'  => $value,
				'change' => ($base > 0) ? round((($value - $base) / $base) * 100) : null,
			];
		}

		return $result;
	}

	public function timeseries(string $period, string $metric, string $interval, ?string $date = null): array
	{
		$derived = $metric === 'views_per_visit';
		$metrics = $derived ? ['pageviews', 'visits'] : [$metric];

		$data = $this->query([
			'metrics'    => $metrics,
			'date_range' => $this->range($period, $date),
			'dimensions' => ['time:' . $interval],
		]);

		$values = [];
		foreach ($data['results'] ?? [] as $row) {
			$date = $row['dimensions'][0] ?? null;
			if ($date === null) {
				continue;
			}
			if ($derived) {
				$pageviews = $row['metrics'][0] ?? 0;
				$visits    = $row['metrics'][1] ?? 0;
				$values[$date] = $visits > 0 ? $pageviews / $visits : 0;
			} else {
				$values[$date] = $row['metrics'][0] ?? 0;
			}
		}

		// Plausible omits empty buckets and, for hours, returns timezone
		// boundary hours from adjacent days. Zero-fill to a consistent grid.
		$range = $data['query']['date_range'] ?? null;
		if ($interval === 'hour' && is_array($range)) {
			return $this->fillHours($range[0], $values);
		}
		if ($range && ($interval === 'day' || $interval === 'month')) {
			return $this->fillSeries($range, $interval, $values);
		}

		$series = [];
		foreach ($values as $date => $value) {
			$series[] = ['date' => $date, 'value' => $value];
		}

		return $series;
	}

	protected function fillHours(string $rangeStart, array $values): array
	{
		$day = substr($rangeStart, 0, 10);

		// Past days show all 24 hours; today stops at the last hour with data
		// (from the data, not the clock, so the site's timezone can't skew it).
		$lastHour = 23;
		try {
			if ($day === (new DateTime('today'))->format('Y-m-d')) {
				$lastHour = -1;
				foreach ($values as $key => $value) {
					if (strpos($key, $day . ' ') === 0) {
						$lastHour = max($lastHour, (int) substr($key, 11, 2));
					}
				}
			}
		} catch (Throwable) {
		}

		$series = [];
		for ($h = 0; $h <= $lastHour; $h++) {
			$key = sprintf('%s %02d:00:00', $day, $h);
			$series[] = ['date' => $key, 'value' => $values[$key] ?? 0];
		}

		return $series;
	}

	protected function fillSeries(array $range, string $interval, array $values): array
	{
		try {
			$start = new DateTime(substr($range[0], 0, 10));
			$end   = new DateTime(substr($range[1], 0, 10));
		} catch (Throwable) {
			$series = [];
			foreach ($values as $date => $value) {
				$series[] = ['date' => $date, 'value' => $value];
			}
			return $series;
		}

		$today = new DateTime('today');
		if ($end > $today) {
			$end = $today;
		}

		if ($interval === 'month') {
			$cursor = new DateTime($start->format('Y-m-01'));
			$step   = new DateInterval('P1M');
			$format = 'Y-m-01';
		} else {
			$cursor = clone $start;
			$step   = new DateInterval('P1D');
			$format = 'Y-m-d';
		}

		$series = [];
		while ($cursor <= $end) {
			$key = $cursor->format($format);
			$series[] = ['date' => $key, 'value' => $values[$key] ?? 0];
			$cursor->add($step);
		}

		return $series;
	}

	public function breakdown(string $dimension, array $metrics, string $period, int $limit, ?string $date = null): array
	{
		$data = $this->query([
			'metrics'    => $metrics,
			'date_range' => $this->range($period, $date),
			'dimensions' => [$dimension],
			'order_by'   => [[$metrics[0], 'desc']],
			'pagination' => ['limit' => $limit],
		]);

		$rows = [];
		foreach ($data['results'] ?? [] as $row) {
			$values = [];
			foreach ($metrics as $i => $metric) {
				$values[$metric] = $row['metrics'][$i] ?? 0;
			}

			$rows[] = [
				'label'   => $row['dimensions'][0] ?? '',
				'metrics' => $values,
			];
		}

		return $rows;
	}

	public function realtime(): int
	{
		$response = Remote::request($this->url . '/api/v1/stats/realtime/visitors?site_id=' . urlencode($this->site), [
			'method'  => 'GET',
			'headers' => ['Authorization' => 'Bearer ' . $this->token],
		]);

		return (int) $response->content();
	}

	protected function range(string $period, ?string $date = null): array|string
	{
		// No anchor: keep the keyword form so the default view is unchanged.
		if ($period === 'all') {
			return 'all';
		}
		if ($date === null && $period === 'day') {
			return 'day';
		}

		try {
			// Rolling windows end yesterday; month/year windows key off today.
			// The no-anchor defaults reproduce the original payload exactly.
			$rollEnd  = $date ? new DateTime($date) : new DateTime('yesterday');
			$monthRef = $date ? new DateTime($date) : new DateTime('today');
			$maxEnd   = new DateTime('yesterday'); // today is partial, never send it
			$today    = new DateTime('today');
		} catch (Throwable) {
			return $period;
		}

		if ($period === 'day') {
			$day = $rollEnd > $today ? clone $today : clone $rollEnd;
			return [$day->format('Y-m-d'), $day->format('Y-m-d')];
		}

		switch ($period) {
			case '7d':
				$end   = clone $rollEnd;
				$start = (clone $rollEnd)->modify('-6 days');
				break;
			case '28d':
				$end   = clone $rollEnd;
				$start = (clone $rollEnd)->modify('-27 days');
				break;
			case '30d':
				$end   = clone $rollEnd;
				$start = (clone $rollEnd)->modify('-29 days');
				break;
			case '91d':
				$end   = clone $rollEnd;
				$start = (clone $rollEnd)->modify('-90 days');
				break;
			case 'month':
				$start = new DateTime($monthRef->format('Y-m-01'));
				$end   = (clone $start)->modify('last day of this month');
				break;
			case '6mo':
				$start = (new DateTime($monthRef->format('Y-m-01')))->modify('-5 months');
				$end   = (new DateTime($monthRef->format('Y-m-01')))->modify('last day of this month');
				break;
			case '12mo':
				$start = (new DateTime($monthRef->format('Y-m-01')))->modify('-11 months');
				$end   = (new DateTime($monthRef->format('Y-m-01')))->modify('last day of this month');
				break;
			case 'year':
				$start = new DateTime($monthRef->format('Y-01-01'));
				$end   = new DateTime($monthRef->format('Y-12-31'));
				break;
			default:
				return $period;
		}

		// never send a future / partial-today end
		if ($end > $maxEnd) {
			$end = clone $maxEnd;
		}

		// guard against an empty range (e.g. "month" on the 1st)
		if ($start > $end) {
			$start = clone $end;
		}

		return [$start->format('Y-m-d'), $end->format('Y-m-d')];
	}

	protected function previousRange(array $range): array|null
	{
		try {
			$start = new DateTime(substr($range[0], 0, 10));
			$end   = new DateTime(substr($range[1], 0, 10));
		} catch (Throwable) {
			return null;
		}

		$days      = $start->diff($end)->days + 1;
		$prevEnd   = (clone $start)->modify('-1 day');
		$prevStart = (clone $prevEnd)->modify('-' . ($days - 1) . ' days');

		return [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')];
	}
}
