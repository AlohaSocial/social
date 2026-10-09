<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoClientRequest;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A person's own switch for their presence on Bluesky (D22, §4.6). Off, the
 * identity is deactivated as Bluesky deactivates an account: its profile
 * and posts are no longer shown there, nothing new is published, and every
 * Bluesky app signed in to it is signed out; the DID, the handle and the
 * repository stay theirs. On again, the repository is served as it was
 * left. What was written here while it was off stays off Bluesky.
 */
class PresenceService {
	/** when the person last switched their presence back on, in seconds */
	public const ON_SINCE = 'atproto_on_since';

	public function __construct(
		private IdentityService $identities,
		private AtprotoClientRequest $clients,
		private AtprotoOAuthRequest $oauth,
		private IConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ?ActorsRequest $actors = null,
	) {
	}

	/**
	 * @return Identity the identity as it is now
	 * @throws InvalidArgumentException when the account has no identity here to switch
	 */
	public function switchOff(Person $actor): Identity {
		$identity = $this->switchable($actor);
		$this->identities->deactivate($identity);
		$this->clients->removeSessionsOfUser($actor->getUserId());
		$this->oauth->deleteByUser($actor->getUserId());
		$this->logger->info('Presence on Bluesky switched off', ['did' => $identity->did]);

		return $this->identities->getByDid($identity->did);
	}

	/**
	 * @return Identity the identity as it is now
	 * @throws InvalidArgumentException when the account has no identity here to switch
	 */
	public function switchOn(Person $actor): Identity {
		$identity = $this->switchable($actor);
		if ($identity->state === Identity::STATE_DEACTIVATED) {
			$this->config->setUserValue($actor->getUserId(), Application::APP_ID, self::ON_SINCE, (string)$this->time->getTime());
			$this->identities->activate($identity);
			$this->logger->info('Presence on Bluesky switched back on', ['did' => $identity->did]);
		}

		return $this->identities->getByDid($identity->did);
	}

	/**
	 * Whether a post was written before its author last switched back on:
	 * while they were off, so it is never published, however soon a pass
	 * comes by to publish what is missing.
	 */
	public function writtenWhileOff(Person $author, Stream $post): bool {
		if (!$author->isLocal()) {
			return false;
		}
		$userId = $author->getUserId();
		if ($userId === '' && $this->actors !== null) {
			// a cached actor does not carry its Nextcloud user: the account does
			try {
				$userId = $this->actors->getFromId($author->getId())->getUserId();
			} catch (Throwable) {
				return false;
			}
		}
		if ($userId === '') {
			return false;
		}
		$onSince = (int)$this->config->getUserValue($userId, Application::APP_ID, self::ON_SINCE, '0');
		$published = $post->getPublishedTime();

		return $onSince > 0 && $published > 0 && $published < $onSince;
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function switchable(Person $actor): Identity {
		$identity = $this->identities->forActor($actor, false);
		if ($identity === null) {
			throw new InvalidArgumentException('This account has no Bluesky identity');
		}
		if ($identity->state !== Identity::STATE_ACTIVE && $identity->state !== Identity::STATE_DEACTIVATED) {
			throw new InvalidArgumentException('This Bluesky account is no longer hosted here');
		}

		return $identity;
	}
}
