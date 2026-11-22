<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Moderation;
use Throwable;

/**
 * Which accounts a starter pack may offer: none this instance's moderation
 * has acted on (silenced or suspended accounts, blocked or silenced servers,
 * Bluesky accounts blocked here or limited by Bluesky's moderation). Checked
 * when a pack is read in and again when it is served and followed.
 */
class StarterPackModeration {
	public function __construct(
		private FediverseService $fediverseService,
		private ModerationRequest $moderationRequest,
		private Blocklist $blocklist,
	) {
	}

	/**
	 * The actor ids that may be offered, in their order.
	 *
	 * @param list<string> $actorIds
	 *
	 * @return list<string>
	 */
	public function admitted(array $actorIds): array {
		if ($actorIds === []) {
			return [];
		}

		$decided = $this->moderationRequest->amongAt($actorIds, Moderation::SUSPEND)
			+ $this->moderationRequest->amongAt($actorIds, Moderation::SILENCE);
		$silencedHosts = $this->fediverseService->silencedHosts();

		$admitted = [];
		foreach ($actorIds as $actorId) {
			if (isset($decided[$actorId])) {
				continue;
			}
			if (BlueskyIds::isActorId($actorId)) {
				if (!$this->blocklist->isBlockedDid(BlueskyIds::didOf($actorId))) {
					$admitted[] = $actorId;
				}
				continue;
			}

			$host = (string)parse_url($actorId, PHP_URL_HOST);
			try {
				$this->fediverseService->authorized($host);
			} catch (Throwable $e) {
				continue;
			}
			if (!FediverseService::covers($silencedHosts, $host)) {
				$admitted[] = $actorId;
			}
		}

		return $admitted;
	}

	/**
	 * The actors that may be offered, in their order; a Bluesky account is
	 * also judged by what is stored of it, its PDS and Bluesky's labels.
	 *
	 * @param list<Person> $actors
	 *
	 * @return list<Person>
	 */
	public function admittedActors(array $actors): array {
		$admitted = array_flip($this->admitted(array_map(static fn (Person $actor): string => $actor->getId(), $actors)));

		return array_values(array_filter(
			$actors,
			fn (Person $actor): bool => isset($admitted[$actor->getId()])
				&& !(BlueskyActorService::isBluesky($actor)
					&& (BlueskyActorService::isLimited($actor) || $this->blocklist->isBlockedActor($actor)))
		));
	}

	public function admits(Person $actor): bool {
		return $this->admittedActors([$actor]) !== [];
	}
}
