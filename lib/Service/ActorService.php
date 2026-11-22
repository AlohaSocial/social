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
use Psr\Container\ContainerInterface;

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
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalActor(Person $actor) {
		$actor->setLocal(true);
		$cached = null;
		try {
			$cached = $this->cacheActorsRequest->getFromId($actor->getId());
			$this->carryVerifiedFields($actor, $cached);
		} catch (CacheActorDoesNotExistException $e) {
			// The first published copy has no previous fields to preserve.
		}
		$actor->setSource(json_encode($actor, JSON_UNESCAPED_SLASHES));
		if ($cached === null) {
			$this->save($actor);
		} else {
			$this->update($actor);
		}
		// resolved here: CacheActorService depends on services that need this one
		$this->container?->get(CacheActorService::class)->forget($actor->getId());
	}

	/**
	 * Keeps the verified-link verdicts the cached copy already holds, since
	 * `update()` writes `details` whole and a rebuilt local actor would
	 * otherwise lose `fields_verified` until the next `Cron\Cache` pass.
	 *
	 * `fields_checked` is not carried, so edited fields are checked again at
	 * once rather than after `ProfileLinkVerifier::RECHECK_SECONDS`. Verdicts
	 * for values no longer among the fields are dropped.
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
