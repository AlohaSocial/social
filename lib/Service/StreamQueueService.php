<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\Cache;
use OCA\Social\Tools\Model\CacheItem;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class StreamQueueService
 *
 * @package OCA\Social\Service
 */
class StreamQueueService {
	/**
	 * How much thread resolution one inbox delivery does before handing the
	 * rest to the queue.
	 *
	 * A thread is walked by fetching its parents from the servers that hold
	 * them, and this happens while an FPM worker is still held. A handful of
	 * items and a couple of seconds covers the ordinary case — a reply whose
	 * parent is one fetch away — without letting one slow peer hold a worker
	 * for the length of a conversation.
	 */
	private const INLINE_ITEMS = 5;
	private const INLINE_SECONDS = 3;

	/**
	 * Detail stamped on every ancestor fetched for a reply: how many levels up
	 * the climb already is. NoteInterface::save() stops queueing the next parent
	 * once it reaches MAX_ANCESTOR_DEPTH, so a thread of a thousand messages
	 * costs eight fetches, not a thousand. Mastodon bounds the same climb.
	 */
	public const MAX_ANCESTOR_DEPTH = 8;

	/** An item left `running` for longer than this was stranded by a dead drain. */
	public const STALE_RUNNING_SECONDS = 3600;

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamQueueRequest $streamQueueRequest,
		private CacheActorService $cacheActorService,
		private ImportService $importService,
		private CurlService $curlService,
		private MiscService $miscService,
		private LinkPreviewService $linkPreviewService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $token
	 * @param string $type
	 * @param string $streamId
	 */
	public function generateStreamQueue(string $token, string $type, string $streamId) {
		$cache = new StreamQueue($token, $type, $streamId);

		$this->streamQueueRequest->create($cache);
	}

	/**
	 * Queues a post to be fetched from the server that holds it, under the
	 * token of the delivery that named it.
	 *
	 * This is how an activity nobody can vouch for is taken in: the body is
	 * not stored, the object is asked for by its id and only what its own
	 * server answers is kept (see `fetchFromOrigin()`). The column is 255
	 * wide, and an id that does not fit is not queued.
	 *
	 * @return bool whether it was queued
	 */
	public function queueFetch(string $token, string $url): bool {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (($scheme !== 'https' && $scheme !== 'http') || strlen($url) > 255) {
			return false;
		}

		$this->generateStreamQueue($token, StreamQueue::TYPE_FETCH, $url);

		return true;
	}

	/**
	 * @param int $total
	 *
	 * @return StreamQueue[]
	 */
	public function getRequestStandby(int &$total = 0): array {
		$queue = $this->streamQueueRequest->getStandby();
		$total = sizeof($queue);

		$result = [];
		foreach ($queue as $request) {
			$delay = floor(pow($request->getTries(), 4) / 3);
			if ($request->getLast() < (time() - $delay)) {
				$result[] = $request;
			}
		}

		return $result;
	}

	/**
	 * @param string $token
	 */
	public function cacheStreamByToken(string $token) {
		$items = $this->streamQueueRequest->getFromToken($token);
		$deadline = time() + self::INLINE_SECONDS;
		$done = 0;

		foreach ($items as $item) {
			// Bounded, because this runs **inside the inbox request**, after
			// the response has been flushed but while the PHP worker is still
			// held. Each item can be an HTTP fetch of a parent post or an
			// author from a server that may be slow or gone, so an unbounded
			// walk spends the FPM pool waiting for other people's servers —
			// and at a million users' worth of inbound traffic that is the
			// pool. What is left stays queued; the cron and `social:worker`
			// drain it, which is what that queue is for.
			if ($done >= self::INLINE_ITEMS || time() >= $deadline) {
				break;
			}

			$this->manageStreamQueue($item);
			$done++;
		}
	}

	/**
	 * Resolves one queued item, whatever it costs.
	 *
	 * The item is marked `running` before the work starts, and only the
	 * handlers below take it out of that state again. Anything they do not
	 * catch — an ItemAlreadyExistsException from a parent that arrived
	 * meanwhile, a database error, a misconfigured app — left the row
	 * `running` for ever: unlike `social_request_queue` this table has a stale
	 * reaper only since `reapStaleRunning()`, and nothing else ever looks at
	 * a running row. It also took the rest of the batch with it, because the
	 * loops that call this have no per-item handling of their own.
	 *
	 * @param StreamQueue $queue
	 */
	public function manageStreamQueue(StreamQueue $queue) {
		try {
			$this->initCache($queue);
		} catch (QueueStatusException $e) {
			return;
		}

		try {
			switch ($queue->getType()) {
				case StreamQueue::TYPE_CACHE:
					$this->manageStreamQueueCache($queue);
					break;

				case StreamQueue::TYPE_LINK_PREVIEW:
					$this->manageStreamQueueLinkPreview($queue);
					break;

				case StreamQueue::TYPE_FETCH:
					$this->manageStreamQueueFetch($queue);
					break;

				default:
					$this->deleteCache($queue);
					break;
			}
		} catch (Throwable $e) {
			$this->logger->warning('could not resolve a queued item', [
				'streamId' => $queue->getStreamId(),
				'type' => $queue->getType(),
				'exception' => $e,
			]);
			$this->endCache($queue, false);
		}
	}

