<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class DocumentInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		protected CacheDocumentService $cacheDocumentService,
		protected CacheDocumentsRequest $cacheDocumentsRequest,
		protected LoggerInterface $logger = new NullLogger(),
	) {
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function activity(Acore $activity, ACore $item): void {
		if ($activity->getType() === Person::TYPE) {
			$activity->checkOrigin($item->getId());
		}
	}

	#[\Override]
	public function save(ACore $item): void {
		/** @var Document $item */
		if (!$item->isRoot()) {
			$item->setParentId(
				$item->getParent()
					->getId()
			);
		}

		try {
			$known = $this->cacheDocumentsRequest->getById($item->getId());
			$this->keepWhatOnlyTheRowKnows($item, $known);
			$this->cacheDocumentsRequest->update($item);
		} catch (CacheDocumentDoesNotExistException $e) {
			// An attachment without a wire id receives a fresh generated id on
			// every import. Find its existing row by URL and parent before any
			// download, otherwise a retry creates orphaned bytes and points the
			// post at a media id that does not exist.
			$addressed = ($item->getUrl() !== '' && $item->getParentId() !== '');
			if ($addressed) {
				try {
					$known = $this->cacheDocumentsRequest->getByUrlAndParent($item->getUrl(), $item->getParentId());
					$item->setId($known->getId());
					$this->keepWhatOnlyTheRowKnows($item, $known);
					// and written, as the id path above writes it. The sender
					// may have added a description, a blurhash or a corrected
					// type since the row was made; without this the merge lived
					// in the object this request happened to hold and the row
					// kept what it first heard, for good.
					$this->cacheDocumentsRequest->update($item);

					return;
				} catch (CacheDocumentDoesNotExistException) {
					// that lookup is the whole of isDuplicate(), and it missed:
					// this document is new, and asking again below would be the
					// same query with the same answer
				}
			}

			// a streamed document is a pointer at somebody else's file and
			// stays one -- see Document::COPY_STREAMED. Fetching it is the one
			// thing that must not happen here.
			if (!$item->isLocal() && !$item->isStreamed()) {
				$this->fetch($item);
			}

			// parentId / url can only be empty on new document, meaning owner cannot be empty here
			$isNew = $addressed
				|| ($item->getUrl() === '' && $item->getParentId() === '' && $item->getAccount() !== '')
				|| !$this->cacheDocumentsRequest->isDuplicate($item);
			if ($isNew) {
				$this->cacheDocumentsRequest->save($item);
			}
		}
	}

	/**
	 * Fetches the file a remote document names, and writes the row either way.
	 *
	 * The download used to happen with nothing around it, so any of the five
	 * ways it can fail -- the origin answering 403, the host being down, the
	 * file being larger than this instance stores, a type it does not store,
	 * bytes that will not decode -- came out of `save()` and out of
	 * `Stream::import()` with it. The whole post was then dropped: four
	 * pictures and one CDN object briefly answering 503 meant the post was
	 * never stored, and because no row was written there was nothing for the
	 * caching cron to retry either. So a post survives its attachments now,
	 * and the row is written with an empty `local_copy`, which is exactly what
	 * `getNotCachedDocuments()` looks for.
	 *
	 * A failure that will fail again the same way is marked so the cron stops
	 * offering it: `DocumentService::cacheRemoteDocument()` decides the same
	 * thing for the rows it handles, and the two agree on purpose.
	 */
	private function fetch(Document $item): void {
		$mime = '';

		try {
			$this->cacheDocumentService->saveRemoteFileToCache($item, $mime);
		} catch (Throwable $e) {
			$item->setLocalCopy('');
			$item->setResizedCopy('');
			$item->setError($this->errorOf($e));
			$this->logger->warning('could not fetch an attachment', [
				'document' => $item->getId(), 'url' => $item->getUrl(), 'exception' => $e,
			]);

			return;
		}

		// The type a peer declared describes bytes this instance has now read
		// for itself, and `/media/{uuid}` serves them from this origin: what it
		// states they are has to be what they are. A peer that declares
		// `text/html` over a file whose bytes sniff as a GIF used to have that
		// served back, as a page, from here.
		if ($mime !== '') {
			$item->setMimeType($mime);
			$item->setMediaType($mime);
		}
	}

	/**
	 * Which of `DocumentService`'s markers a failed fetch deserves, or 0 for
	 * one worth trying again.
	 */
	private function errorOf(Throwable $failure): int {
		return match (true) {
			$failure instanceof CacheContentMimeTypeException => DocumentService::ERROR_MIMETYPE,
			$failure instanceof CacheContentDecodeException => DocumentService::ERROR_CONTENT,
			$failure instanceof CacheContentSizeException,
			$failure instanceof RequestResultSizeException => DocumentService::ERROR_SIZE,
			$failure instanceof NotFoundException,
			$failure instanceof NotPermittedException => DocumentService::ERROR_PERMISSION,
			// the rest is the other end being slow, down or briefly unhappy,
			// which is what the caching cron exists to come back to
			default => 0,
		};
	}

	/**
	 * Moves onto an incoming document the two things that exist only in the
	 * stored row: its key, and where its bytes were put.
	 *
	 * A document arriving off the wire a second time -- a redelivery, an
	 * `Update` of the post it hangs off -- describes a file on somebody else's
	 * server and knows nothing about the copy this instance made of it. Written
	 * as it arrived, it *cleared* `local_copy` and `resized_copy`: the cached
	 * file was orphaned and every post showing that picture broke until the
	 * caching cron happened to fetch it again. The row is the only thing that
	 * knows, so the row is asked.
	 *
	 * The key has the same shape of problem. Without it a re-imported
	 * attachment went back to a client with `id: 0`, and a streamed one would
	 * name row zero to the media proxy -- some other video, or nothing at all.
	 */
	private function keepWhatOnlyTheRowKnows(Document $item, Document $known): void {
		$item->setNid($known->getNid());

		if ($item->getLocalCopy() === '') {
			$item->setLocalCopy($known->getLocalCopy());
		}

		if ($item->getResizedCopy() === '') {
			$item->setResizedCopy($known->getResizedCopy());
		}

		// provenance is only ever read off the bytes, and the wire carries
		// none: whatever the row learnt when the copy was made stands
		if ($item->getAiSource() === Document::AI_SOURCE_NONE) {
			$item->setAiSource($known->getAiSource());
		}
	}
}
