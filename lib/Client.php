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

	public function aggregate(string $period, array $metrics): array
	{
		$current = $this->query([
			'metrics'    => $metrics,
			'date_range' => $this->range($period),
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

	public function timeseries(string $period, string $metric, string $interval): array
	{
		$derived = $metric === 'views_per_visit';
		$metrics = $derived ? ['pageviews', 'visits'] : [$metric];

		$data = $this->query([
			'metrics'    => $metrics,
			'date_range' => $this->range($period),
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

		// Plausible omits empty buckets; zero-fill day/month
		$range = $data['query']['date_range'] ?? null;
		if ($range && ($interval === 'day' || $interval === 'month')) {
			return $this->fillSeries($range, $interval, $values);
		}

		$series = [];
		foreach ($values as $date => $value) {
			$series[] = ['date' => $date, 'value' => $value];
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

	public function breakdown(string $dimension, array $metrics, string $period, int $limit): array
	{
		$data = $this->query([
			'metrics'    => $metrics,
			'date_range' => $this->range($period),
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

	protected function range(string $period): array|string
	{
		if ($period === 'day' || $period === 'all') {
			return $period;
		}

		try {
			$end   = new DateTime('yesterday');
			$start = clone $end;
		} catch (Throwable) {
			return $period;
		}

		switch ($period) {
			case '7d':
				$start->modify('-6 days');
				break;
			case '28d':
				$start->modify('-27 days');
				break;
			case '30d':
				$start->modify('-29 days');
				break;
			case '91d':
				$start->modify('-90 days');
				break;
			case 'month':
				$start = new DateTime(date('Y-m-01'));
				break;
			case '6mo':
				$start = (new DateTime(date('Y-m-01')))->modify('-5 months');
				break;
			case '12mo':
				$start = (new DateTime(date('Y-m-01')))->modify('-11 months');
				break;
			case 'year':
				$start = new DateTime(date('Y-01-01'));
				break;
			default:
				return $period;
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
