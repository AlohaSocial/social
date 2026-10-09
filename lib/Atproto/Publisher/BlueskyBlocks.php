<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A person's blocks of Bluesky accounts, published as `app.bsky.graph.block`
 * records when they choose to (D16). On Bluesky a block is a public record,
 * and only a published one stops the blocked account from replying to,
 * quoting or mentioning the person there; so it is the person's choice, off
 * until they make it. Turned on, every block of a Bluesky account they hold
 * is published, and each one after; turned off, every published block goes.
 */
class BlueskyBlocks {
	public const COLLECTION = 'app.bsky.graph.block';
	private const KEY = 'atproto_publish_blocks';
	/** the most blocks published or withdrawn in one go */
	private const BATCH = 1000;

	public function __construct(
		private Publisher $publisher,
		private ActorRelationRequest $relations,
		private IConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the person publishes their blocks to Bluesky.
	 */
	public function isPublished(string $userId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY, '0') === '1';
	}

	/**
	 * Publishes the person's blocks of Bluesky accounts from now on, and the
	 * ones they hold, or withdraws every published one.
	 */
	public function setPublished(Person $viewer, bool $publish): void {
		$this->config->setUserValue($viewer->getUserId(), Application::APP_ID, self::KEY, $publish ? '1' : '0');
		foreach ($this->relations->getByActor($viewer->getId(), ActorRelation::TYPE_BLOCK, self::BATCH) as $relation) {
			$target = $relation->getObjectId();
			if (!BlueskyIds::isActorId($target)) {
				continue;
			}
			$publish ? $this->write($viewer, $target) : $this->withdraw($viewer, $target);
		}
	}

	/**
	 * A block just made: published when the person publishes theirs.
	 */
	public function blocked(Person $viewer, Person $target): void {
		if (BlueskyIds::isActorId($target->getId()) && $this->isPublished($viewer->getUserId())) {
			$this->write($viewer, $target->getId());
		}
	}

	/**
	 * A block taken back: its record goes, wherever it was published.
	 */
	public function unblocked(Person $viewer, Person $target): void {
		if (BlueskyIds::isActorId($target->getId())) {
			$this->withdraw($viewer, $target->getId());
		}
	}

	/**
	 * What a block's record is tied to: one per blocker and blocked.
	 */
	public static function localId(string $viewerId, string $targetId): string {
		return $viewerId . '#block/' . md5($targetId);
	}

	private function write(Person $viewer, string $targetId): void {
		try {
			$this->publisher->writeRecord($viewer, self::COLLECTION, [
				'$type' => self::COLLECTION,
				'subject' => BlueskyIds::didOf($targetId),
				'createdAt' => Syntax::datetime($this->time->getTime()),
			], self::localId($viewer->getId(), $targetId));
		} catch (Throwable $e) {
			$this->logger->warning('Block not published to Bluesky', ['actor' => $viewer->getId(), 'target' => $targetId, 'exception' => $e]);
		}
	}

	private function withdraw(Person $viewer, string $targetId): void {
		try {
			$this->publisher->removeRecord(self::COLLECTION, self::localId($viewer->getId(), $targetId));
		} catch (Throwable $e) {
			$this->logger->warning('Block not withdrawn from Bluesky', ['actor' => $viewer->getId(), 'target' => $targetId, 'exception' => $e]);
		}
	}
}
