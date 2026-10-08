<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\Backoff;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Traits\TArrayTools;

class RequestQueueService {
	/**
	 * A request is abandoned after this many failed delivery attempts.
	 *
	 * The schedule is Mastodon's (Sidekiq's `count^4 + 15` backoff, 16
	 * attempts): a peer gets about two days to come back, which covers the
	 * weekend outage the old 15 tries on `tries^4/3` (12-16 hours in total)
	 * did not. The first attempts stay minutes apart, because a peer that was
	 * only momentarily down is the common case; the cron runs every 12
	 * minutes, so every wait below that is "next run". A row is retried once
	 * `last + retryDelay(tries)` has passed:
	 *
	 *   failed tries | wait before the next attempt
	 *   -------------|-----------------------------
	 *              0 |  none: never failed, due at once
	 *              1 |      16 s
	 *              2 |      31 s
	 *              3 |      96 s
	 *              4 |     271 s  (4.5 min)
	 *              5 |     640 s  (11 min)
	 *              6 |   1 311 s  (22 min)
	 *              7 |   2 416 s  (40 min)
	 *              8 |   4 111 s  (1.1 h)
	 *              9 |   6 576 s  (1.8 h)
	 *             10 |  10 015 s  (2.8 h)
	 *             11 |  14 656 s  (4.1 h)
	 *             12 |  20 751 s  (5.8 h)
	 *             13 |  28 576 s  (7.9 h)
	 *             14 |  38 431 s  (10.7 h)
	 *             15 |  50 640 s  (14.1 h)
	 *             16 |  abandoned
	 *
	 * Total: 178 537 s, 49.6 hours, plus up to one cron interval per attempt.
	 *
	 * The schedule lives in `Backoff::outbound()`, which both this and the
	 * query that reads the queue (`RequestQueueRequest::getStandby()`) take it
	 * from.
	 */
	public const MAX_TRIES = Backoff::OUTBOUND_MAX_TRIES;

	/**
	 * How long a delivered or abandoned request is kept before it is purged.
	 *
	 * A finished row used to be deleted the moment it finished, which made the
	 * queue a to-do list and nothing else: it could never say where a post had
	 * got to, only where it had not got to yet. Seven days is long enough for an
	 * author to wonder why a post never showed up somewhere and go and look, and
	 * short enough that the table stays a queue rather than becoming an archive
	 * of every delivery this instance ever made.
	 */
	public const RETENTION_SECONDS = 7 * 24 * 3600;

	/**
	 * How long a request waits after its n-th failure, in seconds; the table
	 * on MAX_TRIES. `tries` is the count of failed attempts so far.
	 */
	public static function retryDelay(int $tries): int {
		return Backoff::outbound()->delay($tries);
	}

	/** A `running` request older than this (seconds) is treated as stranded. */
	public const STALE_RUNNING_SECONDS = 3600;

	use TArrayTools;

	public function __construct(
		private RequestQueueRequest $requestQueueRequest,
	) {
	}

	/**
	 * @param array $instancePaths
	 * @param ACore $item
	 * @param string $author
	 * @param int $holdUntil when the rows may first be delivered, 0 for now
	 *
	 * @return string
	 */
	public function generateRequestQueue(array $instancePaths, ACore $item, string $author, int $holdUntil = 0): string {
		return $this->generateRequestQueueFromSource(
			$instancePaths, json_encode($item, JSON_UNESCAPED_SLASHES), $author, $holdUntil
		);
	}

	/**
	 * Queues a document that is already serialised, byte for byte.
	 *
	 * Forwarding has to send on what arrived: the sender's linked-data
	 * signature covers the document as it stands, and re-encoding our own
	 * model of it would both drop the signature and change what it signed.
	 *
	 * @param InstancePath[] $instancePaths
	 * @param int $holdUntil when the rows may first be delivered, 0 for now.
	 *                       Written as `last` with no tries spent, which is
	 *                       the same hold `postponeRequest()` puts on a row
	 *
	 * @return string the token shared by every queued request
	 */
	public function generateRequestQueueFromSource(
		array $instancePaths, string $activity, string $author, int $holdUntil = 0,
	): string {
		$token = '';
		$requests = [];
		$objectIdPrim = self::objectIdPrimOf($activity);
		foreach ($this->uniqueInboxes($instancePaths) as $instancePath) {
			$request = new RequestQueue($activity, $instancePath, $author);
			$request->setObjectIdPrim($objectIdPrim);
			if ($holdUntil > time()) {
				$request->setLast($holdUntil);
			}
			if ($token === '') {
				$token = $request->getToken();
			} else {
				$request->setToken($token);
			}

			$requests[] = $request;
		}

		$this->requestQueueRequest->multiple($requests);

		return $token;
	}

