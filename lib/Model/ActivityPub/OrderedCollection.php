<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use JsonSerializable;

class OrderedCollection extends ACore implements JsonSerializable {
	public const TYPE = 'OrderedCollection';

	/**
	 * How many entries one page of a collection holds, as Mastodon's 40; it only
	 * has to be stable, since page boundaries are computed from it.
	 */
	public const PAGE_SIZE = 40;

	private int $totalItems = 0;
	private string $first = '';
	private string $last = '';

	/** @var array[] the items themselves, for collections small enough to inline */
	private array $orderedItems = [];

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getTotalItems(): int {
		return $this->totalItems;
	}

	public function setTotalItems(int $totalItems): self {
		$this->totalItems = $totalItems;

		return $this;
	}

	public function getFirst(): string {
		return $this->first;
	}

	public function setFirst(string $first): self {
		$this->first = $first;

		return $this;
	}

	public function getLast(): string {
		return $this->last;
	}

	public function setLast(string $last): self {
		$this->last = $last;

		return $this;
	}

	/**
	 * @return array[]
	 */
	public function getOrderedItems(): array {
		return $this->orderedItems;
	}

	/**
	 * @param array[] $orderedItems
	 */
	public function setOrderedItems(array $orderedItems): self {
		$this->orderedItems = $orderedItems;

		return $this;
	}

	/**
	 * A collection that says where its pages are: `first` is `?page=1`, and
	 * `last` the highest page number, still page one when empty, since an empty
	 * page is a truthful answer and a missing page is not.
	 */
	public static function paged(string $id, int $totalItems, string $pageUrl): self {
		$collection = new self();
		$collection->setId($id);
		$collection->setTotalItems($totalItems);
		$collection->setFirst($pageUrl . '?page=1');
		$collection->setLast(
			$pageUrl . '?page=' . max(1, (int)ceil($totalItems / self::PAGE_SIZE))
		);

		return $collection;
	}

	/**
	 * A collection that states how many items it holds and lists none: no
	 * `first`, so there is nothing to page through.
	 */
	public static function counted(string $id, int $totalItems): self {
		$collection = new self();
		$collection->setId($id);
		$collection->setTotalItems($totalItems);

		return $collection;
	}

	#[\Override]
	public function import(array $data): self {
		parent::import($data);
		$this->setFirst($this->validate(ACore::AS_USERNAME, 'first', $data, ''))
			->setLast($this->validate(ACore::AS_USERNAME, 'last', $data, ''))
			->setTotalItems($this->getInt('totalItems', $data));

		return $this;
	}

	/**
	 * `totalItems` is always stated, 0 included: PeerTube reads a missing count
	 * on a video's `likes` as undefined, and writing that crashes it.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$data = array_filter(
			array_merge(
				parent::jsonSerialize(),
				[
					'first' => $this->getFirst(),
					'last' => $this->getLast(),
					'orderedItems' => $this->getOrderedItems()
				]
			)
		);
		$data['totalItems'] = $this->getTotalItems();

		return $data;
	}
}
