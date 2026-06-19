<?php

use Kirby\Toolkit\Str;
use Medienbaecker\Plausibly\Client;
use Medienbaecker\Plausibly\Pages;

$pageDimensions = ['event:page', 'visit:entry_page', 'visit:exit_page'];

$allowedPeriods = ['day', '7d', '28d', '30d', '91d', 'month', '6mo', '12mo', 'year', 'all'];
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

return [
	[
		'pattern' => 'plausible/aggregate',
		'action'  => function () use ($period, $allowedPeriods, $kpiMetrics) {
			try {
				return ['data' => (new Client())->aggregate($period($allowedPeriods), $kpiMetrics)];
			} catch (\Throwable $e) {
				return ['error' => $e->getMessage()];
			}
		},
	],
	[
		'pattern' => 'plausible/timeseries',
		'action'  => function () use ($period, $allowedPeriods, $allowedIntervals, $kpiMetrics) {
			try {
				$metric   = kirby()->request()->get('metric', 'visitors');
				$metric   = in_array($metric, $kpiMetrics, true) ? $metric : 'visitors';
				$interval = kirby()->request()->get('interval', 'day');
				$interval = in_array($interval, $allowedIntervals, true) ? $interval : 'day';

				return ['data' => (new Client())->timeseries($period($allowedPeriods), $metric, $interval)];
			} catch (\Throwable $e) {
				return ['error' => $e->getMessage()];
			}
		},
	],
	[
		'pattern' => 'plausible/breakdown',
		'action'  => function () use ($period, $allowedPeriods, $allowedDimensions, $allowedMetrics, $pageDimensions) {
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

				$rows = (new Client())->breakdown($dimension, $metrics, $period($allowedPeriods), $limit);

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