	/**
	 * One delivery per inbox.
	 *
	 * The paths reaching here come from several places that know nothing of
	 * each other: every mention adds the mentioned actor's inbox, the follower
	 * fan-out adds one path per instance, boosters and repliers add theirs. A
	 * post mentioning three people on one Mastodon server, or mentioning
	 * somebody who also follows the author, produced that many identical
	 * POSTs of the same activity to the same inbox — wasted on us, and
	 * duplicate work for the peer, which drops all but the first as already
	 * seen.
	 *
	 * Same inbox means same URI: the callers already prefer an instance's
	 * shared inbox where it publishes one, so the shared inbox is the single
	 * URI they converge on. An instance that publishes none is addressed by
	 * its personal inboxes, which are distinct URIs and each still delivered —
	 * uniquing must not turn two people on such a server into one delivery.
	 *
	 * Of two paths to one inbox the higher priority wins, so folding a
	 * follower fan-out into a direct mention does not demote the delivery from
	 * inline to the next cron run.
	 *
	 * @param InstancePath[] $instancePaths
	 *
	 * @return InstancePath[] in the order the inboxes were first named
	 */
	private function uniqueInboxes(array $instancePaths): array {
		$unique = [];
		foreach ($instancePaths as $instancePath) {
			$uri = $instancePath->getUri();
			if (!array_key_exists($uri, $unique)) {
				$unique[$uri] = $instancePath;
				continue;
			}

			if ($instancePath->getPriority() > $unique[$uri]->getPriority()) {
				$unique[$uri] = $instancePath;
			}
		}

		return array_values($unique);
	}

	/**
	 * deciding if we run request on main thread,
	 * based on set priority, and number of request linked to one token
	 *
	 * @param string $token
	 *
	 * @return RequestQueue
	 * @throws EmptyQueueException
	 * @throws NoHighPriorityRequestException
	 */
	public function getPriorityRequest(string $token): RequestQueue {
		$requests = $this->requestQueueRequest->getFromToken($token);

		if (sizeof($requests) === 0) {
			throw new EmptyQueueException();
		}

		$request = $requests[0];
		switch ($request->getPriority()) {
			case InstancePath::PRIORITY_TOP:
				return $request;
			case InstancePath::PRIORITY_HIGH:
				if (sizeof($requests) === 1) {
					return $request;
				}

				$next = $requests[1];
				if ($next->getPriority() < InstancePath::PRIORITY_HIGH) {
					return $request;
				}
				break;

			case InstancePath::PRIORITY_MEDIUM:
				if (sizeof($requests) === 1) {
					return $request;
				}
				break;
		}

		throw new NoHighPriorityRequestException();
	}

	/**
	 * The requests that are due. The backoff and the give-up threshold are
	 * the query's (`RequestQueueRequest::getStandby()`), which also marks the
	 * exhausted rows abandoned before it reads.
	 *
	 * @param int $total
	 *
	 * @return RequestQueue[]
	 */
	public function getRequestStandby(int &$total = 0): array {
		$requests = $this->requestQueueRequest->getStandby();
		$total = sizeof($requests);

		return $requests;
	}

	/**
	 * @param string $token
	 * @param int $status
	 *
	 * @return RequestQueue[]
	 */
	public function getRequestFromToken(string $token, int $status = -1): array {
		if ($token === '') {
			return [];
		}

		return $this->requestQueueRequest->getFromToken($token, $status);
	}

