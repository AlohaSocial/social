<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Who is asking, on a GET.
 *
 * - **Authorized fetch** reads the signature when there is one, so what is
 *   served can depend on the reader (followers-only posts for followers on
 *   other servers). Unsigned requests get what an anonymous reader gets.
 * - **Secure mode** refuses unsigned GETs outright. Off by default, as it
 *   hides this instance from every peer that does not sign.
 */
class AuthorizedFetchService {
	public function __construct(
		private SignatureService $signatureService,
		private CacheActorService $cacheActorService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/** Whether this instance refuses a GET that is not signed. */
	public function isSecureMode(): bool {
		return $this->configService->getAppValue(ConfigService::SOCIAL_SECURE_MODE) === '1';
	}

	/**
	 * The remote account behind a signed GET, or null for an unsigned one.
	 * A signature that does not verify also yields null (and a log entry), so
	 * a peer with clock drift sees public content rather than a 401. The actor
	 * is fetched when not cached, as a follow row is not an actor document.
	 */
	public function reader(IRequest $request): ?Person {
		$signer = '';

		try {
			$origin = $this->signatureService->checkGetRequest($request, $signer);
		} catch (\Exception $e) {
			$this->logger->notice('a signed fetch could not be verified', [
				'exception' => $e, 'signer' => $signer,
			]);

			return null;
		}

		if ($origin === '' || $signer === '') {
			return null;
		}

		try {
			// the access list applies to a reader as it applies to a sender:
			// an instance this one does not federate with does not become a
			// reader by signing a GET
			$this->fediverseService->authorized($origin);

			$actor = $this->cacheActorService->getFromId($signer);
		} catch (UnauthorizedFediverseException $e) {
			$this->logger->info('a signed fetch came from an instance this server does not federate with', [
				'origin' => $origin,
			]);

			return null;
		} catch (\Exception $e) {
			$this->logger->notice('the account behind a signed fetch could not be resolved', [
				'exception' => $e, 'signer' => $signer,
			]);

			return null;
		}

		if ($actor->isLocal()) {
			// a local actor's key signing an inbound fetch is this instance
			// talking to itself, or somebody replaying one of our own requests
			return null;
		}

		return $actor;
	}

	/**
	 * Refuses an unsigned GET when this instance is in secure mode.
	 *
	 * @throws SignatureException nothing signed the request
	 * @throws InvalidOriginException the signature is there and does not verify
	 */
	public function assertReadable(IRequest $request, ?Person $reader): void {
		if (!$this->isSecureMode() || $reader !== null) {
			return;
		}

		throw new SignatureException(
			'this server serves ActivityPub objects to signed requests only'
		);
	}
}
