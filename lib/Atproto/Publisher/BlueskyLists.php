<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Service\CacheActorService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One of a person's lists, as a Bluesky list (`app.bsky.graph.list`, a
 * curate list) with an `app.bsky.graph.listitem` for each member that has a
 * Bluesky identity. A Bluesky list is public, so a list is published when
 * its owner makes it public (`sync()`), and withdrawn when they make it
 * private again — unless a threadgate names it: a reply rule here that lets
 * the members of one of the person's lists reply publishes that list too
 * (`ensure()`), and it stays while a gate needs it. Kept in step as it is
 * renamed and as members come and go; the members on the fediverse alone
 * have no Bluesky identity to list, and are held to a reply rule here.
 */
class BlueskyLists {
	public const LIST = 'app.bsky.graph.list';
	public const ITEM = 'app.bsky.graph.listitem';
	public const CURATELIST = 'app.bsky.graph.defs#curatelist';
	/** the longest name a Bluesky list takes, in characters */
	private const NAME_LENGTH = 64;
	private const PAGE = 100;

	public function __construct(
		private Publisher $publisher,
		private RepositoryService $repositories,
		private ListsRequest $lists,
		private CacheActorService $cacheActors,
		private IdentityService $identities,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The list's `at://` URI on Bluesky, null while it is not published.
	 */
	public function uriOf(int $listId): ?string {
		return $this->recordOf($listId)?->uri();
	}

	/**
	 * The list's visibility or its name changed here: a public list is
	 * published, or renamed where it is; a private one is withdrawn, unless
	 * a threadgate of its owner's names it.
	 */
	public function sync(Person $owner, MastodonList $list): void {
		try {
			$record = $this->recordOf($list->getId());
			if ($record === null) {
				if ($list->isPublic()) {
					$this->ensure($owner, $list->getId());
				}

				return;
			}
			if (!$list->isPublic() && !$this->namedByAGate($record)) {
				$this->deleted($list->getId(), $this->lists->getMemberIds($list));

				return;
			}
			$this->rename($record, $list->getTitle());
		} catch (Throwable $e) {
			$this->logger->warning('List not kept in step on Bluesky', ['list' => $list->getId(), 'exception' => $e]);
		}
	}

	/**
	 * Publishes the list, with its members, unless it is already.
	 *
	 * @return string|null its `at://` URI, null when it could not be
	 */
	public function ensure(Person $owner, int $listId): ?string {
		$uri = $this->uriOf($listId);
		if ($uri !== null) {
			return $uri;
		}
		try {
			$list = $this->lists->getOwnedById($owner->getId(), $listId);
			$this->publisher->writeRecord($owner, self::LIST, [
				'$type' => self::LIST,
				'purpose' => self::CURATELIST,
				'name' => self::nameOf($list->getTitle()),
				'createdAt' => Syntax::datetime($this->time->getTime()),
			], self::localId($listId));
			foreach ($this->lists->getMemberIds($list) as $memberId) {
				$this->memberAdded($owner, $listId, $memberId);
			}
		} catch (Throwable $e) {
			$this->logger->warning('List not published to Bluesky', ['list' => $listId, 'exception' => $e]);
		}

		return $this->uriOf($listId);
	}

	/**
	 * A member just added: listed on Bluesky when the list is published and
	 * the member has a Bluesky identity.
	 */
	public function memberAdded(Person $owner, int $listId, string $memberId): void {
		$uri = $this->uriOf($listId);
		$did = $uri === null ? '' : $this->didOf($memberId);
		if ($did === '') {
			return;
		}
		try {
			$this->publisher->writeRecord($owner, self::ITEM, [
				'$type' => self::ITEM,
				'subject' => $did,
				'list' => $uri,
				'createdAt' => Syntax::datetime($this->time->getTime()),
			], self::itemLocalId($listId, $memberId));
		} catch (Throwable $e) {
			$this->logger->warning('List member not published to Bluesky', ['list' => $listId, 'exception' => $e]);
		}
	}

	public function memberRemoved(int $listId, string $memberId): void {
		try {
			$this->publisher->removeRecord(self::ITEM, self::itemLocalId($listId, $memberId));
		} catch (Throwable $e) {
			$this->logger->warning('List member not withdrawn from Bluesky', ['list' => $listId, 'exception' => $e]);
		}
	}

	/**
	 * A list deleted here: its members and the list are withdrawn.
	 *
	 * @param string[] $memberIds
	 */
	public function deleted(int $listId, array $memberIds): void {
		foreach ($memberIds as $memberId) {
			$this->memberRemoved($listId, $memberId);
		}
		try {
			$this->publisher->removeRecord(self::LIST, self::localId($listId));
		} catch (Throwable $e) {
			$this->logger->warning('List not withdrawn from Bluesky', ['list' => $listId, 'exception' => $e]);
		}
	}

	public static function localId(int $listId): string {
		return 'list:' . $listId;
	}

	public static function itemLocalId(int $listId, string $memberId): string {
		return 'list:' . $listId . '#' . md5($memberId);
	}

	/**
	 * The list a list's or a member's record stands for, from its local id;
	 * null for a record that stands for none.
	 */
	public static function listIdOf(string $localId): ?int {
		return preg_match('/^list:(\d{1,18})(#|$)/', $localId, $m) === 1 ? (int)$m[1] : null;
	}

	/** A title as a Bluesky list's name. */
	public static function nameOf(string $title): string {
		return mb_substr($title !== '' ? $title : 'List', 0, self::NAME_LENGTH);
	}

	private function recordOf(int $listId): ?StoredRecord {
		foreach ($this->repositories->getRecordsByLocalId(self::localId($listId)) as $record) {
			if ($record->collection === self::LIST) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * The list record named as the list is now; the rest of it — what an
	 * app wrote, a description, a picture — stays as it is.
	 */
	private function rename(StoredRecord $record, string $title): void {
		$value = $record->value();
		$name = self::nameOf($title);
		if (($value['name'] ?? null) === $name) {
			return;
		}
		$value['name'] = $name;
		try {
			$identity = $this->identities->getByDid($record->did);
			$this->repositories->write($identity->did, $this->identities->signingKey($identity), [
				RepoWrite::update(self::LIST, $record->rkey, $value, $record->localId),
			]);
		} catch (Throwable $e) {
			$this->logger->warning('List not renamed on Bluesky', ['list' => $record->localId, 'exception' => $e]);
		}
	}

	/**
	 * Whether one of the owner's threadgates names the list: a reply rule
	 * here lets its members reply.
	 */
	private function namedByAGate(StoredRecord $list): bool {
		$uri = $list->uri();
		$cursor = '';
		do {
			$page = $this->repositories->listRecords($list->did, RecordMapper::THREADGATE, self::PAGE, $cursor);
			foreach ($page as $gate) {
				foreach ((array)($gate->value()['allow'] ?? []) as $rule) {
					if (is_array($rule) && ($rule['list'] ?? null) === $uri) {
						return true;
					}
				}
			}
			$cursor = count($page) === self::PAGE ? end($page)->rkey : '';
		} while ($cursor !== '');

		return false;
	}

	/**
	 * The member's DID: a Bluesky account's own, or a local account's
	 * identity; '' for an account that has none.
	 */
	private function didOf(string $memberId): string {
		if (BlueskyIds::isActorId($memberId)) {
			return BlueskyIds::didOf($memberId);
		}
		try {
			$actor = $this->cacheActors->getFromId($memberId);

			return $actor->isLocal() ? ($this->identities->forActor($actor, false)?->did ?? '') : '';
		} catch (Throwable) {
			return '';
		}
	}
}
