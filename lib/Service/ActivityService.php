<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\HostBreakerRequest;
use OCA\Social\Db\RelayRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;

/**
 * Class ActivityService
 *
 * @package OCA\Social\Service
 */
class ActivityService {
	use TArrayTools;

	public const TIMEOUT_LIVE = 3;
	public const TIMEOUT_ASYNC = 10;
	public const TIMEOUT_SERVICE = 30;

	/** How many deliveries `manageRequests()` has in flight at once, one per host. */
	public const PARALLEL = 20;

	/** How long a host that has just failed is left alone, and the ceiling; see `HostBreaker`. */
	public const BREAKER_BASE = HostBreaker::BASE;
	public const BREAKER_MAX = HostBreaker::MAX;

	/** The delivery circuit breaker, as this drain sees it. */
	private ?HostBreaker $breaker = null;

	/** The hostnames this instance answers to; see `localHosts()`. */
	private ?array $localHosts = null;

	public function __construct(
		private FollowsRequest $followsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private SignatureService $signatureService,
		private RequestQueueService $requestQueueService,
		private CurlService $curlService,
		private ConfigService $configService,
		private ActorsRequest $actorsRequest,
		private RelayRequest $relayRequest,
		private HostBreakerRequest $hostBreakerRequest,
		private LoggerInterface $logger,
		private StreamRequest $streamRequest,
	) {
	}

	/**
	 * @param Person $actor
	 * @param ACore $item
	 * @param ACore $activity
	 * @param int $holdUntil when the delivery may start, 0 for now; see
	 *                       `request()`
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function createActivity(Person $actor, ACore $item, ?ACore &$activity = null, int $holdUntil = 0): string {
		$activity = $this->buildCreate($actor, $item);

		$this->saveActivity($activity);

		return $this->request($activity, $holdUntil);
	}

	private function buildCreate(Person $actor, ACore $item): Create {
		$activity = new Create();
		$item->setParent($activity);

		$activity->setObject($item);
		$activity->setId($item->getId() . '/activity');
		$activity->setInstancePaths($item->getInstancePaths());
		$this->copyAudience($item, $activity);

		$activity->setActor($actor);
		$this->signatureService->signObject($actor, $activity);

		return $activity;
	}

	/**
	 * @param Person $actor
	 * @param ACore $item
	 * @param int $holdUntil when the delivery may start, 0 for now; see
	 *                       `request()`
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function updateActivity(Person $actor, ACore $item, int $holdUntil = 0): string {
		// An edit is read back from the database in the client format, whose
		// `id` is the nid and which has no `type` and no `updated`. Wrapped as
		// it was, the Update named no post a peer had, and every edit was
		// dropped on arrival. The caller gets its post back as it handed it in.
		$format = $item->getExportFormat();
		$item->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
		try {
			return $this->request($this->buildUpdate($actor, $item), $holdUntil);
		} finally {
			$item->setExportFormat($format);
		}
	}

	private function buildUpdate(Person $actor, ACore $item): Update {
		$update = new Update();
		$item->setParent($update);

		$update->setObject($item);
		$update->setId($item->getId() . '#updates/' . $this->updateSerial($item));
		$update->setInstancePaths($item->getInstancePaths());
		$this->copyAudience($item, $update);
		if ($item instanceof Person) {
			$this->addressActorUpdate($item, $update);
		}

		$update->setActor($actor);
		$this->signatureService->signObject($actor, $update);

		return $update;
	}

	/**
	 * Sends a held post's deliveries, rebuilt from the post as it is now.
	 *
	 * A post carrying a video that is still being converted is queued with
	 * its rows held back (`request()` with a `$holdUntil`). This is the other
	 * end: the conversion has finished, or given up, and the stored post now
	 * names whatever file there is to name. Each held `Create` and `Update` is
	 * built again from it and signed again — the linked-data signature covers
	 * the document, so the stored body cannot simply be patched — and the
	 * rows are made due and drained at once.
	 *
	 * A post that was deleted while it waited has its held rows dropped:
	 * delivering it now would put back on other servers what its author has
	 * already taken down.
	 *
	 * @return int how many rows were released
	 */
	public function releaseHeld(string $objectId): int {
		$held = $this->requestQueueService->getHeld($objectId);
		if ($held === []) {
			return 0;
		}

		try {
			$post = $this->streamRequest->getStreamById($objectId);
		} catch (StreamNotFoundException $e) {
			foreach ($held as $queue) {
				$this->requestQueueService->deleteRequest($queue);
			}

			return 0;
		}

		$bodies = [];
		$tokens = [];
		$released = 0;
		foreach ($held as $queue) {
			$body = $this->rebuiltBody($queue, $post, $bodies);
			if ($this->requestQueueService->releaseRequest($queue, $body)) {
				$tokens[$queue->getToken()] = true;
				$released++;
			}
		}

		foreach (array_keys($tokens) as $token) {
			$this->curlService->asyncWithToken((string)$token);
		}

		return $released;
	}

