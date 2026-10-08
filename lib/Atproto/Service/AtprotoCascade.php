<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoClientRequest;
use OCA\Social\Db\AtprotoLabelerRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\Db\AtprotoVideoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What happens to an account's Bluesky side when the account goes.
 *
 * A deletion tombstones the DID: the directory marks it dead, the
 * repository and its blobs are dropped, and the firehose says so. A
 * suspension leaves the identity alone — it can be lifted, and nothing on
 * Bluesky would learn that it was.
 */
class AtprotoCascade {
	public function __construct(
		private IdentityService $identities,
		private AtprotoClientRequest $clients,
		private AtprotoLabelerRequest $labelers,
		private AtprotoVideoRequest $videos,
		private AtprotoOAuthRequest $oauth,
		private AtprotoMoveRequest $moves,
		private ActorsRequest $actors,
		private LoggerInterface $logger,
	) {
	}

	public function purge(string $actorId, bool $reversible): void {
		if ($reversible) {
			return;
		}
		try {
			// the account's app passwords, its apps' sessions — app password
			// and OAuth — and its labelers are its own and go with it
			$userId = $this->actors->getFromId($actorId)->getUserId();
			if ($userId !== '') {
				$this->clients->deleteByUser($userId);
				$this->oauth->deleteByUser($userId);
				$this->moves->deleteByUser($userId);
				$this->labelers->deleteByUser($userId);
			}
		} catch (Throwable) {
		}
		try {
			$identity = $this->identities->getByActorId($actorId);
		} catch (AtprotoIdentityNotFoundException) {
			return;
		}
		$this->videos->removeByDid($identity->did);
		try {
			$this->identities->tombstone($identity);
		} catch (Throwable $e) {
			// the row is marked and the operation logged; the maintenance pass resends it
			$this->logger->warning('Bluesky identity not tombstoned yet', ['did' => $identity->did, 'exception' => $e]);
		}
	}
}
