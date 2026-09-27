<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Db\FeedsRequest;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Following something that is not on the fediverse.
 *
 * A blog, a YouTube channel, a newsroom: things that publish a feed and will
 * never speak ActivityPub. What comes out is shown with a link to where it is.
 * **Nothing here is a post.** It is not attributed to anybody on this server,
 * it cannot be boosted or replied to, and it never federates — which is why it
 * lives in tables of its own rather than in `social_stream`.
 */
class SubscriptionService {
	/** How long one feed read may take. */
	private const TIMEOUT = 30;

	/** A first read belongs to the follow request, so keep its wait bounded. */
	private const FIRST_READ_TIMEOUT = 10;

	/** The biggest feed worth reading. */
	private const MAX_BYTES = 4 * 1024 * 1024;

	/**
	 * How long one pass of the job may spend reading feeds, in seconds.
	 *
	 * A time budget, not a count: twenty feeds a pass, one after another, was
	 * 80 feeds an hour for the whole instance — the hourly re-read held for
	 * one reader with a modest list and nobody else. Well inside the job's
	 * fifteen-minute interval, with a read's own timeout to spare.
	 */
	public const PASS_SECONDS = 240;

	/**
	 * How many feeds are read at once. They have nothing to do with each
	 * other, and read one after another the slowest set the pace for all.
	 */
	public const PARALLEL = 10;

	/** How often a feed is re-read. */
	public const INTERVAL = 3600;

	/** A failed first read should get another chance on the next cron pass. */
	public const FAILURE_RETRY = 15 * 60;

	/** How many feeds one account may follow. */
	public const MAX_FEEDS = 200;

	/**
	 * How many entries one feed keeps, and for how long.
	 *
	 * A feed document carries its latest hundred entries at most
	 * (`FeedParserService::MAX_ITEMS`), and every read adds what is new: a
	 * newsroom publishing all day grew its rows without bound. What is past
	 * either limit is dropped after each read, and an entry older than the age
	 * limit is not stored in the first place — it would be deleted again on
	 * every read of a document that still lists it.
	 */
	public const KEEP_ITEMS = 500;
	public const KEEP_DAYS = 180;

	public function __construct(
		private FeedsRequest $feedsRequest,
		private FeedDiscoveryService $discoveryService,
		private FeedParserService $parserService,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What this reader follows, with how much each has published.
	 *
	 * @return array<array<string, mixed>>
	 */
	public function feeds(string $userId): array {
		$rows = $this->feedsRequest->feedsOf($userId);
		$counts = $this->feedsRequest->countsFor(array_map(static fn (array $r): int => (int)$r['id'], $rows));

		$feeds = [];
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			$feeds[] = [
				'id' => $id,
				'title' => ($row['title'] ?? '') !== '' ? $row['title'] : $row['url'],
				// Feed metadata is remote input too. Keep only web links before
				// the page puts this value in an anchor's href.
				'site_url' => $this->safeExternalLink((string)($row['site_url'] ?? '')),
				'items' => $counts[$id] ?? 0,
				'error' => (string)($row['error'] ?? ''),
				// a feed with nothing in it means one thing before its first
				// read and another after, and the page says so either way
				'read' => ($row['fetched_at'] ?? null) !== null,
			];
		}

		return $feeds;
	}

