<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidActionException;
use OCP\IAvatarManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The profile picture, as a Mastodon client sends it on `update_credentials`.
 *
 * Unlike the banner, this is not the app's own picture: it is the Nextcloud
 * account's avatar, the one the whole server shows, and the actor document
 * points at `core.avatar.getAvatar` for it. So it is written where it lives
 * and the actor cache is refreshed; `DocumentService::cacheLocalAvatarByUsername()`
 * notices the version core bumped and re-caches the icon.
 *
 * `avatar` used to be accepted and dropped. A client's profile editor sends the
 * whole form in one PATCH, so somebody changing their picture and their bio
 * together got a 200 and a new bio over the old picture.
 */
class AvatarService {
	/**
	 * What a Nextcloud avatar may be. Narrower than what an attachment may be:
	 * core renders these and nothing else.
	 */
	private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

	/** Core's own ceiling for an avatar upload, in bytes. */
	private const MAX_SIZE = 20 * 1024 * 1024;

	public function __construct(
		private IAvatarManager $avatarManager,
		private IUserManager $userManager,
		private AccountService $accountService,
		private LoggerInterface $logger,
		private MultipartBodyService $multipartBodyService,
	) {
	}

	/**
	 * Stores an uploaded file as the account's avatar.
	 *
	 * @throws InvalidActionException the backend owns the avatar, or the bytes
	 *                                are not a picture this can store
	 */
	public function setFromTempFile(string $userId, array $upload): void {
		$this->write($userId, $this->checkUpload($userId, $upload));
	}

	/**
	 * Refuses, without writing anything, an upload `setFromTempFile()` would
	 * refuse.
	 *
	 * @return string the uploaded file's path
	 * @throws InvalidActionException the backend owns the avatar, or the bytes
	 *                                are not a picture this can store
	 */
	public function checkUpload(string $userId, array $upload): string {
		$this->assertChangeable($userId);

		$tmpPath = $upload['tmp_name'] ?? '';
		if (!is_string($tmpPath) || $tmpPath === '' || !$this->multipartBodyService->isUpload($tmpPath)) {
			throw new InvalidActionException('no avatar found in the request');
		}

		$this->checkFile($tmpPath);

		return $tmpPath;
	}

	/**
	 * Takes the account's own picture away, leaving the generated initials.
	 *
	 * Mastodon's `DELETE /api/v1/profile/avatar`, which is how a client offers
	 * "remove picture" — without it a client can only replace one picture with
	 * another, and an account that wanted none was stuck with whatever it last
	 * uploaded.
	 *
	 * Removing a picture that was never set is not an error: the account ends
	 * up in the state that was asked for either way, and a client that retries
	 * a failed delete must not be told the second attempt was wrong.
	 *
	 * @throws InvalidActionException the backend owns the avatar
	 */
	public function remove(string $userId): void {
		$this->assertChangeable($userId);

		try {
			$this->avatarManager->getAvatar($userId)->remove();
		} catch (\Throwable $e) {
			$this->logger->warning('could not remove an avatar', [
				'userId' => $userId, 'exception' => $e,
			]);

			throw new InvalidActionException('the avatar could not be removed');
		}

		// the same catch-up the upload path does: the actor's icon is a copy of
		// the account's, and nothing refreshes it on its own
		$this->accountService->cacheLocalActorByUsername(
			$this->accountService->getActorFromUserId($userId)->getPreferredUsername()
		);
	}

	/**
	 * Stores a file this server already holds as the account's avatar: the
	 * picture a Bluesky app uploaded for the profile it then saves. The same
	 * checks as an upload, but no upload to be one.
	 *
	 * @throws InvalidActionException the backend owns the avatar, or the bytes
	 *                                are not a picture this can store
	 */
	public function setFromFile(string $userId, string $path): void {
		$this->assertChangeable($userId);
		$this->checkFile($path);
		$this->write($userId, $path);
	}

	/**
	 * The picture an export archive carried, put back — but never over one the
	 * account already has.
	 *
	 * An imported archive is read alongside whatever is already here, and the
	 * avatar of a Nextcloud account is the account's rather than this app's:
	 * core's own migrator carries it, and the order the migrators run in is not
	 * this app's to decide. So a picture that is already there wins, and the
	 * archived one is only used where the account has none of its own — the
	 * generated initials -- which is the case where it would otherwise be lost.
	 *
	 * @return bool whether the avatar was written
	 */
	public function restoreFromArchive(string $userId, string $tmpPath): bool {
		$user = $this->userManager->get($userId);
		if ($user === null || !$user->canChangeAvatar()) {
			return false;
		}

		try {
			if ($this->avatarManager->getAvatar($userId)->isCustomAvatar()) {
				return false;
			}
		} catch (\Throwable $e) {
			return false;
		}

		$this->checkFile($tmpPath);
		$this->write($userId, $tmpPath);

		return true;
	}

	/**
	 * @throws InvalidActionException the account is unknown, or its backend owns the avatar
	 */
	private function assertChangeable(string $userId): void {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new InvalidActionException('unknown account');
		}

		// LDAP, SAML and friends serve the picture from elsewhere. Saying so is
		// the point: the profile looks unchanged either way, and only a refusal
		// tells the user why.
		if (!$user->canChangeAvatar()) {
			throw new InvalidActionException(
				'the avatar of this account is managed outside Nextcloud and cannot be changed here'
			);
		}
	}

	/**
	 * The checks both ways in make: the bytes decide what this is, not the
	 * name they arrived under.
	 *
	 * @throws InvalidActionException
	 */
	private function checkFile(string $tmpPath): void {
		$size = filesize($tmpPath);
		if ($size === false || $size === 0) {
			throw new InvalidActionException('the uploaded avatar is empty');
		}

		if ($size > self::MAX_SIZE) {
			throw new InvalidActionException('the uploaded avatar is too large');
		}

		// the bytes decide, not the name or the declared type: a client may
		// call anything an image, and core will be handed this to render
		$type = (string)@mime_content_type($tmpPath);
		if (!in_array($type, self::ALLOWED_TYPES, true)) {
			throw new InvalidActionException('an avatar has to be a JPEG, PNG, GIF or WebP image');
		}
	}

	/**
	 * Stores a file `checkFile()` accepted as the account's avatar.
	 *
	 * @throws InvalidActionException
	 */
	private function write(string $userId, string $tmpPath): void {
		$data = file_get_contents($tmpPath);
		if ($data === false) {
			throw new InvalidActionException('the uploaded avatar could not be read');
		}

		try {
			$this->avatarManager->getAvatar($userId)->set($data);
		} catch (\Throwable $e) {
			$this->logger->warning('could not store an avatar', [
				'userId' => $userId, 'exception' => $e,
			]);

			throw new InvalidActionException('the uploaded avatar could not be stored');
		}

		// the actor's icon follows the account's, but only once something asks
		// the cache to catch up
		$this->accountService->cacheLocalActorByUsername(
			$this->accountService->getActorFromUserId($userId)->getPreferredUsername()
		);
	}
}