	/**
	 * @param RequestQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function initRequest(RequestQueue $queue) {
		$this->requestQueueRequest->setAsRunning($queue);
	}

	/**
	 * @param RequestQueue $queue
	 * @param bool $success
	 */
	public function endRequest(RequestQueue $queue, bool $success) {
		try {
			if ($success === true) {
				// kept, not deleted: it is the one record that this server got the
				// post, and the author may ask. `purgeFinished()` takes it away once
				// RETENTION_SECONDS have passed.
				$this->requestQueueRequest->setAsSuccess($queue);
			} else {
				$this->requestQueueRequest->setAsFailure($queue);
			}
		} catch (QueueStatusException $e) {
		}
	}

	/**
	 * Holds a standby request back until `$until`.
	 *
	 * For a row whose host has an open circuit breaker: it was not attempted,
	 * so it keeps `tries = 0` and would come back at the head of the very next
	 * batch — ahead of every row that has been attempted and every row queued
	 * since — and be skipped again. A few hundred of them to instances that
	 * are gone filled the whole window and nothing else was ever delivered.
	 */
	public function postponeRequest(RequestQueue $queue, int $until): void {
		if ($until <= time()) {
			return;
		}

		try {
			$this->requestQueueRequest->postpone($queue, $until);
		} catch (QueueStatusException $e) {
			// somebody else claimed the row meanwhile; it is no longer ours to
			// hold back
		}
	}

	/**
	 * The requests about one object that are still held back: on standby,
	 * never attempted, and not due until some time from now.
	 *
	 * That is a held video post's fan-out — and also, for as long as it lasts,
	 * a row the circuit breaker put off, which is the same state for the same
	 * reason: it has not gone yet and must not go early.
	 *
	 * @return list<RequestQueue>
	 */
	public function getHeld(string $objectId): array {
		return $this->requestQueueRequest->getHeldByObject(md5($objectId), time());
	}

	/**
	 * Hands a held request the body it goes out with and makes it due now.
	 *
	 * @return bool false when the row was no longer on standby: a drain took
	 *              it, or it was dropped, and it is not ours to change
	 */
	public function releaseRequest(RequestQueue $queue, string $activity): bool {
		try {
			$this->requestQueueRequest->release($queue, $activity);

			return true;
		} catch (QueueStatusException $e) {
			return false;
		}
	}

	/**
	 * Return requests stuck `running` past the reaper cutoff to standby, so a worker
	 * that died mid-delivery does not strand them forever.
	 */
	public function reapStaleRunning(): int {
		return $this->requestQueueRequest->resetStaleRunning(time() - self::STALE_RUNNING_SECONDS);
	}

	/**
	 * Gives up on one request: it stays as STATUS_ABANDONED until the retention
	 * has passed, as a delivered one does.
	 */
	public function abandonRequest(RequestQueue $queue): void {
		try {
			$this->requestQueueRequest->setAsAbandoned($queue);
		} catch (QueueStatusException $e) {
			// somebody else got to it first; the row is no longer ours to judge
		}
	}

	/**
	 * Removes the delivered and abandoned rows older than the retention.
	 *
	 * @return int rows removed
	 */
	public function purgeFinished(): int {
		return $this->requestQueueRequest->deleteFinished(time() - self::RETENTION_SECONDS);
	}

	/**
	 * The md5 of the id of the object a serialised activity is about -- the
	 * Note under a Create, Update or Delete, the object of an Announce -- or of
	 * the activity's own id when it carries no object, or '' when it has neither.
	 *
	 * Read off the JSON rather than passed in, so that `ForwardService`, which
	 * queues a third party's bytes verbatim, keys its rows the same way as
	 * everything this instance wrote itself.
	 */
	public static function objectIdPrimOf(string $activity): string {
		$decoded = json_decode($activity, true);
		if (!is_array($decoded)) {
			return '';
		}

		$object = $decoded['object'] ?? null;
		$id = match (true) {
			is_array($object) && is_string($object['id'] ?? null) => $object['id'],
			is_string($object) => $object,
			is_string($decoded['id'] ?? null) => $decoded['id'],
			default => '',
		};

		return ($id === '') ? '' : md5($id);
	}

	/**
	 * @param RequestQueue $queue
	 */
	public function deleteRequest(RequestQueue $queue) {
		$this->requestQueueRequest->delete($queue);
	}
}
