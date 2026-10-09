<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\BlueskyLists;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\MastodonList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The curate lists in a person's own repository, as their lists here: what a
 * Bluesky app signed in here writes (`WriteService`) and what an account
 * that moved here brought (`MoveInService`). A list record becomes a public
 * list, a list item one of its members, and each record is then tied to
 * what it stands for by the local id `BlueskyLists` gives it, so the list is
 * one from then on — renamed, filled or emptied on either side, it follows
 * on the other.
 *
 * Only ever reads a record somebody else wrote: a record this server wrote
 * for a list here already stands for it and is not read again, so nothing
 * goes round in a circle. A moderation list is not a list here (§12.5),
 * and neither are its members.
 */
class BlueskyListImport {
	private const PAGE = 100;

	public function __construct(
		private ListsRequest $lists,
		private RepositoryService $repositories,
		private AtprotoRepoRequest $repoRequest,
		private LocalRecordResolver $local,
		private BlueskyActorService $blueskyActors,
		private BlueskyLists $blueskyLists,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A list record written to the owner's repository: the list it stands
	 * for renamed, or a public list made for a new one.
	 *
	 * @return int|null the list, null for a record that is not a curate list
	 */
	public function listWritten(Person $owner, StoredRecord $record): ?int {
		$value = $record->value();
		if ($record->collection !== BlueskyLists::LIST || ($value['purpose'] ?? '') !== BlueskyLists::CURATELIST) {
			return null;
		}
		$title = ListsRequest::normaliseTitle((string)($value['name'] ?? ''));
		$title = $title !== '' ? $title : 'List';
		$list = $this->owned($owner, BlueskyLists::listIdOf($record->localId));
		if ($list !== null) {
			if ($list->getTitle() !== $title && $list->getGroupId() === '') {
				$this->lists->update($list->setTitle($title));
			}

			return $list->getId();
		}
		$list = $this->lists->create((new MastodonList())
			->setOwnerId($owner->getId())
			->setTitle($title)
			->setPublic(true));
		$this->repoRequest->setLocalId($record->did, $record->collection, $record->rkey, BlueskyLists::localId($list->getId()));

		return $list->getId();
	}

	/**
	 * A list item written to the owner's repository: its subject a member
	 * of the list it names, when that is one of the owner's lists here.
	 *
	 * @return bool whether somebody was added
	 */
	public function itemWritten(Person $owner, StoredRecord $record): bool {
		$value = $record->value();
		if ($record->collection !== BlueskyLists::ITEM || $record->localId !== '') {
			return false;
		}
		$list = $this->listNamed($owner, $record->did, (string)($value['list'] ?? ''));
		$memberId = $list === null ? '' : $this->memberId((string)($value['subject'] ?? ''), true);
		if ($list === null || $memberId === '') {
			return false;
		}
		$this->lists->addMember($list, $memberId);
		$localId = BlueskyLists::itemLocalId($list->getId(), $memberId);
		if ($this->repositories->getRecordsByLocalId($localId) === []) {
			$this->repoRequest->setLocalId($record->did, $record->collection, $record->rkey, $localId);
		}

		return true;
	}

	/**
	 * A list's or a list item's record deleted from the owner's repository:
	 * the list goes here, with the records of its members, or the member
	 * leaves it.
	 */
	public function deleted(Person $owner, StoredRecord $record): void {
		$list = $this->owned($owner, BlueskyLists::listIdOf($record->localId));
		if ($list === null) {
			return;
		}
		if ($record->collection === BlueskyLists::LIST) {
			$members = $this->lists->getMemberIds($list);
			$this->lists->delete($list);
			$this->blueskyLists->deleted($list->getId(), $members);

			return;
		}
		$memberId = $this->memberId((string)($record->value()['subject'] ?? ''), false);
		if ($memberId !== '' && $record->collection === BlueskyLists::ITEM) {
			$this->lists->removeMember($list, $memberId);
		}
	}

	/**
	 * The curate lists in the repository of an account that moved here, and
	 * their members, as its lists here. A record that stands for a list
	 * already is left alone, so a second run adds nothing.
	 *
	 * @return int how many lists were made
	 */
	public function adopt(Person $owner, string $did): int {
		$made = 0;
		foreach ($this->records($did, BlueskyLists::LIST) as $record) {
			if ($record->localId !== '') {
				continue;
			}
			try {
				$made += $this->listWritten($owner, $record) !== null ? 1 : 0;
			} catch (Throwable $e) {
				$this->logger->info('List not taken over', ['did' => $did, 'rkey' => $record->rkey, 'exception' => $e]);
			}
		}
		foreach ($this->records($did, BlueskyLists::ITEM) as $record) {
			try {
				$this->itemWritten($owner, $record);
			} catch (Throwable $e) {
				$this->logger->info('List member not taken over', ['did' => $did, 'rkey' => $record->rkey, 'exception' => $e]);
			}
		}

		return $made;
	}

	/**
	 * @return iterable<StoredRecord>
	 */
	private function records(string $did, string $collection): iterable {
		$cursor = '';
		do {
			$page = $this->repositories->listRecords($did, $collection, self::PAGE, $cursor);
			yield from $page;
			$cursor = count($page) === self::PAGE ? end($page)->rkey : '';
		} while ($cursor !== '');
	}

	private function owned(Person $owner, ?int $listId): ?MastodonList {
		if ($listId === null) {
			return null;
		}
		try {
			return $this->lists->getOwnedById($owner->getId(), $listId);
		} catch (ItemNotFoundException) {
			return null;
		}
	}

	/**
	 * The owner's list a list item names: a list record of their own
	 * repository that stands for one of their lists.
	 */
	private function listNamed(Person $owner, string $did, string $uri): ?MastodonList {
		$parsed = Syntax::parseAtUri($uri);
		if ($parsed === null || $parsed['authority'] !== $did || $parsed['collection'] !== BlueskyLists::LIST) {
			return null;
		}
		$record = $this->repositories->getRecord($did, BlueskyLists::LIST, $parsed['rkey']);

		return $record === null ? null : $this->owned($owner, BlueskyLists::listIdOf($record->localId));
	}

	/**
	 * The actor a DID is here: a local account's own, or the Bluesky
	 * account's, read into the cache first when `$resolve` asks; '' for
	 * one that cannot be.
	 */
	private function memberId(string $did, bool $resolve): string {
		if (!Syntax::isDid($did)) {
			return '';
		}
		$local = $this->local->actorId($did);
		if ($local !== '') {
			return $local;
		}
		if (!$resolve) {
			return BlueskyIds::actorId($did);
		}
		try {
			return $this->blueskyActors->resolve($did)->getId();
		} catch (Throwable $e) {
			$this->logger->info('List member not read from Bluesky', ['did' => $did, 'exception' => $e]);

			return '';
		}
	}
}
