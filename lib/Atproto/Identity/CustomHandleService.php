<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use InvalidArgumentException;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A handle on a domain the person owns (§4.2): checked before it is used,
 * written into the DID document, and checked again every day after, so a
 * record that went away shows as broken in the settings rather than only
 * on Bluesky. The assigned `alice.<host>` handle keeps resolving to the DID
 * throughout, an alias the document does not list.
 */
class CustomHandleService {
	/** how often a custom handle is checked again */
	private const RECHECK = 86400;

	public function __construct(
		private IdentityService $identities,
		private HandleVerifier $verifier,
		private AtprotoIdentityRequest $identityRequest,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Identity the identity with its new handle
	 * @throws InvalidArgumentException with what to tell the person
	 * @throws AtprotoException
	 */
	public function set(Identity $identity, string $typed): Identity {
		if (!$identity->isActive()) {
			throw new InvalidArgumentException('The Bluesky account is not active');
		}
		$handle = HandleVerifier::normal($typed);
		$refusal = $this->verifier->refusal($handle);
		if ($refusal !== '') {
			throw new InvalidArgumentException($refusal);
		}
		if ($handle === $identity->handle) {
			return $identity;
		}
		if ($this->identityRequest->handleExists($handle)) {
			throw new InvalidArgumentException('That handle is another account\'s here');
		}
		if ($this->verifier->verify($handle, $identity->did) === '') {
			throw new InvalidArgumentException('Neither _atproto.' . $handle . ' nor https://' . $handle . '/.well-known/atproto-did names ' . $identity->did . ' yet');
		}

		return $this->identities->useCustomHandle($identity, $handle);
	}

	/**
	 * The assigned handle again.
	 *
	 * @throws AtprotoException
	 */
	public function clear(Identity $identity): Identity {
		if ($identity->customHandle === '') {
			return $identity;
		}

		return $this->identities->useCustomHandle($identity, '');
	}

	/**
	 * Checks the custom handles not checked for a day.
	 *
	 * @return int how many no longer name their DID
	 */
	public function recheck(int $limit = 20): int {
		$failing = 0;
		foreach ($this->identityRequest->getCustomHandlesDue($this->time->getTime() - self::RECHECK, $limit) as $identity) {
			try {
				$ok = $this->verifier->verify($identity->customHandle, $identity->did) !== '';
			} catch (Throwable $e) {
				$this->logger->debug('Custom handle not checked', ['did' => $identity->did, 'exception' => $e]);
				continue;
			}
			$this->identityRequest->customHandleChecked($identity->did, $ok);
			if (!$ok) {
				$failing++;
				$this->logger->info('Custom handle no longer names its DID', ['did' => $identity->did, 'handle' => $identity->customHandle]);
			}
		}

		return $failing;
	}
}
