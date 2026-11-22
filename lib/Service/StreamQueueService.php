<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Db\HostBreakerRequest;
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

class StreamQueueService {
	/**
	 * How much thread resolution one inbox delivery does before handing the
	 * rest to the queue: enough for a parent one fetch away, without letting
	 * a slow peer hold an FPM worker for a whole conversation.
	 */
	private const INLINE_ITEMS = 5;
	private const INLINE_SECONDS = 3;

	/**
	 * Detail stamped on every ancestor fetched for a reply: how many levels up
	 * the climb already is. NoteInterface::save() stops queueing parents at
	 * MAX_ANCESTOR_DEPTH, as Mastodon bounds the same climb.
	 */
	public const MAX_ANCESTOR_DEPTH = 8;

	/** An item left `running` for longer than this was stranded by a dead drain. */
	public const STALE_RUNNING_SECONDS = 3600;

	/**
	 * The breaker deliveries keep on dead hosts, consulted before a fetch so an
	 * unreachable server does not cost a timeout per item on every pass.
	 */
	private HostBreaker $breaker;

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamQueueRequest $streamQueueRequest,
		private CacheActorService $cacheActorService,
		private ImportService $importService,
		private CurlService $curlService,
		private MiscService $miscService,
		private LinkPreviewService $linkPreviewService,
		private LoggerInterface $logger,
		HostBreakerRequest $hostBreakerRequest,
		private ?RemoteMediaPrefetcher $mediaPrefetcher = null,
	) {
		$this->breaker = new HostBreaker($hostBreakerRequest);
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
	 * token of the delivery that named it. This is how an activity nobody can
	 * vouch for is taken in: only what the object's own server answers is
	 * kept (see `fetchFromOrigin()`). An id wider than the 255-character
	 * column is not queued.
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
	 * Queues the attachments of a stored post for their files to be fetched
	 * in the background (`RemoteMediaPrefetcher`), under the token of the
	 * delivery that brought it. A post id wider than the column is not queued;
	 * the sweep in `Cron\Cache` still finds its documents.
	 *
	 * @return bool whether it was queued
	 */
	public function queueMedia(string $token, string $streamId): bool {
		if ($streamId === '' || strlen($streamId) > 255) {
			return false;
		}

		$this->generateStreamQueue($token, StreamQueue::TYPE_MEDIA, $streamId);

		return true;
	}

	/**
	 * Fetches a post from the server that holds it, now, for a job that is
	 * itself in the background (`ActivityPubThreadSource`): what its own
	 * server answers, taken in as `fetchFromOrigin()` takes it.
	 *
	 * @throws Throwable the server could not be reached, is held back, or did not answer with a post
	 */
	public function fetchNow(string $url, ?int $timeout = null): void {
		$openUntil = $this->breaker->openUntil(self::hostOf($url));
		if ($openUntil > 0) {
			throw new RequestNetworkException('held back until ' . $openUntil . ': ' . $url);
		}
		try {
			$this->fetchFromOrigin($url, $timeout);
		} catch (RequestNetworkException|RequestResultNotJsonException|RequestServerException $e) {
			$this->holdHost($url, $e);

			throw $e;
		}
	}

	/**
	 * The items that are due. The backoff and the give-up threshold are the
	 * query's (`StreamQueueRequest::getStandby()`).
	 *
	 * @param int $total
	 *
	 * @return StreamQueue[]
	 */
	public function getRequestStandby(int &$total = 0): array {
		// a batch is a drain: what the breaker says is read again for it
		$this->breaker->reset();
		$queue = $this->streamQueueRequest->getStandby();
		$total = sizeof($queue);

		return $queue;
	}

	/**
	 * @param string $token
	 */
	public function cacheStreamByToken(string $token) {
		$items = $this->streamQueueRequest->getFromToken($token);
		$deadline = time() + self::INLINE_SECONDS;
		$done = 0;

		foreach ($items as $item) {
			// the inbox has just tried these files itself; what it could not
			// finish is for the background drain, with its longer timeout
			if ($item->getType() === StreamQueue::TYPE_MEDIA) {
				continue;
			}

			// bounded, because this runs inside the inbox request while the PHP
			// worker is still held; the rest stays queued for the cron and
			// `social:worker`
			if ($done >= self::INLINE_ITEMS || time() >= $deadline) {
				break;
			}

			$this->manageStreamQueue($item);
			$done++;
		}
	}

	/**
	 * Resolves one queued item, whatever it costs. The item is marked
	 * `running` first; anything the handlers do not catch is caught here so
	 * the row is not left `running` and the rest of the batch goes on.
	 *
	 * @param StreamQueue $queue
	 */
	public function manageStreamQueue(StreamQueue $queue) {
		if ($queue->getType() === StreamQueue::TYPE_FETCH) {
			$openUntil = $this->breaker->openUntil(self::hostOf($queue->getStreamId()));
			if ($openUntil > 0) {
				// held back until the breaker closes, without spending a try
				$this->postponeCache($queue, $openUntil);

				return;
			}
		}

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

				case StreamQueue::TYPE_MEDIA:
					$this->manageStreamQueueMedia($queue);
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
	 * Fetches the files of a stored post's attachments
	 * (`RemoteMediaPrefetcher::prefetchPost()`). Done when none waits any more;
	 * once the queue gives up, the sweep in `Cron\Cache` still finds them.
	 */
	private function manageStreamQueueMedia(StreamQueue $queue): void {
		if ($this->mediaPrefetcher === null) {
			$this->deleteCache($queue);

			return;
		}

		$waiting = $this->mediaPrefetcher->prefetchPost(
			$queue->getStreamId(), time() + RemoteMediaPrefetcher::POST_SECONDS
		);
		if ($waiting === 0) {
			$this->deleteCache($queue);

			return;
		}

		$this->endCache($queue, false);
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
			$this->holdHost($queue->getStreamId(), $e);
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
	 * Takes in a post as the server that holds it serves it. Its id must be
	 * the URL asked for and its author on the URL's host (`NoteInterface`
	 * checks against the origin set here). It then goes through the inbox's
	 * path as a `Create`, or as an `Update` when a stored copy is older.
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestServerException
	 */
	private function fetchFromOrigin(string $url, ?int $timeout = null): void {
		$started = hrtime(true);
		$data = $timeout === null
			? $this->curlService->retrieveObject($url)
			: $this->curlService->retrieveObject($url, true, $timeout);
		$this->breaker->close(self::hostOf($url));
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
		$activity->setOrigin(ConfigService::authorityOf($url), SignatureService::ORIGIN_REQUEST, time());
		$activity->setObject($object);

		// its author has to be known before the post can be shown as theirs
		if ($timeout === null) {
			$this->cacheActorService->getFromId($object->getAttributedTo());
		} else {
			$remaining = $timeout - (int)ceil((hrtime(true) - $started) / 1_000_000_000);
			if ($remaining < 1) {
				throw new \RuntimeException('the visible post fetch exceeded its time budget: ' . $url);
			}
			$this->cacheActorService->getActivityPubActorWithTimeout($object->getAttributedTo(), $remaining);
		}

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
				if (!$this->cacheItem($stream, $item)) {
					// its server is behind the breaker: not attempted, so
					// not counted against the item either
					continue;
				}
				if ($stream->getType() === Note::TYPE) {
					// a reply only needed its parent fetched, which is now a row
					// of its own
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
				// the item itself is the problem (gone, not ours, too big, an
				// unknown type), so asking again would get the same answer
				$this->logCacheError($item, $e);
				$cache->removeItem($item->getUrl());
			} catch (
				RequestNetworkException
				|RequestResultNotJsonException
				|RequestServerException $e
			) {
				// the other server is the problem, which may pass: counted
				// against the item, which the queue retries
				$this->holdHost($item->getUrl(), $e);
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
	 * Fetches the object a cache item names (a boost's object or a reply's
	 * parent) unless it is stored already, checked like anything fetched and
	 * saved through the same interface an inbox delivery goes through.
	 *
	 * @param Stream $stream the stream whose cache wants the item
	 * @param CacheItem $item
	 *
	 * @return bool false when the item's server is behind the breaker and was
	 *              not asked
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
	private function cacheItem(Stream $stream, CacheItem &$item): bool {
		try {
			$note = $this->streamRequest->getStreamById($item->getUrl());
		} catch (StreamNotFoundException $e) {
			$host = self::hostOf($item->getUrl());
			if ($this->breaker->openUntil($host) > 0) {
				return false;
			}

			$data = $this->curlService->retrieveObject($item->getUrl());
			$this->breaker->close($host);
			$object = AP::instance()->getItemFromData($data);

			$object->setOrigin(ConfigService::authorityOf($item->getUrl()), SignatureService::ORIGIN_REQUEST, time());

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

		return true;
	}

	/**
	 * Puts the server of a fetch that failed behind the breaker — unless it
	 * answered: a reply that is not JSON is a server that is up.
	 */
	private function holdHost(string $url, Throwable $e): void {
		if ($e instanceof RequestNetworkException || $e instanceof RequestServerException) {
			$this->breaker->open(self::hostOf($url));
		}
	}

	private static function hostOf(string $url): string {
		return (string)parse_url($url, PHP_URL_HOST);
	}

	/**
	 * Counts the replies that arrived before their parent, as
	 * NoteInterface::updateDetails() does when the parent came first.
	 */
	private function countStoredReplies(Stream $note): void {
		$this->streamRequest->recount($note, Details::REPLIES);
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

	/** Holds a standby item back until `$until`; one taken meanwhile is left alone. */
	private function postponeCache(StreamQueue $queue, int $until): void {
		try {
			$this->streamQueueRequest->postpone($queue, $until);
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