	/**
	 * Return the items stuck `running` past the cutoff to standby.
	 *
	 * @return int the number of items re-queued
	 */
	public function reapStaleRunning(): int {
		return $this->streamQueueRequest->resetStaleRunning(time() - self::STALE_RUNNING_SECONDS);
	}

	/**
	 * Reads the page a post links to, once. A post without a link, or a page
	 * that cannot be read, ends the queue item for good: retrying would hit
	 * the same wall on every run.
	 */
	private function manageStreamQueueLinkPreview(StreamQueue $queue): void {
		try {
			$stream = $this->streamRequest->getStreamById($queue->getStreamId());
		} catch (StreamNotFoundException $e) {
			$this->deleteCache($queue);

			return;
		}

		$this->linkPreviewService->generate($stream);
		$this->deleteCache($queue);
	}

	/**
	 * Fetches the post a queue item names. A server that could not be reached
	 * is asked again by the queue; an answer that is not an acceptable post
	 * ends the item, because asking again gets the same answer.
	 */
	private function manageStreamQueueFetch(StreamQueue $queue): void {
		try {
			$this->fetchFromOrigin($queue->getStreamId());
		} catch (
			RequestNetworkException
			|RequestResultNotJsonException
			|RequestServerException $e
		) {
			$this->logger->info('could not fetch a post from its origin', [
				'url' => $queue->getStreamId(),
				'exception' => $e,
			]);
			$this->endCache($queue, false);

			return;
		} catch (Throwable $e) {
			$this->logger->info('a post fetched from its origin was not taken in', [
				'url' => $queue->getStreamId(),
				'exception' => $e,
			]);
		}

		$this->deleteCache($queue);
	}

	/**
	 * Takes in a post as the server that holds it serves it.
	 *
	 * The document has to be the one asked for (its id is the URL) and a post,
	 * and its author has to live on the same host — `NoteInterface` checks that
	 * against the origin set here, which is the URL's host and nothing the
	 * delivery said. It then goes through the inbox's own path as a `Create`
	 * of its author, or as an `Update` when a copy is stored and the fetched
	 * one says it was edited since; a copy that has not changed is left alone.
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestServerException
	 */
	private function fetchFromOrigin(string $url): void {
		$data = $this->curlService->retrieveObject($url);
		$object = AP::instance()->getItemFromData($data);
		if ($object->getId() !== $url) {
			throw new InvalidOriginException('the document does not claim the address it came from: ' . $url);
		}

		if (!($object instanceof Stream)
			|| !in_array($object->getType(), array_merge([Note::TYPE, Question::TYPE], AP::NOTE_LIKE_TYPES), true)) {
			throw new InvalidResourceException('not a post: ' . $url);
		}

		$activity = new Create();
		try {
			$stored = $this->streamRequest->getStreamById($url);
			if ($object->getUpdated() === '' || $object->getUpdated() === $stored->getUpdated()) {
				return;
			}
			$activity = new Update();
		} catch (StreamNotFoundException $e) {
		}

		$activity->setId($url);
		$activity->setActorId($object->getAttributedTo());
		$activity->setOrigin((string)parse_url($url, PHP_URL_HOST), SignatureService::ORIGIN_REQUEST, time());
		$activity->setObject($object);

		// its author has to be known before the post can be shown as theirs
		$this->cacheActorService->getFromId($object->getAttributedTo());

		$this->importService->parseIncomingRequest($activity);
	}

	/**
	 * @param StreamQueue $queue
	 */
	private function manageStreamQueueCache(StreamQueue $queue) {
		try {
			$stream = $this->streamRequest->getStreamById($queue->getStreamId());
		} catch (StreamNotFoundException $e) {
			$this->deleteCache($queue);

			return;
		}

		if (!$stream->hasCache()) {
			$this->deleteCache($queue);

			return;
		}

		$this->endCache($queue, $this->manageStreamCache($stream));
	}

