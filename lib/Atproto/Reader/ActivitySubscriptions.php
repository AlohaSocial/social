<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The bell on a Bluesky account, kept on Bluesky too: Bluesky's activity
 * subscriptions (`app.bsky.notification.putActivitySubscription`), which the
 * AppView keeps for the person, so a Bluesky app signed in to the account
 * shows the same bell and Bluesky tells about the posts (`subscribed-post`,
 * which `NotificationPoller` turns into the bell's own notification).
 */
class ActivitySubscriptions {
	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private IdentityService $identities,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Rings or silences the bell on a Bluesky account for the person, on
	 * Bluesky; nothing for any other account, or a person not on Bluesky.
	 */
	public function set(Person $subscriber, Person $target, bool $on): void {
		if (!$this->config->isEnabled() || !BlueskyIds::isActorId($target->getId())) {
			return;
		}
		$identity = $this->identities->activeForActor($subscriber);
		if ($identity === null) {
			return;
		}
		try {
			$this->appView->procedureAs($identity->did, $this->identities->signingKey($identity), 'app.bsky.notification.putActivitySubscription', [
				'subject' => BlueskyIds::didOf($target->getId()),
				'activitySubscription' => ['post' => $on, 'reply' => false],
			]);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky activity subscription not kept', ['target' => $target->getId(), 'exception' => $e]);
		}
	}
}
