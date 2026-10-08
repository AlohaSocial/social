<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\DocumentService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The picture of a link card (§8.3), as the blob an external embed's
 * `thumb` names. The picture is the page's own preview image, which the
 * reader's browser otherwise loads from the page's server: here it is
 * fetched once, as any remote document is — through the same guards and
 * size limits — kept as a cached document, and made to fit what Bluesky
 * takes for a card.
 */
class CardThumbnail {
	/** what the lexicon lets an external card's thumbnail weigh */
	public const MAX_BYTES = 1000000;

	public function __construct(
		private CacheDocumentsRequest $cacheDocuments,
		private DocumentInterface $documentInterface,
		private DocumentService $documents,
		private PictureService $pictures,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The blob for a card's picture, or null when it has none or it cannot
	 * be had; the card is published without one then.
	 */
	public function blobFor(Identity $identity, Person $owner, StreamCard $card): ?BlobRef {
		$url = trim($card->getImage());
		if (!Stream::isExternalLink($url)) {
			return null;
		}
		try {
			$document = $this->cachedOrFetched($url, $card->getStreamId());
			if ($document === null || $document->getError() > 0 || $document->getLocalCopy() === '') {
				return null;
			}

			return $this->pictures->blobFor($identity, $owner, $document, self::MAX_BYTES)['blob'] ?? null;
		} catch (Throwable $e) {
			$this->logger->info('Link card picture not made for Bluesky', ['url' => $url, 'exception' => $e]);

			return null;
		}
	}

	/**
	 * The cached copy of a picture, fetched the first time. A fetch that
	 * failed for good is kept as one; one that may work later is tried again
	 * as the cache tries any, not on every publication.
	 */
	private function cachedOrFetched(string $url, string $streamId): ?Document {
		try {
			$known = $this->cacheDocuments->getByUrl($url);

			return $known->getLocalCopy() === '' && $known->getError() === 0
				? $this->documents->cacheRemoteDocument($known->getId(), true)
				: $known;
		} catch (CacheDocumentDoesNotExistException) {
		}
		$document = new Document();
		$document->setId($url);
		$document->setUrl($url);
		$document->setMediaType('image/jpeg');
		$document->setParentId($streamId);
		$document->setLocal(false);
		$document->setPublic(true);
		$this->documentInterface->save($document);
		try {
			return $this->cacheDocuments->getByUrl($url);
		} catch (CacheDocumentDoesNotExistException) {
			return null;
		}
	}
}
