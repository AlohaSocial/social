<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Videos as blobs.
 *
 * A video a record refers to is a stored document as it is, never
 * re-encoded here: what Bluesky plays is the stream its video service made
 * of it. It is hashed and served as a stream, a chunk at a time, because a
 * video of a hundred megabytes is not something to hold in memory.
 */
class VideoBlobService {
	/** `app.bsky.embed.video`: the blob's `maxSize` */
	public const MAX_BYTES = 100000000;
	/** what Bluesky's apps and video service take, in seconds */
	public const MAX_SECONDS = 180;
	/** `app.bsky.embed.video`: the blob's `accept` */
	public const TYPE = 'video/mp4';

	public function __construct(
		private AtprotoBlobRequest $blobRequest,
		private AtprotoRepoRequest $repoRequest,
		private CacheDocumentService $cache,
		private DocumentService $documents,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The blob of a stored video, made the first time it is asked for.
	 *
	 * @return BlobRef|null null when it is not a video Bluesky takes
	 */
	public function blobFor(Identity $identity, Document $document): ?BlobRef {
		$existing = $this->blobRequest->getByDocument($identity->did, $document->getId());
		if ($existing !== null) {
			return $existing;
		}
		if (strtolower($document->getMimeType()) !== self::TYPE) {
			return null;
		}
		try {
			$file = $this->cache->getFromUuid($document->getLocalCopy());
			$size = $file->getSize();
			if ($size <= 0 || $size > self::MAX_BYTES) {
				return null;
			}
			$stream = $file->read();
			$hash = hash_init('sha256');
			hash_update_stream($hash, $stream);
			fclose($stream);
			$cid = Cid::forRawDigest(hash_final($hash, true));
		} catch (Throwable $e) {
			$this->logger->notice('Video not readable for Bluesky', ['document' => $document->getId(), 'exception' => $e]);

			return null;
		}

		$blob = new BlobRef($identity->did, $cid, $document->getId(), self::TYPE, (int)$size);
		$this->blobRequest->put($blob);
		$this->repoRequest->addBlobBytes($identity->did, $blob->size);

		return $blob;
	}

	/**
	 * The bytes of a video blob, for `getBlob`, as a stream.
	 *
	 * Checked by size rather than hashed again on every read: the blob was
	 * hashed from this file when it was made, and a stored video is only
	 * ever replaced whole, which changes its size.
	 *
	 * @return array{stream: resource, size: int}
	 * @throws AtprotoException when the document is gone or no longer the blob
	 */
	public function open(BlobRef $blob): array {
		try {
			$document = $this->documents->getDocumentById($blob->documentId);
			$file = $this->cache->getFromUuid($document->getLocalCopy());
			$size = (int)$file->getSize();
			$stream = $size === $blob->size ? $file->read() : false;
		} catch (Throwable $e) {
			throw new AtprotoException('Blob ' . $blob->cid->toString() . ' is not readable', 0, $e);
		}
		if (!is_resource($stream)) {
			throw new AtprotoException('Blob ' . $blob->cid->toString() . ' no longer matches its document');
		}

		return ['stream' => $stream, 'size' => $size];
	}
}
