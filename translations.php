<?php

use Kirby\Data\Json;

$translations = [];

foreach (glob(__DIR__ . '/translations/*.json') ?: [] as $file) {
	$locale = pathinfo($file, PATHINFO_FILENAME);

	foreach (Json::read($file) as $key => $value) {
		$translations[$locale]['medienbaecker.plausibly.' . $key] = $value;
	}
}

return $translations;