	/**
	 * What one held row goes out with: its `Create` or `Update` built again
	 * from the post, or the body it was queued with for anything else.
	 * Built once per type and author, however many inboxes share it.
	 *
	 * @param array<string, string> $built
	 */
	private function rebuiltBody(RequestQueue $queue, Stream $post, array &$built): string {
		$decoded = json_decode($queue->getActivity(), true);
		$type = is_array($decoded) ? (string)($decoded['type'] ?? '') : '';
		$key = $type . ' ' . $queue->getAuthor();
		if (array_key_exists($key, $built)) {
			return $built[$key];
		}

		$activity = null;
		try {
			if ($type === Create::TYPE || $type === Update::TYPE) {
				$actor = $this->actorsRequest->getFromId($queue->getAuthor());
				$activity = ($type === Create::TYPE)
					? $this->buildCreate($actor, clone $post)
					: $this->buildUpdate($actor, clone $post);
			}
		} catch (Exception $e) {
			// sent as it was queued rather than not at all: the video in it
			// may not play everywhere, but the post itself does
			$this->logger->warning('a held delivery could not be rebuilt and goes out as queued', [
				'object' => $post->getId(), 'exception' => $e,
			]);
		}

		$built[$key] = ($activity === null)
			? $queue->getActivity()
			: (string)json_encode($activity, JSON_UNESCAPED_SLASHES);

		return $built[$key];
	}

	/**
	 * What tells one `Update` of an object from the next.
	 *
	 * An activity id has to be unique, and every edit of a post used to go
	 * out as `<post>/activity#update`: a peer that remembers the activities it
	 * has seen by id dropped the second edit as a repeat. Mastodon names its
	 * own `#updates/<edited_at>`; the post's `updated` is the same thing here,
	 * so a redelivery of one version keeps its id. An object with no such date
	 * — an actor, a poll whose count moved — gets the time in milliseconds.
	 */
	private function updateSerial(ACore $item): string {
		$updated = ($item instanceof Stream) ? strtotime($item->getUpdated()) : false;
		if ($updated !== false && $updated > 0) {
			return (string)$updated;
		}

		return (string)(int)floor(microtime(true) * 1000.0);
	}

