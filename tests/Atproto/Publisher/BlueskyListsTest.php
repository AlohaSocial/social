<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\BlueskyLists;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Service\CacheActorService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyListsTest extends TestCase {
	private const DID = 'did:plc:alice';

	/** @var StoredRecord[] */
	private array $records = [];
	private array $written = [];
	private array $removed = [];

	private function lists(): BlueskyLists {
		$publisher = $this->createMock(Publisher::class);
		$publisher->method('writeRecord')->willReturnCallback(function (Person $actor, string $collection, array $record, string $localId): bool {
			$this->written[] = [$collection, $record['subject'] ?? $record['name'], $localId];
			if ($collection === BlueskyLists::LIST) {
				$this->records[] = new StoredRecord(self::DID, $collection, '3klist', Cid::forRaw('l'), '', $localId, 0);
			}

			return true;
		});
		$publisher->method('removeRecord')->willReturnCallback(function (string $collection, string $localId): bool {
			$this->removed[] = [$collection, $localId];

			return true;
		});
		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('getRecordsByLocalId')->willReturnCallback(fn (string $id): array => array_values(array_filter($this->records, static fn (StoredRecord $r): bool => $r->localId === $id)));
		$lists = $this->createMock(ListsRequest::class);
		$lists->method('getOwnedById')->willReturn((new MastodonList())->setId(7)->setTitle('Friends'));
		$lists->method('getMemberIds')->willReturn(['https://bsky.app/profile/did:plc:bob', 'https://social.test/@carol', 'https://remote.example/users/dave']);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturnCallback(static fn (string $id): Person => (new Person())->setId($id)->setLocal(str_starts_with($id, 'https://social.test/')));
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(static fn (Person $p): ?Identity => $p->getId() === 'https://social.test/@carol' ? new Identity(2, $p->getId(), 'did:plc:carol', 'carol.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0) : null);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);

		return new BlueskyLists($publisher, $repositories, $lists, $cacheActors, $identities, $time, new NullLogger());
	}

	public function testAListIsPublishedOnceWithTheMembersThatAreOnBluesky(): void {
		$alice = (new Person())->setId('https://social.test/@alice')->setLocal(true);
		$lists = $this->lists();

		$this->assertSame('at://' . self::DID . '/app.bsky.graph.list/3klist', $lists->ensure($alice, 7));
		$this->assertSame('at://' . self::DID . '/app.bsky.graph.list/3klist', $lists->ensure($alice, 7), 'once');
		$this->assertSame([
			[BlueskyLists::LIST, 'Friends', 'list:7'],
			[BlueskyLists::ITEM, 'did:plc:bob', 'list:7#' . md5('https://bsky.app/profile/did:plc:bob')],
			[BlueskyLists::ITEM, 'did:plc:carol', 'list:7#' . md5('https://social.test/@carol')],
		], $this->written, 'a member on the fediverse alone has nothing to be on Bluesky');

		$lists->memberRemoved(7, 'https://bsky.app/profile/did:plc:bob');
		$lists->deleted(7, ['https://social.test/@carol']);
		$this->assertSame([
			[BlueskyLists::ITEM, 'list:7#' . md5('https://bsky.app/profile/did:plc:bob')],
			[BlueskyLists::ITEM, 'list:7#' . md5('https://social.test/@carol')],
			[BlueskyLists::LIST, 'list:7'],
		], $this->removed);
	}

	public function testAMemberOfAListNotPublishedIsNotPublished(): void {
		$this->lists()->memberAdded((new Person())->setId('https://social.test/@alice'), 9, 'https://bsky.app/profile/did:plc:bob');

		$this->assertSame([], $this->written);
	}
}
