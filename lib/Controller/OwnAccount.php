<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\AccountNotSetUpException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use Throwable;

/**
 * The signed-in person's own account, for a route that acts as it, or 403
 * `account-not-set-up` with the setup page's address.
 *
 * Looked up, never made: accounts are made by
 * `LocalController::accountCreate()`, `occ social:account:create` and the
 * import, so nobody gets a handle they did not choose. The using class has
 * an `AccountService` in `$accountService`.
 */
trait OwnAccount {
	/**
	 * @throws AccountNotSetUpException
	 */
	protected function ownAccountOf(string $userId): Person {
		try {
			return $this->accountService->getActorFromUserId($userId);
		} catch (ActorDoesNotExistException $e) {
			throw AccountNotSetUpException::withSetupUrl($e);
		}
	}

	protected static function notSetUp(AccountNotSetUpException $e): DataResponse {
		return new DataResponse($e->toArray(), Http::STATUS_FORBIDDEN);
	}

	/**
	 * `$answer`, unless the person has no account here at all, in which case
	 * they are told that instead.
	 */
	protected function unlessNotSetUp(string $userId, DataResponse $answer): DataResponse {
		try {
			$this->ownAccountOf($userId);
		} catch (AccountNotSetUpException $e) {
			return self::notSetUp($e);
		} catch (Throwable) {
		}

		return $answer;
	}
}