	/**
	 * @param ACore $item
	 *
	 * @return string
	 * @throws Exception
	 */
	public function deleteActivity(ACore $item): string {
		$delete = new Delete();
		$delete->setId($item->getId() . '#delete');
		$delete->setActorId($item->getActorId());

		$tombstone = new Tombstone($delete);
		$tombstone->setId($item->getId());

		$delete->setObject($tombstone);
		$delete->addInstancePaths($item->getInstancePaths());
		// from the post, not from the Tombstone that replaces it: a Tombstone
		// names nobody
		$this->copyAudience($item, $delete);

		// what has not gone yet never goes: a held Create delivered after this
		// Delete would put the post back on every server that received both
		foreach ($this->requestQueueService->getHeld($item->getId()) as $held) {
			$this->requestQueueService->deleteRequest($held);
		}

		// A recipient may only pass an activity on (AP §7.1.2) if it carries the
		// author's own signature over the document. Unsigned, a Delete of a
		// reply cannot travel the way the reply itself did, so the post stays
		// visible on every instance that only ever received it forwarded.
		try {
			$this->signatureService->signObject(
				$this->actorsRequest->getFromId($delete->getActorId()), $delete
			);
		} catch (Exception $e) {
			$this->logger->notice('a Delete goes out without a linked-data signature', [
				'activity' => $delete->getId(),
				'actor' => $delete->getActorId(),
				'exception' => $e,
			]);
		}

		return $this->request($delete);
	}

	/**
	 * Addresses an activity the way the object it carries is addressed.
	 *
	 * An activity has an audience of its own (AP §6), and a Create, Update or
	 * Delete that names nobody is one every reader has to open the object to
	 * place — including this server, whose relay fan-out asks the activity
	 * whether it is public.
	 */
	/**
	 * An actor has no audience of its own, so an `Update` of one copied none
	 * and went out naming no recipient at all. A server that routes its shared
	 * inbox by `to` and `cc` — Loops does — has nobody to hand such an
	 * activity to and drops it. Addressed the way Mastodon addresses its own:
	 * to the public, since a profile is public, and copied to the followers,
	 * who are the ones holding a copy of it.
	 */
	private function addressActorUpdate(Person $actor, Update $update): void {
		if (array_filter($update->getToAll()) !== [] || $update->getCcArray() !== []) {
			return;
		}

		$update->setTo(ACore::CONTEXT_PUBLIC);
		if ($actor->getFollowers() !== '') {
			$update->setCcArray([$actor->getFollowers()]);
		}
	}

	private function copyAudience(ACore $item, ACore $activity): void {
		$activity->setTo($item->getTo());
		$activity->setToArray($item->getToArray());
		$activity->setCcArray($item->getCcArray());
	}

	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws InvalidResourceException
	 */
	public function getItem(string $id): ACore {
		if ($id === '') {
			throw new InvalidResourceException();
		}

		$requests = [
			'Note'
		];

		foreach ($requests as $request) {
			try {
				$interface = AP::instance()->getInterfaceFromType($request);

				return $interface->getItemById($id);
			} catch (Exception $e) {
			}
		}

		throw new InvalidResourceException();
	}

	/**
	 * Queues an activity for every inbox it is addressed to, and starts the
	 * delivery.
	 *
	 * With a `$holdUntil` in the future the rows are written with that as
	 * their `last`, which keeps every drain off them until then, and nothing
	 * is delivered inline or handed to the async worker. `releaseHeld()` sends
	 * them sooner; left alone, the cron delivers them as they are once the
	 * time has passed.
	 *
	 * @throws SocialAppConfigException
	 */
	public function request(ACore $activity, int $holdUntil = 0): string {
		$author = $this->getAuthorFromItem($activity);
		$instancePaths = $this->generateInstancePaths($activity);
		if ($activity instanceof Delete && isset($instancePaths[0])) {
			// Start one retraction before handing the rest of a fan-out to the
			// detached queue worker. A Delete should not depend entirely on that
			// self-request succeeding before any remote copy is told to disappear.
			// Clone because most Delete paths are the post's saved InstancePath
			// objects, whose priorities must remain unchanged on the stored item.
			$instancePaths[0] = (clone $instancePaths[0])->setPriority(InstancePath::PRIORITY_TOP);
		}

		if ($instancePaths === [] && $this->isPublicActivity($activity) && $this->isLocalAuthor($author)) {
			$this->logger->notice('public activity resolved no remote inboxes; activity was not delivered', [
				'activityId' => $activity->getId(),
				'objectId' => $activity->getObjectId(),
				'actorId' => $author,
				'addressingPaths' => array_map(
					static fn (InstancePath $path): int => $path->getType(),
					$activity->getInstancePaths()
				),
			]);
		}
		$token = $this->requestQueueService->generateRequestQueue($instancePaths, $activity, $author, $holdUntil);

		if ($token === '') {
			return '<request token not needed>';
		}

		if ($holdUntil > time()) {
			return $token;
		}

		$this->manageInit();

		try {
			$directRequest = $this->requestQueueService->getPriorityRequest($token);
			$directRequest->setTimeout(self::TIMEOUT_LIVE);
			$this->manageRequest($directRequest, true);
		} catch (NoHighPriorityRequestException $e) {
		} catch (EmptyQueueException $e) {
			return $token;
		}

		$requests = $this->requestQueueService->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
		if (sizeof($requests) > 0) {
			$this->curlService->asyncWithToken($token);
		}

		return $token;
	}

