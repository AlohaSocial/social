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
		$profile = [];
		if ($account !== null) {
			try {
				$profile = $this->identity->profile($account->getDid(), $account->getPds());
				if ($account->getState() !== AtprotoAccount::STATE_LINKED || $account->getLastError() !== '') {
					$account->setState(AtprotoAccount::STATE_LINKED)->setLastError('');
					$this->atprotoRequest->setAccountState($userId, AtprotoAccount::STATE_LINKED);
				}
			} catch (AtprotoException $e) {
				$message = trim($e->getMessage());
				$state = $e->getStatus() === 401 ? AtprotoAccount::STATE_BROKEN : $account->getState();
				$account->setState($state)->setLastError($message);
				$this->atprotoRequest->setAccountState($userId, $state, $message);
				$this->logger->debug('could not read linked ATProto profile', ['exception' => $e]);
			}
		}

		return [
			'enabled' => $this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_ENABLED) === '1',
			'account' => $account === null ? null : $this->describe($account),
			'profile' => $account === null ? null : [
				'displayName' => (string)($profile['displayName'] ?? ''),
				'description' => (string)($profile['description'] ?? ''),
			],
			// Watch rows are shared, deduplicated work for the instance. They are
			// not a profile's public following list, so never disclose everybody
			// else's follows through an account-settings response.
			'watches' => [],
		];
	}

	/** Update the public profile record in the linked account's repository. */
	public function updateProfile(string $userId, string $displayName, string $description, ?array $avatarUpload = null, ?array $bannerUpload = null, bool $removeAvatar = false, bool $removeBanner = false): array {
		$account = $this->atprotoRequest->getAccount($userId);
		if ($account === null) {
			throw new AtprotoException('no Bluesky account is linked', 404);
		}
		if ($account->getState() !== AtprotoAccount::STATE_LINKED) {
			throw new AtprotoException('reconnect the Bluesky account before editing its profile', 401);
		}

		$record = $this->identity->profile($account->getDid(), $account->getPds());
		$record['$type'] = 'app.bsky.actor.profile';
		$record['displayName'] = $this->limit($displayName, 64);
		$record['description'] = $this->limit($description, 256);
		if ($avatarUpload !== null) {
			$record['avatar'] = $this->uploadProfileBlob($avatarUpload, $account);
		} elseif ($removeAvatar) {
			unset($record['avatar']);
		}
		if ($bannerUpload !== null) {
			$record['banner'] = $this->uploadProfileBlob($bannerUpload, $account);
		} elseif ($removeBanner) {
			unset($record['banner']);
		}
		$this->client->authedPost('com.atproto.repo.putRecord', [
			'repo' => $account->getDid(),
			'collection' => 'app.bsky.actor.profile',
			'rkey' => 'self',
			'record' => $record,
		], $account, $account->getPds());

		return [
			'displayName' => $record['displayName'],
			'description' => $record['description'],
		];
	}

	/** @return array<string, mixed> */
	private function uploadProfileBlob(array $upload, AtprotoAccount $account): array {
		$error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
		$path = (string)($upload['tmp_name'] ?? '');
		$mime = strtolower(trim((string)($upload['type'] ?? '')));
		$size = (int)($upload['size'] ?? 0);
		$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
		if ($error !== UPLOAD_ERR_OK || $path === '' || !is_readable($path) || !in_array($mime, $allowed, true) || $size < 1 || $size > 1 * 1024 * 1024) {
			throw new AtprotoException('profile images must be readable JPEG, PNG, GIF or WebP files up to 1 MiB', 422);
		}

		$binary = file_get_contents($path);
		if (!is_string($binary) || $binary === '') {
			throw new AtprotoException('could not read the uploaded profile image', 422);
		}

		$blob = $this->client->authedBlobPost($binary, $mime, $account, $account->getPds())['blob'] ?? null;
		if (!is_array($blob)) {
			throw new AtprotoException('the PDS did not return a profile image blob', 502);
		}

		return $blob;
	}

	private function limit(string $value, int $length): string {
		$value = trim($value);
		if (function_exists('grapheme_substr') && grapheme_strlen($value) > $length) {
			return (string)grapheme_substr($value, 0, $length);
		}

		return mb_substr($value, 0, $length);
	}

	/**
	 * Links an account, and answers what was written.
	 *
	 * @param string $handle as typed, with or without an `@`
	 * @param string $appPassword from Bluesky's app-password page, not the account password
	 * @param string $pds optional PDS base URL; empty uses the DID-resolved PDS
	 *
	 * @throws AtprotoException what the PDS said, when the pair is no good
	 */
	public function link(string $userId, string $handle, string $appPassword, string $pds = ''): AtprotoAccount {
		$handle = $this->identity->normalize($handle);
		if ($appPassword === '') {
			throw new AtprotoException('an app password is required to link an account', 400);
		}

		$resolved = $this->identity->resolve($handle);
		$pds = trim($pds) !== '' ? rtrim(trim($pds), '/') : $resolved['pds'];
		if (filter_var($pds, FILTER_VALIDATE_URL) === false || !in_array(parse_url($pds, PHP_URL_SCHEME), ['http', 'https'], true)) {
			throw new AtprotoException('the PDS server must be a valid http(s) URL', 400);
		}
		$session = $this->client->post('com.atproto.server.createSession', [
			'identifier' => $handle,
			'password' => $appPassword,
		], $pds);

		$did = (string)($session['did'] ?? '');
		if (!str_starts_with($did, 'did:')) {
			throw new AtprotoException('the PDS answered the login without an identity', 400);
		}
		if ($did !== $resolved['did']) {
			throw new AtprotoException('the PDS session identity does not match the resolved handle', 409);
		}

		// the did the login proves is the one that would post: a handle
		// pointed at another account between the directory read and this
		$account = $this->atprotoRequest->getAccount($userId) ?? new AtprotoAccount();
		$account->setUserId($userId)
			->setHandle($handle)
			->setDid($did)
			->setPds($pds)
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
