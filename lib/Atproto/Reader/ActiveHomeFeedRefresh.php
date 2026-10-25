<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FollowService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes the next background pass read a small rotating sample of the viewer's
 * Bluesky follows when they open Home. This improves freshness without making
 * the timeline request wait on AppView or polling every follow on every 30 s
 * conditional request.
 */
class ActiveHomeFeedRefresh {
	public const FOLLOW_PAGE = 200;
	public const WAKE_LIMIT = 8;
	public const COOLDOWN = 300;

	public function __construct(
		private AtprotoConfig $config,
		private FollowService $follows,
		private AtprotoWatchRequest $watches,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private DurableCache $cache,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function wakeFollowedFeeds(Person $viewer): void {
		if (!$this->config->isEnabled()) {
			return;
		}
		$key = hash('sha256', $viewer->getId());
		if (!$this->cache->addShared('social.atproto.home-refresh', $key, '1', self::COOLDOWN)) {
			return;
		}

		try {
			$offsetKey = 'follow-offset:' . $key;
			$offset = max(0, (int)($this->cache->getShared('social.atproto.home-refresh', $offsetKey) ?? 0));
			$follows = $this->follows->getFollowing($viewer, self::FOLLOW_PAGE, $offset);
			if ($follows === [] && $offset > 0) {
				$offset = 0;
				$follows = $this->follows->getFollowing($viewer, self::FOLLOW_PAGE);
			}
			$this->cache->setShared(
				'social.atproto.home-refresh',
				$offsetKey,
				count($follows) < self::FOLLOW_PAGE ? 0 : $offset + self::FOLLOW_PAGE,
				30 * 86400
			);

			$dids = [];
			foreach ($follows as $follow) {
				$did = BlueskyIds::didOf($follow->getObjectId());
				if ($did !== '') {
					$dids[$did] = true;
				}
			}
			$watches = $this->watches->getByDids(array_keys($dids));
			usort($watches, static fn (Watch $left, Watch $right): int => $left->lastSync <=> $right->lastSync);

			$woken = 0;
			$now = $this->time->getTime();
			foreach ($watches as $watch) {
				if ($woken >= self::WAKE_LIMIT) {
					break;
				}
				// Keep exponential retry delays for unhealthy sources; this path is
				// only for feeds that were read successfully but went quiet.
				if ($watch->failures > 0 || $watch->nextSync <= $now) {
					continue;
				}
				$actor = $this->actors->cached($watch->did);
				if ($this->blocklist->isBlockedDid($watch->did)
					|| ($actor !== null && ($this->blocklist->isBlockedActor($actor)
						|| (BlueskyActorService::isLimited($actor) && BlueskyActorService::status($actor) === '')))) {
					continue;
				}
				$this->watches->wake($watch->did, $now);
				$woken++;
			}
		} catch (Throwable $e) {
			// A failed freshness hint must never make the ordinary home feed fail.
			$this->logger->debug('Could not wake followed Bluesky feeds', [
				'actor' => $viewer->getId(),
				'exception' => $e,
			]);
		}
	}
}
