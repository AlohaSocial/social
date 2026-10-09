<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use Gumlet\ImageResize;
use Gumlet\ImageResizeException;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCP\Accounts\IAccountManager;
use OCP\IAvatarManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pictures as blobs.
 *
 * A picture a record refers to is one of the app's stored documents with a
 * `social_atproto_blob` row naming its CID. The stored copy is used as it
 * is when it fits Bluesky's 2,000,000 bytes and is a type Bluesky shows;
 * otherwise it is re-encoded as JPEG — descending quality, then smaller —
 * and the result stored as a document of its own beside the original. A
 * local account's avatar is read from Nextcloud and stored the same way. A
 * picture whose bytes are a blob already is that blob.
 */
class PictureService {
	/** Bluesky's limit per picture */
	public const MAX_BYTES = 2000000;
	public const MAX_PER_POST = 4;
	private const SHOWN_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
	private const QUALITIES = [85, 75, 65];
	private const WIDTHS = [2000, 1600, 1200, 800];

	public function __construct(
		private AtprotoBlobRequest $blobRequest,
		private AtprotoRepoRequest $repoRequest,
		private CacheDocumentService $cache,
		private DocumentService $documents,
		private IAvatarManager $avatars,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * The blob for a picture, made the first time it is asked for.
	 *
	 * @param int $maxBytes what the blob may weigh: a post's picture, or less for a link card's
	 * @return array{blob: BlobRef, width: int, height: int}|null null when the picture cannot be shown on Bluesky
	 */
	public function blobFor(Identity $identity, Person $owner, Document $document, int $maxBytes = self::MAX_BYTES): ?array {
		$existing = $this->blobRequest->getByDocument($identity->did, $document->getId());
		[$width, $height] = $document->getLocalCopySize();
		if ($existing !== null && $existing->size <= $maxBytes) {
			return ['blob' => $existing, 'width' => (int)$width, 'height' => (int)$height];
		}

		try {
			$bytes = $this->cache->getFromUuid($document->getLocalCopy())->getContent();
		} catch (Throwable $e) {
			$this->logger->notice('Picture not readable for Bluesky', ['document' => $document->getId(), 'exception' => $e]);

			return null;
		}

		return $this->blobOf($identity, $owner, $bytes, $document->getMimeType(), (int)$width, (int)$height, $maxBytes, $document);
	}

	/**
	 * The blob for a local account's own avatar, the picture it chose in
	 * Nextcloud: none for a generated one, or where the account keeps its
	 * avatar from other servers. It is read from the avatar itself — the
	 * actor's icon document names the avatar's address, not its bytes — and
	 * stored as a document of its own, so `getBlob` serves the bytes the CID
	 * names after the avatar changes.
	 *
	 * @return array{blob: BlobRef, width: int, height: int}|null
	 */
	public function avatarBlob(Identity $identity, Person $owner, int $maxBytes = self::MAX_BYTES): ?array {
		if (!$owner->isLocal()) {
			return null;
		}
		$accounts = $this->container?->get(AccountService::class);
		try {
			// an actor read from the actors cache carries no user id
			$userId = $owner->getUserId() !== '' ? $owner->getUserId() : (string)$accounts?->getFromId($owner->getId())->getUserId();
			if ($userId === '' || $accounts?->mayPublish((clone $owner)->setUserId($userId), IAccountManager::PROPERTY_AVATAR) === false) {
				return null;
			}
			$avatar = $this->avatars->getAvatar($userId);
			if (!$avatar->isCustomAvatar()) {
				return null;
			}
			$bytes = $avatar->getFile(-1)->getContent();
		} catch (Throwable $e) {
			$this->logger->notice('Avatar not readable for Bluesky', ['actor' => $owner->getId(), 'exception' => $e]);

			return null;
		}
		[$width, $height] = getimagesizefromstring($bytes) ?: [0, 0];

		return $this->blobOf($identity, $owner, $bytes, (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), $width, $height, $maxBytes, null);
	}

	/**
	 * A picture's bytes as a blob: re-encoded when they do not fit, the
	 * blob they are already when they are one, and stored as a document of
	 * their own unless they are the original document's.
	 *
	 * @return array{blob: BlobRef, width: int, height: int}|null
	 */
	private function blobOf(Identity $identity, Person $owner, string $bytes, string $mime, int $width, int $height, int $maxBytes, ?Document $original): ?array {
		$copied = $original === null;
		if (strlen($bytes) > $maxBytes || !in_array($mime, self::SHOWN_TYPES, true)) {
			$encoded = $this->reencode($bytes, $maxBytes);
			if ($encoded === null) {
				return null;
			}
			[$bytes, $width, $height] = $encoded;
			$mime = 'image/jpeg';
			$copied = true;
		}

		$cid = Cid::forRaw($bytes);
		$known = $this->blobRequest->get($identity->did, $cid->toString());
		if ($known !== null) {
			return ['blob' => $known, 'width' => $width, 'height' => $height];
		}
		$storedId = $original?->getId() ?? '';
		if ($copied) {
			$copy = $this->store($owner, $bytes, $original);
			if ($copy === null) {
				return null;
			}
			$storedId = $copy->getId();
		}

		$blob = new BlobRef($identity->did, $cid, $storedId, $mime, strlen($bytes));
		$this->blobRequest->put($blob);
		$this->repoRequest->addBlobBytes($identity->did, $blob->size);

		return ['blob' => $blob, 'width' => $width, 'height' => $height];
	}

	/**
	 * The bytes a blob is, for `getBlob`.
	 *
	 * @throws AtprotoException when the document is gone
	 */
	public function read(BlobRef $blob): string {
		try {
			$document = $this->documents->getDocumentById($blob->documentId);
			$bytes = $this->cache->getFromUuid($document->getLocalCopy())->getContent();
		} catch (Throwable $e) {
			throw new AtprotoException('Blob ' . $blob->cid->toString() . ' is not readable', 0, $e);
		}
		if (!Cid::forRaw($bytes)->equals($blob->cid)) {
			throw new AtprotoException('Blob ' . $blob->cid->toString() . ' no longer matches its document');
		}

		return $bytes;
	}

	/**
	 * @return array{0: string, 1: int, 2: int}|null bytes, width, height
	 */
	private function reencode(string $original, int $maxBytes): ?array {
		foreach (self::WIDTHS as $width) {
			foreach (self::QUALITIES as $quality) {
				try {
					$image = ImageResize::createFromString($original);
					$image->quality_jpg = $quality;
					$image->resizeToBestFit($width, $width);
					$bytes = $image->getImageAsString(IMAGETYPE_JPEG);
				} catch (ImageResizeException $e) {
					$this->logger->notice('Picture cannot be re-encoded for Bluesky', ['exception' => $e]);

					return null;
				}
				if (strlen($bytes) <= $maxBytes) {
					return [$bytes, (int)$image->getDestWidth(), (int)$image->getDestHeight()];
				}
			}
		}

		return null;
	}

	private function store(Person $owner, string $bytes, ?Document $original): ?Document {
		$path = tempnam(sys_get_temp_dir(), 'social-atproto-');
		if ($path === false) {
			return null;
		}
		try {
			file_put_contents($path, $bytes);

			return $this->documents->storeLocalAttachment($owner, $path, $original?->getParentId() ?? '', $original?->getDescription() ?? '', true);
		} catch (Throwable $e) {
			$this->logger->warning('Picture for Bluesky could not be stored', ['exception' => $e]);

			return null;
		} finally {
			@unlink($path);
		}
	}
}
