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
	 * A request is abandoned after this many failed delivery attempts, on
	 * Mastodon's schedule (Sidekiq's `count^4 + 15`): seconds apart at first,
	 * hours later, about 49.6 hours in all plus a cron interval per attempt, so
	 * a peer can be down over a weekend. The schedule is `Backoff::outbound()`,
	 * shared with `RequestQueueRequest::getStandby()`.
	 */
	public const MAX_TRIES = Backoff::OUTBOUND_MAX_TRIES;

	/**
	 * A request still on standby this long after it was queued is abandoned,
	 * whatever its try count: rows held back by the circuit breaker spend no
	 * tries. Three days is the whole schedule plus room to spare.
	 */
	public const MAX_AGE = 3 * 24 * 3600;

	/**
	 * How long a delivered or abandoned request is kept before it is purged:
	 * long enough for an author to check where a post got to, short enough to
	 * keep the table a queue.
	 */
	public const RETENTION_SECONDS = 7 * 24 * 3600;

	/**
	 * How long a request waits after its n-th failure, in seconds (see
	 * MAX_TRIES). `tries` is the count of failed attempts so far.
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
	 * Queue an already serialised default activity with selected inbox-specific
	 * variants. The map is keyed by the exact inbox URI; all variants share one
	 * token so the caller's delivery and hold semantics stay unchanged.
	 *
	 * @param InstancePath[] $instancePaths
	 * @param array<string, string> $activityByInbox
	 */
	public function generateRequestQueueWithVariants(
		array $instancePaths,
		string $defaultActivity,
		array $activityByInbox,
		string $author,
		int $holdUntil = 0,
	): string {
		$token = '';
		$requests = [];
		foreach ($this->uniqueInboxes($instancePaths) as $instancePath) {
			$activity = $activityByInbox[$instancePath->getUri()] ?? $defaultActivity;
			$request = new RequestQueue($activity, $instancePath, $author);
			$request->setObjectIdPrim(self::objectIdPrimOf($activity));
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
	 * Queues a document that is already serialised, byte for byte: a forwarded
	 * document must keep the sender's linked-data signature valid.
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
	 * One delivery per inbox URI, since mentions, the follower fan-out, boosters
	 * and repliers each add paths. Callers prefer shared inboxes, so those
	 * converge; personal inboxes stay distinct. Of two paths to one inbox the
	 * higher priority wins, so a direct mention stays inline.
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
	 * Decides whether to run a request on the main thread, by its priority and
	 * the number of requests under its token.
	 *
	 * @param string $token
	 *
	 * @return RequestQueue
	 * @throws EmptyQueueException
	 * @throws NoHighPriorityRequestException
	 */
	public function getPriorityRequest(string $token): RequestQueue {
		// the two most urgent rows are all the decision below looks at
		$requests = $this->requestQueueRequest->getFromToken($token, -1, 2);

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

	/** Whether anything under a token is still on standby. */
	public function hasStandby(string $token): bool {
		return $token !== '' && $this->requestQueueRequest->hasToken($token, RequestQueue::STATUS_STANDBY);
	}

	/**
	 * Up to `$limit` standby rows under a token that are due now, and only the
	 * ones never tried when `$neverTried` says so.
	 *
	 * @return list<RequestQueue>
	 */
	public function getDueFromToken(string $token, bool $neverTried, int $limit): array {
		if ($token === '' || $limit < 1) {
			return [];
		}

		return $this->requestQueueRequest->getDueFromToken($token, time(), $neverTried, $limit);
	}

	/**
	 * Which of these authors still have deliveries waiting or going out.
	 *
	 * @param string[] $actorIds
	 * @return array<string, true> actor id => true
	 */
	public function authorsWithPending(array $actorIds): array {
		return $this->requestQueueRequest->authorsWithPending($actorIds);
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
				// kept as the record that the post was delivered, until
				// `purgeFinished()` removes it after RETENTION_SECONDS
				$this->requestQueueRequest->setAsSuccess($queue);
			} else {
				$this->requestQueueRequest->setAsFailure($queue);
			}
		} catch (QueueStatusException $e) {
		}
	}

	/**
	 * Holds a standby request back until `$until`, for a row whose host has an
	 * open circuit breaker: never attempted, it would otherwise head every batch
	 * and crowd out deliverable rows.
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
	 * The requests about one object that are still held back: on standby, never
	 * attempted, not due yet. That is a held video post's fan-out, or a row the
	 * circuit breaker put off.
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
	 * The md5 of the id of the object a serialised activity is about (the Note
	 * under a Create, Update or Delete, the object of an Announce), else of the
	 * activity's own id, else ''. Read off the JSON so `ForwardService`'s
	 * verbatim bytes are keyed the same way.
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
