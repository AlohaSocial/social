<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\InstancePath;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The banner across the top of a profile (`image` on the actor document),
 * set from a picked file, a typed URL or Mastodon's `update_credentials`
 * `header`. One place so all three store it public and send the
 * `Update{Person}`.
 */
class BannerService {
	public function __construct(
		private AccountService $accountService,
		private ActorService $actorService,
		private CacheActorService $cacheActorService,
		private CacheDocumentService $cacheDocumentService,
		private MediaPurgeService $mediaPurgeService,
		private ActivityService $activityService,
		private ConfigService $configService,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stores a local file as the user's banner and federates the change.
	 *
	 * @throws \Exception the actor is unknown, or the bytes are not an image
	 *                    this app stores
	 */
	public function setFromTempFile(string $userId, string $tmpPath): Image {
		$actor = $this->accountService->getActorFromUserId($userId);
		$previous = $this->currentBanner($actor->getId());

		$image = new Image();
		$image->setLocal(true);
		$image->setAccount($actor->getPreferredUsername());
		$image->setUrlCloud($this->configService->getCloudUrl());
		$image->generateUniqueId('/documents/header');
		// Public, unlike an attachment: it is part of the actor document.
		$image->setPublic(true);
		// Hung off the account, so deleting the account takes it (`ActorCascadeService`).
		$image->setParentId($actor->getId());

		$this->cacheDocumentService->saveFromTempToCache($image, $tmpPath);
		$image->setUrl($image->getMediaUrl($this->urlGenerator, $image->getMimeType()));

		$interface = AP::instance()->getInterfaceForItem($image);
		$interface->save($image);

		$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
		$cached = $this->cacheActorService->getFromId($actor->getId());
		$cached->setHeader($image->getUrl());
		$this->actorService->cacheLocalActor($cached);

		$this->forget($previous, $actor->getPreferredUsername());
		$this->announce($actor, $cached);
		$this->accountService->queueBlueskyProfile($actor);

		return $image;
	}

	/**
	 * Takes the banner away, deletes the stored image and tells the servers that
	 * have it (Mastodon's `DELETE /api/v1/profile/header`). Removing a banner
	 * nobody set is not an error.
	 */
	public function remove(string $userId): void {
		$actor = $this->accountService->getActorFromUserId($userId);

		$cached = $this->cacheActorService->getFromId($actor->getId());
		$previous = $cached->getHeader();
		if ($previous === '') {
			return;
		}

		$cached->setHeader('');
		$this->actorService->cacheLocalActor($cached);

		$this->forget($previous, $actor->getPreferredUsername());

		// Otherwise the banner stays on every other server.
		$this->announce($actor, $cached);
		$this->accountService->queueBlueskyProfile($actor);
	}

	/** What the profile has on it now, or '' for an actor nothing is cached for. */
	private function currentBanner(string $actorId): string {
		try {
			return $this->cacheActorService->getFromId($actorId)->getHeader();
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Deletes the image a banner was, once nothing points at it. Only this
	 * account's own, so pointing a header at somebody else's file cannot get it
	 * deleted.
	 */
	private function forget(string $url, string $account): void {
		try {
			$this->mediaPurgeService->purgeLocalByUrl($url, $account);
		} catch (\Throwable $e) {
			$this->logger->warning('could not remove a replaced banner', [
				'url' => $url, 'exception' => $e,
			]);
		}
	}

	/**
	 * Sends the change to followers, signed with the account's row (the cached
	 * copy holds no key). A failure is logged: the banner is stored, and peers
	 * pick it up on their next refresh.
	 */
	private function announce(Person $actor, Person $cached): void {
		try {
			$update = clone $cached;
			$update->addInstancePath(new InstancePath(
				$cached->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			));
			$this->activityService->updateActivity($actor, $update);
		} catch (\Exception $e) {
			$this->logger->warning('could not federate a banner change', [
				'actor' => $cached->getId(),
				'exception' => $e,
			]);
		}
	}
}
