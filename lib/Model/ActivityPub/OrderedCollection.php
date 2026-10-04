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
	 * How many entries one page of a collection holds. Mastodon serves 40 at a
	 * time; the number only has to be stable, because it is what the page
	 * boundaries on `first`/`last` and `next`/`prev` are computed from.
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
	 * A collection that says where its pages actually are.
	 *
	 * followers, following and outbox have always advertised `first` as
	 * `?page=1`, but nothing read the parameter: `?page=1` returned the
	 * identical collection, whose `first` pointed at itself. A consumer
	 * following `first` either looped or gave up, so no peer could enumerate any
	 * of the three — which is how another instance discovers who to deliver to
	 * when its own record is incomplete, and how migration and archiving tools
	 * read an account.
	 *
	 * `last` names the highest page number the collection has; with nothing in
	 * it, that is still page one — an empty page is a truthful answer, a page
	 * number that does not exist is not.
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

	#[\Override]
	public function import(array $data): self {
		parent::import($data);
		$this->setFirst($this->validate(ACore::AS_USERNAME, 'first', $data, ''))
			->setLast($this->validate(ACore::AS_USERNAME, 'last', $data, ''))
			->setTotalItems($this->getInt('totalItems', $data));

		return $this;
	}

	/**
	 * `totalItems` is always stated, 0 included: an empty collection says it
	 * holds nothing, where one that leaves the count out says nothing. PeerTube
	 * reads a missing count on a video's `likes` as undefined, and its write of
	 * that undefined crashed the whole server.
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
