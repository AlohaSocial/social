<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\RelationshipService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A person's mutes of Bluesky accounts, kept in step with the AppView. A
 * Bluesky mute is private and lives at the AppView, so a Bluesky app signed
 * in here only hides an account muted there: a mute made here is told to
 * the AppView, and one an app makes through this server is made here too.
 */
class BlueskyMutes {
	public const MUTE = 'app.bsky.graph.muteActor';
	public const UNMUTE = 'app.bsky.graph.unmuteActor';

	/** whether a mute an app made is being applied here, so it is not told back */
	private bool $fromApp = false;

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private BlueskyActorService $actors,
		private AccountService $accounts,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * A mute just made here: told to the AppView, for a Bluesky account and
	 * a person with a Bluesky identity.
	 */
	public function muted(Person $viewer, Person $target): void {
		$this->tell($viewer, $target, self::MUTE);
	}

	public function unmuted(Person $viewer, Person $target): void {
		$this->tell($viewer, $target, self::UNMUTE);
	}

	/**
	 * A mute or an unmute an app made through this server, which the AppView
	 * took: made here as well.
	 */
	public function fromApp(ClientSession $session, string $method, array $body): void {
		$relationships = $this->container?->get(RelationshipService::class);
		if (!$relationships instanceof RelationshipService) {
			return;
		}
		try {
			$viewer = $this->accounts->getActorFromUserId($session->userId);
			$target = $this->actors->cached((string)($body['actor'] ?? '')) ?? $this->actors->resolve((string)($body['actor'] ?? ''));
			$this->fromApp = true;
			$method === self::MUTE ? $relationships->mute($viewer, $target) : $relationships->unmute($viewer, $target);
		} catch (Throwable $e) {
			$this->logger->info('A Bluesky app\'s mute not made here', ['method' => $method, 'exception' => $e]);
		} finally {
			$this->fromApp = false;
		}
	}

	private function tell(Person $viewer, Person $target, string $method): void {
		if ($this->fromApp || !BlueskyIds::isActorId($target->getId())) {
			return;
		}
		try {
			$identity = $this->identities->activeForActor($viewer);
			if ($identity === null) {
				return;
			}
			$this->appView->procedureAs($identity->did, $this->identities->signingKey($identity), $method, ['actor' => BlueskyIds::didOf($target->getId())]);
		} catch (Throwable $e) {
			$this->logger->warning('Mute not told to Bluesky', ['actor' => $viewer->getId(), 'target' => $target->getId(), 'exception' => $e]);
		}
	}
}
