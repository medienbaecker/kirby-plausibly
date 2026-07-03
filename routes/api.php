<?php

use Kirby\Toolkit\Str;
use Medienbaecker\Plausibly\Client;
use Medienbaecker\Plausibly\Pages;

$pageDimensions = ['event:page', 'visit:entry_page', 'visit:exit_page'];

$allowedPeriods = ['day', '7d', '28d', '30d', '91d', 'month', '6mo', '12mo', 'year', 'all', 'custom'];
$allowedIntervals = ['hour', 'day', 'month'];
$allowedDimensions = [
	'visit:source',
	'visit:channel',
	'visit:utm_campaign',
	'event:page',
	'visit:entry_page',
	'visit:exit_page',
	'visit:country',
	'visit:country_name',
	'visit:region_name',
	'visit:city_name',
	'visit:browser',
	'visit:os',
	'visit:device',
	'event:goal',
];
$allowedFilterOps = ['is', 'is_not', 'contains'];
$allowedMetrics = [
	'visitors',
	'visits',
	'pageviews',
	'views_per_visit',
	'bounce_rate',
	'visit_duration',
	'events',
	'conversion_rate',
	'time_on_page',
];

$kpiMetrics = ['visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'visit_duration'];

$period = function (array $allowed) {
	$period = kirby()->request()->get('period', '28d');
	return in_array($period, $allowed, true) ? $period : '28d';
};

$ymd = function ($value) {
	if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
		[$y, $m, $d] = array_map('intval', explode('-', $value));
		return checkdate($m, $d, $y);
	}
	return false;
};

$date = function () use ($ymd) {
	$value = kirby()->request()->get('date');
	return $ymd($value) ? $value : null;
};

$custom = function () use ($ymd) {
	$from = kirby()->request()->get('from');
	$to   = kirby()->request()->get('to');
	if ($ymd($from) && $ymd($to) && $from <= $to) {
		return [$from, $to];
	}
	return null;
};

$compare = function () use ($ymd) {
	$mode = kirby()->request()->get('comparison');
	if (in_array($mode, ['previous_period', 'year_over_year', 'custom'], true) === false) {
		return [];
	}
	$options = [
		'mode'     => $mode,
		'matchDow' => kirby()->request()->get('match_day_of_week') === '1',
	];
	if ($mode === 'custom') {
		$from = kirby()->request()->get('compare_from');
		$to   = kirby()->request()->get('compare_to');
		if ($ymd($from) === false || $ymd($to) === false || $from > $to) {
			return [];
		}
		$options['range'] = [$from, $to];
	}
	return $options;
};

// Parses the frontend's filter codec ("is,visit%3Asource,taz.de;…") into
// API-ready triples. Throws on anything invalid: silently dropping a filter
// would show unfiltered numbers while a filter is active.
$filters = function () use ($allowedDimensions, $allowedFilterOps) {
	$raw = kirby()->request()->get('filters');
	if (is_string($raw) === false || $raw === '') {
		return [];
	}
	$out = [];
	foreach (explode(';', $raw) as $chunk) {
		$parts = array_map('rawurldecode', explode(',', $chunk));
		if (
			count($parts) < 3
			|| in_array($parts[0], $allowedFilterOps, true) === false
			|| in_array($parts[1], $allowedDimensions, true) === false
		) {
			throw new \Kirby\Exception\Exception('Invalid filter');
		}
		$values = array_values(array_filter(
			array_map('strval', array_slice($parts, 2)),
			fn($v) => $v !== ''
		));
		if ($values === []) {
			throw new \Kirby\Exception\Exception('Invalid filter');
		}
		$out[] = [$parts[0], $parts[1], $values];
	}
	return $out;
};

return [
	[
		'pattern' => 'plausible/aggregate',
		'action'  => function () use ($period, $date, $filters, $custom, $compare, $allowedPeriods, $kpiMetrics) {
			try {
				return ['data' => (new Client())->aggregate($period($allowedPeriods), $kpiMetrics, $date(), $filters(), $custom(), $compare())];
			} catch (\Throwable $e) {
				return ['error' => $e->getMessage()];
			}
		},
	],
	[
		'pattern' => 'plausible/timeseries',
		'action'  => function () use ($period, $date, $filters, $custom, $compare, $allowedPeriods, $allowedIntervals, $kpiMetrics) {
			try {
				$metric   = kirby()->request()->get('metric', 'visitors');
				$metric   = in_array($metric, $kpiMetrics, true) ? $metric : 'visitors';
				$interval = kirby()->request()->get('interval', 'day');
				$interval = in_array($interval, $allowedIntervals, true) ? $interval : 'day';

				return ['data' => (new Client())->timeseries($period($allowedPeriods), $metric, $interval, $date(), $filters(), $custom(), $compare())];
			} catch (\Throwable $e) {
				return ['error' => $e->getMessage()];
			}
		},
	],
	[
		'pattern' => 'plausible/breakdown',
		'action'  => function () use ($period, $date, $filters, $custom, $allowedPeriods, $allowedDimensions, $allowedMetrics, $pageDimensions) {
			try {
				$dimension = kirby()->request()->get('dimension');
				if (in_array($dimension, $allowedDimensions, true) === false) {
					return ['error' => 'Invalid dimension'];
				}

				$metrics = Str::split(kirby()->request()->get('metrics', 'visitors'), ',');
				$metrics = array_values(array_filter($metrics, fn($m) => in_array($m, $allowedMetrics, true)));
				$metrics = $metrics ?: ['visitors'];

				if ($dimension !== 'event:goal') {
					$metrics[] = 'percentage';
				}

				$limit = (int) kirby()->request()->get('limit', 9);
				$limit = max(1, min($limit, 100));

				$rows = (new Client())->breakdown($dimension, $metrics, $period($allowedPeriods), $limit, $date(), $filters(), $custom());

				if (in_array($dimension, $pageDimensions, true)) {
					foreach ($rows as &$row) {
						$row['page'] = Pages::link($row['label']);
					}
					unset($row);
				}

				return ['data' => $rows];
			} catch (\Throwable $e) {
				return ['error' => $e->getMessage()];
			}
		},
	],
	[
		'pattern' => 'plausible/realtime',
		'action'  => function () {
			try {
				return ['data' => (new Client())->realtime()];
			} catch (\Throwable $e) {
				return ['error' => $e->getMessage()];
			}
		},
	],
];
