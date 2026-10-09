<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\BlockedBy;

use OCA\Social\Atproto\Reader\BlueskyBlockedBy;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Exceptions\BlockedByException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\TimelineRevisionService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who has blocked a local account, wherever they are. Every block is the
 * same `blocked_by` relation, which the timelines, notifications,
 * suggestions and relationship flags already read: one arriving from
 * another server is recorded by `BlockInterface`, one between two accounts
 * here by `RelationshipService`, and one a network keeps where it can only
 * be asked about (`BlockedBySource`) is recorded whenever it is asked —
 * before a reply, a quote or a follow reaches the account, and wherever a
 * read as the local account says so. A reply to, quote of or follow of an
 * account that has blocked the local one is refused with the reason, since
 * it would reach nobody.
 */
class BlockedByService {
	public const REFUSAL = 'This account has blocked you';
	private const CACHE = 'social.blocked_by';
	/** how long what a network said is believed, in seconds */
	private const KEPT = 300;

	public function __construct(
		private ActorRelationRequest $relations,
		private DurableCache $cache,
		private TimelineRevisionService $revisions,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * Refuses a reply to the post when its author, or another account whose
	 * block keeps a reply out of the thread, has blocked the replier.
	 *
	 * @throws BlockedByException
	 */
	public function assertMayReply(Person $replier, Stream $parent): void {
		$authors = [$parent->getAttributedTo()];
		foreach ($this->sources() as $source) {
			if ($source->supports($parent->getAttributedTo())) {
				$authors = array_merge($authors, $source->threadAuthors($parent));
			}
		}
		$this->assertNotBlocked($replier, $authors);
	}

	/**
	 * Refuses a quote, a follow or anything else that would reach one of
	 * the accounts, when one of them has blocked the local account.
	 *
	 * @param list<string> $actorIds
	 * @throws BlockedByException
	 */
	public function assertNotBlocked(Person $local, array $actorIds): void {
		if (in_array(true, $this->blockedBy($local->getId(), $actorIds), true)) {
			throw new BlockedByException(self::REFUSAL);
		}
	}

	/**
	 * Whether each account has blocked the local one: what its network says
	 * when it can be asked (`ask()`), and what is recorded otherwise.
	 *
	 * @param list<string> $actorIds
	 * @param bool $fresh ask the network even when it was asked a moment ago
	 * @return array<string, bool> by actor id
	 */
	public function blockedBy(string $localId, array $actorIds, bool $fresh = false): array {
		$actorIds = self::others($localId, $actorIds);
		$answers = $this->ask($localId, $actorIds, $fresh);
		foreach ($actorIds as $id) {
			$answers[$id] ??= $this->relations->exists($localId, $id, ActorRelation::TYPE_BLOCKED_BY);
		}

		return $answers;
	}

	/**
	 * Asks the networks that can be asked whether each of their accounts has
	 * blocked the local one, and records the answers; an answer is believed
	 * for a few minutes. An account no network was asked about, or could
	 * answer for, is left out.
	 *
	 * @param list<string> $actorIds
	 * @param bool $fresh ask even when it was asked a moment ago
	 * @return array<string, bool> by actor id
	 */
	public function ask(string $localId, array $actorIds, bool $fresh = false): array {
		$actorIds = self::others($localId, $actorIds);
		$answers = [];
		foreach ($this->sources() as $source) {
			$ask = [];
			foreach (array_filter($actorIds, $source->supports(...)) as $id) {
				$kept = $fresh ? null : $this->cache->getShared(self::CACHE, self::key($localId, $id));
				if (is_bool($kept)) {
					$answers[$id] = $kept;
				} else {
					$ask[] = $id;
				}
			}
			if ($ask === []) {
				continue;
			}
			try {
				$said = array_intersect_key($source->ask($localId, $ask), array_flip($ask));
			} catch (Throwable $e) {
				$this->logger->info('Not asked who blocked an account', ['actor' => $localId, 'exception' => $e]);
				$said = [];
			}
			$this->record($localId, $said, true);
			$answers += $said;
		}

		return $answers;
	}

	/**
	 * Asks again, for every local account the account is recorded as
	 * blocking, whether it still does — after the network told of a block
	 * taken back without saying whose.
	 *
	 * @return list<string> the local accounts it still blocks
	 */
	public function recheck(string $actorId): array {
		$still = [];
		foreach ($this->relations->getLocalByObject($actorId, ActorRelation::TYPE_BLOCKED_BY) as $localId) {
			if ($this->blockedBy($localId, [$actorId], true)[$actorId] ?? false) {
				$still[] = $localId;
			}
		}

		return $still;
	}

	/**
	 * Records what a network said about who has blocked the local account:
	 * a block becomes the relation, an unblock takes it away. Only what
	 * changes is written.
	 *
	 * @param array<string, bool> $answers by actor id
	 * @param bool $keep also believe each answer for a few minutes, as an
	 *                   answer to a question asked for it is
	 */
	public function record(string $localId, array $answers, bool $keep = false): void {
		unset($answers[$localId]);
		if ($answers === []) {
			return;
		}
		$held = [];
		foreach ($this->relations->getBetweenMany($localId, array_map('strval', array_keys($answers))) as $id => $relations) {
			foreach ($relations as $relation) {
				if ($relation->getType() === ActorRelation::TYPE_BLOCKED_BY) {
					$held[$id] = true;
				}
			}
		}
		$changed = false;
		foreach ($answers as $id => $blocked) {
			$id = (string)$id;
			if ($blocked !== isset($held[$id])) {
				$blocked
					? $this->relations->save($localId, $id, ActorRelation::TYPE_BLOCKED_BY)
					: $this->relations->delete($localId, $id, ActorRelation::TYPE_BLOCKED_BY);
				$changed = true;
			} elseif (!$keep) {
				continue;
			}
			$this->cache->setShared(self::CACHE, self::key($localId, $id), $blocked, self::KEPT);
		}
		if ($changed) {
			// the blocker's posts leave the local account's timelines
			$this->revisions->bumpForActor($localId);
		}
	}

	/**
	 * @return list<BlockedBySource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([BlueskyBlockedBy::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof BlockedBySource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}

	/**
	 * @param list<string> $actorIds
	 * @return list<string> the accounts, each once, without the local one
	 */
	private static function others(string $localId, array $actorIds): array {
		return array_values(array_unique(array_filter($actorIds, static fn (string $id): bool => $id !== '' && $id !== $localId)));
	}

	private static function key(string $localId, string $actorId): string {
		return md5($localId . ' ' . $actorId);
	}
}
