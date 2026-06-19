<?php

namespace Medienbaecker\Plausibly;

use Kirby\Cms\Page;
use Throwable;

class Pages
{
	public static function link(string $path): ?array
	{
		$kirby  = kirby();
		$lookup = trim(static::pathname($path), '/');
		$lang   = null;

		if ($kirby->multilang()) {
			[$first, $rest] = array_pad(explode('/', $lookup, 2), 2, null);

			if ($language = $kirby->language($first)) {
				$lang   = $language->code();
				$lookup = (string) $rest;
			} else {
				$lang = $kirby->defaultLanguage()->code();
			}
		}

		try {
			$page = $kirby->resolve($lookup ?: null, $lang);
		} catch (Throwable) {
			return null;
		}

		if ($page instanceof Page === false) {
			return null;
		}

		$link = $page->panel()->url(true);

		if ($lang !== null) {
			$link .= '?language=' . $lang;
		}

		return [
			'title' => $page->title()->value(),
			'link'  => $link,
		];
	}

	protected static function pathname(string $url): string
	{
		return parse_url($url, PHP_URL_PATH) ?: '/';
	}
}