	/**
	 * @param Stream $stream
	 *
	 * @return bool
	 * @throws SocialAppConfigException
	 */
	private function manageStreamCache(Stream $stream): bool {
		$cache = $stream->getCache();

		foreach ($cache->getItems() as $item) {
			try {
				$this->cacheItem($stream, $item);
				if ($stream->getType() === Note::TYPE) {
					// a reply only needed its parent fetched; the parent is a row
					// of its own now and nothing reads a copy out of the reply
					$cache->removeItem($item->getUrl());
				} else {
					$item->setStatus(StreamQueue::STATUS_SUCCESS);
					$cache->updateItem($item);
				}
			} catch (
				StreamNotFoundException
				|InvalidOriginException
				|RequestContentException
				|MalformedArrayException
				|RedundancyLimitException
				|InvalidResourceException
				|RequestResultSizeException
				|ItemUnknownException
				|UnauthorizedFediverseException $e
			) {
				// the item itself is the problem — gone, not ours, too big, or a
				// type this app has no model for — so asking again would ask the
				// same question and get the same answer
				$this->logCacheError($item, $e);
				$cache->removeItem($item->getUrl());
			} catch (
				RequestNetworkException
				|RequestResultNotJsonException
				|RequestServerException $e
			) {
				// the other server is the problem, and it may not be tomorrow:
				// counted against the item, which the queue retries until the
				// count runs out
				$this->logCacheError($item, $e);
				$item->incrementError();
			}
		}

		return $this->updateCache($stream, $cache);
	}

	/**
	 * @param CacheItem $item the item that could not be fetched
	 * @param Throwable $e why not
	 */
	private function logCacheError(CacheItem $item, Throwable $e): void {
		$this->miscService->log(
			'Error caching stream: ' . json_encode($item) . ' ' . get_class($e) . ' '
			. $e->getMessage(), 1
		);
	}

	/**
	 * Fetches the object a cache item names, unless it is stored already: the
	 * object of a boost, or the parent of a reply. Either way it is checked like
	 * anything fetched — id equal to the URL asked for, origin the URL's host,
	 * author on that host (NoteInterface::save()) — and saved through the same
	 * interface an inbox delivery goes through.
	 *
	 * @param Stream $stream the stream whose cache wants the item
	 * @param CacheItem $item
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws StreamNotFoundException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	private function cacheItem(Stream $stream, CacheItem &$item) {
		try {
			$note = $this->streamRequest->getStreamById($item->getUrl());
		} catch (StreamNotFoundException $e) {
			$data = $this->curlService->retrieveObject($item->getUrl());
			$object = AP::instance()->getItemFromData($data);

			$origin = parse_url($item->getUrl(), PHP_URL_HOST);
			$object->setOrigin($origin, SignatureService::ORIGIN_REQUEST, time());

			if ($object->getId() !== $item->getUrl()) {
				throw new InvalidOriginException(
					'StreamQueueService::cacheItem - objectId: ' . $object->getId() . ' - itemUrl: '
					. $item->getUrl()
				);
			}

			if ($object->getType() !== Note::TYPE) {
				throw new InvalidResourceException();
			}

			/** @var Stream $object */
			$this->cacheActorService->getFromId($object->getAttributedTo());

			// one level further up than the stream that asked for it; the save
			// below queues the next parent only while this stays under the cap
			$object->setDetailInt(
				Details::ANCESTOR_DEPTH,
				$stream->getDetailInt(Details::ANCESTOR_DEPTH) + 1
			);

			$interface = AP::instance()->getInterfaceForItem($object);
			$interface->save($object);

			$note = $this->streamRequest->getStreamById($object->getId());
			$this->countStoredReplies($note);
		}

		$item->setContent(json_encode($note, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * The replies that arrived before their parent did were stored without
	 * bumping anything — there was no parent to bump. Counted now, the way
	 * NoteInterface::updateDetails() counts them when the parent came first.
	 */
	private function countStoredReplies(Stream $note): void {
		$stored = $this->streamRequest->countRepliesTo($note->getId());
		if ($stored === 0) {
			return;
		}

		$note->setDetailInt(Details::REPLIES, $note->getDetailInt(Details::REMOTE_REPLIES) + $stored);
		$this->streamRequest->updateDetails($note);
	}

	/**
	 * @param Stream $stream
	 * @param Cache $cache
	 *
	 * @return bool
	 */
	private function updateCache(Stream $stream, Cache $cache): bool {
		$this->streamRequest->updateCache($stream, $cache);
		try {
			$interface = AP::instance()->getInterfaceForItem($stream);
			$interface->event($stream, 'updateCache');
		} catch (ItemUnknownException $e) {
		}

		$done = true;
		foreach ($cache->getItems() as $item) {
			if ($item->getStatus() !== StreamQueue::STATUS_SUCCESS) {
				$done = false;
			}
		}

		return $done;
	}

	/**
	 * @param StreamQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	private function initCache(StreamQueue $queue) {
		$this->streamQueueRequest->setAsRunning($queue);
	}

	/**
	 * @param StreamQueue $queue
	 * @param bool $success
	 */
	private function endCache(StreamQueue $queue, bool $success) {
		try {
			if ($success === true) {
				$this->streamQueueRequest->setAsSuccess($queue);
			} else {
				$this->streamQueueRequest->setAsFailure($queue);
			}
		} catch (QueueStatusException $e) {
		}
	}

	/**
	 * @param StreamQueue $queue
	 */
	private function deleteCache(StreamQueue $queue) {
		$this->streamQueueRequest->delete($queue);
	}
}
