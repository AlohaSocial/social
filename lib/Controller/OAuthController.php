<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\OAuth\PermissionDescriber;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AccountNotSetUpException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\InvalidGrantException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DeviceAuthorizationService;
use OCA\Social\Service\ExternalSignupService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\VapidService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class OAuthController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private InstanceService $instanceService,
		private AccountService $accountService,
		private ClientService $clientService,
		private ConfigService $configService,
		private CheckService $checkService,
		private LoggerInterface $logger,
		private IInitialState $initialState,
		private AuthorizationServer $atprotoOAuth,
		private AtprotoConfig $atprotoConfig,
		private DpopNonce $dpopNonce,
		private VapidService $vapidService,
		private DeviceAuthorizationService $deviceAuthorization,
		private ExternalSignupService $externalSignupService,
		private ?PermissionDescriber $permissionDescriber = null,
		private ?IFactory $l10nFactory = null,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * What this software is called on NodeInfo. The schema wants
	 * `^[a-z0-9-]+$`, and crawlers group instances by this string, so it is a
	 * constant: the theming title that used to go here put every Nextcloud
	 * under its own "software" and made the app invisible in every statistic.
	 * The human title travels as `metadata.nodeName`.
	 */
	private const REPOSITORY = 'https://github.com/alohasocial/social';

	/**
	 * The NodeInfo schemas this instance serves, oldest first.
	 *
	 * Read by `WebfingerHandler` to build the discovery document, so that the
	 * versions advertised and the versions actually served cannot drift apart
	 * again -- which is the whole of what went wrong with 2.1.
	 */
	public const NODEINFO_SCHEMAS = ['2.0', '2.1'];

	/** `/.well-known/nodeinfo/2.0` */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/nodeinfo/2.0')]
	public function nodeinfo2(): Response {
		return new DataResponse($this->nodeInfo('2.0'), Http::STATUS_OK);
	}

	/**
	 * `/.well-known/nodeinfo/2.1`: 2.0 plus `software.repository` and
	 * `software.homepage`.
	 *
	 * The document was built and tested from the day it was written and could
	 * not be fetched by anybody: the method had no route attribute, so
	 * Nextcloud registered no URL for it, and the discovery document named
	 * only 2.0. Both are needed -- a crawler reads the discovery document and
	 * follows what it finds, so a route nothing points at is as unreachable as
	 * no route at all.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/nodeinfo/2.1')]
	public function nodeinfo21(): Response {
		return new DataResponse($this->nodeInfo('2.1'), Http::STATUS_OK);
	}

	/**
	 * The NodeInfo document for one schema version. The schema allows no
	 * property it does not name, so `services` and `metadata` are always
	 * present (both required) and nothing else is added at the top level.
	 */
	private function nodeInfo(string $schema): array {
		// This is Social's signup state, not Nextcloud's full-account signup
		// switch. It does not depend on the instance metadata row being present.
		$openReg = $this->externalSignupService->acceptsPublicRegistrations();
		try {
			$local = $this->instanceService->getLocal();
			$name = $local->getTitle();
			$description = $local->getShortDescription();
			$version = $local->getVersion();
			$usage = $local->getUsage();
		} catch (InstanceDoesNotExistException $e) {
			$name = 'Aloha Social';
			$description = '';
			$version = $this->configService->getAppValue('installed_version');
			// an object with the one member the schema requires, never `[]`
			$usage = ['users' => new \stdClass()];
			$openReg = false;
		}

		$software = [
			'name' => Application::SOFTWARE_NAME,
			'version' => (string)$version,
		];
		if ($schema === '2.1') {
			$software['repository'] = self::REPOSITORY;
			$software['homepage'] = self::REPOSITORY;
		}

		return [
			'version' => $schema,
			'software' => $software,
			'protocols' => ['activitypub'],
			// no third-party service is bridged in or out
			'services' => ['inbound' => [], 'outbound' => []],
			'usage' => $usage,
			'openRegistrations' => $openReg,
			'metadata' => [
				'nodeName' => $name,
				'nodeDescription' => $description,
			],
		];
	}

	/**
	 * Registers a client app. Public, so it carries a limit of its own for a
	 * caller with a session and one without: every registration is a row
	 * that stays until the sweep in `Cron\Queue` finds it never authorized.
	 *
	 * A registration the service refuses -- no name, no redirect URI, or one
	 * with a scheme a browser would run rather than follow -- is a 422, which
	 * is how Mastodon answers a registration that fails validation.
	 *
	 * @param array|string $redirect_uris
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 15, period: 300)]
	#[UserRateLimit(limit: 15, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/apps')]
	public function apps(
		string $client_name = '',
		$redirect_uris = '',
		string $website = '',
		string $scopes = 'read',
	): DataResponse {
		// Mastodon's own API takes several redirect URIs, newline-separated in a
		// single `redirect_uris` field. Wrapping the whole block into one entry
		// meant `ClientService::confirmData()` compared a single URI against a
		// string holding all of them, so a client registered with more than one
		// could never authorize with any of them.
		if (!is_array($redirect_uris)) {
			$redirect_uris = preg_split('/\r\n|\r|\n/', (string)$redirect_uris);
		}

		$redirect_uris = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ($uri): string => trim((string)$uri),
						$redirect_uris
					),
					static fn (string $uri): bool => $uri !== ''
				)
			)
		);

		$client = new SocialClient();
		$client->setAppWebsite($website);
		$client->setAppRedirectUris($redirect_uris);
		$client->setAppScopes($client->getScopesFromString($scopes));
		$client->setAppName($client_name);

		try {
			$this->clientService->createApp($client);
		} catch (ClientException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(
			[
				// A string, as every id in Mastodon's API is. This one is a
				// database row number and the temptation is to answer with the
				// number; a client that decodes the response into a typed
				// struct declares `id: String` because that is what Mastodon
				// sends, and a JSON number fails to decode. The registration
				// then succeeds with a 200 that the client cannot read, and
				// its sign-in stops with nothing in any log to show for it.
				'id' => (string)$client->getId(),
				'name' => $client->getAppName(),
				// null rather than '' where the client registered no website:
				// Mastodon sends null, and a client that decodes this field as
				// a URL fails on the empty string, which is not one.
				'website' => $client->getAppWebsite() === '' ? null : $client->getAppWebsite(),
				// lists, as Mastodon 4.3 sends them: a client decoding the
				// answer into `[String]` fails on one space-separated string
				'scopes' => array_values($client->getAppScopes()),
				'redirect_uris' => array_values($client->getAppRedirectUris()),
				'client_id' => $client->getAppClientId(),
				'client_secret' => $client->getAppClientSecret(),
				// Mastodon's Application entity always carries these two, and a
				// client that decodes the answer into a typed struct fails
				// without them. `redirect_uri` is the first of what was
				// registered, which is what Mastodon reports.
				'redirect_uri' => $client->getAppRedirectUris()[0] ?? '',
				'vapid_key' => $this->vapidService->publicKey(),
			], Http::STATUS_OK
		);
	}

	/** Starts the RFC 8628 flow for a registered OAuth application. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 20, period: 300)]
	#[UserRateLimit(limit: 20, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/device_authorization')]
	public function deviceAuthorization(
		string $client_id = '',
		string $scope = 'read',
		string $client_secret = '',
	): DataResponse {
		try {
			[$client_id, $client_secret] = $this->clientCredentials($client_id, $client_secret);
			$client = $this->clientService->getFromClientId($client_id);
			$credentials = ['app_scopes' => $scope];
			if ($client_secret !== '' || $client->getAppClientSecret() !== '') {
				$credentials['client_secret'] = $client_secret;
			}
			$this->clientService->confirmData($client, $credentials);
			return new DataResponse($this->deviceAuthorization->create(
				$client_id,
				implode(' ', $client->getScopesFromString($scope))
			));
		} catch (ClientNotFoundException|ClientException $e) {
			$response = new DataResponse(
				['error' => 'invalid_client', 'error_description' => 'unknown or invalid client credentials'],
				Http::STATUS_UNAUTHORIZED
			);
			$response->throttle(['action' => 'socialOauthToken']);
			return $response;
		} catch (Throwable $e) {
			$this->logger->error('OAuth device authorization failed', ['exception' => $e]);
			return new DataResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/** Shows the registered client and scopes before a device code is approved. */
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[UserRateLimit(limit: 30, period: 300)]
	#[FrontpageRoute(verb: 'GET', url: '/oauth/device')]
	public function device(string $user_code = ''): Response {
		$user = $this->userSession->getUser();
		$pending = $this->deviceAuthorization->inspect($user_code);
		$client = null;
		if ($pending !== null) {
			try {
				$client = $this->clientService->getFromClientId($pending['client_id']);
			} catch (Throwable) {
				$pending = null;
			}
		}
		$this->initialState->provideInitialState('deviceUserCode', $user_code);
		$this->initialState->provideInitialState('devicePending', $pending !== null);
		$this->initialState->provideInitialState('deviceAppName', $client?->getAppName() ?? '');
		$this->initialState->provideInitialState('deviceAppWebsite', $client === null ? '' : $this->websiteLink($client->getAppWebsite()));
		$this->initialState->provideInitialState('deviceScopes', $pending === null ? [] : $client->getScopesFromString($pending['scopes']));
		$this->initialState->provideInitialState('deviceDecisionResult', '');
		$this->initialState->provideInitialState('deviceDecisionUrl', $this->urlGenerator->linkToRoute('social.OAuth.deviceDecision'));
		$this->initialState->provideInitialState('appName', $client?->getAppName() ?? '');
		$this->initialState->provideInitialState('appWebsite', $client === null ? '' : $this->websiteLink($client->getAppWebsite()));
		$this->initialState->provideInitialState('scopes', $pending === null ? [] : $client->getScopesFromString($pending['scopes']));
		$account = $this->deviceAccountState();
		if ($account !== null) {
			$this->initialState->provideInitialState('account', $account);
		}
		if ($pending !== null && $user !== null && $this->accountOrNull($user) === null) {
			$this->provideSetup($user);
		}
		$response = new TemplateResponse(Application::APP_ID, 'oauth2', [], TemplateResponse::RENDER_AS_GUEST);
		$response->setContentSecurityPolicy($this->consentPolicy($this->configService->getSocialUrl()));
		return $response;
	}

	/** Records the signed-in person's one-time decision for this device. */
	#[NoAdminRequired]
	#[BruteForceProtection(action: 'socialOauthDeviceCode')]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/device')]
	public function deviceDecision(string $user_code = '', string $decision = ''): Response {
		$user = $this->userSession->getUser();
		$canAuthorize = false;
		if ($user !== null) {
			try {
				$this->accountService->getActorFromUserId($user->getUID());
				$canAuthorize = true;
			} catch (Throwable) {
			}
		}
		$validDecision = in_array($decision, ['allow', 'deny'], true);
		$saved = $canAuthorize && $validDecision
			&& $this->deviceAuthorization->decide($user_code, $user->getUID(), $decision === 'allow');
		$this->initialState->provideInitialState('deviceUserCode', $user_code);
		$this->initialState->provideInitialState('deviceDecisionResult', $saved ? $decision : 'invalid');
		$this->initialState->provideInitialState('devicePending', false);
		$this->initialState->provideInitialState('deviceAppName', '');
		$this->initialState->provideInitialState('deviceAppWebsite', '');
		$this->initialState->provideInitialState('deviceScopes', []);
		$this->initialState->provideInitialState('deviceDecisionUrl', $this->urlGenerator->linkToRoute('social.OAuth.deviceDecision'));
		return new TemplateResponse(Application::APP_ID, 'oauth2', [], TemplateResponse::RENDER_AS_GUEST);
	}

	private function deviceAccountState(): ?array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}
		$actor = $this->accountOrNull($user);
		if ($actor === null) {
			return null;
		}
		return [
			'uid' => $user->getUID(),
			'displayName' => $this->accountDisplayName($actor, $user),
			'handle' => '@' . $actor->getPreferredUsername() . '@' . $this->configService->getSocialAddress(),
		];
	}

	/**
	 * The consent page.
	 *
	 * Everything that can be wrong with the request — an unknown `client_id`, a
	 * `response_type` that is not `code`, a `redirect_uri` or scope the client
	 * never registered — is answered the way `authorizing()` answers it. Left
	 * to escape, each of them reached the browser as a Nextcloud HTML error
	 * page (with a stack trace where debug is on) rather than as something the
	 * client can read.
	 *
	 * Somebody whose first visit is from an app has no account here yet; once
	 * the request has passed every check, the page asks them for a username
	 * first (`setup`), and reloading it after the account is made shows the
	 * consent for that account, from the same address and so with the same
	 * request.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/oauth/authorize')]
	public function authorize(
		string $client_id,
		string $redirect_uri = '',
		string $response_type = '',
		string $scope = 'read',
		string $state = '',
		string $code_challenge = '',
		string $code_challenge_method = '',
		string $request_uri = '',
	): Response {
		if ($this->userSession->getUser() === null) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm', [
				'redirect_url' => $this->request->getRequestUri(),
			]));
		}

		if ($request_uri !== '') {
			return $this->atprotoAuthorize($client_id, $request_uri);
		}
		try {
			$user = $this->userSession->getUser();

			if ($response_type !== 'code') {
				throw new ClientNotFoundException('invalid response type');
			}

			$code_challenge_method = $this->challengeMethod($code_challenge, $code_challenge_method);

			// check client exists in db
			$client = $this->clientService->getFromClientId($client_id);
			// A code must only ever travel to a URI the client registered; checked before
			// the consent page exists, so there is nothing to confirm on a forged link.
			if (!$this->isRegisteredRedirectUri($client, $redirect_uri)) {
				return $this->invalidRequest('redirect_uri is not registered for this client');
			}

			$this->clientService->confirmData($client, ['app_scopes' => $scope]);

			// what the person is being asked to agree to: the app, what it may
			// do, and where the code is about to be sent
			$this->initialState->provideInitialState('appName', $client->getAppName());
			$this->initialState->provideInitialState('appWebsite', $this->websiteLink($client->getAppWebsite()));
			$actor = $this->accountOrNull($user);
			if ($actor === null) {
				$this->provideSetup($user);
			} else {
				// which account is about to be handed over: on a server where
				// somebody holds more than one, the name of the application
				// alone does not answer the question being asked
				$this->initialState->provideInitialState('account', [
					'uid' => $user->getUID(),
					'displayName' => $this->accountDisplayName($actor, $user),
					'handle' => '@' . $actor->getPreferredUsername() . '@' . $this->configService->getSocialAddress(),
				]);
			}
			$this->initialState->provideInitialState('scopes', $client->getScopesFromString($scope));
			$this->initialState->provideInitialState('redirectUri', $redirect_uri);
			$this->initialState->provideInitialState('denyUrl', $this->denyUrl($redirect_uri, $state));

			// as a guest page, not inside the app: this asks somebody to hand
			// an application their account, and the navigation, the search bar
			// and the rest of the app around that question are both a
			// distraction and a way to wander off mid-consent. It is also what
			// puts the box in the middle of the screen -- the app's own
			// content area is a flex container, so a page mounted into it
			// takes the width of its contents and sits against the left edge.
			$response = new TemplateResponse(Application::APP_ID, 'oauth2', [
				'request'
					=> [
						'clientId' => $client_id,
						'redirectUri' => $redirect_uri,
						'responseType' => $response_type,
						'scope' => $scope,
						// carried through the consent form so the POST can echo it
						'state' => $state,
						// RFC 7636: the challenge is bound to the authorization
						// the POST creates, so the form has to carry it too
						'codeChallenge' => $code_challenge,
						'codeChallengeMethod' => $code_challenge_method,
					]
			], TemplateResponse::RENDER_AS_GUEST);

			// Nextcloud's default policy is `form-action 'self'`, and a browser
			// applies that to where a form submission *ends up*, not only to
			// where it is sent. Granting an application whose redirect_uri is
			// anywhere but this server therefore submitted the form, took the
			// 303 to the client, and had the navigation blocked -- no error, no
			// page, the button apparently doing nothing.
			//
			// The URI is the one already registered for this client and
			// confirmed against the request above, so this widens the policy to
			// exactly the destination the code was always going to be sent to.
			$response->setContentSecurityPolicy($this->consentPolicy($redirect_uri));

			return $response;
		} catch (Throwable $e) {
			return $this->authorizationFailure($e);
		}
	}

	/**
	 * The signed-in person's account, or null while they have not set one up.
	 */
	private function accountOrNull(IUser $user): ?Person {
		try {
			return $this->accountService->getActorFromUserId($user->getUID());
		} catch (ActorDoesNotExistException) {
			return null;
		}
	}

	/**
	 * What the sign-in page needs to ask for a username before the consent,
	 * the way the app's own setup page asks (`NavigationController`): the
	 * handle to suggest and the hosts the addresses end in. Creating the
	 * account is `LocalController::accountCreate()`, as from that page.
	 */
	private function provideSetup(IUser $user): void {
		try {
			$suggested = $this->accountService->generateHandleFromUserId($user->getUID());
		} catch (Exception) {
			$suggested = '';
		}
		$address = $this->configService->getSocialAddress();
		$this->initialState->provideInitialState('setup', [
			'suggestedHandle' => $suggested,
			'socialAddress' => $address,
			'bluesky' => [
				'enabled' => $this->atprotoConfig->isEnabled(),
				'host' => strtolower($address),
			],
		]);
	}

	/**
	 * The name to show for the account being handed over.
	 *
	 * `Person::getDisplayName()` falls back to the handle when the profile
	 * carries no name of its own, which on the consent page reads as the
	 * username twice over -- once as the name and once as the handle under it.
	 * The Nextcloud account's name is what the rest of the server would show
	 * next to that same avatar, so it stands in.
	 */
	private function accountDisplayName(Person $actor, IUser $user): string {
		$name = $actor->getDisplayName();
		if ($name === $actor->getPreferredUsername()) {
			return $user->getDisplayName();
		}

		return $name;
	}

	/**
	 * The consent page's policy: the default one, plus the client's own
	 * redirect URI as a form-action destination.
	 *
	 * Out-of-band needs nothing added -- that answer is a page on this server.
	 * A custom scheme (`tusky://…`) is passed through as it is: a browser
	 * matches form-action against the scheme, and an application that
	 * registered one is exactly the case this exists for.
	 */
	private function consentPolicy(string $redirectUri): ContentSecurityPolicy {
		$policy = new ContentSecurityPolicy();
		$source = $this->formActionSource($redirectUri);
		if ($source !== '') {
			$policy->addAllowedFormActionDomain($source);
		}

		return $policy;
	}

	/**
	 * The redirect URI as a CSP source expression, which is not the URI.
	 *
	 * A mobile client registers a custom scheme -- `icecubesapp://` -- and
	 * putting that in the policy verbatim writes `form-action 'self'
	 * icecubesapp://`, which is not a valid source: a scheme-source is the
	 * scheme and one colon. A browser drops the expression it cannot parse,
	 * leaving `'self'`, and blocks the redirect to the client exactly as if
	 * nothing had been added -- so the Authorize button goes on doing nothing,
	 * for the one kind of client that most needs it to work.
	 *
	 * An `http`/`https` URI becomes its origin instead of the whole address:
	 * a source expression carries no path, and the path is not ours to
	 * constrain anyway -- the code goes to the URI the client registered,
	 * which was checked before this page was drawn.
	 */
	private function formActionSource(string $redirectUri): string {
		if ($redirectUri === '' || $redirectUri === ClientService::REDIRECT_URI_OOB) {
			// answered by a page on this server; 'self' already covers it
			return '';
		}

		// Read with a pattern rather than parse_url(): the URI a mobile client
		// registers is often `icecubesapp://` -- a scheme, two slashes and
		// nothing else -- and parse_url() answers `false` for that, having no
		// host to find. The scheme is then lost and this returns nothing,
		// which is the whole widening silently not happening.
		if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $redirectUri, $matches) !== 1) {
			return '';
		}

		$scheme = strtolower($matches[1]);
		if (!in_array($scheme, ['http', 'https'], true)) {
			return $scheme . ':';
		}

		$host = (string)parse_url($redirectUri, PHP_URL_HOST);
		if ($host === '') {
			return '';
		}

		$port = parse_url($redirectUri, PHP_URL_PORT);

		return $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
	}

	/**
	 * The PKCE transformation to record, for a request that carries a
	 * challenge.
	 *
	 * An omitted method means `plain` in RFC 7636, which this server does not
	 * implement and does not advertise; a client that sends a challenge with no
	 * method is told so rather than silently granted a code that is not bound
	 * to anything.
	 *
	 * @throws ClientException
	 */
	private function challengeMethod(string $challenge, string $method): string {
		if ($challenge === '') {
			return '';
		}

		if (!in_array($method, ClientService::CODE_CHALLENGE_METHODS, true)) {
			throw new ClientException('unsupported code_challenge_method');
		}

		return $method;
	}

	/**
	 * The application's website as the consent page may link it: an `http` or
	 * `https` address, or nothing. The registration takes any string there,
	 * and the page offers it as a link to click.
	 */
	private function websiteLink(string $website): string {
		return preg_match('/^https?:\/\/[^\/]/i', $website) === 1 ? $website : '';
	}

	/**
	 * RFC 6749 §3.1.2.3: the `redirect_uri` of a request has to be, exactly,
	 * one the client registered. Compared as strings rather than by origin or
	 * prefix -- a code sent to a path the client did not register is a code
	 * sent to whoever controls that path.
	 */
	private function isRegisteredRedirectUri(SocialClient $client, string $redirectUri): bool {
		return in_array($redirectUri, $client->getAppRedirectUris(), true);
	}

	/**
	 * Why an authorization request was not granted.
	 *
	 * The client's own mistakes -- an unknown client, a response type or
	 * scope it may not ask for, a PKCE method nobody supports -- are this
	 * app's refusals and say what was wrong, so the client is told them. A
	 * reader who has no account here is told so, and where to set one up.
	 * Anything else is this
	 * server's failure, and its message describes the server rather than the
	 * request: it goes to the log, and the client gets `server_error`, as
	 * from `token()`.
	 */
	private function authorizationFailure(Throwable $e): DataResponse {
		if ($e instanceof ClientException || $e instanceof ClientNotFoundException) {
			$this->logger->notice('OAuth authorization refused: ' . $e->getMessage());

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		if ($e instanceof ActorDoesNotExistException) {
			return new DataResponse(AccountNotSetUpException::withSetupUrl($e)->toArray(), Http::STATUS_FORBIDDEN);
		}

		$this->logger->error('OAuth authorization failed', ['exception' => $e]);

		return new DataResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	/**
	 * RFC 6749 §4.1.2.1 `invalid_request`, in the shape Mastodon answers it.
	 * Never a redirect: the one parameter that is wrong is where the redirect
	 * would go.
	 */
	private function invalidRequest(string $description): DataResponse {
		$this->logger->notice('OAuth invalid_request: ' . $description);

		return new DataResponse(
			['error' => 'invalid_request', 'error_description' => $description],
			Http::STATUS_BAD_REQUEST
		);
	}

	/**
	 * Where refusing consent sends the browser: back to the client with
	 * `error=access_denied`, which is the answer RFC 6749 §4.1.2.1 owes it.
	 *
	 * A client left without one waits for a redirect that never comes. The
	 * out-of-band flow has nowhere to send it, so that lands on the app.
	 *
	 * So does a redirect URI the registration would refuse today: this is a
	 * link the person clicks, and a `javascript:` URI registered before the
	 * scheme was checked would run in their session rather than send them
	 * anywhere.
	 */
	private function denyUrl(string $redirectUri, string $state): string {
		if ($redirectUri === '' || $redirectUri === ClientService::REDIRECT_URI_OOB
			|| !ClientService::isAcceptableRedirectUri($redirectUri)) {
			return $this->urlGenerator->linkToRoute('social.Navigation.navigate');
		}

		$parameters = ['error' => 'access_denied'];
		if ($state !== '') {
			$parameters['state'] = $state;
		}

		return $this->appendToRedirectUri($redirectUri, $parameters);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/authorize')]
	public function authorizing(
		string $client_id,
		string $redirect_uri = '',
		string $response_type = '',
		string $scope = 'read',
		string $state = '',
		string $code_challenge = '',
		string $code_challenge_method = '',
		string $request_uri = '',
	): Response {
		if ($request_uri !== '') {
			return $this->atprotoAuthorizing($client_id, $request_uri);
		}
		try {
			$user = $this->userSession->getUser();

			if ($response_type !== 'code') {
				throw new ClientNotFoundException('invalid response type');
			}

			$code_challenge_method = $this->challengeMethod($code_challenge, $code_challenge_method);

			$client = $this->clientService->getFromClientId($client_id);
			if (!$this->isRegisteredRedirectUri($client, $redirect_uri)) {
				return $this->invalidRequest('redirect_uri is not registered for this client');
			}

			$this->clientService->confirmData($client, ['app_scopes' => $scope]);

			// no code without an account: a token names the account it acts as
			$account = $this->accountService->getActorFromUserId($user->getUID());

			$client->setAuthScopes($client->getScopesFromString($scope));
			$client->setAuthAccount($account->getPreferredUsername());
			$client->setAuthUserId($user->getUID());
			$client->setAuthCodeChallenge($code_challenge);
			$client->setAuthCodeChallengeMethod($code_challenge_method);
			// the token exchange has to name this same URI (RFC 6749 §4.1.3)
			$client->setAuthRedirectUri($redirect_uri);

			$this->clientService->authClient($client);
			$code = $client->getAuthCode();

			if ($redirect_uri !== ClientService::REDIRECT_URI_OOB) {
				return new RedirectResponse($this->redirectWithCode($redirect_uri, $code, $state));
			}

			// The out-of-band flow: the code is shown to the person to paste
			// into their client. This is reached by submitting the consent
			// form, so what arrives here is a browser, and the answer has to
			// be a page -- as a JSON body it rendered as `{"code":"..."}` on a
			// blank white document, immediately after a consent screen that
			// had promised the code would be shown to them. Somebody who does
			// not recognise that as the code reads it as the button having
			// done nothing.
			$this->initialState->provideInitialState('code', $code);
			$this->initialState->provideInitialState('appName', $client->getAppName());

			return new TemplateResponse(
				Application::APP_ID, 'oauth2', [], TemplateResponse::RENDER_AS_GUEST
			);
		} catch (Exception $e) {
			return $this->authorizationFailure($e);
		}
	}

	/**
	 * The registered redirect URI with the authorization code — and the
	 * client's `state` — appended to whatever query string it already had.
	 *
	 * Built with http_build_query rather than by concatenating '?code=': a
	 * `redirect_uri` that already carries a query string (Elk registers one)
	 * ended up with two `?` in it and the client could not read the code out of
	 * it. `state` was dropped entirely, which is the value a web client
	 * compares against what it stored to know the redirect is the answer to its
	 * own request — a client that follows the spec rejects a redirect without
	 * it, and one that does not is open to having a code injected.
	 */
	private function redirectWithCode(string $redirectUri, string $code, string $state): string {
		$parameters = ['code' => $code];
		if ($state !== '') {
			$parameters['state'] = $state;
		}

		return $this->appendToRedirectUri($redirectUri, $parameters);
	}

	/**
	 * Parameters added to a registered redirect URI's query string, keeping
	 * whatever query string and fragment it already had.
	 */
	private function appendToRedirectUri(string $redirectUri, array $parameters): string {
		$fragment = '';
		$pos = strpos($redirectUri, '#');
		if ($pos !== false) {
			$fragment = substr($redirectUri, $pos);
			$redirectUri = substr($redirectUri, 0, $pos);
		}

		$separator = (strpos($redirectUri, '?') === false) ? '?' : '&';

		return $redirectUri . $separator . http_build_query($parameters) . $fragment;
	}

	/**
	 * The authorization-code grant.
	 *
	 * There is no `scope` parameter here, and there never was one in RFC 6749
	 * §4.1.3. What the token carries is what the person granted, which lives on
	 * the authorization the code names; comparing a `scope` the client sent
	 * here against the *app row* refused every client registered since
	 * authorizations moved to a table of their own, because that column has had
	 * no writer since.
	 *
	 * The client may authenticate with its credentials in the body
	 * (`client_secret_post`) or in an `Authorization: Basic` header
	 * (`client_secret_basic`); both are what the discovery document advertises.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[BruteForceProtection(action: 'socialOauthToken')]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/token')]
	public function token(
		string $redirect_uri = '',
		string $grant_type = '',
		string $client_id = '',
		string $client_secret = '',
		string $code = '',
		string $code_verifier = '',
		string $device_code = '',
	): DataResponse {
		if ($this->isAtprotoClient($client_id)) {
			return $this->atprotoToken();
		}
		try {
			[$client_id, $client_secret] = $this->clientCredentials($client_id, $client_secret);
			$client = $this->clientService->getFromClientId($client_id);
			if ($grant_type === 'urn:ietf:params:oauth:grant-type:device_code') {
				if ($device_code === '') {
					return new DataResponse(['error' => 'invalid_request', 'error_description' => 'missing device_code'], Http::STATUS_BAD_REQUEST);
				}
				if ($client_secret !== '' || $client->getAppClientSecret() !== '') {
					$this->clientService->confirmData($client, ['client_secret' => $client_secret]);
				}
				$authorization = $this->deviceAuthorization->poll($device_code, $client_id);
				if (isset($authorization['error'])) {
					return new DataResponse(['error' => $authorization['error']], Http::STATUS_BAD_REQUEST);
				}
				$account = $this->accountService->getActorFromUserId($authorization['user_id']);
				$client->setAuthScopes($client->getScopesFromString($authorization['scopes']))
					->setAuthAccount($account->getPreferredUsername())
					->setAuthUserId($authorization['user_id'])
					->setAuthRedirectUri(ClientService::REDIRECT_URI_OOB);
				$this->clientService->authClient($client);
				$client = $this->clientService->exchangeCode($client, $client->getAuthCode(), '', ClientService::REDIRECT_URI_OOB);
			} else {
				// no redirect_uri on the client_credentials grant (RFC 6749 §4.4.2)
				$this->clientService->confirmData(
					$client,
					($grant_type === 'client_credentials')
						? ['client_secret' => $client_secret]
						: ['client_secret' => $client_secret, 'redirect_uri' => $redirect_uri]
				);

				// the error bodies carry RFC 6749 §5.2's names, which is what a
				// client matches on, and say in words what was wrong
				if ($grant_type === 'authorization_code') {
					if ($code === '') {
						return new DataResponse(
							['error' => 'invalid_request', 'error_description' => 'missing code'],
							Http::STATUS_BAD_REQUEST
						);
					}

					// the code names the authorization, so what comes back is the
					// account that granted it rather than whatever the app row
					// last held -- and only against the redirect_uri it was sent to
					$client = $this->clientService->exchangeCode($client, $code, $code_verifier, $redirect_uri);
				} elseif ($grant_type === 'client_credentials') {
					// a token for the app itself, bound to no account: what
					// Mastodon's own apps take before anybody has signed in
					$client = $this->clientService->issueAppToken($client);
				} else {
					return new DataResponse(
						[
							'error' => 'unsupported_grant_type',
							'error_description' => 'grant_type must be authorization_code, client_credentials or urn:ietf:params:oauth:grant-type:device_code',
						],
						Http::STATUS_BAD_REQUEST
					);
				}
			}

			if ($client->getToken() === '') {
				return new DataResponse(
					['error' => 'issue generating access_token'], Http::STATUS_BAD_REQUEST
				);
			}

			return new DataResponse(
				[
					// the scopes this token really carries, which is what
					// checkTokenScope() enforces on every request made with it.
					// Echoing the scope of the token *call* — 'read', for a
					// client like Tusky that omits it — had clients hiding
					// their compose button while writes in fact worked.
					'access_token' => $client->getToken(),
					'token_type' => 'Bearer',
					'scope' => implode(' ', $client->getAuthScopes()),
					'created_at' => $client->getCreation()
				], Http::STATUS_OK
			);
		} catch (ClientNotFoundException $e) {
			// A wrong client id / secret / code is a credential guess; throttle it so
			// the public token endpoint cannot be brute-forced.
			$response = new DataResponse(
				['error' => 'invalid_client', 'error_description' => 'unknown client_id'],
				Http::STATUS_UNAUTHORIZED
			);
			$response->throttle(['action' => 'socialOauthToken']);

			return $response;
		} catch (InvalidGrantException $e) {
			// RFC 6749 §5.2: a code presented under another redirect_uri than
			// the one it was issued for is a 400, and a guess at somebody's code
			$response = new DataResponse(
				['error' => 'invalid_grant', 'error_description' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
			$response->throttle(['action' => 'socialOauthToken']);

			return $response;
		} catch (ClientException $e) {
			// the app's own refusals -- a wrong secret, an unknown redirect_uri,
			// an expired code, a bad verifier -- are a credential guess too
			$response = new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'socialOauthToken']);

			return $response;
		} catch (Exception $e) {
			// anything else is this server's failure, and its message is for
			// the log rather than for an anonymous caller
			$this->logger->error('OAuth token exchange failed', ['exception' => $e]);

			return new DataResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Token revocation (RFC 7009). Only the client the token was issued to may
	 * revoke it. Always answers 200 for a token that (no longer) exists, so the
	 * endpoint is not an oracle; wrong client credentials are throttled like the
	 * token endpoint.
	 *
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[BruteForceProtection(action: 'socialOauthToken')]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/revoke')]
	public function revoke(string $token, string $client_id = '', string $client_secret = ''): DataResponse {
		if ($this->isAtprotoClient($client_id)) {
			$this->atprotoOAuth->revoke($token);
			$response = new DataResponse([], Http::STATUS_OK);
			AtprotoOAuthController::cors($response);

			return $response;
		}
		try {
			[$client_id, $client_secret] = $this->clientCredentials($client_id, $client_secret);
			$client = $this->clientService->getFromClientId($client_id);
			$this->clientService->confirmData($client, ['client_secret' => $client_secret]);
		} catch (Exception $e) {
			$response = new DataResponse(['error' => 'unknown client_id'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'socialOauthToken']);

			return $response;
		}

		try {
			$this->clientService->revokeToken($client, $token);
		} catch (Exception $e) {
			// RFC 7009: an unknown or already-revoked token is a success
		}

		return new DataResponse([], Http::STATUS_OK);
	}

	/**
	 * Every scope this server understands, in the order Mastodon lists them.
	 *
	 * The narrow ones are what the routes actually ask for; the broad ones are
	 * what `checkTokenScope()` accepts in their place, so a client that asks
	 * for `read` gets every `read:*` route — and a client that asks for
	 * `read:lists` gets that one and no other. Published so a client can ask
	 * for what it needs rather than for everything, which is the whole point of
	 * the discovery document.
	 */
	public const SCOPES = [
		'read', 'write', 'follow', 'push',
		'read:accounts', 'read:blocks', 'read:bookmarks', 'read:collections',
		'read:favourites', 'read:filters', 'read:follows', 'read:lists',
		'read:mutes', 'read:notifications', 'read:search', 'read:statuses',
		'read:stories',
		'write:accounts', 'write:blocks', 'write:bookmarks', 'write:collections',
		'write:conversations', 'write:favourites', 'write:filters',
		'write:follows', 'write:lists', 'write:media', 'write:mutes',
		'write:notifications', 'write:reports', 'write:statuses',
		'write:stories',
	];

	/**
	 * RFC 8414: where the OAuth endpoints are, and what they take.
	 *
	 * A Mastodon 4.3 client asks for this before it registers an app, and a
	 * server that answers it saves a round of guessing — the client learns the
	 * authorization and token endpoints, the scopes it may ask for and the
	 * response types that work, instead of assuming Mastodon's own paths.
	 *
	 * **The addresses have to match how the document was reached.** RFC 8414
	 * §3.3 says the `issuer` must be the URL the metadata was fetched from
	 * minus the well-known suffix, and a client that follows the spec rejects
	 * a document whose issuer is anything else. A client asking
	 * `https://cloud.example/.well-known/oauth-authorization-server` and being
	 * told the issuer is `https://cloud.example/index.php/apps/social/` has
	 * been handed a mismatch, and is right to refuse it -- which it does
	 * before ever opening a browser, so nothing on this server sees it fail.
	 *
	 * So where the domain-root rewrite is in place, this answers with the root
	 * addresses the client actually used. Where it is not, it answers with the
	 * app's own, which is the honest answer for a client that found this
	 * document under `/apps/social/` and the only reachable one.
	 *
	 * The rewrite cannot be detected from the request -- an internal proxy
	 * hands PHP the app path either way -- so what decides is the check that
	 * already probes it for the setup warning, read from its cache and never
	 * run from here.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/oauth-authorization-server')]
	public function oauthMetadata(): DataResponse {
		$base = rtrim($this->configService->getSocialUrl(), '/');
		if ($this->checkService->clientApiRootIsKnownGood()) {
			$base = rtrim($this->configService->getCloudUrl(true), '/');
		}

		$metadata = [
			'issuer' => $base . '/',
			'authorization_endpoint' => $base . '/oauth/authorize',
			'device_authorization_endpoint' => $base . '/oauth/device_authorization',
			'token_endpoint' => $base . '/oauth/token',
			'revocation_endpoint' => $base . '/oauth/revoke',
			'userinfo_endpoint' => $base . '/oauth/userinfo',
			'app_registration_endpoint' => $base . '/api/v1/apps',
			'scopes_supported' => self::SCOPES,
			'response_types_supported' => ['code'],
			'grant_types_supported' => ['authorization_code', 'client_credentials', 'urn:ietf:params:oauth:grant-type:device_code'],
			'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
			'code_challenge_methods_supported' => ClientService::CODE_CHALLENGE_METHODS,
			'service_documentation' => self::REPOSITORY,
		];

		// One origin has one authorization server document, and AT Protocol
		// requires its issuer to be the bare origin, so with Bluesky on the
		// document is both servers': the Mastodon one's fields, and AT
		// Protocol's added to them. A Mastodon client reads what it knows;
		// the shared endpoints tell the two apart by what only AT Protocol
		// sends. The issuer loses its trailing slash, which RFC 8414 never
		// had: it is the address the document was fetched from, minus the
		// well-known suffix.
		if ($this->atprotoConfig->isEnabled() && $this->checkService->clientApiRootIsKnownGood()) {
			$atproto = $this->atprotoOAuth->metadata();
			$metadata = array_merge($metadata, $atproto, [
				'scopes_supported' => array_values(array_unique(array_merge(self::SCOPES, AuthorizationServer::SCOPES_LISTED))),
				'grant_types_supported' => ['authorization_code', 'client_credentials', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'],
				'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none', 'private_key_jwt'],
			]);
		}

		$response = new DataResponse($metadata, Http::STATUS_OK);
		AtprotoOAuthController::cors($response);

		return $response;
	}

	/**
	 * Whether a token or revocation request is a Bluesky app's: it proves a
	 * DPoP key, or names its client by URL. A Mastodon client does neither,
	 * and AT Protocol requires both.
	 */
	private function isAtprotoClient(string $clientId): bool {
		return $this->atprotoConfig->isEnabled()
			&& ($this->request->getHeader('DPoP') !== '' || str_starts_with($clientId, 'https://') || str_starts_with($clientId, 'http://localhost'));
	}

	/**
	 * The consent page for a Bluesky app's pushed request: the same page a
	 * Mastodon app's is shown on, told what this one asks for.
	 *
	 * Somebody without an account here is asked for a username first, once
	 * the request itself has been found good, as on `authorize()`. The
	 * account's Bluesky identity is made when the consent is drawn for it
	 * (`AuthorizationServer::pending()`), as on any first need of one.
	 */
	private function atprotoAuthorize(string $clientId, string $requestUri): Response {
		try {
			$user = $this->userSession->getUser();
			if ($user === null || !$this->atprotoConfig->isEnabled()) {
				throw OAuthException::invalidRequest('Not signed in');
			}
			$requested = $this->atprotoOAuth->requested($clientId, $requestUri);
			$actor = $this->accountOrNull($user);
			$identity = ($actor === null) ? null : $this->atprotoOAuth->pending($clientId, $requestUri, $user->getUID())['identity'];
		} catch (OAuthException $e) {
			return $this->invalidRequest($e->getMessage());
		}
		$request = $requested['request'];
		$redirectUri = (string)$request->params['redirect_uri'];

		// an app's own name and logo are what it says of itself, shown only
		// for an app the administrator vouches for; otherwise the address its
		// metadata is at, the one thing about it that is checked
		$metadata = $requested['metadata'];
		$trusted = $this->atprotoConfig->isTrustedClient($clientId);
		$name = $trusted && is_string($metadata['client_name'] ?? null) ? trim($metadata['client_name']) : '';
		$logo = $trusted && is_string($metadata['logo_uri'] ?? null) && str_starts_with($metadata['logo_uri'], 'https://') ? $metadata['logo_uri'] : '';
		$this->initialState->provideInitialState('appName', $name !== '' ? mb_substr($name, 0, 100) : (parse_url($clientId, PHP_URL_HOST) ?: $clientId));
		$this->initialState->provideInitialState('appWebsite', $clientId);
		$this->initialState->provideInitialState('appLogo', $logo);
		$this->initialState->provideInitialState('trusted', $trusted);
		$this->initialState->provideInitialState('protocol', 'atproto');
		$this->initialState->provideInitialState('permissions', $this->permissionDescriber?->describe(
			$requested['scopes'], $this->l10nFactory?->getUserLanguage($user) ?? 'en'
		) ?? []);
		if ($actor !== null && $identity !== null) {
			$this->initialState->provideInitialState('account', [
				'uid' => $user->getUID(),
				'displayName' => $this->accountDisplayName($actor, $user),
				'handle' => '@' . $identity->handle,
			]);
		} else {
			$this->provideSetup($user);
		}
		$this->initialState->provideInitialState('scopes', $requested['scopes']);
		$this->initialState->provideInitialState('redirectUri', $redirectUri);
		$this->initialState->provideInitialState('denyUrl', $this->atprotoOAuth->refusal($request));
		$response = new TemplateResponse(Application::APP_ID, 'oauth2', [], TemplateResponse::RENDER_AS_GUEST);
		$policy = $this->consentPolicy($redirectUri);
		if ($logo !== '') {
			$policy->addAllowedImageDomain((string)parse_url($logo, PHP_URL_SCHEME) . '://' . (string)parse_url($logo, PHP_URL_HOST));
		}
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	/**
	 * The person agreed on the consent page: the app gets its code.
	 */
	private function atprotoAuthorizing(string $clientId, string $requestUri): Response {
		try {
			$user = $this->userSession->getUser();
			if ($user === null || !$this->atprotoConfig->isEnabled()) {
				throw OAuthException::invalidRequest('Not signed in');
			}

			return new RedirectResponse($this->atprotoOAuth->approve($clientId, $requestUri, $user->getUID()));
		} catch (OAuthException $e) {
			return $this->invalidRequest($e->getMessage());
		}
	}

	/**
	 * A Bluesky app's token request: a code exchanged or a session refreshed,
	 * answered with a fresh DPoP nonce whatever the outcome.
	 */
	private function atprotoToken(): DataResponse {
		try {
			$response = new DataResponse($this->atprotoOAuth->token($this->request->getParams(), $this->request->getHeader('DPoP')), Http::STATUS_OK);
			$response->addHeader('Cache-Control', 'no-store');
		} catch (OAuthException $e) {
			$response = AtprotoOAuthController::error($e);
			if (in_array($e->error, ['invalid_grant', 'invalid_client'], true)) {
				$response->throttle(['action' => 'socialOauthToken']);
			}
		} catch (Throwable $e) {
			$this->logger->error('Bluesky OAuth token request failed', ['exception' => $e]);
			$response = new DataResponse(['error' => 'server_error', 'error_description' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if (!isset($response->getHeaders()['DPoP-Nonce'])) {
			$response->addHeader('DPoP-Nonce', $this->dpopNonce->current());
		}
		AtprotoOAuthController::cors($response);

		return $response;
	}

	/**
	 * Who the token belongs to, in OpenID Connect's shape.
	 *
	 * Mastodon 4.3 added this so a client can show "signed in as …" without
	 * spending a `read:accounts` call on `verify_credentials`. It answers from
	 * the token alone and needs no scope beyond having one, which is what
	 * OpenID Connect expects of it.
	 *
	 * The claims are the four Mastodon sends. `sub` is the actor's ActivityPub
	 * id rather than the Nextcloud user id: it is the identifier that means
	 * the same thing to everybody, and handing out an internal user id to
	 * every client that asks is not something to do by accident.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/oauth/userinfo')]
	public function userinfo(): DataResponse {
		try {
			$client = $this->clientService->getFromToken($this->bearerToken());
			// read-only: a token cannot exist without the account it was
			// granted for, since /oauth/authorize looks one up without creating
			$actor = $this->accountService->getActorFromUserId($client->getAuthUserId());
		} catch (Exception $e) {
			return new DataResponse(
				['error' => 'The access token is invalid'], Http::STATUS_UNAUTHORIZED
			);
		}

		return new DataResponse([
			'sub' => $actor->getId(),
			'name' => ($actor->getName() !== '') ? $actor->getName() : $actor->getPreferredUsername(),
			'preferred_username' => $actor->getPreferredUsername(),
			'profile' => $actor->getId(),
			'picture' => $actor->getAvatar(),
		], Http::STATUS_OK);
	}

	/**
	 * The client credentials on this request, from the body or from an
	 * `Authorization: Basic` header (RFC 6749 §2.3.1).
	 *
	 * The body wins where both are present, which is what the client meant by
	 * sending it. The header's two halves are form-urlencoded before they are
	 * base64'd, so they are decoded that way — a secret containing `+` or `%`
	 * is otherwise not the secret that was issued.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function clientCredentials(string $clientId, string $clientSecret): array {
		if ($clientId !== '') {
			return [$clientId, $clientSecret];
		}

		$header = $this->request->getHeader('Authorization');
		if (!str_starts_with($header, 'Basic ')) {
			return [$clientId, $clientSecret];
		}

		$decoded = base64_decode(substr($header, 6), true);
		if ($decoded === false || !str_contains($decoded, ':')) {
			return [$clientId, $clientSecret];
		}

		[$id, $secret] = explode(':', $decoded, 2);

		return [urldecode($id), urldecode($secret)];
	}

	/** The bearer token on this request, or '' when there is none. */
	private function bearerToken(): string {
		$header = $this->request->getHeader('Authorization');

		return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
	}
}
