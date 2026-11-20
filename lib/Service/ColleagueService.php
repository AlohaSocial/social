<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The people on this Nextcloud, as the Fediverse knows them: the handle each
 * wrote in their profile's `fediverse` field.
 *
 * The field's visibility is respected: `private` is skipped, `local` (the
 * default) is shown to readers here. Bounded, since the account store cannot
 * query "has one": only the first `LIMIT` users are looked at.
 */
class ColleagueService {
	/** How many Nextcloud users are looked at for a handle. */
	public const LIMIT = 200;

	public function __construct(
		private IUserManager $userManager,
		private IAccountManager $accountManager,
		private ConfigService $configService,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The handles written on this instance's profiles, keyed by the user who
	 * wrote them. The reader's own is left out.
	 *
	 * @return array<string, string> uid => `user@host`, without a leading `@`
	 */
	public function handles(string $exceptUserId = ''): array {
		$handles = [];
		foreach ($this->userManager->searchDisplayName('', self::LIMIT) as $user) {
			if ($user->getUID() === $exceptUserId) {
				continue;
			}

			$handle = $this->handleOf($user->getUID());
			if ($handle !== '') {
				$handles[$user->getUID()] = $handle;
			}
		}

		return $handles;
	}

	/**
	 * The accounts those handles name, as far as this instance already knows
	 * them: a local handle is the account, a remote one is looked up in the actor
	 * cache only, since this runs while drawing a page.
	 *
	 * @return Person[] in the order the handles were found, each once
	 */
	public function accounts(string $exceptUserId = ''): array {
		$accounts = [];
		foreach ($this->handles($exceptUserId) as $handle) {
			$account = $this->resolve($handle);
			if ($account !== null && !isset($accounts[$account->getId()])) {
				$accounts[$account->getId()] = $account;
			}
		}

		return array_values($accounts);
	}

	/**
	 * The `fediverse` field of one profile, as a handle, or '' when there is
	 * nothing usable there.
	 */
	public function handleOf(string $userId): string {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return '';
		}

		try {
			$property = $this->accountManager->getAccount($user)
				->getProperty(IAccountManager::PROPERTY_FEDIVERSE);
		} catch (PropertyDoesNotExistException $e) {
			return '';
		}

		// their choice, and this page is not the place it stops being one
		if ($property->getScope() === IAccountManager::SCOPE_PRIVATE) {
			return '';
		}

		return $this->normalise($property->getValue());
	}

	/**
	 * Whether a handle names an account on this instance.
	 */
	public function isLocal(string $handle): bool {
		$host = (string)substr($handle, (int)strrpos($handle, '@') + 1);

		try {
			return strcasecmp($host, $this->configService->getSocialAddress()) === 0;
		} catch (SocialAppConfigException $e) {
			return false;
		}
	}

	private function resolve(string $handle): ?Person {
		try {
			if ($this->isLocal($handle)) {
				return $this->cacheActorService->getFromLocalAccount(strstr($handle, '@', true) ?: $handle);
			}

			// cache only: `false` is "do not go and fetch it"
			return $this->cacheActorService->getFromAccount($handle, false);
		} catch (Throwable $e) {
			$this->logger->debug('[ColleagueService] a profile names an account this instance has not met', [
				'handle' => $handle, 'exception' => $e->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * `user@host`, from whatever a person typed: a leading `@`, a profile URL,
	 * or nothing of the kind.
	 */
	private function normalise(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		// https://host/@user and https://host/users/user are handles too
		if (preg_match('~^https?://([^/]+)/(?:@|users/)([^/?#]+)/?$~i', $value, $m) === 1) {
			$value = $m[2] . '@' . $m[1];
		}

		$value = ltrim($value, '@');

		return preg_match('/^[\w.\-]+@[\w.\-]+\.[a-zA-Z]{2,}$/', $value) === 1 ? $value : '';
	}
}
