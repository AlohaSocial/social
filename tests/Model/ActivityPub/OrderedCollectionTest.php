<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\OrderedCollection;
use PHPUnit\Framework\TestCase;

class OrderedCollectionTest extends TestCase {
	public function testConstructorSetsTheType(): void {
		$this->assertSame('OrderedCollection', (new OrderedCollection())->getType());
	}

	public function testImportReadsAMastodonFollowersCollection(): void {
		$collection = new OrderedCollection();

		$result = $collection->import([
			'id' => 'https://mastodon.social/users/alice/followers',
			'type' => 'OrderedCollection',
			'totalItems' => 1234,
			'first' => 'https://mastodon.social/users/alice/followers?page=1',
			'last' => 'https://mastodon.social/users/alice/followers?page=13',
		]);

		$this->assertSame($collection, $result);
		$this->assertSame(1234, $collection->getTotalItems());
		$this->assertSame('https://mastodon.social/users/alice/followers?page=1', $collection->getFirst());
		$this->assertSame('https://mastodon.social/users/alice/followers?page=13', $collection->getLast());
	}

	/**
	 * Empty fields are left out, but not the count: an empty collection
	 * holds 0 items and says so. PeerTube crashed on a video whose `likes`
	 * left the count out.
	 */
	public function testJsonSerializeDropsEmptyFieldsButStatesAZeroCount(): void {
		$collection = new OrderedCollection();
		$collection->setId('https://mastodon.social/users/alice/followers');

		$json = $collection->jsonSerialize();
		$this->assertSame(0, $json['totalItems']);
		$this->assertArrayNotHasKey('first', $json);
		$this->assertArrayNotHasKey('last', $json);

		$collection->setTotalItems(3)->setFirst('https://mastodon.social/users/alice/followers?page=1');
		$json = $collection->jsonSerialize();
		$this->assertSame(3, $json['totalItems']);
		$this->assertSame('https://mastodon.social/users/alice/followers?page=1', $json['first']);
		$this->assertArrayNotHasKey('last', $json);
	}
}
