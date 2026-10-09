<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\BlueskyLists;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyListImport;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\MastodonList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A person's curate lists in their own repository, written by a Bluesky app
 * or brought by a move, as their lists here.
 */
#[AllowMockObjectsWithoutExpectations]
class BlueskyListImportTest extends TestCase {
	private const DID = 'did:plc:alice';
	private const BOB = 'did:plc:bob';
	private const CAROL = 'did:plc:carol';
	private const ALICE = 'https://social.test/@alice';

	/** @var array<int, MastodonList> the lists here, by id */
	private array $lists = [];
	/** @var array<int, list<string>> their members */
	private array $members = [];
	/** @var StoredRecord[] the repository */
	private array $records = [];
	/** @var array<string, string> path => local id given */
	private array $linked = [];
	/** @var BlueskyLists&MockObject */
	private BlueskyLists $blueskyLists;
	private BlueskyListImport $import;
	private Person $alice;

	protected function setUp(): void {
		$this->alice = (new Person())->setId(self::ALICE)->setLocal(true);
		$lists = $this->createMock(ListsRequest::class);
		$lists->method('create')->willReturnCallback(function (MastodonList $list): MastodonList {
			$list->setId(count($this->lists) + 7);
			$this->lists[$list->getId()] = $list;

			return $list;
		});
		$lists->method('getOwnedById')->willReturnCallback(function (string $owner, int $id): MastodonList {
			$list = $this->lists[$id] ?? null;
			if ($list === null || $list->getOwnerId() !== $owner) {
				throw new ItemNotFoundException();
			}

			return $list;
		});
		$lists->method('update')->willReturnCallback(function (MastodonList $list): void {
			$this->lists[$list->getId()] = $list;
		});
		$lists->method('delete')->willReturnCallback(function (MastodonList $list): void {
			unset($this->lists[$list->getId()], $this->members[$list->getId()]);
		});
		$lists->method('addMember')->willReturnCallback(function (MastodonList $list, string $member): void {
			$this->members[$list->getId()][] = $member;
		});
		$lists->method('removeMember')->willReturnCallback(function (MastodonList $list, string $member): void {
			$this->members[$list->getId()] = array_values(array_diff($this->members[$list->getId()] ?? [], [$member]));
		});
		$lists->method('getMemberIds')->willReturnCallback(fn (MastodonList $list): array => $this->members[$list->getId()] ?? []);

		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('getRecord')->willReturnCallback(fn (string $did, string $collection, string $rkey): ?StoredRecord => $this->current($collection, $rkey));
		$repositories->method('getRecordsByLocalId')->willReturnCallback(fn (string $localId): array => array_values(array_filter(array_map(fn (StoredRecord $r): StoredRecord => $this->current($r->collection, $r->rkey) ?? $r, $this->records), static fn (StoredRecord $r): bool => $r->localId === $localId)));
		$repositories->method('listRecords')->willReturnCallback(fn (string $did, string $collection): array => array_values(array_filter(array_map(fn (StoredRecord $r): ?StoredRecord => $this->current($r->collection, $r->rkey), $this->records), static fn (?StoredRecord $r): bool => $r?->collection === $collection)));
		$repoRequest = $this->createMock(AtprotoRepoRequest::class);
		$repoRequest->method('setLocalId')->willReturnCallback(function (string $did, string $collection, string $rkey, string $localId): void {
			$this->linked[$collection . '/' . $rkey] = $localId;
		});

		$local = $this->createMock(LocalRecordResolver::class);
		$local->method('actorId')->willReturnCallback(static fn (string $did): string => $did === self::CAROL ? 'https://social.test/@carol' : '');
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('resolve')->willReturnCallback(static function (string $did): Person {
			if ($did !== self::BOB) {
				throw new CacheActorDoesNotExistException();
			}

			return (new Person())->setId('https://bsky.app/profile/' . $did);
		});
		$this->blueskyLists = $this->createMock(BlueskyLists::class);

		$this->import = new BlueskyListImport($lists, $repositories, $repoRequest, $local, $actors, $this->blueskyLists, new NullLogger());
	}