	public function manageInit() {
		$this->breaker()->reset();
	}

	private function breaker(): HostBreaker {
		return $this->breaker ??= new HostBreaker($this->hostBreakerRequest);
	}

	/**
	 * Forgets the hosts that have not failed for longer than the breaker's
	 * ceiling. A cheap DELETE on an index, for the cron to run each pass.
	 */
	public function forgetRecoveredHosts(): void {
		$this->breaker()->forgetRecovered();
	}

	/**
	 * Whether an HTTP status from a peer is worth trying again later.
	 *
	 * 408 and 429 are explicitly temporary, and any 5xx is the peer's own
	 * problem rather than something wrong with what we sent. Everything else
	 * in the 4xx range means this activity will never be accepted, so there is
	 * nothing to gain by keeping it queued.
	 */
	private function isTransientHttpStatus(int $status): bool {
		return $status === Http::STATUS_REQUEST_TIMEOUT
			|| $status === Http::STATUS_TOO_MANY_REQUESTS
			|| $status >= Http::STATUS_INTERNAL_SERVER_ERROR;
	}

	/**
	 * Delivers one queued request, unless its host is being left alone.
	 *
	 * @param bool $live whether this is the inline delivery inside the web
	 *                   request, which runs on a three-second timeout
	 *
	 * @return bool whether the row was actually attempted. A caller that
	 *              drains in a loop counts attempts, not rows: a batch of rows
	 *              whose hosts all have an open breaker is skipped in
	 *              milliseconds, and counting those as work is what made
	 *              `social:worker` spin without ever sleeping.
	 *
	 * @throws SocialAppConfigException
	 */
	public function manageRequest(RequestQueue $queue, bool $live = false): bool {
		$host = $queue->getInstance()
			->getAddress();
		$openUntil = $this->breaker()->openUntil($host);
		if ($openUntil > 0) {
			// held back until the breaker closes. A skipped row keeps `tries =
			// 0` and its old `last`, which sorts it ahead of every row ever
			// attempted and every newer row: a few hundred of them to dead
			// instances filled the whole 200-row window on every pass and
			// nothing else was ever fetched.
			$this->requestQueueService->postponeRequest($queue, $openUntil);

			return false;
		}

		try {
			$this->requestQueueService->initRequest($queue);
		} catch (QueueStatusException $e) {
			$this->logger->error('Error while trying to init request', [
				'exception' => $e,
			]);

			return false;
		}

		$url = $queue->getInstance()->getUri();
		$body = $this->bodyFromQueue($queue);

		$failure = null;
		try {
			$headers = $this->signatureService->signRequest($url, $body, $queue);
			$this->curlService->retrieveJson(
				$this->methodFromQueue($queue),
				$url,
				['headers' => $headers, 'body' => $body, 'timeout' => $queue->getTimeout()]
			);
		} catch (UnauthorizedFediverseException|RequestResultNotJsonException|RequestContentException|ActorDoesNotExistException|RequestResultSizeException|RequestNetworkException|RequestServerException $e) {
			// what settle() knows how to end; anything else is the caller's
			$failure = $e;
		}

		$this->settle($queue, $failure, $live);

		return true;
	}

