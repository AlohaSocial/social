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
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\QuoteAuthorization;
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
		ConfigService $configService,
		LoggerInterface $logger,
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
	 * A responder answering in the media type the peer asked for.
	 *
	 * It builds on the framework's own `json` responder so that a returned
	 * `DataResponse` keeps its status and headers: a 404, 410 or 401 must
	 * reach the peer as such, not as a 200 with an error in the body. The
	 * header has to be a type the peer recognises as ActivityStreams —
	 * GoToSocial asks for the profiled `application/ld+json` first and
	 * refuses any other answer.
	 */
	private function activityStreamsResponder(string $contentType): \Closure {
		return function ($response) use ($contentType): Response {
			$resp = $this->buildResponse($response, 'json');
			$resp->addHeader('Content-Type', $contentType);

			return $resp;
		};
	}

	/**
	 * returns information about an Actor, based on the username.
	 *
	 * This method should be called when a remote ActivityPub server require information
	 * about a local Social account
	 *
	 * The format is pure Json
	 *
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
	 * An account's public posts as an RSS feed.
	 *
	 * PeerTube publishes one per channel and Mastodon one per account, and
	 * PeerTube's users in particular live in feed readers and podcast apps: a
	 * channel with no feed is one they cannot subscribe to from outside,
	 * whatever else it offers. A video carries an `enclosure`, which is what
	 * makes such a feed usable in a podcast client at all.
	 *
	 * Built from an **anonymous** read — the same one a stranger visiting the
	 * profile gets — because the feed is readable signed out. That is not a
	 * convenience: a feed is the easiest possible way to leak a followers-only
	 * post, since one wrong predicate puts it in somebody's reader and out of
	 * reach for ever. `FeedService` refuses a non-public post a second time.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}.rss')]
	public function feed(string $username): Response {
		// No secure-mode check, unlike every ActivityPub route: this is RSS,
		// read by feed readers and podcast clients that sign nothing, and an
		// instance in secure mode is refusing *ActivityPub* to strangers rather
		// than withdrawing its public feeds.
		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);

			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
				->setProbe(ProbeOptions::ACCOUNT)
				->setAccountId($actor->getId())
				->setLimit(FeedService::LIMIT);

			// no viewer at all: the account query answers an anonymous reader
			// with public posts and nothing else, which is the rule that keeps
			// this safe rather than a switch somebody can get wrong once
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
	 * The remote account behind a signed GET, resolved once a request.
	 *
	 * Signature verification ran on inbox POSTs only, so this instance could
	 * not tell one remote reader from another: every ActivityPub GET served
	 * what an anonymous reader gets. That failed closed, which is safe, and is
	 * also why a follower on another server saw a profile with nothing on it.
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
	 * Alias to the actor() method.
	 *
	 * Normal path is /apps/social/users/username
	 * This alias is /apps/social/@username
	 *
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
	 * Shared inbox — receives incoming ActivityPub activities from remote servers.
	 *
	 * This is the primary entry point for federation (sharedInbox receives for all
	 * local actors). Flow:
	 *  1. Read raw JSON body
	 *  2. Verify HTTP Signature: ensures the request came from the claimed origin
	 *  3. Check Fediverse authorization (blocklist/allowlist) and the per-origin
	 *     rate ceiling, now that the origin is proven
	 *  4. Parse JSON into an ActivityPub model object
	 *  5. Verify LinkedDataSignature (if present), else trust HTTP signature origin
	 *  6. Process the incoming activity (varies by type: Follow→auto-accept,
	 *     Create→cache post, Accept→mark follow as accepted)
	 *  7. Send HTTP 200, then async-process the stream cache queue
	 *
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

			// the per-origin ceiling is spent here rather than on the way in:
			// before this line the origin is only what the sender wrote, and
			// counting against a name anyone can write is a way to throttle the
			// instance that owns it
			$this->inboxLimiter->assertOriginAllowed($origin);

			$activity = $this->importService->importFromJson($body);
			if (!$this->signatureService->checkObject($activity)) {
				// no Linked Data signature to vouch for the object, so the only
				// thing standing behind this activity is the key that signed the
				// request — and it has to be the actor's own
				try {
					$this->signatureService->assertSignerSpeaksFor($signer, $activity);
				} catch (InvalidOriginException $e) {
					return $this->acceptForwarded($activity, $origin, $e);
				}
				$activity->setOrigin($origin, SignatureService::ORIGIN_HEADER, $requestTime);
			}

			// A Linked Data signature moves the origin off the host that
			// delivered the request and onto the object's own actor, and that
			// host has not been asked about. A relay is entitled to forward for
			// anyone; whom this instance federates with is its own decision, so
			// it is the origin the activity is finally stored under that has to
			// pass.
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
	 * User-specific inbox — receives incoming ActivityPub activities for a specific user.
	 *
	 * Same logic as sharedInbox but also verifies the local actor exists.
	 *
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

			// the per-origin ceiling is spent here rather than on the way in:
			// before this line the origin is only what the sender wrote, and
			// counting against a name anyone can write is a way to throttle the
			// instance that owns it
			$this->inboxLimiter->assertOriginAllowed($origin);

			// throws for an inbox of an account this server does not have
			$this->cacheActorService->getFromLocalAccount($username);

			$activity = $this->importService->importFromJson($body);
			if (!$this->signatureService->checkObject($activity)) {
				// no Linked Data signature to vouch for the object, so the only
				// thing standing behind this activity is the key that signed the
				// request — and it has to be the actor's own
				try {
					$this->signatureService->assertSignerSpeaksFor($signer, $activity);
				} catch (InvalidOriginException $e) {
					return $this->acceptForwarded($activity, $origin, $e);
				}
				$activity->setOrigin($origin, SignatureService::ORIGIN_HEADER, $requestTime);
			}

			// A Linked Data signature moves the origin off the host that
			// delivered the request and onto the object's own actor, and that
			// host has not been asked about. A relay is entitled to forward for
			// anyone; whom this instance federates with is its own decision, so
			// it is the origin the activity is finally stored under that has to
			// pass.
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
	 * An activity signed by somebody other than its actor, with nothing on it
	 * to prove the actor wrote it.
	 *
	 * That is ActivityPub's inbox forwarding (§7.1.2): Mastodon passes a reply
	 * on to the followers of the post it answers, signed with its own user's
	 * key, and a reply from a server that makes no Linked Data signatures
	 * arrives exactly like this. Refusing it was final for the forwarder, so the
	 * reply never reached this copy of the thread. The body is still not
	 * believed: a `Create` or `Update` of an object on its actor's own host is
	 * answered by queueing a fetch of that object from there, and only what
	 * that server serves is taken in (`StreamQueueService::fetchFromOrigin()`).
	 * A forwarded `Delete` or `Announce` is acknowledged and dropped, as
	 * Mastodon drops what it cannot verify. Any other activity is still
	 * refused: nothing forwards a Follow or a Like.
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
	 * A delivery this instance will not take, answered with what the refusal
	 * actually is.
	 *
	 * The status is the only thing a peer reads to decide what to do next, and
	 * Mastodon re-queues a 5xx with backoff for about two days. Answering every
	 * rejection 500 — which a catch-all `fail()` did — therefore turned one
	 * refused delivery into a dozen, and made blocking an instance *multiply*
	 * its traffic instead of ending it. A 4xx is final: the peer drops the
	 * activity and stops.
	 */
	private function rejectDelivery(Exception $e): Response {
		return $this->fail($e, [], $this->statusForRejection($e));
	}

	/**
	 * 500 is reserved for a fault of this instance's own — anything else the
	 * inbox refuses has a status that says which part of the request was
	 * unacceptable.
	 */
	private function statusForRejection(Exception $e): int {
		return match (true) {
			// a decision about who may talk to this instance, not a failure
			$e instanceof UnauthorizedFediverseException => Http::STATUS_FORBIDDEN,

			// the sender is fine, the verification is not finishable right now:
			// the host holding the signing key could not be reached, or its
			// last fetch failed recently enough to still be in backoff. This is
			// the one rejection that *should* be redelivered.
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
	 * An activity that was understood well enough to be modelled, but for which
	 * there is no handler.
	 *
	 * The delivery itself succeeded, so the answer stays a success — what was
	 * missing was any record of what arrived. Without this line the only symptom
	 * of a whole class of activity being ignored is that nothing happens, which
	 * is indistinguishable from the peer never having sent it.
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
	 * An activity whose own `type` this app has no model for: it never became an
	 * item, so there is nothing to name it but the raw document.
	 *
	 * This used to answer 500, which makes a peer redeliver something we will
	 * never understand — for as long as its own queue allows.
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
	 * Method is called when a remote ActivityPub server wants to GET in the INBOX of a USER
	 * Checking that the user exists, and that the header is properly signed.
	 *
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
	 * Outbox. does nothing.
	 *
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// Two verbs on one method need two route names: a route is keyed by
	// controller, method and postfix, so without one the POST registration
	// replaced the GET and remote servers fetching an outbox got nothing.
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/outbox')]
	#[FrontpageRoute(verb: 'POST', url: '/@{username}/outbox', postfix: 'post')]
	public function outbox(string $username, string $page = '', string $max_id = ''): Response {
		//		if (!$this->checkSourceActivityStreams()) {
		//			return $this->socialPubController->outbox($username);
		//		}

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
	 * posts, newest first, as Mastodon serialises them.
	 *
	 * The activity is rebuilt around the stored post rather than replayed from
	 * what was sent: the post is the durable record, and a consumer reading an
	 * outbox wants the object, not our original delivery envelope.
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
	 * The actor's featured collection: the posts pinned to their profile.
	 * Remote servers read pinned posts from here — a pin is never federated
	 * as an activity of its own.
	 *
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
	 * followers. does nothing.
	 *
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

			// The collection has always advertised `first` as `?page=1` while
			// nothing read the parameter, so `?page=1` answered with the
			// collection again and its `first` pointed at itself: a consumer
			// following it looped or gave up.
			$requested = OrderedCollectionPage::requestedPage($page);
			if ($requested > 0 || $max_id !== '') {
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
	 * following. does nothing.
	 *
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
	 * FEP-044f: the approval a quote of one of our posts rests on.
	 *
	 * A peer that received an `Accept` from us holds only the URI of the
	 * approval; Mastodon fetches it and will not render the quote inline unless
	 * what comes back names the same two posts and is attributed to the quoted
	 * author. The stamp carries the quoting post's id, and the question the
	 * document answers — may this be quoted? — is answered the way
	 * `QuoteRequestInterface` answered the request: a post whose policy lets
	 * anybody quote it approves every quote, and a post whose policy is
	 * narrower approves exactly the quotes its author's server accepted,
	 * which `QuoteRequestInterface::accept()` wrote down as a grant.
	 *
	 * The stamp alone is not an approval. Anyone can mint one, since it is
	 * only the quoting post's id encoded, so answering from the stamp and the
	 * post being quotable at all served a followers-only approval to a quote
	 * nobody had asked about.
	 *
	 * Asking now rather than remembering the old answer is deliberate. A post
	 * that has since been narrowed stops approving strangers' quotes, and a
	 * grant that was taken back is gone from the table; this endpoint stops
	 * answering, and a peer that re-checks sees the approval withdrawn — which
	 * is the behaviour the author asked for.
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

		// no viewer is set, so this is the anonymous view of the post: an
		// approval is a public statement, and one for a post the asker cannot
		// even read would be a way of confirming it exists
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
	 * The instance's own `Application` actor.
	 *
	 * Every outbound signed fetch names `<this url>#main-key` as its `keyId`,
	 * and a peer running authorized-fetch dereferences exactly this URL before
	 * it will answer. Without the route the signature names a key nobody can
	 * find, which is worse than sending none.
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
	 * The `replies` collection of a post, and its pages.
	 *
	 * A reply reaches the instances that hold the post it answers and nowhere
	 * else, so without this a reader on a third instance sees a post with no
	 * replies. The note points here with `replies`.
	 *
	 * No viewer is set: this is the anonymous view, and `StreamService` selects
	 * only public replies for it — a followers-only reply is not served here
	 * even as an id, because an id is enough to go and fetch the reply itself.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/@{username}/{token}/replies')]
	public function replies(string $username, string $token, string $page = '', string $min_id = ''): Response {
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

		// a post this instance does not hold has its replies somewhere else,
		// under an id this instance does not own; and a post addressed to its
		// followers or to named people is not confirmed to exist to anybody
		// else, which is what displayPost() answers them too
		if (!$post->isLocal() || !$this->isOpenToAnybody($post)) {
			return $this->fail(
				new ItemUnknownException('no such replies collection'),
				['stream' => $postId],
				Http::STATUS_NOT_FOUND
			);
		}

		$requested = OrderedCollectionPage::requestedPage($page);
		if ($requested > 0 || $min_id !== '') {
			return $this->activityPubSuccess($this->streamService->getRepliesPage($post, max(1, $requested), $min_id));
		}

		return $this->activityPubSuccess($this->streamService->getRepliesCollection($post));
	}

	/**
	 * One story, for a peer that would rather fetch it than trust the copy it
	 * was handed.
	 *
	 * A story is published to followers, so it is served to them: the author,
	 * or whoever signed the fetch and follows the author — the same rule the
	 * API keeps and the same follow rows the local timelines are built from.
	 * Anybody else gets the **404** a story that is not theirs to see has
	 * always been, here as in the client API: whether an account even has one
	 * up is told to its followers and to nobody else.
	 *
	 * Only while it is alive. An expired story is gone, and gone is a 404.
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

			// The capability the `Add` carried, presented as a bearer token —
			// which is how Pixelfed fetches a story: `StoryFetch` follows the
			// bearcap rather than signing a request, so there is no reader to
			// check against the followers. The token names this one story and
			// nothing else, and stops working when the story does.
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
	// `{token}` is one segment, so this url also matches `/@{username}/inbox`,
	// `/outbox`, `/followers` and `/following`. Attributes of one class are read
	// in method-declaration order, which is why this method is declared after
	// all four: moving it up the file would swallow them.
	/**
	 * A post: as ActivityPub for a client that asks for it, as a page for a
	 * browser.
	 *
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	// The route is in `appinfo/routes.php`, not here. `{token}` matches any
	// single segment, so it also matches `/@{username}/portfolio` and
	// `/@{username}/collections` — which belong to `SocialPubController` — and
	// the first route offered to the matcher wins. Attribute routes of two
	// *different* controllers are contributed in whatever order the filesystem
	// hands the directory back, so on some installs this one was offered first
	// and swallowed both: `resolvePost()` found no post called `portfolio`, and
	// the reader got "Post not found". See the file for why being last is
	// something only `appinfo/routes.php` can guarantee.
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
				// nobody local is asking. Whoever signed the fetch is the
				// reader instead, which is what lets a followers-only post
				// reach the people who follow it from another server — the
				// follow rows this instance holds are the same ones the local
				// timelines are built from
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
	 * Check that the request comes from an ActivityPub server, based on the header.
	 *
	 * If not, should forward to a readable webpage that displays content for navigation.
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