	/** The record at a path, with the local id it was given since. */
	private function current(string $collection, string $rkey): ?StoredRecord {
		foreach ($this->records as $record) {
			if ($record->collection === $collection && $record->rkey === $rkey) {
				$localId = $this->linked[$collection . '/' . $rkey] ?? $record->localId;

				return new StoredRecord($record->did, $record->collection, $record->rkey, $record->cid, $record->bytes, $localId, 0);
			}
		}

		return null;
	}

	private function record(string $collection, string $rkey, array $value, string $localId = ''): StoredRecord {
		$bytes = DagCbor::encode(['$type' => $collection] + $value + ['createdAt' => '2026-10-09T10:00:00.000Z']);
		$record = new StoredRecord(self::DID, $collection, $rkey, Cid::forDagCbor($bytes), $bytes, $localId, 0);
		$this->records[] = $record;

		return $record;
	}

	private function listRecord(string $rkey, string $name, string $purpose = BlueskyLists::CURATELIST, string $localId = ''): StoredRecord {
		return $this->record(BlueskyLists::LIST, $rkey, ['purpose' => $purpose, 'name' => $name], $localId);
	}

	private function itemRecord(string $rkey, string $list, string $subject, string $localId = ''): StoredRecord {
		return $this->record(BlueskyLists::ITEM, $rkey, ['list' => 'at://' . self::DID . '/' . BlueskyLists::LIST . '/' . $list, 'subject' => $subject], $localId);
	}

	public function testACurateListAnAppWritesIsAPublicListHereTiedToItsRecord(): void {
		$listId = $this->import->listWritten($this->alice, $this->listRecord('3kfriends', '  Friends '));

		$this->assertSame(7, $listId);
		$this->assertSame('Friends', $this->lists[7]->getTitle());
		$this->assertTrue($this->lists[7]->isPublic(), 'a Bluesky list is public');
		$this->assertSame(self::ALICE, $this->lists[7]->getOwnerId());
		$this->assertSame(['app.bsky.graph.list/3kfriends' => 'list:7'], $this->linked);
	}

	public function testAListRecordThatStandsForAListRenamesIt(): void {
		$this->import->listWritten($this->alice, $this->listRecord('3kfriends', 'Friends'));
		$this->lists[7]->setPublic(false);
		$this->records = [];

		$this->assertSame(7, $this->import->listWritten($this->alice, $this->listRecord('3kfriends', 'Close friends', BlueskyLists::CURATELIST, 'list:7')));

		$this->assertCount(1, $this->lists, 'not made again');
		$this->assertSame('Close friends', $this->lists[7]->getTitle());
		$this->assertFalse($this->lists[7]->isPublic(), 'a rename is not a change of visibility');
	}

	public function testAModerationListIsNoListHere(): void {
		$this->assertNull($this->import->listWritten($this->alice, $this->listRecord('3kmod', 'Spam', 'app.bsky.graph.defs#modlist')));
		$this->assertSame([], $this->lists);

		$this->itemRecord('3kitem', '3kmod', self::BOB);
		$this->assertFalse($this->import->itemWritten($this->alice, $this->current(BlueskyLists::ITEM, '3kitem')));
	}

	public function testAListItemIsAMemberWhetherOnBlueskyOrHere(): void {
		$this->import->listWritten($this->alice, $this->listRecord('3kfriends', 'Friends'));

		$this->assertTrue($this->import->itemWritten($this->alice, $this->itemRecord('3kbob', '3kfriends', self::BOB)));
		$this->assertTrue($this->import->itemWritten($this->alice, $this->itemRecord('3kcarol', '3kfriends', self::CAROL)));
		$this->assertFalse($this->import->itemWritten($this->alice, $this->itemRecord('3kgone', '3kfriends', 'did:plc:gone')), 'an account Bluesky does not know is nobody');

		$this->assertSame(['https://bsky.app/profile/' . self::BOB, 'https://social.test/@carol'], $this->members[7]);
		$this->assertSame(BlueskyLists::itemLocalId(7, 'https://bsky.app/profile/' . self::BOB), $this->linked['app.bsky.graph.listitem/3kbob']);
		$this->assertSame(BlueskyLists::itemLocalId(7, 'https://social.test/@carol'), $this->linked['app.bsky.graph.listitem/3kcarol']);
	}

