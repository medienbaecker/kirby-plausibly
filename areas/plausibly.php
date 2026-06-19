<?php

use Medienbaecker\Plausibly\Client;

return [
	'plausibly' => function () {
		if (Client::isConfigured() === false) return [];

		return [
			'label' => t('medienbaecker.plausibly.title'),
			'icon'  => 'chart',
			'menu'  => true,
			'link'  => 'plausibly',
			'views' => [
				[
					'pattern' => 'plausibly',
					'action'  => function () {
						return [
							'component' => 'k-plausible-view',
							'title'     => t('medienbaecker.plausibly.title'),
							'props'     => [
								'site'        => option('medienbaecker.plausibly.site'),
								'faviconBase' => rtrim(option('medienbaecker.plausibly.url'), '/') . '/favicon/sources/',
							],
						];
					},
				],
			],
		];
	},
];