	/**
	 * Delivers a batch of queued requests, several servers at a time.
	 *
	 * The rows go out in waves of up to `PARALLEL`, at most one per host in a
	 * wave — a host is not asked twice at once, and once it has failed the
	 * breaker holds the rest of its rows back without a timeout — through
	 * `CurlService::sendMany()`. A wave costs about as long as its slowest
	 * peer, so a dead one costs its timeout once, beside nineteen deliveries,
	 * rather than in front of all of them. Every row is settled exactly as
	 * `manageRequest()` settles one.
	 *
	 * @param RequestQueue[] $queues each carrying the timeout it is sent with
	 * @param int $deadline when to stop starting waves, or 0 for none
	 * @param callable(RequestQueue, \Throwable): void $failed what to do with a row
	 *                                                         that failed in a way this does not handle itself — the row is `running`
	 *                                                         by then, and handing it back is the caller's
	 *
	 * @return int how many rows were attempted
	 */
	public function manageRequests(array $queues, int $deadline, callable $failed): int {
		$attempted = 0;
		$pending = array_values($queues);

		while ($pending !== [] && ($deadline <= 0 || time() < $deadline)) {
			$wave = [];
			$hosts = [];
			$later = [];
			foreach ($pending as $queue) {
				$host = $queue->getInstance()->getAddress();
				if (count($wave) >= self::PARALLEL || isset($hosts[$host])) {
					$later[] = $queue;
					continue;
				}
				$hosts[$host] = true;
				$wave[] = $queue;
			}
			$pending = $later;

			$attempted += $this->deliverWave($wave, $failed);
		}

		return $attempted;
	}

	/**
	 * @param list<RequestQueue> $wave
	 * @param callable(RequestQueue, \Throwable): void $failed
	 *
	 * @return int how many rows were attempted
	 */
	private function deliverWave(array $wave, callable $failed): int {
		$sending = [];
		foreach ($wave as $i => $queue) {
			try {
				$openUntil = $this->breaker()->openUntil($queue->getInstance()->getAddress());
				if ($openUntil > 0) {
					$this->requestQueueService->postponeRequest($queue, $openUntil);
					continue;
				}

				try {
					$this->requestQueueService->initRequest($queue);
				} catch (QueueStatusException $e) {
					// somebody else took it: nothing to hand back
					continue;
				}

				$url = $queue->getInstance()->getUri();
				$body = $this->bodyFromQueue($queue);
				$sending[$i] = [
					'method' => $this->methodFromQueue($queue),
					'url' => $url,
					'options' => [
						'headers' => $this->signatureService->signRequest($url, $body, $queue),
						'body' => $body,
						'timeout' => $queue->getTimeout(),
					],
				];
			} catch (\Throwable $e) {
				$this->settleOrHandBack($queue, $e, $failed);
			}
		}

		if ($sending === []) {
			return 0;
		}

		foreach ($this->curlService->sendMany($sending) as $i => $outcome) {
			$this->settleOrHandBack($wave[$i], $outcome, $failed);
		}

		return count($sending);
	}

	/** @param callable(RequestQueue, \Throwable): void $failed */
	private function settleOrHandBack(RequestQueue $queue, ?\Throwable $outcome, callable $failed): void {
		try {
			$this->settle($queue, $outcome, false);
		} catch (\Throwable $e) {
			$failed($queue, $e);
		}
	}

