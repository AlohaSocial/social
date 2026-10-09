<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One of a person's lists, as a Bluesky list (`app.bsky.graph.list`, a
 * curate list) with an `app.bsky.graph.listitem` for each member that has a
 * Bluesky identity: what a threadgate's `listRule` names when a reply rule
 * here lets the members of one of their lists reply. Published when a rule
 * first names it, and kept in step as members come and go; the members on
 * the fediverse alone have no Bluesky identity to list, and are held to the
 * rule here.
 */
class BlueskyLists {
	public const LIST = 'app.bsky.graph.list';
	public const ITEM = 'app.bsky.graph.listitem';

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
		foreach ($this->repositories->getRecordsByLocalId(self::localId($listId)) as $record) {
			if ($record->collection === self::LIST) {
				return $record->uri();
			}
		}

		return null;
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
				'purpose' => 'app.bsky.graph.defs#curatelist',
				'name' => mb_substr($list->getTitle() !== '' ? $list->getTitle() : 'List', 0, 64),
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

	private static function itemLocalId(int $listId, string $memberId): string {
		return 'list:' . $listId . '#' . md5($memberId);
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
