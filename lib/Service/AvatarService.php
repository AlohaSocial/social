<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Gumlet\ImageResize;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCP\Accounts\IAccountManager;
use OCP\Config\IUserConfig;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IAvatarManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The profile picture a Mastodon client sends on `update_credentials`. It is
 * the Nextcloud account's avatar, which the actor points at through this
 * app's route of the username (`published()`), so it is written to Nextcloud
 * and the actor cache is refreshed;
 * `DocumentService::cacheLocalAvatarByUsername()` re-caches the icon when
 * core bumps the version.
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
		private IUserConfig $userConfig,
		private ActorsRequest $actorsRequest,
		private IUserSession $userSession,
	) {
	}

	/**
	 * The avatar a local account publishes, by username, since Nextcloud's
	 * own route names the login. Serves what core would serve this reader,
	 * except that visitors get the generated initials where the account does
	 * not publish its picture (`AccountService::mayPublish()`).
	 *
	 * @throws NotFoundException no account here by that name has a Nextcloud avatar
	 * @throws SocialAppConfigException
	 */
	public function published(string $username, int $size): ISimpleFile {
		try {
			$actor = $this->actorsRequest->getFromUsername($username);
		} catch (ActorDoesNotExistException) {
			throw new NotFoundException('no avatar for this account');
		}
		$userId = $actor->getUserId();
		if ($actor->getDeleted() > 0 || $userId === ''
			|| str_starts_with($userId, 'team/') || str_starts_with($userId, 'channel/')) {
			throw new NotFoundException('no avatar for this account');
		}

		$avatar = ($this->userSession->getUser() !== null
			|| $this->accountService->mayPublish($actor, IAccountManager::PROPERTY_AVATAR))
			? $this->avatarManager->getAvatar($userId)
			: $this->avatarManager->getGuestAvatar($username);

		return $avatar->getFile($size);
	}

	/**
	 * Reloads the account's settings before the picture changes: Nextcloud
	 * derives the avatar's `?v=` from its cached copy, and a stale number
	 * would leave peers never re-fetching the picture.
	 */
	private function freshPictureVersion(string $userId): void {
		$this->userConfig->clearCache($userId, true);
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
	 * Takes the account's own picture away, leaving the generated initials
	 * (Mastodon's `DELETE /api/v1/profile/avatar`). Removing a picture that
	 * was never set is not an error, so retries succeed.
	 *
	 * @throws InvalidActionException the backend owns the avatar
	 */
	public function remove(string $userId): void {
		$this->assertChangeable($userId);
		$this->freshPictureVersion($userId);

		try {
			$this->avatarManager->getAvatar($userId)->remove();
		} catch (\Throwable $e) {
			$this->logger->warning('could not remove an avatar', [
				'userId' => $userId, 'exception' => $e,
			]);

			throw new InvalidActionException('the avatar could not be removed');
		}

		// the same as the upload path: a new icon, told to the followers
		$this->accountService->avatarChanged(
			$userId, $this->accountService->getActorFromUserId($userId)->getPreferredUsername()
		);
	}

	/**
	 * Stores a file this server already holds as the account's avatar (an
	 * image a Bluesky app uploaded for its profile), with the upload checks.
	 * Nextcloud keeps square avatars only, so it is cut to its middle square,
	 * which is what Bluesky shows in a circle anyway.
	 *
	 * @throws InvalidActionException the backend owns the avatar, or the bytes
	 *                                are not a picture this can store
	 */
	public function setFromFile(string $userId, string $path): void {
		$this->assertChangeable($userId);
		$this->checkFile($path);
		$square = $this->squared($path);
		try {
			$this->write($userId, $square);
		} finally {
			if ($square !== $path) {
				@unlink($square);
			}
		}
	}

	/**
	 * The picture's middle square, in a file of its own; the path itself when
	 * it is square already.
	 *
	 * @throws InvalidActionException
	 */
	private function squared(string $path): string {
		$size = @getimagesize($path);
		if ($size === false || $size[0] === $size[1]) {
			return $path;
		}
		$side = min($size[0], $size[1]);
		$square = tempnam(sys_get_temp_dir(), 'social-avatar-');
		try {
			if ($square === false) {
				throw new \RuntimeException('no temporary file');
			}
			$image = new ImageResize($path);
			$image->crop($side, $side, false, ImageResize::CROPCENTER);
			$image->save($square, $size[2] === IMAGETYPE_JPEG ? IMAGETYPE_JPEG : IMAGETYPE_PNG);
		} catch (\Throwable $e) {
			if ($square !== false) {
				@unlink($square);
			}
			$this->logger->warning('could not make an avatar square', ['exception' => $e]);

			throw new InvalidActionException('the avatar could not be made square');
		}

		return $square;
	}

	/**
	 * Restores the avatar an export archive carried, but only over the
	 * generated initials: core's own migrator carries the avatar too and may
	 * run in either order, so an existing picture wins.
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

		// LDAP, SAML and similar backends serve the picture from elsewhere;
		// only a refusal tells the user why nothing changed.
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

		// cropping and core's own store both decode every pixel, so the
		// dimensions are read from the header first
		$dimensions = @getimagesize($tmpPath);
		if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
			throw new InvalidActionException('the uploaded avatar is not a readable image');
		}
		if ($dimensions[0] * $dimensions[1] > CacheDocumentService::MAX_PIXELS) {
			throw new InvalidActionException('the uploaded avatar has too many pixels');
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

		$this->freshPictureVersion($userId);
		try {
			$this->avatarManager->getAvatar($userId)->set($data);
		} catch (\Throwable $e) {
			$this->logger->warning('could not store an avatar', [
				'userId' => $userId, 'exception' => $e,
			]);

			throw new InvalidActionException('the uploaded avatar could not be stored');
		}

		// the actor takes the new icon and the followers are told; Nextcloud's
		// own event for the change says the same, and is not told twice
		$this->accountService->avatarChanged(
			$userId, $this->accountService->getActorFromUserId($userId)->getPreferredUsername()
		);
	}
}