	/**
	 * Ends one attempted delivery according to how it went: delivered, to be
	 * retried (and the host held back), or dropped for good.
	 *
	 * @throws \Throwable a failure this does not know how to end, for the caller
	 */
	private function settle(RequestQueue $queue, ?\Throwable $failure, bool $live): void {
		$host = $queue->getInstance()->getAddress();
		$url = $queue->getInstance()->getUri();

		if ($failure === null || $failure instanceof RequestResultNotJsonException) {
			// an answer that is not JSON is still an answer: delivered
			$this->breaker()->close($host);
			$this->requestQueueService->endRequest($queue, true);

			return;
		}

		if ($failure instanceof UnauthorizedFediverseException) {
			// nothing was sent: the domain is not one this instance federates
			// with. Kept as delivered, it told the author their post had
			// reached a server it was never offered to.
			$this->logger->notice(
				'Delivery refused by the instance policy, dropping the request: ' . $url
			);
			$this->requestQueueService->deleteRequest($queue);

			return;
		}

		if ($failure instanceof RequestContentException) {
			// The peer answered, but not with a 2xx. Whether that is worth
			// retrying depends entirely on the status: a 503 during an upgrade
			// or a 429 from a rate limiter is temporary and used to cost us
			// every activity queued for that instance, deleted on the spot.
			if ($this->isTransientHttpStatus($failure->getCode())) {
				$this->logger->notice(
					'Temporary error while managing request: HTTP ' . $failure->getCode() . ' - '
					. $url . ' - ' . $failure->getMessage()
				);
				$this->requestQueueService->endRequest($queue, false);
				$this->holdHost($host, $live);

				return;
			}

			$this->logger->notice(
				'Permanent error while managing request: HTTP ' . $failure->getCode() . ' - '
				. $url . ' - ' . $failure->getMessage()
			);
			$this->requestQueueService->deleteRequest($queue);

			return;
		}

		if ($failure instanceof ActorDoesNotExistException || $failure instanceof RequestResultSizeException) {
			$this->logger->notice(
				'Error while managing request: ' . $url . ' ' . get_class($failure) . ': '
				. $failure->getMessage()
			);
			$this->requestQueueService->deleteRequest($queue);

			return;
		}

		if ($failure instanceof RequestNetworkException || $failure instanceof RequestServerException) {
			$this->logger->notice(
				'Temporary error while managing request: RequestServerException - ' . $url
				. ' - ' . get_class($failure) . ': ' . $failure->getMessage()
			);
			$this->requestQueueService->endRequest($queue, false);
			$this->holdHost($host, $live);

			return;
		}

		throw $failure;
	}

	/**
	 * Leaves a host alone after a failed delivery — unless it was the live one.
	 *
	 * The inline delivery has three seconds; the cron, the async drain and
	 * `social:worker` have ten to thirty. A large but healthy peer that needs
	 * four seconds under load fails only the live attempt, and holding the host
	 * on the strength of that put every other path off it too — for up to an
	 * hour, of which only a success anywhere clears the strike.
	 */
	private function holdHost(string $host, bool $live): void {
		if ($live) {
			return;
		}

		$this->breaker()->open($host);
	}

	/** // ====> instanceService
	 *
	 * @param ACore $activity
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePaths(ACore $activity): array {
		$instancePaths = [];
		foreach ($activity->getInstancePaths() as $instancePath) {
			switch ($instancePath->getType()) {
				case InstancePath::TYPE_FOLLOWERS:
					$instancePaths
						= array_merge($instancePaths, $this->generateInstancePathsFollowers($instancePath));
					break;

				case InstancePath::TYPE_ALL:
					$instancePaths = array_merge($instancePaths, $this->generateInstancePathsAll());
					break;

				default:
					$instancePaths[] = $instancePath;
					break;
			}
		}

		$instancePaths = array_merge($instancePaths, $this->relayPaths($activity));

		return array_values(array_filter($instancePaths, fn (InstancePath $path): bool => !$this->isOurs($path)));
	}

	/**
	 * The relay inboxes a *local, public* activity also goes to.
	 *
	 * Subscribing to a relay and sending it nothing is taking without giving:
	 * the point of a relay is that every instance on it sees the others, and
	 * an instance that only reads is invisible to all of them. Mastodon
	 * delivers the same set — a public post and what happens to it afterwards
	 * — and relays drop anything else.
	 *
	 * Local only, because a relay wants what *this* server wrote. Forwarding a
	 * third party's activity on to a relay would put this instance's name on
	 * somebody else's post and, where two instances both relay, loop it.
	 *
	 * Not public means not sent: a followers-only post has an audience that
	 * was chosen, and a relay is the opposite of a chosen audience.
	 *
	 * @return InstancePath[]
	 */
	private function relayPaths(ACore $activity): array {
		if (!$this->isPublicActivity($activity) || !$this->isLocalAuthor($this->getAuthorFromItem($activity))) {
			return [];
		}

		$paths = [];
		foreach ($this->relayRequest->acceptedInboxes() as $inbox) {
			$paths[] = new InstancePath($inbox, InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW);
		}

		return $paths;
	}