	public function testAnItemOfAListThatIsNotTheOwnersIsLeftAlone(): void {
		$this->lists[9] = (new MastodonList())->setId(9)->setOwnerId('https://social.test/@mallory');
		$this->listRecord('3kforeign', 'Not hers', BlueskyLists::CURATELIST, 'list:9');

		$this->assertFalse($this->import->itemWritten($this->alice, $this->itemRecord('3kbob', '3kforeign', self::BOB)));
		$this->assertFalse($this->import->itemWritten($this->alice, $this->record(BlueskyLists::ITEM, '3kelse', ['list' => 'at://did:plc:else/' . BlueskyLists::LIST . '/3kfriends', 'subject' => self::BOB])));
		$this->assertSame([], $this->members);
	}

	public function testAnItemThisServerWroteIsNotReadAgain(): void {
		$this->import->listWritten($this->alice, $this->listRecord('3kfriends', 'Friends'));

		$this->assertFalse($this->import->itemWritten($this->alice, $this->itemRecord('3kbob', '3kfriends', self::BOB, BlueskyLists::itemLocalId(7, 'https://bsky.app/profile/' . self::BOB))));
		$this->assertSame([], $this->members);
	}

	public function testDeletingTheListRecordDeletesTheListAndWithdrawsItsMembers(): void {
		$this->import->listWritten($this->alice, $this->listRecord('3kfriends', 'Friends'));
		$this->import->itemWritten($this->alice, $this->itemRecord('3kbob', '3kfriends', self::BOB));
		$this->blueskyLists->expects($this->once())->method('deleted')->with(7, ['https://bsky.app/profile/' . self::BOB]);

		$this->import->deleted($this->alice, $this->current(BlueskyLists::LIST, '3kfriends'));

		$this->assertSame([], $this->lists);
	}

	public function testDeletingAnItemTakesTheMemberOut(): void {
		$this->import->listWritten($this->alice, $this->listRecord('3kfriends', 'Friends'));
		$this->import->itemWritten($this->alice, $this->itemRecord('3kbob', '3kfriends', self::BOB));
		$this->import->itemWritten($this->alice, $this->itemRecord('3kcarol', '3kfriends', self::CAROL));
		$this->blueskyLists->expects($this->never())->method('memberRemoved');

		$this->import->deleted($this->alice, $this->current(BlueskyLists::ITEM, '3kbob'));

		$this->assertSame(['https://social.test/@carol'], $this->members[7]);
		$this->assertCount(1, $this->lists);
	}

	public function testDeletingARecordThatStandsForNothingChangesNothing(): void {
		$this->import->deleted($this->alice, $this->itemRecord('3kbob', '3kfriends', self::BOB));
		$this->import->deleted($this->alice, $this->listRecord('3kmod', 'Spam', 'app.bsky.graph.defs#modlist'));

		$this->assertSame([], $this->lists);
	}

	public function testAMovedAccountsListsBecomeItsListsOnce(): void {
		$this->listRecord('3kfriends', 'Friends');
		$this->listRecord('3kmod', 'Spam', 'app.bsky.graph.defs#modlist');
		$this->itemRecord('3kbob', '3kfriends', self::BOB);
		$this->itemRecord('3kspammer', '3kmod', self::BOB);

		$this->assertSame(1, $this->import->adopt($this->alice, self::DID));
		$this->assertSame(['Friends'], array_map(static fn (MastodonList $l): string => $l->getTitle(), array_values($this->lists)));
		$this->assertTrue($this->lists[7]->isPublic());
		$this->assertSame(['https://bsky.app/profile/' . self::BOB], $this->members[7]);

		$this->assertSame(0, $this->import->adopt($this->alice, self::DID), 'a record that stands for a list is not read again');
		$this->assertCount(1, $this->lists);
		$this->assertSame(['https://bsky.app/profile/' . self::BOB], $this->members[7]);
	}
}
