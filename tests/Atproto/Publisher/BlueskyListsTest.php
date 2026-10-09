<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\BlueskyLists;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Repository\CommitResult;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
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
	/** @var RepoWrite[] */
	private array $rewritten = [];
	/** @var StoredRecord[] the owner's threadgates */
	private array $gates = [];

	/** @var MastodonList[] */
	private array $publicLists = [];
	/** @var StoredRecord[] item records in the repository */
	private array $items = [];
	private ?DurableCache $durableCache = null;

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
		$repositories->method('listRecords')->willReturnCallback(fn (string $did, string $collection): array => match ($collection) {
			RecordMapper::THREADGATE => $this->gates,
			BlueskyLists::ITEM => $this->items,
			default => [],
		});
		$repositories->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $writes): CommitResult {
			array_push($this->rewritten, ...$writes);

			return new CommitResult($did, Cid::forRaw('commit'), '3krev', 1, []);
		});
		$lists = $this->createMock(ListsRequest::class);
		$lists->method('getOwnedById')->willReturn((new MastodonList())->setId(7)->setTitle('Friends'));
		$lists->method('getPublic')->willReturnCallback(fn (int $limit, int $after): array => array_slice(array_values(array_filter($this->publicLists, static fn (MastodonList $l): bool => $l->getId() > $after)), 0, $limit));
		$lists->method('getMemberIds')->willReturn(['https://bsky.app/profile/did:plc:bob', 'https://social.test/@carol', 'https://remote.example/users/dave']);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturnCallback(static fn (string $id): Person => (new Person())->setId($id)->setLocal(str_starts_with($id, 'https://social.test/')));
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(static fn (Person $p): ?Identity => $p->getId() === 'https://social.test/@carol' ? new Identity(2, $p->getId(), 'did:plc:carol', 'carol.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0) : null);
		$identities->method('getByDid')->willReturn(new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0));
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);

		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$this->durableCache ??= new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);

		return new BlueskyLists($publisher, $repositories, $lists, $cacheActors, $identities, $time, new NullLogger(), $this->durableCache);
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

	public function testAPublicListNotOnBlueskyYetIsPublishedByTheNextPass(): void {
		$this->publicLists = [
			(new MastodonList())->setId(7)->setTitle('Friends')->setOwnerId('https://social.test/@alice'),
			(new MastodonList())->setId(8)->setTitle('Work')->setOwnerId('https://social.test/@alice'),
		];
		$this->records[] = new StoredRecord(self::DID, BlueskyLists::LIST, '3kwork', Cid::forRaw('w'), '', 'list:8', 0);

		$this->assertSame(1, $this->lists()->publishMissing(1), 'the first page: the list made public while Bluesky was off');
		$this->assertSame([BlueskyLists::LIST, 'Friends', 'list:7'], $this->written[0]);
		$this->assertSame(0, $this->lists()->publishMissing(1), 'the next page: the one that is there already');
		$this->assertSame(0, $this->lists()->publishMissing(1), 'past the end: the next pass starts from the first');
	}

	public function testAnItemNoMemberHereStandsForGoesWithItsList(): void {
		$this->records[] = new StoredRecord(self::DID, BlueskyLists::LIST, '3klist', Cid::forRaw('l'), '', 'list:7', 0);
		$item = static fn (string $rkey, string $list): StoredRecord => new StoredRecord(self::DID, BlueskyLists::ITEM, $rkey, Cid::forRaw($rkey), DagCbor::encode(['$type' => BlueskyLists::ITEM, 'subject' => 'did:plc:gone', 'list' => $list, 'createdAt' => '2026-10-09T10:00:00.000Z']), '', 0);
		$this->items = [$item('3kgone', 'at://' . self::DID . '/app.bsky.graph.list/3klist'), $item('3kother', 'at://' . self::DID . '/app.bsky.graph.list/3kother')];

		$this->lists()->deleted(7, []);

		$this->assertSame(['3kgone'], array_map(static fn (RepoWrite $write): string => $write->rkey, $this->rewritten), 'only the item of the list that went');
		$this->assertSame([[BlueskyLists::LIST, 'list:7']], $this->removed);
	}

	public function testAMemberOfAListNotPublishedIsNotPublished(): void {
		$this->lists()->memberAdded((new Person())->setId('https://social.test/@alice'), 9, 'https://bsky.app/profile/did:plc:bob');

		$this->assertSame([], $this->written);
	}

	/** A list record published as `ensure()` writes one, with a description an app gave it. */
	private function published(string $name): StoredRecord {
		$bytes = DagCbor::encode(['$type' => BlueskyLists::LIST, 'purpose' => BlueskyLists::CURATELIST, 'name' => $name, 'description' => 'the people I read', 'createdAt' => '2026-10-09T10:00:00.000Z']);
		$record = new StoredRecord(self::DID, BlueskyLists::LIST, '3klist', Cid::forDagCbor($bytes), $bytes, 'list:7', 0);
		$this->records[] = $record;

		return $record;
	}

	public function testAListMadePublicIsPublishedWithItsMembers(): void {
		$alice = (new Person())->setId('https://social.test/@alice')->setLocal(true);

		$this->lists()->sync($alice, (new MastodonList())->setId(7)->setTitle('Friends')->setPublic(true));

		$this->assertSame([BlueskyLists::LIST, 'Friends', 'list:7'], $this->written[0]);
		$this->assertCount(3, $this->written, 'the list and its two members on Bluesky');
	}

	public function testAPrivateListIsNotPublished(): void {
		$this->lists()->sync((new Person())->setId('https://social.test/@alice'), (new MastodonList())->setId(7)->setTitle('Friends'));

		$this->assertSame([], $this->written);
	}

	public function testAPublicListRenamedHereIsRenamedThereAndKeepsTheRest(): void {
		$this->published('Friends');

		$this->lists()->sync((new Person())->setId('https://social.test/@alice'), (new MastodonList())->setId(7)->setTitle(str_repeat('Close friends ', 6))->setPublic(true));

		$this->assertCount(1, $this->rewritten);
		$write = $this->rewritten[0];
		$this->assertSame(RepoWrite::UPDATE, $write->action);
		$this->assertSame('3klist', $write->rkey);
		$this->assertSame('list:7', $write->localId, 'it still stands for the list');
		$this->assertSame(mb_substr(str_repeat('Close friends ', 6), 0, 64), $write->record['name'], 'as long as a Bluesky list name may be');
		$this->assertSame('the people I read', $write->record['description']);
		$this->assertSame([], $this->written);

		$this->rewritten = [];
		$this->records = [];
		$this->published('Friends');
		$this->lists()->sync((new Person())->setId('https://social.test/@alice'), (new MastodonList())->setId(7)->setTitle('Friends')->setPublic(true));
		$this->assertSame([], $this->rewritten, 'a name that did not change is not written again');
	}

	public function testAListMadePrivateIsWithdrawnWithItsMembers(): void {
		$this->published('Friends');

		$this->lists()->sync((new Person())->setId('https://social.test/@alice'), (new MastodonList())->setId(7)->setTitle('Friends'));

		$this->assertSame([
			[BlueskyLists::ITEM, 'list:7#' . md5('https://bsky.app/profile/did:plc:bob')],
			[BlueskyLists::ITEM, 'list:7#' . md5('https://social.test/@carol')],
			[BlueskyLists::ITEM, 'list:7#' . md5('https://remote.example/users/dave')],
			[BlueskyLists::LIST, 'list:7'],
		], $this->removed);
	}

	public function testAListAReplyRuleNamesStaysOnBlueskyWhenMadePrivate(): void {
		$list = $this->published('Friends');
		$bytes = DagCbor::encode(['$type' => RecordMapper::THREADGATE, 'post' => 'at://' . self::DID . '/app.bsky.feed.post/3kpost', 'allow' => [
			['$type' => RecordMapper::THREADGATE . '#followerRule'],
			['$type' => RecordMapper::THREADGATE . '#listRule', 'list' => $list->uri()],
		], 'createdAt' => '2026-10-09T10:00:00.000Z']);
		$this->gates = [new StoredRecord(self::DID, RecordMapper::THREADGATE, '3kpost', Cid::forDagCbor($bytes), $bytes, 'https://social.test/@alice/1', 0)];

		$this->lists()->sync((new Person())->setId('https://social.test/@alice'), (new MastodonList())->setId(7)->setTitle('Friends'));

		$this->assertSame([], $this->removed);
	}

	public function testTheListALocalIdStandsFor(): void {
		$this->assertSame(7, BlueskyLists::listIdOf('list:7'));
		$this->assertSame(7, BlueskyLists::listIdOf(BlueskyLists::itemLocalId(7, 'https://bsky.app/profile/did:plc:bob')));
		$this->assertNull(BlueskyLists::listIdOf(''));
		$this->assertNull(BlueskyLists::listIdOf('https://social.test/@alice/1'));
		$this->assertNull(BlueskyLists::listIdOf('list:x'));
	}
}