	/**
	 * Whether an activity is addressed to the public collection.
	 *
	 * The object decides where the activity itself names nobody: an activity
	 * built elsewhere — a forwarded document, an `Announce` of somebody else's
	 * post — is not guaranteed to carry the audience of what it wraps.
	 */
	private function isPublicActivity(ACore $activity): bool {
		if ($activity->isPublic()) {
			return true;
		}

		return $activity->hasObject() && $activity->getObject()->isPublic();
	}

	/** Whether an actor id is one this server hosts. */
	private function isLocalAuthor(string $authorId): bool {
		if ($authorId === '') {
			return false;
		}

		try {
			$root = $this->configService->getSocialUrl();
		} catch (SocialAppConfigException $e) {
			return false;
		}

		return $root !== '' && str_starts_with($authorId, $root);
	}

	/**
	 * Whether a delivery is addressed to this very instance.
	 *
	 * Everyone here already has the activity: recipients are written into
	 * social_stream_dest when the item is saved, which is what puts it in a
	 * local timeline — the HTTP round trip would only hand us back something we
	 * wrote ourselves. Worse, it is a request the server has to be able to make
	 * to its own public address, which behind a reverse proxy, split-horizon
	 * DNS or an SSRF guard it often cannot: the delivery then fails fifteen
	 * times and is dropped, while remote instances queue up behind it.
	 */
	private function isOurs(InstancePath $instancePath): bool {
		$host = strtolower($instancePath->getAddress());

		return $host !== '' && in_array($host, $this->localHosts(), true);
	}

	/**
	 * The hostnames this instance answers to.
	 *
	 * Two settings name this server and they are not the same one. The cloud
	 * address is what `getCloudHost()` reads and what an administrator sets;
	 * the social URL is what every local id and inbox is generated from
	 * (`ConfigService::generateId()`), and it is taken from the web root. They
	 * agree when the app configures itself, but the cloud address can be set
	 * by hand to a different host — and then every inbox this server would be
	 * posting to itself is on the *other* one, which the check missed.
	 *
	 * @return string[] lowercased, empty when nothing is configured
	 */
	private function localHosts(): array {
		if ($this->localHosts !== null) {
			return $this->localHosts;
		}

		$hosts = [];
		try {
			$hosts[] = $this->configService->getCloudHost();
		} catch (SocialAppConfigException $e) {
			// nothing configured to compare against; send it and find out
		}

		try {
			$hosts[] = parse_url($this->configService->getSocialUrl(), PHP_URL_HOST);
		} catch (SocialAppConfigException $e) {
		}

		$this->localHosts = array_values(array_unique(array_map(
			static fn (string $host): string => strtolower($host),
			array_filter($hosts, static fn ($host): bool => is_string($host) && $host !== '')
		)));

		return $this->localHosts;
	}

