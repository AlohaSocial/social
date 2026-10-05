<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Model\Atproto\AtprotoWatch;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ConfigService;
use Psr\Log\LoggerInterface;

/**
 * One Nextcloud account linked to one Bluesky account, and what the settings
 * page says about both that and the profiles this instance reads.
 *
 * The link is verified rather than stored on trust: the handle is resolved
 * the way any lookup resolves it, and the app password is sent to the PDS as
 * a login before a row is written. A typo would otherwise sit in the table
 * until the first post to Bluesky failed with it, and the person who typed it
 * would have been told the account was linked.
 *
 * The password is sealed with the instance secret before it is written — the
 * same `PrivateKeyCipher` that seals actor private keys — and opened where it
 * is used, in the client that logs in. A database dump alone then says what
 * handle is linked and not what would post as it.
 */
class AtprotoAccountService {
	public function __construct(
		private AtprotoClient $client,
		private AtprotoIdentity $identity,
		private AtprotoRequest $atprotoRequest,
		private PrivateKeyCipher $cipher,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The state the settings page draws: whether the feature is on at all,
	 * what this account has linked, and the profiles whose posts arrive here.
	 *
	 * @return array<string, mixed>
	 */
	public function status(string $userId): array {
		$account = $this->atprotoRequest->getAccount($userId);

		return [
			'enabled' => $this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_ENABLED) === '1',
			'account' => $account === null ? null : $this->describe($account),
			// Watch rows are shared, deduplicated work for the instance. They are
			// not a profile's public following list, so never disclose everybody
			// else's follows through an account-settings response.
			'watches' => [],
		];
	}

	/**
	 * Links an account, and answers what was written.
	 *
	 * @param string $handle as typed, with or without an `@`
	 * @param string $appPassword from Bluesky's app-password page, not the account password
	 *
	 * @throws AtprotoException what the PDS said, when the pair is no good
	 */
	public function link(string $userId, string $handle, string $appPassword): AtprotoAccount {
		$handle = $this->identity->normalize($handle);
		if ($appPassword === '') {
			throw new AtprotoException('an app password is required to link an account', 400);
		}

		$resolved = $this->identity->resolve($handle);
		$session = $this->client->post('com.atproto.server.createSession', [
			'identifier' => $handle,
			'password' => $appPassword,
		], $resolved['pds']);

		$did = (string)($session['did'] ?? '');
		if (!str_starts_with($did, 'did:')) {
			throw new AtprotoException('the PDS answered the login without an identity', 400);
		}

		// the did the login proves is the one that would post: a handle
		// pointed at another account between the directory read and this
		$account = $this->atprotoRequest->getAccount($userId) ?? new AtprotoAccount();
		$account->setUserId($userId)
			->setHandle($handle)
			->setDid($did)
			->setPds($resolved['pds'])
			->setAppPassword($this->cipher->seal($appPassword))
			->setState(AtprotoAccount::STATE_LINKED)
			->setLastError('')
			->setLastSync(0);
		$this->atprotoRequest->saveAccount($account);

		$this->logger->info('a Bluesky account was linked', [
			'user' => $userId, 'handle' => $handle, 'did' => $did,
		]);

		return $account;
	}

	/**
	 * Unlinks, and says whether there was a link to remove.
	 */
	public function unlink(string $userId): bool {
		$account = $this->atprotoRequest->getAccount($userId);
		if ($account === null) {
			return false;
		}

		$this->client->forgetSession($account);
		$this->logger->info('a Bluesky account was unlinked', [
			'user' => $userId, 'handle' => $account->getHandle(),
		]);

		return $this->atprotoRequest->deleteAccount($userId);
	}

	/**
	 * The linked account as the page shows it — never the password, sealed
	 * or otherwise.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(AtprotoAccount $account): array {
		return [
			'handle' => $account->getHandle(),
			'did' => $account->getDid(),
			'pds' => $account->getPds(),
			'state' => $account->getState(),
			'lastError' => $account->getLastError(),
			'lastSync' => $account->getLastSync(),
		];
	}

	/**
	 * One watched profile: which one, how much has arrived, and whether the
	 * last pass had a reason to complain.
	 *
	 * @return array<string, mixed>
	 */
	private function describeWatch(AtprotoWatch $watch): array {
		return [
			'handle' => $watch->getHandle(),
			'did' => $watch->getDid(),
			'imported' => $watch->getImported(),
			'failures' => $watch->getFailures(),
			'lastSync' => $watch->getLastSync(),
			'lastError' => $watch->getLastError(),
		];
	}
}
