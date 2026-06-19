<?php

use Kirby\Cms\App as Kirby;
use Medienbaecker\Plausibly\License;

require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Pages.php';
require_once __DIR__ . '/lib/License.php';

Kirby::plugin(
	'medienbaecker/plausibly',
	license: fn($plugin) => new License($plugin),
	extends: [
		'options' => [
			'url'    => null,
			'site'   => null,
			'token'  => null,
			'script' => null,
		],
		'translations' => require __DIR__ . '/translations.php',
		'snippets'     => ['plausibly' => __DIR__ . '/snippets/plausibly.php'],
		'areas'        => array_merge(
			require __DIR__ . '/areas/plausibly.php',
			require __DIR__ . '/areas/license.php'
		),
		'api'          => [
			'routes' => require __DIR__ . '/routes/api.php',
		],
	]
);
