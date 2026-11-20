<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Atproto\Reader\BlueskyIds;
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
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Security\AsyncRequestSigner;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\Http;
use Psr\Container\ContainerInterface;
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

	/** How recently a server must have answered to be sent an activity addressed to everybody. */
	public const ALL_SEEN_DAYS = 90;

	/** The delivery circuit breaker, as this drain sees it. */
	private ?HostBreaker $breaker = null;

	/** The hostnames this instance answers to; see `localHosts()`. */
	private ?array $localHosts = null;
	private bool $deferDeliveries = false;
	/** @var list<string> */
	private array $deferredDeliveryTokens = [];

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
		private ?ContainerInterface $container = null,
	) {
	}

	private ?FediverseDirectoryService $fediverseDirectoryService = null;

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
		$this->prepareActivityPubReply($item, $activity);

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
		// An edit is read back in the client format, whose `id` is the nid and
		// which has no `type` or `updated`, so it is exported as ActivityPub for
		// the Update; the caller gets its post back as it handed it in.
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
		$this->prepareActivityPubReply($item, $update);
		if ($item instanceof Person) {
			$this->addressActorUpdate($item, $update);
		}

		$update->setActor($actor);
		$this->signatureService->signObject($actor, $update);

		return $update;
	}

	/**
	 * Sends a held post's deliveries, rebuilt from the post as it is now: a
	 * post with a video being converted is queued held (`request()` with a
	 * `$holdUntil`), and once the conversion is done or has given up each held
	 * `Create` and `Update` is built and signed again, as the signature covers
	 * the document. A post deleted while it waited has its held rows dropped.
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
		$video = $this->videoRepresentationForHost($queue->getInstance()?->getAddress() ?? '');
		if ($video === null && is_array($decoded['object'] ?? null)) {
			$queuedType = (string)($decoded['object']['type'] ?? '');
			if (in_array($queuedType, ['Note', 'Video'], true)) {
				// Keep the representation selected when this held row was queued,
				// even if the bounded NodeInfo cache has since expired.
				$video = $queuedType === 'Video';
			}
		}
		$key = $type . ' ' . $queue->getAuthor() . ' ' . (string)$video;
		if (array_key_exists($key, $built)) {
			return $built[$key];
		}

		$activity = null;
		try {
			if ($type === Create::TYPE || $type === Update::TYPE) {
				$actor = $this->actorsRequest->getFromId($queue->getAuthor());
				$copy = clone $post;
				if ($copy instanceof Note && $video !== null) {
					$copy->setVideoRepresentationOverride($video);
				}
				$activity = ($type === Create::TYPE)
					? $this->buildCreate($actor, $copy)
					: $this->buildUpdate($actor, $copy);
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
	 * What tells one `Update` of an object from the next, as an activity id
	 * must be unique and peers drop a repeated one. Like Mastodon's
	 * `#updates/<edited_at>`, it is the post's `updated`, so a redelivery keeps
	 * its id; an object with no such date gets the time in milliseconds.
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
		// author's own signature, so a Delete of a reply can travel the way the
		// reply did.
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
	 * Addresses an activity the way the object it carries is addressed (AP §6),
	 * so readers, including this server's relay fan-out, need not open the
	 * object to place it.
	 */
	/**
	 * An actor has no audience of its own, so an `Update` of one is addressed
	 * as Mastodon does: to the public and copied to the followers. A server
	 * that routes its shared inbox by `to` and `cc`, such as Loops, drops an
	 * activity addressed to nobody.
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
	 * ActivityPub peers cannot join a reply whose parent exists only in ATProto.
	 * Preserve the native parent on the stored stream for the Bluesky publisher;
	 * make the ActivityPub copy standalone and link back to the parent instead.
	 */
	private function prepareActivityPubReply(ACore $item, ACore $activity): void {
		if (!$item instanceof Stream || !BlueskyIds::isPostId($item->getInReplyTo())) {
			return;
		}

		$item->setActivityPubReplyLink($item->getInReplyTo());
		$authorId = BlueskyIds::actorId(BlueskyIds::didOf($item->getInReplyTo()));
		$activity->setCcArray(array_values(array_diff($activity->getCcArray(), [$authorId])));
		$activity->setInstancePaths(array_values(array_filter(
			$activity->getInstancePaths(),
			static fn (InstancePath $path): bool => $path->getAddress() !== BlueskyIds::HOST
		)));
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
	 * delivery. With a `$holdUntil` in the future the rows are written with
	 * that as their `last`, keeping every drain off them until then;
	 * `releaseHeld()` sends them sooner.
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
		$variants = [];
		if ($this->isVideoActivity($activity)) {
			$videoByHost = [];
			foreach ($instancePaths as $instancePath) {
				$host = $instancePath->getAddress();
				if (!array_key_exists($host, $videoByHost)) {
					$videoByHost[$host] = $this->videoRepresentationForHost($host);
				}
				$video = $videoByHost[$host];
				if ($video === null) {
					continue;
				}
				$variant = $this->videoActivityVariant($activity, $video);
				if ($variant !== null) {
					$variants[$instancePath->getUri()] = $variant;
				}
			}
		}
		$token = $variants === []
			? $this->requestQueueService->generateRequestQueue($instancePaths, $activity, $author, $holdUntil)
			: $this->requestQueueService->generateRequestQueueWithVariants(
				$instancePaths, (string)json_encode($activity, JSON_UNESCAPED_SLASHES), $variants, $author, $holdUntil
			);

		if ($token === '') {
			return '<request token not needed>';
		}

		if ($holdUntil > time()) {
			return $token;
		}
		if ($this->deferDeliveries) {
			$this->deferredDeliveryTokens[] = $token;

			return $token;
		}

		$this->deliverToken($token);

		return $token;
	}

	/** A cached peer classification; unknown peers keep the configured default. */
	private function videoRepresentationForHost(string $host): ?bool {
		if ($this->fediverseDirectoryService === null && $this->container !== null) {
			try {
				$service = $this->container->get(FediverseDirectoryService::class);
				$this->fediverseDirectoryService = $service instanceof FediverseDirectoryService ? $service : null;
			} catch (\Throwable) {
				// A missing optional directory cache must not prevent video delivery.
			}
		}
		$software = $this->fediverseDirectoryService?->knownSoftware($host) ?? '';

		return match ($software) {
			'peertube' => true,
			'pixelfed' => false,
			default => null,
		};
	}

	private function isVideoActivity(ACore $activity): bool {
		if (!$activity instanceof Create && !$activity instanceof Update) {
			return false;
		}
		$object = $activity->getObject();

		return $object instanceof Note && $object->isLocal()
			&& PeerTubeService::soleVideo($object->getAttachments()) !== null;
	}

	/** Build a signed, inbox-specific wire representation without mutating the post. */
	private function videoActivityVariant(ACore $activity, bool $asVideo): ?string {
		if (!$activity instanceof Create && !$activity instanceof Update) {
			return null;
		}
		$object = $activity->getObject();
		if (!$object instanceof Note || !$object->isLocal()
			|| PeerTubeService::soleVideo($object->getAttachments()) === null
			|| !$activity->hasActor()) {
			return null;
		}

		$variant = clone $activity;
		$variantObject = clone $object;
		$variantObject->setVideoRepresentationOverride($asVideo);
		$variant->setObject($variantObject);
		$variant->clearSignature();
		$actor = $variant->getActor();
		if ($actor === null) {
			return null;
		}
		$this->signatureService->signObject($actor, $variant);

		return json_encode($variant, JSON_UNESCAPED_SLASHES) ?: null;
	}

	/** Hold inline and asynchronous delivery until the surrounding write commits. */
	public function beginDeferredDeliveries(): void {
		if ($this->deferDeliveries) {
			throw new \LogicException('Activity deliveries are already deferred');
		}
		$this->deferDeliveries = true;
		$this->deferredDeliveryTokens = [];
	}

	/** Start deliveries after the database transaction has committed. */
	public function releaseDeferredDeliveries(): void {
		if (!$this->deferDeliveries) {
			return;
		}
		$tokens = $this->deferredDeliveryTokens;
		$this->deferDeliveries = false;
		$this->deferredDeliveryTokens = [];
		foreach ($tokens as $token) {
			try {
				$this->deliverToken($token);
			} catch (\Throwable $e) {
				$this->logger->notice('Queued activity will be delivered by the worker', ['token' => AsyncRequestSigner::fingerprint($token), 'exception' => $e]);
			}
		}
	}

	/** Discard delivery starts when the surrounding write rolls back. */
	public function discardDeferredDeliveries(): void {
		$this->deferDeliveries = false;
		$this->deferredDeliveryTokens = [];
	}

	private function deliverToken(string $token): void {

		$this->manageInit();

		try {
			$directRequest = $this->requestQueueService->getPriorityRequest($token);
			$directRequest->setTimeout(self::TIMEOUT_LIVE);
			$this->manageRequest($directRequest, true);
		} catch (NoHighPriorityRequestException $e) {
		} catch (EmptyQueueException $e) {
			return;
		}

		if ($this->requestQueueService->hasStandby($token)) {
			$this->curlService->asyncWithToken($token);
		}
	}

	/**
	 * Which of these authors still have deliveries waiting or going out: a
	 * deleted account's signing key is kept until it has none.
	 *
	 * @param string[] $actorIds
	 * @return array<string, true> actor id => true
	 */
	public function withPendingDeliveries(array $actorIds): array {
		return $this->requestQueueService->authorsWithPending($actorIds);
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
	 * Whether an HTTP status from a peer is worth trying again later: 408, 429
	 * and any 5xx. Every other 4xx means the activity will never be accepted.
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
	 *              drains in a loop counts attempts, not rows, so rows skipped
	 *              for an open breaker do not keep `social:worker` spinning.
	 *
	 * @throws SocialAppConfigException
	 */
	public function manageRequest(RequestQueue $queue, bool $live = false): bool {
		$host = $queue->getInstance()
			->getAddress();
		$openUntil = $this->breaker()->openUntil($host);
		if ($openUntil > 0) {
			// held back until the breaker closes; postponed, as a skipped row
			// would keep sorting ahead of every other and fill the drain's window
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
	 * Delivers a batch of queued requests, several servers at a time, through
	 * `CurlService::sendMany()`: waves of up to `PARALLEL`, at most one row per
	 * host in a wave, so a dead host costs its timeout once beside the others.
	 * Each wave goes to the hosts with the most rows left, ties by batch order.
	 * Every row is settled as `manageRequest()` settles one.
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
			$remaining = [];
			foreach ($pending as $queue) {
				$host = $queue->getInstance()->getAddress();
				$remaining[$host] = ($remaining[$host] ?? 0) + 1;
			}
			// stable: hosts with as many rows left keep the order they came in
			arsort($remaining);
			$hosts = array_fill_keys(array_slice(array_keys($remaining), 0, self::PARALLEL), true);

			$wave = [];
			$later = [];
			foreach ($pending as $queue) {
				$host = $queue->getInstance()->getAddress();
				if (isset($hosts[$host])) {
					unset($hosts[$host]);
					$wave[] = $queue;
					continue;
				}
				$later[] = $queue;
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
			// with, so the row is dropped rather than recorded as delivered
			$this->logger->notice(
				'Delivery refused by the instance policy, dropping the request: ' . $url
			);
			$this->requestQueueService->deleteRequest($queue);

			return;
		}

		if ($failure instanceof RequestContentException) {
			// The peer answered, but not with a 2xx: whether to retry depends on the
			// status, as a 503 during an upgrade or a 429 is temporary.
			if ($this->isTransientHttpStatus($failure->getCode())) {
				$this->logger->notice(
					'Temporary error while managing request: HTTP ' . $failure->getCode() . ' - '
					. $url . ' - ' . $failure->getMessage()
				);
				$this->requestQueueService->endRequest($queue, false);
				$this->holdHost($host, $live, $failure->getRetryAfter());

				return;
			}

			$this->logger->notice(
				'Permanent error while managing request: HTTP ' . $failure->getCode() . ' - '
				. $url . ' - ' . $failure->getMessage()
			);
			$this->requestQueueService->deleteRequest($queue);

			return;
		}

		if ($failure instanceof ActorDoesNotExistException) {
			// the local account the activity is signed as is gone, key and
			// all: nothing can send this any more. A deleted account is kept
			// while it has deliveries waiting, so this is not the normal end
			// of its `Delete` and is worth an administrator's attention.
			$this->logger->warning(
				'A queued delivery has no local account left to sign it, dropping the request: ' . $url,
				['author' => $queue->getAuthor(), 'exception' => $failure]
			);
			$this->requestQueueService->deleteRequest($queue);

			return;
		}

		if ($failure instanceof RequestResultSizeException) {
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
	 * Leaves a host alone after a failed delivery, unless it was the live one:
	 * the inline delivery has three seconds where the other paths have ten to
	 * thirty, and a slow but healthy peer must not be held off all of them.
	 */
	private function holdHost(string $host, bool $live, int $retryAfter = 0): void {
		if ($live) {
			return;
		}

		$this->breaker()->open($host, $retryAfter);
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
	 * The relay inboxes a local, public activity also goes to, as Mastodon
	 * sends them: a relay wants what this server wrote, so third parties'
	 * activities are not forwarded (which could also loop), and a
	 * followers-only post has a chosen audience.
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
	 * Whether a delivery is addressed to this very instance. Local recipients
	 * already have the activity through social_stream_dest, and a request to
	 * the server's own public address often fails behind a reverse proxy,
	 * split-horizon DNS or an SSRF guard.
	 */
	private function isOurs(InstancePath $instancePath): bool {
		$host = strtolower($instancePath->getAddress());

		return $host !== '' && in_array($host, $this->localHosts(), true);
	}

	/**
	 * The hostnames this instance answers to: the cloud address
	 * (`getCloudHost()`, set by the administrator) and the social URL every
	 * local id and inbox is generated from (`ConfigService::generateId()`),
	 * which can differ when the cloud address is set by hand.
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

		// One row per distinct inbox, resolved in the database: the inboxes are
		// as many as the instances, not the followers. The shared inbox where
		// the remote publishes one, as `endpoints.sharedInbox` is optional, and
		// the personal inbox otherwise.
		foreach ($this->followsRequest->getFollowerInboxes($instancePath->getUri()) as $inbox) {
			$instancePaths[] = new InstancePath(
				$inbox, InstancePath::TYPE_GLOBAL, $instancePath->getPriority()
			);
		}

		return $instancePaths;
	}

	/**
	 * Every server worth telling, for an activity addressed to everybody (an
	 * account deletion): one shared inbox per server heard from in the last
	 * `ALL_SEEN_DAYS`, less the ones whose breaker is open.
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePathsAll(): array {
		$sharedInboxes = $this->cacheActorsRequest->getSharedInboxesSeenSince(
			time() - self::ALL_SEEN_DAYS * 86400
		);
		$instancePaths = [];
		foreach ($sharedInboxes as $sharedInbox) {
			$instancePath = new InstancePath(
				$sharedInbox,
				InstancePath::TYPE_GLOBAL,
				InstancePath::PRIORITY_LOW
			);
			if ($this->breaker()->openUntil($instancePath->getAddress()) > 0) {
				continue;
			}

			$instancePaths[] = $instancePath;
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
	 * The bytes the delivery puts on the wire: the stored activity, as stored,
	 * so a third party's document `ForwardService` queues keeps its Linked Data
	 * signature. This instance's own activities are encoded once when queued
	 * (`generateRequestQueue()`); the digest is computed over these bytes.
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
	 * Stores what an outgoing activity is about, not the activity: no table
	 * holds activities, so the post, like or follow it carries goes through
	 * the interface for its own type, as an inbox delivery does.
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
