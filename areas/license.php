<?php

use Kirby\Exception\InvalidArgumentException;
use Medienbaecker\Plausibly\License;

return [
	'system' => function ($kirby) {
		return [
			'dialogs' => [
				'plausibly/remove-license' => [
					'load' => fn() => [
						'component' => 'k-remove-dialog',
						'props' => [
							'text' => t('medienbaecker.plausibly.license.remove.text'),
							'submitButton' => [
								'icon' => 'trash',
								'text' => t('medienbaecker.plausibly.license.remove.submit'),
								'theme' => 'negative'
							]
						]
					],
					'submit' => function () {
						License::remove();
						return ['redirect' => 'system'];
					}
				],
				'plausibly/activate' => [
					'load' => function () use ($kirby) {
						$key = License::readKey();

						if ($key) {
							$version = $kirby->plugin('medienbaecker/plausibly')->version();
							return [
								'component' => 'k-plausibly-license-dialog',
								'props' => [
									'license' => [
										'code'    => $key,
										'version' => $version ? 'v' . $version : null,
									],
									'cancelButton' => false,
									'submitButton' => [
										'icon'   => 'open',
										'text'   => t('medienbaecker.plausibly.license.portal'),
										'theme'  => 'info',
										'link'   => License::PORTAL_URL,
										'target' => '_blank'
									]
								]
							];
						}

						return [
							'component' => 'k-form-dialog',
							'props' => [
								'fields' => [
									'info' => [
										'type' => 'info',
										'text' => tt('medienbaecker.plausibly.license.info', ['url' => License::BUY_URL])
									],
									'key' => [
										'label' => t('medienbaecker.plausibly.license.key'),
										'type' => 'text',
										'required' => true,
										'placeholder' => 'PLAUSIBLY-0A550468-F0BB-4894-A833-F056AC38CE98',
										'help' => t('medienbaecker.plausibly.license.key.help')
									]
								],
								'submitButton' => [
									'icon' => 'check',
									'text' => t('medienbaecker.plausibly.license.submit'),
									'theme' => 'love'
								]
							]
						];
					},
					'submit' => function () use ($kirby) {
						$key = $kirby->request()->get('key');

						if (empty($key)) {
							throw new InvalidArgumentException(t('medienbaecker.plausibly.license.error.empty'));
						}

						if (!License::activate($key)) {
							throw new InvalidArgumentException(t('medienbaecker.plausibly.license.error.invalid'));
						}

						return ['redirect' => 'system'];
					}
				]
			]
		];
	}
];
