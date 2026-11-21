<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Suggestion;
use OCP\IUserManager;

/**
 * Accounts to follow: `GET /api/v2/suggestions`.
 *
 * In order: accounts followed by the accounts the viewer follows (by how
 * many do), handles this Nextcloud's people wrote in their `fediverse`
 * profile field (`ColleagueService`), then local accounts that opted in to
 * the directory, most recently active first. No scoring model: each
 * suggestion can be explained.
 *
 * Never suggested, applied after gathering: accounts the viewer follows,
 * has blocked, muted or dismissed, who blocked the viewer, and the viewer.
 */
class SuggestionService {
	/** What Mastodon defaults and caps the suggestion list at. */
	public const LIMIT = 40;
	public const MAX_LIMIT = 80;

	public function __construct(
		private DiscoveryRequest $discoveryRequest,
		private DirectoryService $directoryService,
		private ColleagueService $colleagueService,
		private ActorsRequest $actorsRequest,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @return Suggestion[]
	 */
	public function suggestions(string $viewerId, int $limit): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$excluded = $this->excluded($viewerId);

		$friends = $this->keep(
			$this->discoveryRequest->friendsOfFriendsPrims($viewerId, $limit * 2), $excluded, $limit
		);

		// Each source only fills the slots the better one before it left empty.
		$excluded += array_fill_keys($friends, true);
		$colleagues = $this->keep(
			$this->colleaguePrims($viewerId), $excluded, $limit - count($friends)
		);

		$excluded += array_fill_keys($colleagues, true);
		$active = $this->keep(
			$this->discoveryRequest->activeLocalPrims($limit * 2),
			$excluded,
			$limit - count($friends) - count($colleagues)
		);

		$suggestions = [];
		$sources = [
			Suggestion::SOURCE_FRIENDS => $friends,
			Suggestion::SOURCE_COLLEAGUES => $colleagues,
			Suggestion::SOURCE_ACTIVE => $active,
		];
		foreach ($sources as $source => $prims) {
			$prims = $this->directoryService->withoutModerated($prims);
			foreach ($this->discoveryRequest->actorsByPrims($prims) as $actor) {
				$suggestions[] = new Suggestion($actor, $source);
			}
		}

		return $suggestions;
	}

	/**
	 * The accounts named on this Nextcloud's profiles, as prims, in the order
	 * the profiles were read, leaving out the viewer's own profile.
	 *
	 * An external user gets none: the list would tell them who works here.
	 *
	 * @return string[]
	 */
	private function colleaguePrims(string $viewerId): array {
		try {
			$exceptUserId = $this->actorsRequest->getFromId($viewerId)->getUserId();
		} catch (Exception $e) {
			$exceptUserId = '';
		}
		if ($exceptUserId !== '' && ExternalUserBackend::isExternal($this->userManager->get($exceptUserId))) {
			return [];
		}

		return array_map(
			static fn (Person $account): string => md5($account->getId()),
			$this->colleagueService->accounts($exceptUserId)
		);
	}

	/**
	 * The same set for `FollowGraphService`, so "who may be suggested" has one
	 * answer.
	 *
	 * @return array<string, bool> keyed by prim, as `excluded()` builds it
	 */
	public function excludedPrims(string $viewerId): array {
		return $this->excluded($viewerId);
	}

	/**
	 * Every account that may not be suggested, as a set of prims, the viewer
	 * included.
	 *
	 * @return array<string, bool>
	 */
	private function excluded(string $viewerId): array {
		$prims = array_merge(
			[md5($viewerId)],
			$this->discoveryRequest->followedPrims($viewerId),
			$this->discoveryRequest->relatedPrims($viewerId, [
				ActorRelation::TYPE_BLOCK,
				ActorRelation::TYPE_MUTE,
				ActorRelation::TYPE_BLOCKED_BY,
				// And every account this viewer dismissed.
				AccountRelationService::TYPE_SUGGESTION_DISMISSED,
			])
		);

		return array_fill_keys($prims, true);
	}

	/**
	 * The first `$limit` of `$prims` that are not excluded and not already
	 * taken, in the order they arrived.
	 *
	 * @param string[] $prims
	 * @param array<string, bool> $excluded
	 *
	 * @return string[]
	 */
	private function keep(array $prims, array $excluded, int $limit): array {
		if ($limit < 1) {
			return [];
		}

		$kept = [];
		foreach ($prims as $prim) {
			if (isset($excluded[$prim]) || isset($kept[$prim])) {
				continue;
			}

			$kept[$prim] = true;
			if (count($kept) >= $limit) {
				break;
			}
		}

		return array_keys($kept);
	}
}
