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
use OCA\Social\Service\ConfigService;
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
		protected ConfigService $configService,
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
	public function save(ACore $item, bool $deferDownload = false): void {
		/** @var Document $item */
		if (!$item->isRoot()) {
			$item->setParentId(
				$item->getParent()
					->getId()
			);
		}

		try {
			$known = $this->cacheDocumentsRequest->getById($item->getId());
			if ($this->mayRewrite($item, $known)) {
				$this->keepWhatOnlyTheRowKnows($item, $known);
				$this->cacheDocumentsRequest->update($item);

				return;
			}

			$this->takeFreshIdentity($item, $known);
		} catch (CacheDocumentDoesNotExistException) {
			// not stored under that id: a new document, or one without a wire
			// id of its own, which the lookup below finds
		}

		// An attachment without a wire id gets a fresh id on every import, so
		// its row is found by URL and parent before any download; otherwise a
		// retry orphans bytes and points the post at a media id that does not exist.
		$addressed = ($item->getUrl() !== '' && $item->getParentId() !== '');
		if ($addressed) {
			try {
				$known = $this->cacheDocumentsRequest->getByUrlAndParent($item->getUrl(), $item->getParentId());
				if ($this->mayRewrite($item, $known, true)) {
					$item->setId($known->getId());
					$this->keepWhatOnlyTheRowKnows($item, $known);
					// written back, as the id path above does: the sender
					// may have added a description, a blurhash or a
					// corrected type since the row was made
					$this->cacheDocumentsRequest->update($item);

					return;
				}

				$this->takeFreshIdentity($item, $known);
			} catch (CacheDocumentDoesNotExistException) {
				// that lookup is the whole of isDuplicate(), and it missed:
				// this document is new, and asking again below would be the
				// same query with the same answer
			}
		}

		// a streamed document is a pointer at somebody else's file and
		// stays one -- see Document::COPY_STREAMED. Fetching it is the one
		// thing that must not happen here.
		if (!$deferDownload && !$item->isLocal() && !$item->isStreamed()) {
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

	/**
	 * Fetches the file a remote document names, and writes the row either
	 * way. A failed download must not drop the post: the row is written with
	 * an empty `local_copy`, which `getNotCachedDocuments()` retries. A
	 * failure that would fail again the same way is marked so the cron stops
	 * offering it, as `DocumentService::cacheRemoteDocument()` decides.
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

		// `/media/{uuid}` serves these bytes from this origin, so the declared
		// type must be what the bytes are: `text/html` over a GIF must not be
		// served back as a page.
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
	 * Whether a document that came in from elsewhere may be written over a
	 * row already stored. An attachment keeps the id it arrived with, which
	 * `save()` updates by, so a peer naming somebody else's row would rewrite
	 * it.
	 *
	 * A row may only be rewritten by the post it hangs off (a redelivery, an
	 * `Update`) or by the server whose id it carries, and only through a
	 * request whose checked origin is the server of that post. A row of this
	 * instance's own (owned by a local account, hanging off a local post, or
	 * named on this host) is never rewritten from outside.
	 *
	 * @param bool $byUrl the row was found by url and parent rather than by
	 *                    the incoming id, so it hangs off the same post and
	 *                    its id is one this instance gave it
	 */
	private function mayRewrite(Document $item, Document $known, bool $byUrl = false): bool {
		if ($item->isLocal()) {
			return true;
		}

		$here = strtolower($this->configService->getCloudHost());
		if ($known->getAccount() !== '' || self::hostOf($known->getParentId()) === $here) {
			return false;
		}

		// what came through a checked request may only rewrite rows of the
		// server it came from: naming another server's post as its own
		// object does not make that post's media the sender's to change
		$origin = strtolower($item->getRoot()->getOrigin());
		if ($origin !== '' && self::hostOf($known->getParentId()) !== $origin) {
			return false;
		}

		if ($byUrl) {
			return true;
		}

		$host = self::hostOf($item->getId());
		if ($host === '' || $host === $here) {
			return false;
		}

		if ($known->getParentId() === $item->getParentId()) {
			return true;
		}

		return self::hostOf($known->getParentId()) === $host
			&& self::hostOf($item->getParentId()) === $host;
	}

	/**
	 * Gives an incoming document that may not touch the row it named an id
	 * of its own, as `Document::import()` does for one that arrived without
	 * any: it is then stored as the new document it is, and the row it named
	 * is left as it was.
	 */
	private function takeFreshIdentity(Document $item, Document $known): void {
		$this->logger->warning('an incoming document named a stored row it does not own', [
			'document' => $item->getId(),
			'parent' => $item->getParentId(),
			'row parent' => $known->getParentId(),
		]);

		if ($item->getUrlCloud() === '') {
			$item->setUrlCloud($this->configService->getCloudUrl());
		}
		$item->generateUniqueId('/documents/g');
	}

	private static function hostOf(string $id): string {
		$host = parse_url($id, PHP_URL_HOST);

		return is_string($host) ? strtolower($host) : '';
	}

	/**
	 * Moves onto an incoming document what only the stored row knows: its key
	 * and where its bytes were put. A redelivered document knows nothing of
	 * this instance's copy and would clear `local_copy` and `resized_copy`, and
	 * without the key a client would get `id: 0`.
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
