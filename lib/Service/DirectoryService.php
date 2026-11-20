<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * The local profile directory: `GET /api/v1/directory`.
 *
 * Opt-in through `discoverable`, applied in the deciding query so an account
 * that did not opt in never takes a slot. Remote accounts are never listed;
 * Mastodon's `local` parameter is accepted and changes nothing.
 */
class DirectoryService {
	/** What Mastodon defaults and caps a page of the directory at. */
	public const LIMIT = 40;
	public const MAX_LIMIT = 80;

	public function __construct(
		private DiscoveryRequest $discoveryRequest,
		private ModerationService $moderationService,
	) {
	}

	/**
	 * A page of the directory.
	 *
	 * @param string $order `active` (most recently posted first, Mastodon's
	 *                      default) or `new` (newest account first); anything
	 *                      else is read as `active`
	 *
	 * @return Person[]
	 */
	public function page(string $order, int $limit, int $offset): array {
		$order = ($order === DiscoveryRequest::ORDER_NEW)
			? DiscoveryRequest::ORDER_NEW
			: DiscoveryRequest::ORDER_ACTIVE;

		$prims = $this->discoveryRequest->directoryPrims(
			$order, max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)
		);

		return $this->discoveryRequest->actorsByPrims($this->withoutModerated($prims));
	}

	/**
	 * Drops every account a moderator has made a decision about, silencing
	 * included: a directory puts accounts in front of people who did not ask.
	 *
	 * @param string[] $prims
	 *
	 * @return string[]
	 */
	public function withoutModerated(array $prims): array {
		if ($prims === []) {
			return [];
		}

		$moderated = [];
		foreach ($this->moderationService->decisions() as $decision) {
			$moderated[md5($decision->getActorId())] = true;
		}

		if ($moderated === []) {
			return $prims;
		}

		return array_values(
			array_filter($prims, static fn (string $prim): bool => !isset($moderated[$prim]))
		);
	}
}
