<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Model;

use JsonSerializable;
use OCA\Social\Tools\Exceptions\CacheItemNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class Cache
 *
 * @package OCA\Social\Tools\Model
 */
class Cache implements JsonSerializable {
	use TArrayTools;

	/** @var CacheItem[] */
	private array $items = [];

	public function __construct() {
	}

	/**
	 * @return CacheItem[]
	 */
	public function getItems(): array {
		return $this->items;
	}

	/**
	 * @param CacheItem $item
	 *
	 * @return Cache
	 */
	public function addItem(CacheItem $item): Cache {
		if ($item->getUrl() === '' || $this->hasItem($item->getUrl())) {
			return $this;
		}

		$this->items[] = $item;

		return $this;
	}

	/**
	 * @param string $url
	 *
	 * @return CacheItem
	 * @throws CacheItemNotFoundException
	 */
	public function getItem(string $url): CacheItem {
		foreach ($this->getItems() as $item) {
			if ($item->getUrl() === $url) {
				return $item;
			}
		}

		throw new CacheItemNotFoundException();
	}

	/**
	 * @param string $url
	 *
	 * @return bool
	 */
	public function hasItem(string $url): bool {
		try {
			$this->getItem($url);

			return true;
		} catch (CacheItemNotFoundException $e) {
			return false;
		}
	}

	/**
	 * @param string $url
	 *
	 * @return Cache
	 */
	public function removeItem(string $url): Cache {
		$new = [];
		foreach ($this->getItems() as $item) {
			if ($item->getUrl() !== $url) {
				$new[] = $item;
			}
		}

		$this->items = $new;

		return $this;
	}

	/**
	 * $create can be false if we don't create item if it is not already in the list.
	 *
	 * @param CacheItem $cacheItem
	 * @param bool $create
	 *
	 * @return Cache
	 */
	public function updateItem(CacheItem $cacheItem, bool $create = true): Cache {
		if ($cacheItem->getUrl() === '') {
			return $this;
		}

		$new = [];
		$updated = false;
		foreach ($this->getItems() as $item) {
			if ($item->getUrl() === $cacheItem->getUrl()) {
				$updated = true;
				$new[] = $cacheItem;
			} else {
				$new[] = $item;
			}
		}

		if (!$updated && $create) {
			$new[] = $cacheItem;
		}

		$this->items = $new;

		return $this;
	}

	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$items = $this->getArray('_items', $data, []);

		foreach ($items as $entry) {
			$item = new CacheItem($entry);
			if (!array_key_exists($entry, $data)) {
				continue;
			}

			$item->import($data[$entry]);
			$this->addItem($item);
		}
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$ids = array_map(
			function (CacheItem $item) {
				return $item->getUrl();
			}, $this->getItems()
		);

		$result = [
			'_items' => $ids,
			'_count' => count($ids)
		];

		foreach ($this->getItems() as $item) {
			$result[$item->getUrl()] = $item;
		}

		return $result;
	}
}
