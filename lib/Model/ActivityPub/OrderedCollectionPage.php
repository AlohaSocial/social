<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use JsonSerializable;

class OrderedCollectionPage extends ACore implements JsonSerializable {
	public const TYPE = 'OrderedCollectionPage';

	private string $partOf = '';
	private array $orderedItems = [];
	private string $next = '';
	private string $prev = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * One page of a collection by number: the items, a back-reference to the
	 * collection, and the neighbours a consumer walks.
	 *
	 * `next` is present exactly when the page came back full, the only thing
	 * known without counting. With `$nextCursor` it points at a cursor page (see
	 * after()), since a numbered page is an offset that rereads all before it.
	 *
	 * @param string[]|array[] $items
	 * @param string $cursorName the query parameter a cursor travels in
	 */
	public static function of(
		string $partOf, string $pageUrl, int $page, array $items, string $nextCursor = '', string $cursorName = 'max_id',
	): self {
		$next = ($nextCursor === '')
			? $pageUrl . '?page=' . ($page + 1)
			: self::cursorUrl($pageUrl, $cursorName, $nextCursor);

		return self::build(
			$partOf, $pageUrl . '?page=' . $page, $items, $next,
			($page > 1) ? $pageUrl . '?page=' . ($page - 1) : ''
		);
	}

	/**
	 * The page after a cursor: what every `next` link of a collection names.
	 * It carries no `prev`: the collection is walked forwards from `first`.
	 *
	 * @param string[]|array[] $items
	 * @param string $cursor where this page starts
	 * @param string $nextCursor where the page after it would
	 */
	public static function after(
		string $partOf, string $pageUrl, string $cursorName, string $cursor, array $items, string $nextCursor,
	): self {
		return self::build(
			$partOf, self::cursorUrl($pageUrl, $cursorName, $cursor), $items,
			self::cursorUrl($pageUrl, $cursorName, $nextCursor), ''
		);
	}

	private static function cursorUrl(string $pageUrl, string $cursorName, string $cursor): string {
		return $pageUrl . '?page=true&' . $cursorName . '=' . rawurlencode($cursor);
	}

	/** @param string[]|array[] $items */
	private static function build(string $partOf, string $id, array $items, string $next, string $prev): self {
		$collectionPage = new self();
		$collectionPage->setId($id);
		$collectionPage->setPartOf($partOf);
		$collectionPage->setOrderedItems($items);

		if (count($items) === OrderedCollection::PAGE_SIZE) {
			$collectionPage->setNext($next);
		}
		$collectionPage->setPrev($prev);

		return $collectionPage;
	}

	/**
	 * The page number a `page` query parameter asks for, or 0 when it asks for
	 * none. `?page=true` is Mastodon's way of saying "the first one".
	 */
	public static function requestedPage(string $page): int {
		if ($page === 'true') {
			return 1;
		}

		return (ctype_digit($page) && (int)$page > 0) ? (int)$page : 0;
	}

	public function getPartOf(): string {
		return $this->partOf;
	}

	public function setPartOf(string $partOf): self {
		$this->partOf = $partOf;

		return $this;
	}

	public function getOrderedItems(): array {
		return $this->orderedItems;
	}

	public function setOrderedItems(array $orderedItems): self {
		$this->orderedItems = $orderedItems;

		return $this;
	}

	public function getNext(): string {
		return $this->next;
	}

	public function setNext(string $next): self {
		$this->next = $next;

		return $this;
	}

	public function getPrev(): string {
		return $this->prev;
	}

	public function setPrev(string $prev): self {
		$this->prev = $prev;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return array_filter(
			array_merge(
				parent::jsonSerialize(),
				[
					'partOf' => $this->getPartOf(),
					'orderedItems' => $this->getOrderedItems(),
					'next' => $this->getNext(),
					'prev' => $this->getPrev()
				]
			)
		);
	}
}
