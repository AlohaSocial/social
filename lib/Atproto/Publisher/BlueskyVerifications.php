<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The instance's verifications as `app.bsky.graph.verification` records in
 * the repository of its verifying account (`VerificationService`), one per
 * verified DID. A record names the handle and display name the account had
 * when it was written; the AppView judges it invalid once either changes,
 * so a change means a new record, the old one withdrawn first — the AppView
 * keeps one verification per issuer and subject.
 */
class BlueskyVerifications {
	public const COLLECTION = 'app.bsky.graph.verification';

	public function __construct(
		private Publisher $publisher,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What a verification's record is tied to: one per verified DID,
	 * whichever account issued it.
	 */
	public static function localId(string $did): string {
		return 'verification:' . $did;
	}

	/**
	 * Whether a record can name the account at all: a DID and a handle that
	 * resolves; `handle.invalid` and the like cannot be verified.
	 */
	public static function publishable(string $did, string $handle): bool {
		return Syntax::isDid($did) && Syntax::isHandle($handle) && !str_ends_with($handle, '.invalid');
	}

	/**
	 * Writes the verification of a DID into the verifier's repository, in
	 * place of any record about it there was.
	 *
	 * @return bool whether a record was written
	 */
	public function publish(Person $verifier, string $did, string $handle, string $displayName): bool {
		if (!self::publishable($did, $handle)) {
			return false;
		}
		try {
			$this->publisher->removeRecord(self::COLLECTION, self::localId($did));

			return $this->publisher->writeRecord($verifier, self::COLLECTION, [
				'$type' => self::COLLECTION,
				'subject' => $did,
				'handle' => $handle,
				'displayName' => $displayName,
				'createdAt' => Syntax::datetime($this->time->getTime()),
			], self::localId($did));
		} catch (Throwable $e) {
			$this->logger->warning('Verification not published to Bluesky', ['verifier' => $verifier->getId(), 'subject' => $did, 'exception' => $e]);

			return false;
		}
	}

	/**
	 * Takes the verification of a DID back, wherever it was published.
	 *
	 * @return bool whether a record was there to remove
	 */
	public function withdraw(string $did): bool {
		if ($did === '') {
			return false;
		}
		try {
			return $this->publisher->removeRecord(self::COLLECTION, self::localId($did));
		} catch (Throwable $e) {
			$this->logger->warning('Verification not withdrawn from Bluesky', ['subject' => $did, 'exception' => $e]);

			return false;
		}
	}
}