	/**
	 * Follows whatever somebody pasted.
	 *
	 * The address is resolved first — a page is read for the feed it declares,
	 * a YouTube channel for the one it has — so what is stored is the feed
	 * rather than what was typed. Following the same thing twice is one
	 * subscription.
	 *
	 * Read a newly followed feed once before answering so its first entries are
	 * visible immediately. The shorter timeout keeps that request bounded; the
	 * cron remains responsible for later refreshes and retries after failures.
	 *
	 * @throws InvalidArgumentException nothing there, or too many already
	 */
	public function follow(string $userId, string $input): array {
		$url = $this->discoveryService->discover($input);

		$id = $this->feedsRequest->idOf($userId, $url);
		if ($id !== 0) {
			return ['id' => $id, 'url' => $url];
		}

		if (count($this->feedsRequest->feedsOf($userId)) >= self::MAX_FEEDS) {
			throw new InvalidArgumentException('that is as many feeds as one account may follow');
		}

		$id = $this->feedsRequest->create($userId, $url, '', '');
		$this->refresh([
			'id' => $id,
			'url' => $url,
			'etag' => '',
			'modified_at' => '',
		], self::FIRST_READ_TIMEOUT);

		return ['id' => $id, 'url' => $url];
	}

	public function unfollow(string $userId, int $id): bool {
		return $this->feedsRequest->delete($userId, $id);
	}

	/**
	 * A page of what the reader's feeds have published.
	 *
	 * @return array<array<string, mixed>>
	 */
	public function timeline(string $userId, int $limit, int $maxId, string $before = '', int $beforeId = 0): array {
		$items = [];
		foreach ($this->feedsRequest->timelineOf($userId, max(1, min($limit, 100)), $maxId, $before, $beforeId) as $row) {
			$link = $this->safeExternalLink((string)($row['link'] ?? ''));
			$published = (string)($row['published'] ?? '');
			$items[] = [
				'id' => (int)$row['id'],
				'link' => $link,
				'video_id' => $this->youtubeVideoId($link),
				'title' => (string)($row['title'] ?? ''),
				'summary' => (string)($row['summary'] ?? ''),
				'thumbnail' => (string)($row['thumbnail'] ?? ''),
				'feed_title' => (string)($row['feed_title'] ?? ''),
				// The cursor carries the stored wall-clock value exactly. The
				// display timestamp is UTC and may differ across server time zones.
				'cursor' => $published,
				'published' => $this->asDate($published),
			];
		}

		return $items;
	}