	/**
	 * @param InstancePath $instancePath
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePathsFollowers(InstancePath $instancePath): array {
		$instancePaths = [];

		// One row per distinct inbox, resolved in the database. This used to
		// hydrate every follower into a Follow with a Person and its details
		// just to read one string off each — a popular local actor's every post
		// loaded its whole follower list into PHP memory, while the number of
		// inboxes involved is the number of *instances*, not of followers.
		//
		// The shared inbox is used where the remote publishes one and its
		// personal inbox otherwise: `endpoints.sharedInbox` is optional, and
		// using the empty string unconditionally aimed the delivery at host ''
		// — and, because the deduplication then treated '' as an inbox already
		// seen, dropped every follower after the first one on any such instance.
		foreach ($this->followsRequest->getFollowerInboxes($instancePath->getUri()) as $inbox) {
			$instancePaths[] = new InstancePath(
				$inbox, InstancePath::TYPE_GLOBAL, $instancePath->getPriority()
			);
		}

		return $instancePaths;
	}

	/**
	 * @return InstancePath[]
	 */
	private function generateInstancePathsAll(): array {
		$sharedInboxes = $this->cacheActorsRequest->getSharedInboxes();
		$instancePaths = [];
		foreach ($sharedInboxes as $sharedInbox) {
			$instancePaths[] = new InstancePath(
				$sharedInbox,
				InstancePath::TYPE_GLOBAL,
				InstancePath::PRIORITY_LOW
			);
		}

		return $instancePaths;
	}

	/**
	 * Whether the queued delivery is a POST. Every row that reaches the queue
	 * is addressed at an inbox or a shared inbox, so in practice they all are;
	 * the other InstancePath types are expanded into those before queueing.
	 */
	private function methodFromQueue(RequestQueue $queue): string {
		$type = $queue->getInstance()->getType();

		return in_array($type, [
			InstancePath::TYPE_INBOX,
			InstancePath::TYPE_GLOBAL,
			InstancePath::TYPE_FOLLOWERS,
		], true) ? 'post' : 'get';
	}

	/**
	 * The bytes the delivery puts on the wire: the stored activity, as stored.
	 *
	 * They used to be decoded and re-encoded here, which defeated
	 * `ForwardService` on purpose: it queues a third party's `getSource()`
	 * verbatim so that their Linked Data signature still verifies on arrival,
	 * and re-encoding -- key order, escaping, whitespace -- is exactly what
	 * breaks such a signature. What this instance writes itself is encoded once,
	 * with unescaped slashes, when it is queued (`generateRequestQueue()`), so
	 * nothing it sends changes; the digest is computed over these same bytes.
	 */
	private function bodyFromQueue(RequestQueue $queue): string {
		return $queue->getActivity();
	}

	/**
	 * $signature = new LinkedDataSignature();
	 *
	 * @param ACore $activity
	 *
	 * @return string
	 */
	private function getAuthorFromItem(Acore $activity): string {
		if ($activity->hasActor()) {
			return $activity->getActor()
				->getId();
		}

		return $activity->getActorId();
	}

	/**
	 * Stores what an outgoing activity is about, which is not the activity.
	 *
	 * There is no table for activities and there has never been one: a `Create`
	 * or a `Like` is the envelope a thing travelled in, and nothing in this app
	 * reads an envelope back. What is kept is what it carried — the post, the
	 * like, the follow — each through the interface for its own type, which is
	 * also what an inbox delivery goes through.
	 *
	 * @param ACore $activity the activity about to go out
	 */
	private function saveActivity(ACore $activity) {
		if ($activity->hasObject()) {
			$this->saveObject($activity->getObject());
		}
	}

	/**
	 * @param ACore $item
	 */
	private function saveObject(ACore $item) {
		try {
			if ($item->hasObject()) {
				$this->saveObject($item->getObject());
			}

			$service = AP::instance()->getInterfaceForItem($item);
			$service->save($item);
		} catch (ItemUnknownException $e) {
		} catch (ItemAlreadyExistsException $e) {
		}
	}
}
