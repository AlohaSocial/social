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
 * The banner across the top of a profile — `image` on the actor document.
 *
 * There are three ways one arrives: a file the user picked, a URL they typed,
 * and `header` on Mastodon's `update_credentials`. All three end with the same
 * work — store the bytes, point the cached actor at them, tell the followers —
 * and that work is here so the three cannot drift on the parts that matter:
 * the banner being public where an attachment is not, and the `Update{Person}`
 * that is the only reason anybody else ever sees it.
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
		// unlike an attachment, a banner is public by definition: it is part of
		// the actor document, which every server that has heard of this account
		// may read
		$image->setPublic(true);

		$this->cacheDocumentService->saveFromTempToCache($image, $tmpPath);
		$image->setUrl($image->getMediaUrl($this->urlGenerator, $image->getMimeType()));

		$interface = AP::instance()->getInterfaceForItem($image);
		$interface->save($image);

		$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
		$cached = $this->cacheActorService->getFromId($actor->getId());
		$cached->setHeader($image->getUrl());
		$this->actorService->cacheLocalActor($cached);

		$this->forget($previous, $actor->getPreferredUsername());
		$this->announce($cached);
		$this->accountService->queueBlueskyProfile($actor);

		return $image;
	}

	/**
	 * Takes the banner away and tells the servers that have it.
	 *
	 * Mastodon's `DELETE /api/v1/profile/header`. The stored picture goes with
	 * it. It used to be left where it was, on the grounds that "the document
	 * sweep collects it" — there was no such sweep, so every banner anybody
	 * ever replaced stayed on disk and stayed readable at its own URL by
	 * anyone who had it, which for a picture somebody has just taken off their
	 * profile is the wrong half of the promise to keep.
	 *
	 * Removing a banner nobody set is not an error — the profile ends up
	 * without one either way.
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

		// an actor whose header is gone here but not on the servers that follow
		// it is a profile that still has a banner everywhere else
		$this->announce($cached);
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
	 * The picture a banner used to be, once nothing points at it any more.
	 *
	 * Only this account's own: the url is the one handle there is on a banner,
	 * and an actor whose header was set to somebody else's address must not be
	 * able to have their file deleted by taking their own banner off.
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
	 * A banner nobody is told about is a banner only this instance can see, so
	 * the failure is worth a line in the log — but not worth losing the banner
	 * over: it is stored either way, and a remote server picks it up the next
	 * time it refreshes the actor.
	 */
	private function announce(Person $cached): void {
		try {
			$update = clone $cached;
			$update->addInstancePath(new InstancePath(
				$cached->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			));
			$this->activityService->updateActivity($cached, $update);
		} catch (\Exception $e) {
			$this->logger->warning('could not federate a banner change', [
				'actor' => $cached->getId(),
				'exception' => $e,
			]);
		}
	}
}
