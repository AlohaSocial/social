<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\PayloadTooLargeException;
use OCA\Social\Exceptions\RealTokenException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Interfaces\Activity\ReplyRequestInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\QuoteAuthorization;
use OCA\Social\Model\ActivityPub\Object\ReplyAuthorization;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\OrderedCollectionPage;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AuthorizedFetchService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FeedService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HiddenCollectionsService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\InboxLimiter;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StoryService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TAsync;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ActivityPubController extends Controller {
	use TNCDataResponse;
	use TStringTools;
	use TAsync;

	private SocialPubController $socialPubController;
	private FediverseService $fediverseService;
	private CacheActorService $cacheActorService;
	private SignatureService $signatureService;
	private StreamQueueService $streamQueueService;
	private ImportService $importService;
	private InboxLimiter $inboxLimiter;
	private AccountService $accountService;
	private FollowService $followService;
	private StreamService $streamService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	/** The account behind a signed GET, resolved at most once a request. */
	private ?Person $fetchReader = null;
	private bool $readerResolved = false;

	public function __construct(
		IRequest $request,
		SocialPubController $socialPubController,
		FediverseService $fediverseService,
		CacheActorService $cacheActorService,
		SignatureService $signatureService,
		StreamQueueService $streamQueueService,
		ImportService $importService,
		InboxLimiter $inboxLimiter,
		AccountService $accountService,
		FollowService $followService,
		StreamService $streamService,
		private StreamRequest $streamRequest,
		private PinService $pinService,
		private InstanceActorService $instanceActorService,
		private AuthorizedFetchService $authorizedFetchService,
		private StoryService $storyService,
		private FeedService $feedService,
		private QuoteGrantRequest $quoteGrantRequest,
		private IConfig $systemConfig,
		ConfigService $configService,
		LoggerInterface $logger,
		private ?HiddenCollectionsService $hiddenCollections = null,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->socialPubController = $socialPubController;
		$this->fediverseService = $fediverseService;
		$this->cacheActorService = $cacheActorService;
		$this->signatureService = $signatureService;
		$this->streamQueueService = $streamQueueService;
		$this->importService = $importService;
		$this->inboxLimiter = $inboxLimiter;
		$this->accountService = $accountService;
		$this->followService = $followService;
		$this->streamService = $streamService;
		$this->configService = $configService;
		$this->logger = $logger;

		$this->registerResponder('activity+json', $this->activityStreamsResponder('application/activity+json; charset=utf-8'));
		$this->registerResponder(
			'ld+json; profile="https://www.w3.org/ns/activitystreams"',
			$this->activityStreamsResponder('application/ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=utf-8')
		);
	}

	/**
	 * A responder answering in the media type the peer asked for. It builds on
	 * the framework's `json` responder so a `DataResponse` keeps its status and
	 * headers, and sets a type the peer recognises as ActivityStreams
	 * (GoToSocial refuses anything but the profiled `application/ld+json`).
	 */
	private function activityStreamsResponder(string $contentType): \Closure {
		return function ($response) use ($contentType): Response {
			$resp = $this->buildResponse($response, 'json');
			$resp->addHeader('Content-Type', $contentType);

			return $resp;
		};
	}

	/**
	 * A local actor, for a remote ActivityPub server.
	 *
	 * @param string $username
	 *
	 * @return Response
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/users/{username}')]
	public function actor(string $username): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->actor($username);
		}

		try {
			$this->assertReadable();
			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$actor->setDisplayW3ContextSecurity(true);

			return $this->activityPubSuccess($actor);
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		} catch (Exception $e) {
			return $this->fail($e, [], 404);
		}
	}

	/**
	 * An account's public posts as an RSS feed, as PeerTube and Mastodon offer;
	 * a video carries an `enclosure` for podcast clients.
	 *
	 * Built from an anonymous read, the one a signed-out visitor gets, so a
	 * followers-only post cannot leak into a feed reader; `FeedService` refuses
	 * non-public posts a second time.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}.rss')]
	public function feed(string $username): Response {
		// No secure-mode check: feed readers sign nothing, and secure mode refuses
		// ActivityPub to strangers, not the public feeds.
		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
				->setProbe(ProbeOptions::ACCOUNT)
				->setAccountId($actor->getId())
				->setLimit(FeedService::LIMIT);

			// no viewer: the account query answers an anonymous reader with public
			// posts only
			$this->streamRequest->resetViewer();
			$posts = $this->streamRequest->getTimeline($options);

			$response = new DataDisplayResponse(
				$this->feedService->forAccount($actor, $posts),
				Http::STATUS_OK,
				['Content-Type' => 'application/rss+xml; charset=utf-8']
			);
			$response->cacheFor(600, false, true);

			return $response;
		} catch (Exception $e) {
			return $this->fail($e, [], 404);
		}
	}

	/**
	 * The remote account behind a signed GET, resolved once a request, so a
	 * follower on another server is served what they may read.
	 */
	private function reader(): ?Person {
		if ($this->readerResolved) {
			return $this->fetchReader;
		}

		$this->readerResolved = true;
		$this->fetchReader = $this->authorizedFetchService->reader($this->request);

		return $this->fetchReader;
	}

	/**
	 * Refuses the request when this instance is in secure mode and nothing
	 * signed it.
	 *
	 * @throws SignatureException
	 */
	private function assertReadable(): void {
		$this->authorizedFetchService->assertReadable($this->request, $this->reader());
	}

	/**
	 * Alias of actor() at /apps/social/@username.
	 *
	 * @param string $username
	 *
	 * @return Response
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/')]
	public function actorAlias(string $username): Response {
		return $this->actor($username);
	}

	/**
	 * The shared inbox, the entry point for activities to every local actor.
	 *
	 * Verifies the HTTP signature, then checks the origin against the federation
	 * rules and its rate ceiling, parses the activity, checks a Linked Data
	 * signature or that the signer is the actor, handles the activity, answers
	 * 200 and then processes the stream cache queue.
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/inbox')]
	public function sharedInbox(): Response {
		$body = '';
		$origin = '';
		try {
			$this->inboxLimiter->assertAllowed($this->request);
			// bounded: the digest below hashes whatever arrives, before
			// anything about the sender is known — see InboxLimiter::readBody()
			$body = $this->inboxLimiter->readBody($this->request);

			$requestTime = 0;
			$signer = '';
			$origin = $this->signatureService->checkRequest($this->request, $body, $requestTime, $signer);
			$this->fediverseService->authorized($origin);
			if ($this->signatureService->isReplayed($this->request)) {
				// these signed bytes were taken in once already
				return $this->success();
			}

			// the per-origin ceiling is spent only once the origin is proven, so
			// nobody can throttle an instance by writing its name
			$this->inboxLimiter->assertOriginAllowed($origin);

			$activity = $this->importService->importFromJson($body);
			$this->signatureService->assertRemoteActor($activity);
			if (!$this->signatureService->checkObject($activity)) {
				// without a Linked Data signature only the request's key vouches for the
				// activity, so it has to be the actor's own
				try {
					$this->signatureService->assertSignerSpeaksFor($signer, $activity);
				} catch (InvalidOriginException $e) {
					return $this->acceptForwarded($activity, $origin, $e);
				}
				// the signer is the actor here, and its address keeps the port
				$activity->setOrigin(
					ConfigService::authorityOf($signer) ?: $origin, SignatureService::ORIGIN_HEADER, $requestTime
				);
			}

			// A Linked Data signature moves the origin to the object's own actor, whose
			// host has not been checked yet: the origin the activity is stored under is
			// the one that has to pass.
			$this->fediverseService->authorized($activity->getOrigin());

			try {
				$this->importService->parseIncomingRequest($activity);
			} catch (ItemUnknownException $e) {
				$this->logUnhandled($e, $origin, $activity);
			}

			$this->signatureService->rememberRequest($this->request);
			$this->async();
			$this->streamQueueService->cacheStreamByToken($activity->getRequestToken());

			return $this->success();
		} catch (SignatureIsGoneException $e) {
			return $this->success();
		} catch (TooManyRequestsException $e) {
			return new DataResponse(['error' => 'too many requests'], Http::STATUS_TOO_MANY_REQUESTS);
		} catch (PayloadTooLargeException $e) {
			return new DataResponse(
				['error' => 'body too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		} catch (ItemUnknownException $e) {
			return $this->acceptUnhandledType($e, $origin, $body);
		} catch (Exception $e) {
			return $this->rejectDelivery($e);
		}
	}

	/**
	 * A local actor's inbox: as sharedInbox(), for an actor that has to exist.
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/@{username}/inbox')]
	public function inbox(string $username): Response {
		$body = '';
		$origin = '';
		try {
			$this->inboxLimiter->assertAllowed($this->request);
			// bounded: the digest below hashes whatever arrives, before
			// anything about the sender is known — see InboxLimiter::readBody()
			$body = $this->inboxLimiter->readBody($this->request);

			$requestTime = 0;
			$signer = '';
			$origin = $this->signatureService->checkRequest($this->request, $body, $requestTime, $signer);
			$this->fediverseService->authorized($origin);
			if ($this->signatureService->isReplayed($this->request)) {
				// these signed bytes were taken in once already
				return $this->success();
			}

			// the per-origin ceiling is spent only once the origin is proven, so
			// nobody can throttle an instance by writing its name
			$this->inboxLimiter->assertOriginAllowed($origin);

			// throws for an inbox of an account this server does not have
			$this->cacheActorService->getFromLocalAccount($username);

			$activity = $this->importService->importFromJson($body);
			$this->signatureService->assertRemoteActor($activity);
			if (!$this->signatureService->checkObject($activity)) {
				// without a Linked Data signature only the request's key vouches for the
				// activity, so it has to be the actor's own
				try {
					$this->signatureService->assertSignerSpeaksFor($signer, $activity);
				} catch (InvalidOriginException $e) {
					return $this->acceptForwarded($activity, $origin, $e);
				}
				// the signer is the actor here, and its address keeps the port
				$activity->setOrigin(
					ConfigService::authorityOf($signer) ?: $origin, SignatureService::ORIGIN_HEADER, $requestTime
				);
			}

			// A Linked Data signature moves the origin to the object's own actor, whose
			// host has not been checked yet: the origin the activity is stored under is
			// the one that has to pass.
			$this->fediverseService->authorized($activity->getOrigin());

			try {
				$this->importService->parseIncomingRequest($activity);
			} catch (ItemUnknownException $e) {
				$this->logUnhandled($e, $origin, $activity);
			}

			$this->signatureService->rememberRequest($this->request);
			$this->async();
			$this->streamQueueService->cacheStreamByToken($activity->getRequestToken());

			return $this->success();
		} catch (SignatureIsGoneException $e) {
			return $this->success();
		} catch (TooManyRequestsException $e) {
			return new DataResponse(['error' => 'too many requests'], Http::STATUS_TOO_MANY_REQUESTS);
		} catch (PayloadTooLargeException $e) {
			return new DataResponse(
				['error' => 'body too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		} catch (ItemUnknownException $e) {
			return $this->acceptUnhandledType($e, $origin, $body);
		} catch (Exception $e) {
			return $this->rejectDelivery($e);
		}
	}

	/**
	 * An activity signed by somebody other than its actor, with nothing to prove
	 * the actor wrote it: ActivityPub's inbox forwarding (§7.1.2), as Mastodon
	 * forwards replies to a post's followers.
	 *
	 * The body is not believed: a `Create` or `Update` of an object on its
	 * actor's own host queues a fetch from there
	 * (`StreamQueueService::fetchFromOrigin()`); a forwarded `Delete` or
	 * `Announce` is acknowledged and dropped, as Mastodon does; anything else is
	 * refused, since nothing forwards a Follow or a Like.
	 *
	 * @throws InvalidOriginException for an activity no forwarder sends
	 */
	private function acceptForwarded(ACore $activity, string $origin, InvalidOriginException $e): Response {
		$type = $activity->getType();
		if (!in_array($type, [Create::TYPE, Update::TYPE, Delete::TYPE, Announce::TYPE], true)) {
			throw $e;
		}

		$objectId = $activity->hasObject() ? $activity->getObject()->getId() : $activity->getObjectId();
		$token = $this->uuid();
		$queued = in_array($type, [Create::TYPE, Update::TYPE], true)
			&& $this->isOnRemoteActorHost($objectId, $activity->getActorId())
			&& $this->streamQueueService->queueFetch($token, $objectId);

		$this->logger->info('a forwarded activity was not signed by its actor', [
			'activityType' => $type,
			'activity' => $activity->getId(),
			'object' => $objectId,
			'actor' => $activity->getActorId(),
			'origin' => $origin,
			'fetch' => $queued,
		]);

		$this->signatureService->rememberRequest($this->request);
		if ($queued) {
			$this->async();
			$this->streamQueueService->cacheStreamByToken($token);
		}

		return new DataResponse(['result' => [], 'status' => 1], Http::STATUS_ACCEPTED);
	}

	/**
	 * Whether an object is on its actor's host, and that host is not this one:
	 * a post of ours is never fetched back from ourselves.
	 */
	private function isOnRemoteActorHost(string $objectId, string $actorId): bool {
		$objectHost = strtolower((string)parse_url($objectId, PHP_URL_HOST));
		if ($objectHost === '' || $objectHost !== strtolower((string)parse_url($actorId, PHP_URL_HOST))) {
			return false;
		}

		try {
			return $objectHost !== strtolower($this->configService->getCloudHost());
		} catch (SocialAppConfigException $e) {
			return false;
		}
	}

	/**
	 * A delivery this instance will not take, answered with the status of the
	 * actual refusal. Mastodon retries a 5xx for about two days, so a refusal
	 * answered 500 is redelivered over and over; a 4xx is final.
	 */
	private function rejectDelivery(Exception $e): Response {
		return $this->fail($e, [], $this->statusForRejection($e));
	}

	/**
	 * 500 is reserved for a fault of this instance; any other refusal has a
	 * status that says which part of the request was unacceptable.
	 */
	private function statusForRejection(Exception $e): int {
		return match (true) {
			// a decision about who may talk to this instance, not a failure
			$e instanceof UnauthorizedFediverseException => Http::STATUS_FORBIDDEN,

			// the sender is fine, but the signing key's host could not be reached or
			// is in backoff: the one refusal that should be redelivered
			$e instanceof RequestNetworkException,
			$e instanceof RequestServerException,
			$e instanceof SignatureException
			&& $e->getCode() === Http::STATUS_SERVICE_UNAVAILABLE => Http::STATUS_SERVICE_UNAVAILABLE,

			// nothing about the request proves who sent it: no signature, one
			// that does not verify, a replay, a Date outside the window, a
			// Signature header missing its parts, or an activity whose actor is
			// not the origin that signed for it
			$e instanceof SignatureException,
			$e instanceof MalformedArrayException,
			$e instanceof InvalidOriginException => Http::STATUS_UNAUTHORIZED,

			// the bytes could not be read as an activity, and redelivering the
			// same bytes will not change that
			$e instanceof ActivityPubFormatException,
			$e instanceof DateTimeException => Http::STATUS_BAD_REQUEST,

			// addressed to a local actor that does not exist
			$e instanceof CacheActorDoesNotExistException,
			$e instanceof ActorDoesNotExistException => Http::STATUS_NOT_FOUND,

			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
	}

	/**
	 * An activity that was modelled but has no handler. The delivery succeeded,
	 * so the answer stays a success; the log line is the only trace of a type
	 * being ignored.
	 */
	private function logUnhandled(ItemUnknownException $e, string $origin, ACore $activity): void {
		$this->logger->notice('an incoming activity was not handled', [
			'activityType' => $activity->getType(),
			'subType' => $activity->getSubType(),
			'activity' => $activity->getId(),
			'object' => $activity->getObjectId(),
			'actor' => $activity->getActorId(),
			'origin' => $origin,
			'reason' => $e->getMessage(),
		]);
	}

	/**
	 * An activity whose `type` this app has no model for, logged from the raw
	 * document. Answered as a success, since a 5xx would make the peer redeliver
	 * something that will never be understood.
	 */
	private function acceptUnhandledType(ItemUnknownException $e, string $origin, string $body): Response {
		$data = json_decode($body, true);
		$type = is_array($data) ? (string)($data['type'] ?? '') : '';

		$this->logger->notice('an incoming activity is of an unknown type', [
			'activityType' => $type,
			'activity' => is_array($data) ? (string)($data['id'] ?? '') : '',
			'origin' => $origin,
			'reason' => $e->getMessage(),
		]);

		return $this->success();
	}

	/**
	 * A local actor's inbox, as an empty collection, for a remote server's GET.
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/inbox')]
	public function getInbox(string $username): Response {
		try {
			$this->assertReadable();
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			$collection = new OrderedCollection();
			$collection->setId($actor->getInbox());
			$collection->setTotalItems(0);

			return $this->activityPubSuccess($collection);
		} catch (Exception $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * An actor's outbox collection, or one page of it.
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// Two verbs on one method need two route names: a route is keyed by
	// controller, method and postfix, so the POST would replace the GET.
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/outbox')]
	#[FrontpageRoute(verb: 'POST', url: '/@{username}/outbox', postfix: 'post')]
	public function outbox(string $username, string $page = '', string $max_id = ''): Response {
		try {
			$this->assertReadable();
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			$requested = OrderedCollectionPage::requestedPage($page);
			if ($requested > 0 || $max_id !== '') {
				return $this->activityPubSuccess($this->outboxPage($actor, max(1, $requested), $max_id));
			}

			return $this->activityPubSuccess($this->streamService->getOutboxCollection($actor));
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * One page of an actor's outbox: the `Create` activities of their public
	 * posts, newest first, as Mastodon serialises them. Each activity is rebuilt
	 * around the stored post, the durable record, not replayed from the delivery.
	 */
	private function outboxPage(Person $actor, int $page, string $before = ''): OrderedCollectionPage {
		$items = [];
		$numbered = ($before === '');
		// a cursor is a nid; anything else starts no page
		$posts = (!$numbered && !ctype_digit($before))
			? []
			: $this->streamRequest->getPublicByAuthor(
				$actor->getId(),
				OrderedCollection::PAGE_SIZE,
				$numbered ? ($page - 1) * OrderedCollection::PAGE_SIZE : 0,
				$before
			);

		// The activities live inside the page, so none of them is a document
		// root: giving each one a parent is what keeps a `@context` off all
		// forty of them.
		$enclosing = new OrderedCollectionPage();

		foreach ($posts as $post) {
			// the author was joined in, which flags the row for this app's own
			// bookkeeping (`source`, `cache`, `actor_info`, …); a peer gets the
			// Note as `displayPost()` serves it
			$post->setCompleteDetails(false);
			$create = new Create($enclosing);
			$post->setParent($create);
			$create->setId($post->getId() . '/activity');
			$create->setActorId($post->getAttributedTo());
			$create->setPublished($post->getPublished());
			$create->setTo($post->getTo());
			$create->setToArray($post->getToArray());
			$create->setCcArray($post->getCcArray());
			$create->setObject($post);

			$items[] = $create->exportAsActivityPub();
		}

		$last = end($posts);
		$next = ($last === false) ? '' : (string)$last->getNid();

		return $numbered
			? OrderedCollectionPage::of($actor->getOutbox(), $actor->getOutbox(), $page, $items, $next)
			: OrderedCollectionPage::after($actor->getOutbox(), $actor->getOutbox(), 'max_id', $before, $items, $next);
	}

	/**
	 * The actor's featured collection: the posts pinned to their profile, which
	 * remote servers read from here since a pin is not federated as an activity.
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/collections/featured')]
	public function featured(string $username): Response {
		try {
			$this->assertReadable();
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$posts = $this->pinService->getPinnedPosts($actor->getId());

			$collection = new OrderedCollection();
			$collection->setId($actor->getFeatured());
			$collection->setTotalItems(count($posts));
			$collection->setOrderedItems(
				array_map(
					static function (Stream $post): array {
						$post->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
						$post->setCompleteDetails(false);

						return $post->exportAsActivityPub();
					},
					$posts
				)
			);

			return $this->activityPubSuccess($collection);
		} catch (Exception $e) {
			return $this->fail($e, [], 404);
		}
	}

	/**
	 * An actor's followers collection, or one page of it.
	 *
	 * @param string $username
	 *
	 * @return Response
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/followers')]
	public function followers(string $username, string $page = '', string $max_id = ''): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->followers($username);
		}

		try {
			$this->assertReadable();
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			// the collection's `first` is `?page=1`, so a page number has to answer
			// with a page, not the collection again
			$requested = OrderedCollectionPage::requestedPage($page);
			if ($requested > 0 || $max_id !== '') {
				if ($this->listsHidden($actor)) {
					return $this->hiddenListPage();
				}

				return $this->activityPubSuccess(
					$this->followService->getFollowersPage($actor, max(1, $requested), $max_id)
				);
			}

			return $this->activityPubSuccess($this->followService->getFollowersCollection($actor));
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * An actor's following collection, or one page of it.
	 *
	 * @param string $username
	 *
	 * @return Response
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/following')]
	public function following(string $username, string $page = '', string $max_id = ''): Response {
		if (!$this->checkSourceActivityStreams()) {
			return $this->socialPubController->following($username);
		}

		try {
			$this->assertReadable();
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			$requested = OrderedCollectionPage::requestedPage($page);
			if ($requested > 0 || $max_id !== '') {
				if ($this->listsHidden($actor)) {
					return $this->hiddenListPage();
				}

				return $this->activityPubSuccess(
					$this->followService->getFollowingPage($actor, max(1, $requested), $max_id)
				);
			}

			return $this->activityPubSuccess($this->followService->getFollowingCollection($actor));
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * Whether the account hides its follower and following lists. The
	 * collection itself then carries `totalItems` and no `first`
	 * (`FollowService::getFollowersCollection()`), and a page of it is not
	 * served to anybody.
	 */
	private function listsHidden(Person $actor): bool {
		return $this->hiddenCollections?->hidesFor($actor) === true;
	}

	/** A page of a hidden list: refused, as Mastodon refuses it. */
	private function hiddenListPage(): DataResponse {
		return new DataResponse(['error' => 'This account hides its followers and the accounts it follows'], Http::STATUS_FORBIDDEN);
	}

	/**
	 * FEP-044f: the approval a quote of one of our posts rests on.
	 *
	 * Mastodon fetches the approval URI from our `Accept` and renders the quote
	 * only when it names the same two posts and the quoted author. The stamp is
	 * just the quoting post's id encoded, so it is no approval by itself: the
	 * quote is approved by the post's policy when anybody may quote it, else by
	 * the grant `QuoteRequestInterface::accept()` recorded. Asked fresh each
	 * time, so a narrowed policy or revoked grant withdraws the approval.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/{token}/quote_authorizations/{stamp}')]
	public function displayQuoteAuthorization(string $username, string $token, string $stamp): Response {
		$quotedId = $this->configService->getSocialUrl() . '@' . $username . '/' . $token;

		try {
			$quoted = $this->streamService->getStreamById($quotedId);
		} catch (StreamNotFoundException $e) {
			return $this->fail($e, ['stream' => $quotedId], Http::STATUS_NOT_FOUND);
		}

		$quoting = QuoteRequestInterface::instrumentOfStamp($stamp);

		// no viewer is set, so this is the anonymous view: an approval is public,
		// and must not confirm that a post the asker cannot read exists
		if ($quoting === '' || !$quoted->isLocal() || !$this->quoteIsApproved($quoted, $quoting)) {
			return $this->fail(
				new ItemUnknownException('no such quote authorization'),
				['stream' => $quotedId],
				Http::STATUS_NOT_FOUND
			);
		}

		$authorization = new QuoteAuthorization();
		$authorization->setId($quotedId . '/quote_authorizations/' . $stamp);
		$authorization->setAttributedTo($quoted->getAttributedTo());
		$authorization->setInteractingObject($quoting);
		$authorization->setInteractionTarget($quotedId);

		return $this->activityPubSuccess($authorization);
	}

	/**
	 * The approval of one reply to one of our posts, returned to a peer that
	 * accepted a GoToSocial ReplyRequest. The signed stamp binds the reply URI
	 * to this target, so guessing another reply or changing the target cannot
	 * produce an authorization.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/{token}/reply_authorizations/{stamp}')]
	public function displayReplyAuthorization(string $username, string $token, string $stamp): Response {
		$targetId = $this->configService->getSocialUrl() . '@' . $username . '/' . $token;
		try {
			$this->assertReadable();
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		}

		$secret = $this->systemConfig->getSystemValueString('secret', '');
		$replyId = $secret === '' ? '' : ReplyRequestInterface::replyFromStamp($stamp, $targetId, $secret);

		try {
			$target = $this->streamService->getStreamById($targetId);
		} catch (StreamNotFoundException $e) {
			return $this->fail($e, ['stream' => $targetId], Http::STATUS_NOT_FOUND);
		}

		if ($replyId === '' || !$target->isLocal() || !$this->isOpenToAnybody($target)) {
			return $this->fail(
				new ItemUnknownException('no such reply authorization'),
				['stream' => $targetId],
				Http::STATUS_NOT_FOUND
			);
		}

		$authorization = new ReplyAuthorization();
		$authorization->setId($targetId . '/reply_authorizations/' . $stamp);
		$authorization->setAttributedTo($target->getAttributedTo());
		$authorization->setInteractingObject($replyId);
		$authorization->setInteractionTarget($targetId);

		return $this->activityPubSuccess($authorization);
	}

	/**
	 * Whether a post was addressed to the public collection -- `public` or
	 * `unlisted` -- which is what lets an anonymous reader be told about it.
	 */
	private function isOpenToAnybody(Stream $post): bool {
		return $post->isPublic()
			|| in_array($post->getVisibility(), [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);
	}

	/**
	 * Whether a quote of `$quoted` by `$quoting` is approved: by the post's
	 * policy, when that lets anybody quote it, or by the grant recorded when
	 * the author's server accepted that particular `QuoteRequest`.
	 */
	private function quoteIsApproved(Stream $quoted, string $quoting): bool {
		if ($quoted->isQuotable() && $quoted->effectiveQuotePolicy() === Stream::QUOTE_POLICY_PUBLIC) {
			return true;
		}

		return $this->quoteGrantRequest->get($quoted->getId(), $quoting) !== null;
	}

	/**
	 * The instance's own `Application` actor. Every outbound signed fetch names
	 * `<this url>#main-key` as its `keyId`, and a peer with authorized fetch
	 * dereferences it before it answers.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/actor')]
	public function instanceActor(): Response {
		try {
			return $this->activityPubSuccess($this->instanceActorService->getActor());
		} catch (Exception $e) {
			return $this->fail($e, [], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * The `replies` collection of a post, and its pages, so a reader on a third
	 * instance sees the replies.
	 *
	 * No viewer is set: `StreamService` selects only public replies for the
	 * anonymous view, since even the id of a followers-only reply lets anybody
	 * fetch it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/{token}/replies')]
	public function replies(string $username, string $token, string $page = '', string $min_id = ''): Response {
		$post = $this->openLocalPost($username, $token);
		if ($post instanceof Response) {
			return $post;
		}

		$requested = OrderedCollectionPage::requestedPage($page);
		if ($requested > 0 || $min_id !== '') {
			return $this->activityPubSuccess($this->streamService->getRepliesPage($post, max(1, $requested), $min_id));
		}

		return $this->activityPubSuccess($this->streamService->getRepliesCollection($post));
	}

	/**
	 * How many likes a post has had, which PeerTube fetches for every video it
	 * takes in. The count and not the accounts, as Mastodon and PeerTube
	 * publish it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/{token}/likes')]
	public function likes(string $username, string $token): Response {
		$post = $this->openLocalPost($username, $token);
		if ($post instanceof Response) {
			return $post;
		}

		return $this->activityPubSuccess($this->streamService->getRatesCollection($post, Stream::LIKES_PATH));
	}

	/** The other of PeerTube's two counts; see likes(). */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/{token}/dislikes')]
	public function dislikes(string $username, string $token): Response {
		$post = $this->openLocalPost($username, $token);
		if ($post instanceof Response) {
			return $post;
		}

		return $this->activityPubSuccess($this->streamService->getRatesCollection($post, Stream::DISLIKES_PATH));
	}

	/**
	 * The post a collection of it is asked for, or the answer that refuses it:
	 * a post held elsewhere has its collections there, and a followers-only or
	 * direct post is not confirmed to exist, as displayPost() answers too.
	 */
	private function openLocalPost(string $username, string $token): Stream|Response {
		$postId = $this->configService->getSocialUrl() . '@' . $username . '/' . $token;

		try {
			$this->assertReadable();
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$post = $this->streamService->getStreamById($postId);
		} catch (Exception $e) {
			return $this->fail($e, ['stream' => $postId], Http::STATUS_NOT_FOUND);
		}

		if (!$post->isLocal() || !$this->isOpenToAnybody($post)) {
			return $this->fail(
				new ItemUnknownException('no such collection'),
				['stream' => $postId],
				Http::STATUS_NOT_FOUND
			);
		}

		return $post;
	}

	/**
	 * One live story, for a peer that would rather fetch it than trust its copy.
	 * Served to the author and to signed fetches by their followers; anybody
	 * else, and any expired story, gets a 404, so whether an account has one is
	 * told to nobody else.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/stories/{id}', requirements: ['id' => '\\d+'])]
	public function story(string $username, int $id): Response {
		if (!$this->checkSourceActivityStreams()) {
			// a browser has nothing to be shown here: the story is in the app,
			// behind the reader's own carousel
			return $this->socialPubController->actor($username);
		}

		try {
			$this->assertReadable();
		} catch (SignatureException $e) {
			return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$author = $this->cacheActorService->getFromLocalAccount($username);
			$storyId = $this->storyService->idOf($author, $id);
			$story = $this->storyService->bySourceId($storyId);

			// The capability the `Add` carried, presented as a bearer token, which is
			// how Pixelfed fetches a story: the token names this one story and stops
			// working when the story does.
			if (!$this->storyService->bearcapMatches($storyId, $this->bearerToken())) {
				$reader = $this->reader();
				if ($reader === null || !$this->storyService->mayRead($story, $reader)) {
					throw new ItemNotFoundException('unknown story');
				}
			}

			return $this->activityPubSuccess($this->storyService->asActivityPub($author, $story));
		} catch (Exception $e) {
			return $this->fail($e, ['story' => $id], Http::STATUS_NOT_FOUND, false);
		}
	}

	/** The bearer token a capability fetch presents, or `''`. */
	private function bearerToken(): string {
		$header = trim($this->request->getHeader('Authorization'));

		return (stripos($header, 'bearer ') === 0) ? trim(substr($header, 7)) : '';
	}

	#[NoCSRFRequired]
	#[PublicPage]
	/**
	 * A post: as ActivityPub for a client that asks for it, as a page for a
	 * browser.
	 *
	 * Routed in `appinfo/routes.php`: `{token}` matches any single segment
	 * (`inbox`, `outbox`, `collections`, …), so this route has to be offered
	 * last, which attribute routes cannot guarantee.
	 *
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function displayPost(string $username, string $token): Response {
		try {
			return $this->fixToken($username, $token);
		} catch (RealTokenException $e) {
		}

		if ($this->checkSourceActivityStreams()) {
			try {
				$this->assertReadable();
			} catch (SignatureException $e) {
				return $this->fail($e, [], Http::STATUS_UNAUTHORIZED);
			}

			try {
				$viewer = $this->accountService->getCurrentViewer();
				$this->streamService->setViewer($viewer);
			} catch (AccountDoesNotExistException $e) {
				// nobody local is asking: whoever signed the fetch is the reader, so a
				// followers-only post reaches its followers on other servers
				$reader = $this->reader();
				if ($reader !== null) {
					$this->streamService->setViewer($reader);
				}
			}

			$postId = $this->configService->getSocialUrl() . '@' . $username . '/' . $token;
			try {
				$stream = $this->streamService->getStreamById($postId, true);
			} catch (StreamNotFoundException $e) {
				return $this->fail($e, ['stream' => $postId], Http::STATUS_NOT_FOUND);
			}

			$stream->setCompleteDetails(false);

			return $this->activityPubSuccess($stream);
		}

		// a browser: who is reading decides between the app and the public page,
		// and the public controller knows both
		return $this->socialPubController->displayPost($username, $token);
	}

	/**
	 * @param string $username
	 * @param string $token
	 *
	 * @return Response
	 * @throws RealTokenException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	private function fixToken(string $username, string $token): Response {
		$t = strtolower($token);
		if ($t === 'outbox') {
			return $this->outbox($username);
		}

		if ($t === 'followers') {
			return $this->followers($username);
		}

		if ($t === 'following') {
			return $this->following($username);
		}

		throw new RealTokenException();
	}

	/**
	 * Whether the request asks for ActivityPub rather than a page to browse.
	 *
	 * @return bool
	 */
	private function checkSourceActivityStreams(): bool {
		$accepted = [
			'application/ld+json',
			'application/activity+json'
		];

		$accepts = explode(',', $this->request->getHeader('Accept'));
		$accepts = array_map([$this, 'trimHeader'], $accepts);

		foreach ($accepts as $accept) {
			if (in_array($accept, $accepted, true)) {
				return true;
			}
		}

		return false;
	}

	private function trimHeader(string $header) {
		$header = trim($header);

		$pos = strpos($header, ';');
		if ($pos === false) {
			return $header;
		}

		return substr($header, 0, $pos);
	}
}
