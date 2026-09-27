<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\FederationDeliveryException;
use OCA\Social\Exceptions\FollowLimitException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidHandleException;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Exceptions\TranslationUnavailableException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UnknownProbeException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What every controller of the Mastodon client API shares: who is asking, what
 * their token allows, how a failure is answered, and how a page of results is
 * linked to the next one.
 *
 * A token's scope is looked up by the route's method name in one table, so the
 * scopes of every client API route are read in one place whichever controller
 * the route lives on; method names are therefore unique across the
 * controllers that extend this class.
 */
abstract class MastodonApiController extends Controller {
	/**
	 * The entity tag of the poll being answered, held between the check and
	 * the answer so that a route that got past `notModified()` still carries
	 * the tag the next request will send back.
	 */
	protected string $pollTag = '';

	protected string $bearer = '';
	protected ?SocialClient $client = null;
	protected ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		protected IURLGenerator $urlGenerator,
		protected IUserSession $userSession,
		protected LoggerInterface $logger,
		protected ClientService $clientService,
		protected AccountService $accountService,
		protected CacheActorService $cacheActorService,
		protected StreamService $streamService,
		protected FollowService $followService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}

	/**
	 * A boolean as a Mastodon client sends it in a form or JSON body:
	 * `true`/`false`, `1`/`0`, or those as strings.
	 */
	protected function formBool(mixed $value): bool {
		return in_array($value, [true, 1, '1', 'true'], true);
	}

	/**
	 *
	 * @param bool $exception
	 *
	 * @return bool
	 * @throws ClientNotFoundException
	 */
	protected function initViewer(bool $exception = false): bool {
		try {
			$userId = $this->currentSession();

			$this->logger->debug('[' . static::class . '] initViewer: ' . $userId);

			// Read, never create. Creating the actor here made the identity --
			// the handle every other server will know this person by -- a side
			// effect of the page being opened, before the setup screen had
			// asked them anything; the answer they then gave came back as
			// "that handle is taken", by themselves. An account is made where
			// somebody asks for one: LocalController::accountCreate() and
			// `occ social:account:create`.
			$account = $this->accountService->getActorFromUserId($userId);
			$this->logger->debug('[' . static::class . '] Actor retrieved/created', [
				'userId' => $userId,
				'username' => $account->getPreferredUsername()
			]);

			// Try to get from cache, if it fails, cache it first
			try {
				$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			} catch (Exception $e) {
				$this->logger->warning('[' . static::class . '] Actor not in cache, caching now', [
					'username' => $account->getPreferredUsername(),
					'exception' => $e->getMessage()
				]);
				// Cache the actor and retry
				$this->accountService->cacheLocalActorByUsername($account->getPreferredUsername());
				$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			}

			$this->viewer->setExportFormat(ACore::FORMAT_LOCAL);
			// The cached copy of a local actor carries no user id — that column
			// belongs to the account, not to the cache — and a bearer request
			// has no session to fall back on. Anything reading a per-user
			// setting from the viewer (the notification policy, for one) needs
			// it named here or it reads nobody's.
			if ($this->viewer->getUserId() === '') {
				$this->viewer->setUserId($userId);
			}

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);

			$this->logger->debug('[' . static::class . '] Viewer initialized successfully', [
				'viewerId' => $this->viewer->getId()
			]);

			return true;
		} catch (InsufficientScopeException $e) {
			// the token is fine, its grant is not — tell the client which scope it lacks
			if ($exception) {
				throw $e;
			}
		} catch (Exception $e) {
			// A request with a missing, stale or made-up token is ordinary
			// internet noise — every scanner that finds the API produces some —
			// and it is answered with a 401, not a server-side failure. Logging
			// each one at error with a stack trace filled the admin's log with
			// entries nobody can act on. Anything else failing here is a real
			// fault and still says so.
			$credentials = ($e instanceof ClientNotFoundException
				|| $e instanceof AccountDoesNotExistException
				|| $e instanceof ActorDoesNotExistException);
			if ($credentials) {
				$this->logger->debug('[' . static::class . '] initViewer: no usable credentials', [
					'exception' => $e->getMessage()
				]);
			} else {
				$this->logger->warning('[' . static::class . '] initViewer failed', ['exception' => $e]);
			}

			if ($exception) {
				throw new ClientNotFoundException('the access_token was revoked');
			}
		}

		return false;
	}

	/**
	 * @return string
	 * @throws AccountDoesNotExistException
	 * @throws ClientNotFoundException
	 */
	/**
	 * A bearer token wins over the session cookie: an OAuth client stays inside
	 * the scopes it was granted even when the browser also carries a session.
	 * The cookie is only accepted together with a valid CSRF token — these
	 * routes carry #[NoCSRFRequired] so that external clients (which cannot obtain
	 * one) work, and without this check a cross-site form POST would act as the
	 * logged-in user.
	 */
	protected function currentSession(): string {
		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromToken($this->bearer);
			$this->checkTokenScope();

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new AccountDoesNotExistException('userId not defined');
	}

	/**
	 * The scope every route of this controller asks a bearer token for, as the
	 * Mastodon API documents it.
	 *
	 * Enumerating the *reads* and defaulting everything else to `write` is the
	 * way round that fails safe: the table used to enumerate the writes and
	 * default to `read`, so every state-changing route anybody added later —
	 * deleting an avatar, reacting to a post, rewriting preferences, generating
	 * an annual report — was open to a read-only token until somebody
	 * remembered to list it. A route missing from this table now resolves by
	 * its HTTP verb, and only `GET` resolves to a read scope.
	 *
	 * An empty list means the route needs no scope beyond a valid token.
	 */
	private const ROUTE_SCOPES = [
		// -- reads ------------------------------------------------------------
		'appsCredentials' => [],
		'verifyCredentials' => ['read:accounts'],
		'accountLookup' => ['read:accounts'],
		'accountsSearch' => ['read:accounts'],
		'preferences' => ['read:accounts'],
		'annualReports' => ['read:accounts'],
		'annualReport' => ['read:accounts'],
		'annualReportState' => ['read:accounts'],
		'followRequests' => ['read:follows'],
		'relationships' => ['read:follows'],
		'familiarFollowers' => ['read:follows'],
		'accountFollowing' => ['read:follows'],
		'accountFollowers' => ['read:follows'],
		'blocks' => ['read:blocks'],
		'mutes' => ['read:mutes'],
		'favourites' => ['read:favourites'],
		'bookmarks' => ['read:bookmarks'],
		'notifications' => ['read:notifications'],
		'notificationsUnreadCount' => ['read:notifications'],
		'search' => ['read:search'],
		'searchV2' => ['read:search'],
		'savedSearches' => ['read:search'],
		'timelines' => ['read:statuses'],
		'tag' => ['read:statuses'],
		'accountStatuses' => ['read:statuses'],
		'statusGet' => ['read:statuses'],
		'statusCard' => ['read:statuses'],
		'statusContext' => ['read:statuses'],
		'statusSource' => ['read:statuses'],
		'statusDelivery' => ['read:statuses'],
		'statusReactions' => ['read:statuses'],
		'statusFavouritedBy' => ['read:statuses'],
		'statusRebloggedBy' => ['read:statuses'],
		'statusQuotes' => ['read:statuses'],
		'pollGet' => ['read:statuses'],
		'markersGet' => ['read:statuses'],
		'scheduledStatuses' => ['read:statuses'],
		'scheduledStatusGet' => ['read:statuses'],
		'videosContinue' => ['read:statuses'],
		'mediaOpen' => ['read:statuses'],
		'mediaStream' => ['read:statuses'],
		'mediaPlaylist' => ['read:statuses'],
		'mediaPlaylistFile' => ['read:statuses'],
		'mediaLadder' => ['read:statuses'],
		'mediaLadderRung' => ['read:statuses'],
		'mediaLadderFile' => ['read:statuses'],
		// Mastodon puts the pending-upload read behind the media *write* scope:
		// it answers about something only the uploader has
		'mediaGet' => ['write:media'],
		// the instance's own public documents, and the emoji and GIF pictures
		// they refer to: any token may read them
		'instance' => ['read'],
		'instanceV2' => ['read'],
		'instanceRules' => ['read'],
		'instanceDomainBlocks' => ['read'],
		'instanceExtendedDescription' => ['read'],
		'instancePrivacyPolicy' => ['read'],
		'instanceTermsOfService' => ['read'],
		'instanceTranslationLanguages' => ['read'],
		'instancePeers' => ['read'],
		'instanceActivity' => ['read'],
		'customEmojis' => ['read'],
		'emojiOpen' => ['read'],
		'gifs' => ['read'],
		'gifOpen' => ['read'],
		'trendTags' => ['read'],
		'oembed' => ['read'],
		// a POST, and a read scope on purpose: Mastodon documents
		// `read:statuses` for it, and what it answers with is the post the
		// caller may already read, in another language
		'statusTranslate' => ['read:statuses'],

		// -- writes -----------------------------------------------------------
		'updateCredentials' => ['write:accounts'],
		'profileAvatarDelete' => ['write:accounts'],
		'profileHeaderDelete' => ['write:accounts'],
		'accountNew' => ['write:accounts'],
		'preferencesUpdate' => ['write:accounts'],
		'annualReportRead' => ['write:accounts'],
		'annualReportGenerate' => ['write:accounts'],
		'statusNew' => ['write:statuses'],
		'statusUpdate' => ['write:statuses'],
		'statusDelete' => ['write:statuses'],
		'statusInteractionPolicy' => ['write:statuses'],
		'statusQuoteRevoke' => ['write:statuses'],
		'statusWatched' => ['write:statuses'],
		'statusUnwatched' => ['write:statuses'],
		'pollVote' => ['write:statuses'],
		'markersSet' => ['write:statuses'],
		'scheduledStatusUpdate' => ['write:statuses'],
		'scheduledStatusDelete' => ['write:statuses'],
		'statusReact' => ['write:favourites'],
		'statusUnreact' => ['write:favourites'],
		'mediaNew' => ['write:media'],
		'mediaNewV2' => ['write:media'],
		'mediaUpdate' => ['write:media'],
		'mediaFromFile' => ['write:media'],
		'mediaFromGif' => ['write:media'],
		'reportNew' => ['write:reports'],
		// `follow` is Mastodon's broad scope over the relationship routes, and
		// a client that asked for it is not also asking for `write`
		'accountFollow' => ['write:follows', 'follow'],
		'accountUnfollow' => ['write:follows', 'follow'],
		'followRequestAuthorize' => ['write:follows', 'follow'],
		'followRequestReject' => ['write:follows', 'follow'],
		'accountBlock' => ['write:blocks', 'follow'],
		'accountUnblock' => ['write:blocks', 'follow'],
		'accountMute' => ['write:mutes', 'follow'],
		'accountUnmute' => ['write:mutes', 'follow'],
	];

	/**
	 * What each `act` of the one catch-all status route really does, because
	 * they are not the same permission: favouriting is not pinning to a
	 * profile, and neither is muting a conversation.
	 */
	private const STATUS_ACTION_SCOPES = [
		'favourite' => ['write:favourites'],
		'unfavourite' => ['write:favourites'],
		'reblog' => ['write:statuses'],
		'unreblog' => ['write:statuses'],
		'bookmark' => ['write:bookmarks'],
		'unbookmark' => ['write:bookmarks'],
		'pin' => ['write:accounts'],
		'unpin' => ['write:accounts'],
		'mute' => ['write:mutes'],
		'unmute' => ['write:mutes'],
	];

	/**
	 * Whether the token this request carries may make it.
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(): void {
		$route = (string)$this->request->getParam('_route', '');
		$name = substr($route, strrpos($route, '.') + 1);

		$accepted = $this->scopesForRoute(
			$name,
			strtoupper($this->request->getMethod()),
			(string)$this->request->getParam('act', '')
		);

		foreach ($accepted as $scope) {
			// a granular scope is satisfied by itself or by the broad scope
			// that contains it, and by nothing else: `write:favourites` is not
			// permission to post, and `read:lists` is not permission to read
			// somebody's notifications
			$broad = strstr($scope, ':', true);
			$broad = ($broad === false) ? $scope : $broad;

			foreach ($this->client->getAuthScopes() as $granted) {
				if ($granted === $scope || $granted === $broad) {
					return;
				}
			}
		}

		if ($accepted !== []) {
			throw new InsufficientScopeException(
				'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
			);
		}
	}

	/**
	 * The scopes that satisfy one route, any of which is enough.
	 *
	 * @return string[]
	 */
	private function scopesForRoute(string $name, string $verb, string $act = ''): array {
		if ($name === 'statusAction') {
			// an unknown act is refused by the route itself; until then it is
			// treated as the write it would be
			return self::STATUS_ACTION_SCOPES[$act] ?? ['write'];
		}

		if (array_key_exists($name, self::ROUTE_SCOPES)) {
			return self::ROUTE_SCOPES[$name];
		}

		return $verb === 'GET' ? ['read'] : ['write'];
	}

	/**
	 * The HTTP status each failure maps to, in the order the classes are
	 * tested. Everything used to answer 401, which a client reads as "this
	 * token is gone": a deleted status, a mistyped timeline name, a database
	 * hiccup and a slow remote all logged the reader out of their client and
	 * left nothing to diagnose. `InsufficientScopeException` is handled ahead
	 * of this list because it extends `ClientException`.
	 */
	private const ERROR_STATUS = [
		// gone, or never existed
		[StreamNotFoundException::class, Http::STATUS_NOT_FOUND],
		[CacheActorDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ActorDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ItemNotFoundException::class, Http::STATUS_NOT_FOUND],
		[CacheDocumentDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[HashtagDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ReportNotFoundException::class, Http::STATUS_NOT_FOUND],
		[FollowNotFoundException::class, Http::STATUS_NOT_FOUND],
		[InstanceDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[NotFoundException::class, Http::STATUS_NOT_FOUND],
		// the request was understood and refused: retrying it unchanged cannot help
		[InvalidActionException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[UnknownProbeException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidResourceException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidResourceEntryException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidHandleException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[ItemUnknownException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[CacheContentMimeTypeException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[ClientException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		// the credentials, not the request
		[ClientNotFoundException::class, Http::STATUS_UNAUTHORIZED],
		[AccountDoesNotExistException::class, Http::STATUS_UNAUTHORIZED],
		// allowed to ask, not allowed to have
		[UnauthorizedFediverseException::class, Http::STATUS_FORBIDDEN],
		[TooManyRequestsException::class, Http::STATUS_TOO_MANY_REQUESTS],
		[FollowLimitException::class, Http::STATUS_TOO_MANY_REQUESTS],
		// this server could do it, and cannot right now: Mastodon answers a
		// translation it has no provider for with exactly this, and a client
		// reads it as "later", not as "never"
		[TranslationUnavailableException::class, Http::STATUS_SERVICE_UNAVAILABLE],
		// the edit is committed locally but its Update could not be queued; the
		// client should show the saved text and may try the delivery again later
		[FederationDeliveryException::class, Http::STATUS_SERVICE_UNAVAILABLE],
		// somebody else's server let us down
		[RequestContentException::class, Http::STATUS_NOT_FOUND],
		[RequestNetworkException::class, Http::STATUS_BAD_GATEWAY],
		[RequestServerException::class, Http::STATUS_BAD_GATEWAY],
		[RequestResultNotJsonException::class, Http::STATUS_BAD_GATEWAY],
		[RequestResultSizeException::class, Http::STATUS_BAD_GATEWAY],
	];

	/**
	 * A failure as a Mastodon client can act on it: `{"error": "..."}` with a
	 * status that says what to do about it.
	 *
	 * An unrecognised failure is a bug on this side, so it answers 500 and is
	 * logged here with its stack trace — and its message is *not* sent on.
	 * Every route in this controller is a `#[PublicPage]`, so echoing
	 * `getMessage()` published whatever the failure happened to name: a table,
	 * a file path, an internal host.
	 */
	protected function error(Throwable $e): DataResponse {
		if ($e instanceof InsufficientScopeException) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_FORBIDDEN,
				['WWW-Authenticate' => 'Bearer error="insufficient_scope"']
			);
		}

		foreach (self::ERROR_STATUS as [$class, $status]) {
			if ($e instanceof $class) {
				$headers = ($status === Http::STATUS_UNAUTHORIZED)
					? ['WWW-Authenticate' => 'Bearer error="invalid_token"'] : [];

				return new DataResponse(
					['error' => $this->errorMessage($e, $status)], $status, $headers
				);
			}
		}

		$this->logger->error('[' . static::class . '] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}

	/**
	 * What to put in `error`. Several of these failures are raised with no
	 * message at all — an unknown token is one — and `{"error": ""}` tells a
	 * client nothing about what to do next.
	 */
	private function errorMessage(Throwable $e, int $status): string {
		$message = trim($e->getMessage());
		if ($message !== '') {
			return $message;
		}

		return match ($status) {
			Http::STATUS_UNAUTHORIZED => 'the access_token is invalid',
			Http::STATUS_NOT_FOUND => 'not found',
			Http::STATUS_UNPROCESSABLE_ENTITY => 'the request could not be processed',
			default => 'request failed',
		};
	}

	/**
	 * The parameters of a request that carries its body itself.
	 *
	 * A body that says it is JSON and is not one is refused: `json_decode()`
	 * answers `null` for an empty, truncated or scalar body, which under
	 * strict_types raised a TypeError out of a method declared `: array` — a
	 * Nextcloud HTML error page, stack trace and all, for every client that
	 * lost a byte on the way.
	 *
	 * @throws InvalidActionException
	 */
	protected function convertInput(string $input): array {
		$contentType = $this->request->getHeader('Content-Type');

		$pos = strpos($contentType, ';');
		if ($pos > 0) {
			$contentType = substr($contentType, 0, $pos);
		}

		switch ($contentType) {
			case 'application/json':
				$result = json_decode($input, true);
				if (!is_array($result)) {
					throw new InvalidActionException('the request body is not valid JSON');
				}

				return $result;
			case 'application/x-www-form-urlencoded':
				return $this->request->getParams();
			default: // in case of no header ...
				$result = json_decode($input, true);
				if (is_array($result)) {
					return $result;
				}

				return $this->request->getParams();
		}
	}

	/**
	 * A page of entities, with the `Link` header Mastodon pages with.
	 *
	 * masto.js — which Elk and Phanpy are both built on — takes the next page
	 * from this header and nowhere else, so without it those clients show the
	 * first twenty posts of a timeline and stop. Mastodon sends `next` only
	 * while a further page may exist (a short page is the last one) and `prev`
	 * whenever the page is not empty.
	 *
	 * @param array|null $page the rows the query returned, where `$items` is a
	 *                         filtered subset of them
	 */
	/**
	 * The filter context a timeline is read in. Favourites, bookmarks and the
	 * direct timeline are not contexts Mastodon filters in: their statuses
	 * still carry `filtered`, empty, because its absence is read as an answer.
	 */
	protected function filterContext(string $timeline): string {
		return match (strtolower($timeline)) {
			ProbeOptions::HOME => Filter::CONTEXT_HOME,
			ProbeOptions::PUBLIC => Filter::CONTEXT_PUBLIC,
			default => '',
		};
	}

	/**
	 * Answers `304` when the caller already has this version of a poll.
	 *
	 * A client asks for the home timeline and the unread count every thirty
	 * seconds, and the answer is almost always the one it already holds. Fifty
	 * thousand open tabs is about seventeen hundred requests a second, each of
	 * which is a Nextcloud boot and — measured on devel — twenty to thirty
	 * queries, to send back bytes the browser already has.
	 *
	 * The tag is the newest id the viewer can see, which changes exactly when
	 * the answer does and costs one index-only probe: far less than the page it
	 * stands in for, which is the only thing that makes this worth doing.
	 *
	 * `private` because the answer is one account's, and no shared cache may
	 * hold it; `no-cache` because the browser must revalidate rather than serve
	 * it blind — which is precisely what turns the poll into a conditional
	 * request.
	 *
	 * @return JSONResponse|null the 304 to return, or null to carry on
	 */
	protected function notModified(string $tag): ?JSONResponse {
		if ($tag === '') {
			return null;
		}

		$etag = '"' . $tag . '"';
		if (Revalidation::matches($this->request, $etag)) {
			return Revalidation::notModified($etag);
		}

		$this->pollTag = $etag;

		return null;
	}

	/**
	 * Puts the tag on the answer that earned it.
	 *
	 * It hands back a `JSONResponse` rather than the `DataResponse` it was
	 * given, and that is the whole of why the conditional request works. A
	 * controller that returns a `DataResponse` has it rebuilt by Nextcloud's
	 * default json responder, which does
	 *
	 *     $response->setHeaders(array_merge($dataHeaders, $headers));
	 *
	 * where `$headers` are the *fresh* response's — and every response's
	 * defaults include `Cache-Control: no-cache, no-store, must-revalidate`.
	 * The controller's header is therefore overwritten by the default on its
	 * way out, and `no-store` forbids the browser to keep the body at all: it
	 * never sends `If-None-Match`, so the `304` below is never asked for and
	 * the whole design is inert against a real client. Measured on devel: the
	 * response carried our `ETag` and Nextcloud's `no-store`, side by side.
	 *
	 * A `JSONResponse` is what the responder would have built, so returning one
	 * skips it and the header reaches the wire. Only the headers the caller set
	 * are carried over — the framework's own are recomputed identically by the
	 * new response, and copying them would pin today's values into a response
	 * that knows how to work them out.
	 */
	protected function tagged(DataResponse $response): JSONResponse {
		$json = Revalidation::asJson($response);

		if ($this->pollTag !== '') {
			$json->addHeader('ETag', $this->pollTag);
			$json->addHeader('Cache-Control', Revalidation::CACHE_CONTROL);
			$this->pollTag = '';
		}

		return $json;
	}

	protected function paged(array $items, int $limit, ?array $page = null, ?int $rows = null): DataResponse {
		$response = new DataResponse($items, Http::STATUS_OK);

		// what the query returned, which is what says whether there is more —
		// $items may have been filtered since
		$page ??= $items;
		// and $page itself may be shorter than the rows the query read, where
		// the page dropped a boost of a post already in it
		$rows ??= count($page);

		$ids = $this->pageIds($page);
		if ($ids === []) {
			return $response;
		}

		$links = [];
		if ($rows >= $limit) {
			// the next page is older than everything here
			$links[] = '<' . $this->pageUrl(['max_id' => (string)min($ids)]) . '>; rel="next"';
		}
		$links[] = '<' . $this->pageUrl(['min_id' => (string)max($ids)]) . '>; rel="prev"';

		$response->addHeader('Link', implode(', ', $links));

		return $response;
	}

	/**
	 * The paging ids of a page of entities. Streams and actors both carry the
	 * numeric id the API pages by; an already-serialised entity carries it as
	 * its string `id`.
	 *
	 * @return string[]
	 */
	private function pageIds(array $items): array {
		$ids = [];
		foreach ($items as $item) {
			$nid = '0';
			if (is_object($item) && method_exists($item, 'getNid')) {
				$nid = (string)$item->getNid();
			} elseif (is_array($item)) {
				$nid = (string)($item['id'] ?? '0');
			}

			if (\OCA\Social\Tools\Nid::compare($nid, '0') > 0) {
				$ids[] = $nid;
			}
		}

		return $ids;
	}

	/**
	 * This request's own URL with the cursor replaced, so every other filter
	 * the client sent (`limit`, `types`, `only_media`, …) survives into the
	 * next page. Built with http_build_query rather than string concatenation:
	 * a route that already carries a query string must not end up with two
	 * `?`s in it.
	 */
	protected function pageUrl(array $cursor): string {
		$uri = $this->request->getRequestUri();
		$path = $uri;
		$query = [];

		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$path = substr($uri, 0, $pos);
			parse_str(substr($uri, $pos + 1), $query);
		}

		unset($query['max_id'], $query['min_id'], $query['since_id'], $query['_route']);

		return $this->urlGenerator->getAbsoluteURL($path) . '?'
			. http_build_query(array_merge($query, $cursor));
	}
}
