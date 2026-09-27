<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Details;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class ActorService
 *
 * @package OCA\Social\Service
 */
class ActorService {
	use TArrayTools;

	public function __construct(
		private CacheActorsRequest $cacheActorsRequest,
		private CacheDocumentsRequest $cacheDocumentsRequest,
	) {
	}

	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalActor(Person $actor) {
		$actor->setLocal(true);
		$actor->setSource(json_encode($actor, JSON_UNESCAPED_SLASHES));

		try {
			$cached = $this->cacheActorsRequest->getFromId($actor->getId());
			$this->carryVerifiedFields($actor, $cached);
			$this->update($actor);
		} catch (CacheActorDoesNotExistException $e) {
			$this->save($actor);
		}
	}

	/**
	 * Keeps the verified-link verdicts the cached copy already holds.
	 *
	 * `update()` writes `details` whole, and a local actor is rebuilt from the
	 * `actors` row, which has no details column: everything
	 * `AccountService::cacheLocalActorByUsername()` puts back is the two counts
	 * it has just recomputed. So every write to a profile — a new bio, one
	 * flag, one edited field — erased `fields_verified` along with it, and the
	 * ticks left the profile until the next `Cron\Cache` pass re-fetched every
	 * linked page. For those minutes the profile told each visitor that an
	 * account had never proved a link it had proved.
	 *
	 * `fields_checked` is deliberately not carried: dropping it is what makes
	 * the next pass look again at once instead of waiting out
	 * `ProfileLinkVerifier::RECHECK_SECONDS`, which is what a value that has
	 * just been edited needs. A verdict whose value is no longer among the
	 * fields is dropped here rather than lying in wait for the day somebody
	 * types that value again.
	 */
	private function carryVerifiedFields(Person $actor, Person $cached): void {
		$verified = $cached->getDetailsAll()[Details::FIELDS_VERIFIED] ?? [];
		if (!is_array($verified) || $verified === []) {
			return;
		}

		$kept = array_intersect_key(
			$verified,
			array_flip(array_column($actor->getFields(), 'value'))
		);
		if ($kept !== []) {
			$actor->setDetailArray(Details::FIELDS_VERIFIED, $kept);
		}
	}

	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalActorDetails(Person $actor) {
		$this->cacheActorsRequest->updateDetails($actor);
	}

	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	public function save(Person $actor) {
		$this->cacheDocumentIfNeeded($actor);
		$this->cacheActorsRequest->save($actor);
	}

	/**
	 * @param Person $actor
	 *
	 * @return int
	 * @throws ItemAlreadyExistsException
	 */
	public function update(Person $actor): int {
		$this->cacheDocumentIfNeeded($actor);

		return $this->cacheActorsRequest->update($actor);
	}

	/**
	 * Get the cached header URL for a local actor.
	 *
	 * @param Person $actor
	 *
	 * @return string
	 */
	public function getCachedHeader(Person $actor): string {
		try {
			$cached = $this->cacheActorsRequest->getFromLocalAccount(
				$actor->getPreferredUsername()
			);

			return $cached->getHeader();
		} catch (Exception $e) {
			return '';
		}
	}

	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	private function cacheDocumentIfNeeded(Person $actor) {
		if ($actor->hasIcon()) {
			$icon = $actor->getIcon();
			try {
				$cache = $this->cacheDocumentsRequest->getByUrl($icon->getUrl());
				$actor->setIcon($cache);
			} catch (CacheDocumentDoesNotExistException $e) {
				try {
					$interface = AP::instance()->getInterfaceFromType($icon->getType());
					$interface->save($icon);
				} catch (ItemUnknownException $e) {
				}
			}
		}
	}
}