	/** A feed-provided link is clickable only when it is a complete web URL. */
	private function safeExternalLink(string $link): string {
		$parts = parse_url($link);
		if (!is_array($parts)
			|| !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
			|| ($parts['host'] ?? '') === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])) {
			return '';
		}

		return $link;
	}

	/** The ID of a YouTube video link, or empty for other hosts and invalid IDs. */
	private function youtubeVideoId(string $link): string {
		$parts = parse_url($link);
		if (!is_array($parts)) {
			return '';
		}

		$host = strtolower($parts['host'] ?? '');
		if (!in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'], true)) {
			return '';
		}

		$id = '';
		if ($host === 'youtu.be') {
			$id = trim($parts['path'] ?? '', '/');
		} elseif (($parts['path'] ?? '') === '/watch') {
			parse_str($parts['query'] ?? '', $query);
			$id = is_string($query['v'] ?? null) ? $query['v'] : '';
		} elseif (preg_match('#^/(?:shorts|embed|live)/([^/?]+)#', $parts['path'] ?? '', $matches) === 1) {
			$id = $matches[1] ?? '';
		}

		return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? $id : '';
	}

	/**
	 * Re-reads one feed and stores whatever is new.
	 *
	 * A conditional request, so a feed that has not changed since the last
	 * read costs a 304 rather than the file. Whatever goes wrong is written to
	 * the row rather than thrown: a feed that has gone should say so on the
	 * page, not stop the others being read.
	 *
	 * @param array<string, mixed> $feed id, url, and the last read's validators
	 *
	 * @return int how many entries were new
	 */
	public function refresh(array $feed, ?int $timeout = null): int {
		try {
			$response = $this->clientService->newClient()->get((string)$feed['url'], $this->requestOptions($feed, $timeout));
		} catch (Throwable $e) {
			return $this->unreadable($feed, $e);
		}

		return $this->absorb($feed, $response);
	}

	/**
	 * Re-reads the feeds that are due, stalest first, until the pass's time
	 * is up or nothing is due. What the cron does.
	 *
	 * `PARALLEL` at a time: a batch costs about as long as its slowest feed,
	 * so a pass reads some thousands of feeds when they answer in a second or
	 * two, and still `PASS_SECONDS / TIMEOUT * PARALLEL` (80) when every batch
	 * holds one that times out — against twenty a pass, in sequence, before.
	 *
	 * @param int $deadline when the pass must stop, or 0 for `PASS_SECONDS` from now
	 *
	 * @return int how many entries arrived
	 */
	public function refreshDue(int $deadline = 0): int {
		$deadline = ($deadline > 0) ? $deadline : time() + self::PASS_SECONDS;
		$added = 0;
		$seen = [];

		while (time() < $deadline) {
			$batch = [];
			$now = time();
			foreach ($this->feedsRequest->due(
				self::PARALLEL,
				$now - self::INTERVAL,
				$now - self::FAILURE_RETRY
			) as $feed) {
				// a read whose outcome could not be recorded would be due
				// again at once; once a pass is enough
				if (!isset($seen[(int)$feed['id']])) {
					$seen[(int)$feed['id']] = true;
					$batch[] = $feed;
				}
			}

			if ($batch === []) {
				break;
			}

			$added += $this->refreshBatch($batch);
		}

		return $added;
	}

	/**
	 * Reads a batch of feeds concurrently and stores what each had.
	 *
	 * @param array<array<string, mixed>> $feeds
	 */
	private function refreshBatch(array $feeds, ?int $timeout = null): int {
		$client = $this->clientService->newClient();

		$promises = [];
		$added = 0;
		foreach ($feeds as $i => $feed) {
			try {
				$promises[$i] = $client->getAsync((string)$feed['url'], $this->requestOptions($feed, $timeout));
			} catch (Throwable $e) {
				$added += $this->unreadable($feed, $e);
			}
		}

		foreach ($promises as $i => $promise) {
			try {
				$response = $promise->wait();
			} catch (Throwable $e) {
				$added += $this->unreadable($feeds[$i], $e);

				continue;
			}

			$added += ($response instanceof IResponse)
				? $this->absorb($feeds[$i], $response)
				: $this->unreadable($feeds[$i], null);
		}

		return $added;
	}

	/**
	 * @param array<string, mixed> $feed
	 *
	 * @return array<string, mixed>
	 */
	private function requestOptions(array $feed, ?int $timeout = null): array {
		$headers = ['Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5'];
		if ((string)($feed['etag'] ?? '') !== '') {
			$headers['If-None-Match'] = (string)$feed['etag'];
		}
		if ((string)($feed['modified_at'] ?? '') !== '') {
			$headers['If-Modified-Since'] = (string)$feed['modified_at'];
		}

		return [
			'timeout' => $timeout ?? self::TIMEOUT,
			'headers' => $headers,
			// read as it arrives, so the ceiling below is a ceiling on
			// memory and not on a string already in it
			'stream' => true,
		];
	}

	/** @param array<string, mixed> $feed */
	private function unreadable(array $feed, ?Throwable $e): int {
		$this->logger->debug('a followed feed could not be read', ['feed' => (string)$feed['url'], 'exception' => $e]);
		$this->feedsRequest->recordRead((int)$feed['id'], '', '', '', '', 'could not be read');

		return 0;
	}

	/**
	 * Stores what one read of a feed brought.
	 *
	 * @param array<string, mixed> $feed
	 *
	 * @return int how many entries were new
	 */
	private function absorb(array $feed, IResponse $response): int {
		$id = (int)$feed['id'];

		if ($response->getStatusCode() === 304) {
			$this->feedsRequest->recordRead($id, '', '', (string)$feed['etag'], (string)$feed['modified_at'], '');

			return 0;
		}

		$body = CurlService::readAtMost($response, self::MAX_BYTES);
		if (strlen($body) > self::MAX_BYTES) {
			$this->feedsRequest->recordRead($id, '', '', '', '', 'too large to read');

			return 0;
		}

		try {
			$read = $this->parserService->parse($body);
		} catch (InvalidArgumentException $e) {
			$this->feedsRequest->recordRead($id, '', '', '', '', $e->getMessage());

			return 0;
		}

		// Everything the feed offers is stored, however old. The age was a
		// filter here as well as in the prune, and between them a feed that
		// had not published inside `KEEP_DAYS` was emptied on arrival: a blog
		// whose last post is from 2023 read cleanly, parsed to a hundred
		// entries, stored none of them and showed "0 entries" with no error
		// against it. What bounds a feed is `KEEP_ITEMS`, which the prune
		// applies, and the prune keeps that many whatever their age.
		$tooOld = time() - self::KEEP_DAYS * 86400;
		$added = 0;
		foreach ($read['items'] as $item) {
			if ($this->feedsRequest->addItem($id, $item)) {
				$added++;
			}
		}

		if ($added > 0) {
			$this->feedsRequest->prune($id, self::KEEP_ITEMS, $tooOld);
		}

		$this->feedsRequest->recordRead(
			$id,
			$read['title'],
			$read['site_url'],
			$response->getHeader('ETag'),
			$response->getHeader('Last-Modified'),
			''
		);

		return $added;
	}

	/**
	 * Follows every channel in a YouTube takeout.
	 *
	 * `subscriptions.csv` names one channel a line with its id in the first
	 * column, which is the one form a feed address can be built from without
	 * asking YouTube anything. New channels get an immediate first read in
	 * parallel, within one short time budget; the cron reads any remainder.
	 * Reading all two hundred sequentially inside the upload request used to
	 * run past `max_execution_time` with no word of where it stopped.
	 *
	 * @return int how many were newly followed
	 */
	public function importTakeout(string $userId, string $csv): int {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			throw new InvalidArgumentException('that file could not be read');
		}

		fwrite($handle, $csv);
		rewind($handle);

		$held = count($this->feedsRequest->feedsOf($userId));
		$followed = 0;
		$newFeeds = [];
		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			if ($row === [null]) {
				continue;
			}

			$channel = trim($row[0] ?? '');
			if (preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $channel) !== 1) {
				// the header line, and anything else that is not a channel id
				continue;
			}

			if ($held >= self::MAX_FEEDS) {
				break;
			}

			try {
				$url = $this->discoveryService->discover('https://www.youtube.com/channel/' . $channel);
				if ($this->feedsRequest->idOf($userId, $url) === 0) {
					$id = $this->feedsRequest->create($userId, $url, '', '');
					$newFeeds[] = ['id' => $id, 'url' => $url, 'etag' => '', 'modified_at' => ''];
					$held++;
					$followed++;
				}
			} catch (Throwable $e) {
				$this->logger->debug('a takeout channel could not be followed', [
					'channel' => $channel, 'exception' => $e,
				]);
			}
		}

		fclose($handle);

		// Bring in the newest videos before the import request returns, without
		// making a large Takeout hold the request open for every feed. Feeds not
		// read inside this bounded window remain unread and are picked up by cron.
		$deadline = microtime(true) + (float)self::FIRST_READ_TIMEOUT;
		foreach (array_chunk($newFeeds, self::PARALLEL) as $batch) {
			$remaining = $deadline - microtime(true);
			if ($remaining <= 0) {
				break;
			}
			$timeout = max(1, min(self::FIRST_READ_TIMEOUT, (int)ceil($remaining)));
			$this->refreshBatch($batch, $timeout);
		}

		return $followed;
	}

	/** A stored date as the client reads it, or '' where there is none. */
	private function asDate(mixed $value): string {
		if (!is_string($value) || $value === '') {
			return '';
		}

		$stamp = strtotime($value);

		return ($stamp === false) ? '' : gmdate('Y-m-d\TH:i:s\Z', $stamp);
	}
}
