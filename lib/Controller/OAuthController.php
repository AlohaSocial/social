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
use OCA\Social\Exceptions\InvalidScopeException;
use OCA\Social\Middleware\AnonymousRateLimit;
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
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
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
	 * `^[a-z0-9-]+$` and crawlers group instances by it, so it is a constant;
	 * the human title travels as `metadata.nodeName`.
	 */
	private const REPOSITORY = 'https://github.com/alohasocial/social';

	/**
	 * The NodeInfo schemas this instance serves, oldest first. Read by
	 * `WebfingerHandler` so the versions advertised are the versions served.
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
	 * `software.homepage`. Needs both the route and an entry in the discovery
	 * document, since crawlers follow only what that names.
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
	 * Registers a client app. Public, so it carries its own rate limits: every
	 * registration is a row that stays until the sweep in `Cron\Queue` finds
	 * it never authorized. A registration the service refuses is a 422, as
	 * Mastodon answers one that fails validation.
	 *
	 * @param array|string $redirect_uris
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 15, period: 300)]
	#[UserRateLimit(limit: 15, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/apps')]
	public function apps(
		string $client_name = '',
		$redirect_uris = '',
		string $website = '',
		string $scopes = 'read',
	): DataResponse {
		// Mastodon takes several redirect URIs, newline-separated in a single
		// `redirect_uris` field
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
		// separated by any whitespace; none at all is `read`, as on Mastodon
		$scopes = array_values(array_unique(preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: ['read']));
		$client->setAppScopes($scopes);
		$client->setAppName($client_name);

		try {
			$this->clientService->createApp($client);
		} catch (ClientException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(
			[
				// a string, as every id in Mastodon's API is: typed clients
				// declare `id: String` and fail to decode a number
				'id' => (string)$client->getId(),
				'name' => $client->getAppName(),
				// null rather than '' where the client registered no website,
				// as Mastodon sends; '' fails a client decoding a URL
				'website' => $client->getAppWebsite() === '' ? null : $client->getAppWebsite(),
				// lists, as Mastodon 4.3 sends them: a client decoding the
				// answer into `[String]` fails on one space-separated string
				'scopes' => array_values($client->getAppScopes()),
				'redirect_uris' => array_values($client->getAppRedirectUris()),
				'client_id' => $client->getAppClientId(),
				'client_secret' => $client->getAppClientSecret(),
				// Mastodon's Application entity always carries these two;
				// `redirect_uri` is the first registered, as Mastodon reports
				'redirect_uri' => $client->getAppRedirectUris()[0] ?? '',
				'vapid_key' => $this->vapidService->publicKey(),
			], Http::STATUS_OK
		);
	}

	/** Starts the RFC 8628 flow for a registered OAuth application. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 20, period: 300)]
	#[UserRateLimit(limit: 20, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/device_authorization')]
	public function deviceAuthorization(
		string $client_id = '',
		string $scope = 'read',
		#[\SensitiveParameter] string $client_secret = '',
	): DataResponse {
		try {
			[$client_id, $client_secret] = $this->clientCredentials($client_id, $client_secret);
			$client = $this->clientService->getFromClientId($client_id);
			// RFC 8628 §3.5: a token that moves the account away goes to the
			// move-in callback only, never to whoever polls with a device code;
			// and the device consent has no password step for the scopes that
			// need one
			if (ClientService::needsPasswordConfirmation($client->getScopesFromString($scope))) {
				return new DataResponse(
					['error' => 'invalid_scope', 'error_description' => 'write:migration and admin scopes are not granted to a device'],
					Http::STATUS_BAD_REQUEST
				);
			}
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
	#[AnonymousRateLimit(limit: 30, period: 300)]
	#[UserRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: 'socialOauthDeviceCode')]
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
		if ($user_code !== '' && $pending === null) {
			$response->throttle(['action' => 'socialOauthDeviceCode']);
		}
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
		$response = new TemplateResponse(Application::APP_ID, 'oauth2', [], TemplateResponse::RENDER_AS_GUEST);
		if ($canAuthorize && $validDecision && !$saved) {
			// a user code that is unknown, expired or already decided is a guess
			$response->throttle(['action' => 'socialOauthDeviceCode']);
		}
		return $response;
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
	 * The consent page. Every problem with the request (unknown `client_id`,
	 * a `response_type` other than `code`, an unregistered `redirect_uri` or
	 * scope) is answered as `authorizing()` answers it, not as a Nextcloud
	 * error page. Somebody without an account here is first asked for a
	 * username (`setup`); reloading then shows the consent for that account.
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

			$this->clientService->confirmData($client, ['app_scopes' => $scope, 'redirect_uri' => $redirect_uri]);

			// what the person is being asked to agree to: the app, what it may
			// do, and where the code is about to be sent
			$this->initialState->provideInitialState('appName', $client->getAppName());
			$this->initialState->provideInitialState('appWebsite', $this->websiteLink($client->getAppWebsite()));
			$actor = $this->accountOrNull($user);
			if ($actor === null) {
				$this->provideSetup($user);
			} else {
				// which account is about to be handed over, for somebody who
				// holds more than one
				$this->initialState->provideInitialState('account', [
					'uid' => $user->getUID(),
					'displayName' => $this->accountDisplayName($actor, $user),
					'handle' => '@' . $actor->getPreferredUsername() . '@' . $this->configService->getSocialAddress(),
				]);
			}
			$this->initialState->provideInitialState('scopes', $client->getScopesFromString($scope));
			$this->initialState->provideInitialState('redirectUri', $redirect_uri);
			$this->initialState->provideInitialState('denyUrl', $this->denyUrl($redirect_uri, $state));

			// as a guest page, not inside the app: the app's navigation would
			// distract from the consent, and the guest layout centres the box
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

			// Nextcloud's default `form-action 'self'` also applies to where a
			// submission ends up, so the redirect to the client would be
			// blocked. The URI is the one registered and checked above, so
			// this widens the policy to exactly that destination.
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
	 * as the app's own setup page does (`NavigationController`). The account
	 * is created by `LocalController::accountCreate()`.
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
	 * The name to show for the account being handed over. The Nextcloud
	 * account's name stands in where the profile has none, since
	 * `Person::getDisplayName()` would fall back to the handle and show it twice.
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
	 * redirect URI as a form-action destination. Out-of-band needs nothing
	 * added; a custom scheme (`tusky://…`) is allowed as a scheme-source.
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
	 * The redirect URI as a CSP source expression. A custom scheme becomes
	 * `scheme:`, since `icecubesapp://` is not a valid source and a browser
	 * would drop it; an `http`/`https` URI becomes its origin, as a source
	 * carries no path.
	 */
	private function formActionSource(string $redirectUri): string {
		if ($redirectUri === '' || $redirectUri === ClientService::REDIRECT_URI_OOB) {
			// answered by a page on this server; 'self' already covers it
			return '';
		}

		// a pattern rather than parse_url(), which returns false for a bare
		// `icecubesapp://` with no host
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
	 * challenge. An omitted method means `plain` (RFC 7636), which this server
	 * does not support, so the client is told rather than granted an unbound code.
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
	 * `https` address, or nothing.
	 */
	private function websiteLink(string $website): string {
		return ClientService::websiteLink($website);
	}

	/**
	 * RFC 6749 §3.1.2.3: the `redirect_uri` of a request has to be, exactly,
	 * one the client registered. Compared as strings, not by origin or prefix.
	 */
	private function isRegisteredRedirectUri(SocialClient $client, string $redirectUri): bool {
		return in_array($redirectUri, $client->getAppRedirectUris(), true);
	}

	/**
	 * Why an authorization request was not granted. The client's own mistakes
	 * and a missing account are this app's refusals and are told as they are;
	 * anything else is a server failure, logged, and answered `server_error`
	 * as from `token()`.
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
	 * `error=access_denied` (RFC 6749 §4.1.2.1). The out-of-band flow, and a
	 * redirect URI registration would now refuse (such as `javascript:`), land
	 * on the app instead.
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

		return $this->grantCode($client_id, $redirect_uri, $response_type, $scope, $state, $code_challenge, $code_challenge_method, false);
	}

	/**
	 * The consent to the scopes that need the password
	 * (`ClientService::needsPasswordConfirmation()`): `write:migration`,
	 * which lets another Aloha Social server move the account there, and the
	 * `admin` scopes. The same grant as `authorizing()`, behind Nextcloud's
	 * password confirmation.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/authorize/confirmed')]
	public function authorizingConfirmed(
		string $client_id,
		string $redirect_uri = '',
		string $response_type = '',
		string $scope = '',
		string $state = '',
		string $code_challenge = '',
		string $code_challenge_method = '',
	): Response {
		return $this->grantCode($client_id, $redirect_uri, $response_type, $scope, $state, $code_challenge, $code_challenge_method, true);
	}

	/**
	 * Issues an authorization code for the signed-in account.
	 *
	 * @param bool $passwordConfirmed whether the request came through the password-confirmed route, the only one that grants `write:migration` and the `admin` scopes
	 */
	private function grantCode(
		string $client_id,
		string $redirect_uri,
		string $response_type,
		string $scope,
		string $state,
		string $code_challenge,
		string $code_challenge_method,
		bool $passwordConfirmed,
	): Response {
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

			$this->clientService->confirmData($client, ['app_scopes' => $scope, 'redirect_uri' => $redirect_uri]);
			if (!$passwordConfirmed && ClientService::needsPasswordConfirmation($client->getScopesFromString($scope))) {
				throw new ClientException('write:migration and admin scopes are granted only after the password is confirmed');
			}

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

			// The out-of-band flow: the code is shown on a page for the person
			// to paste into their client, since a browser submitted the form.
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
	 * The registered redirect URI with the authorization code and the
	 * client's `state` appended to any query string it already has (Elk
	 * registers one). A spec-following client rejects a redirect without
	 * `state`.
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
	 * The token endpoint: the authorization-code, device-code,
	 * `client_credentials` and `refresh_token` grants.
	 *
	 * The token carries what the person granted on the authorization the code
	 * names; only the `refresh_token` grant reads `scope`, which may narrow it
	 * (RFC 6749 §6). A token for an account comes with a `refresh_token` and
	 * an `expires_in`. Client credentials may come in the body or in an
	 * `Authorization: Basic` header, as the discovery document advertises.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: 'socialOauthToken')]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/token')]
	public function token(
		string $redirect_uri = '',
		string $grant_type = '',
		string $client_id = '',
		#[\SensitiveParameter] string $client_secret = '',
		#[\SensitiveParameter] string $code = '',
		#[\SensitiveParameter] string $code_verifier = '',
		#[\SensitiveParameter] string $device_code = '',
		#[\SensitiveParameter] string $refresh_token = '',
		string $scope = '',
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
				// no redirect_uri on the client_credentials and refresh_token
				// grants (RFC 6749 §4.4.2, §6)
				$this->clientService->confirmData(
					$client,
					($grant_type === 'client_credentials' || $grant_type === 'refresh_token')
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

					// the code names the authorization, so the account is the one
					// that granted it, and only against the redirect_uri it was sent to
					$client = $this->clientService->exchangeCode($client, $code, $code_verifier, $redirect_uri);
				} elseif ($grant_type === 'refresh_token') {
					if ($refresh_token === '') {
						return new DataResponse(
							['error' => 'invalid_request', 'error_description' => 'missing refresh_token'],
							Http::STATUS_BAD_REQUEST
						);
					}

					// rotated: the refresh token presented is spent, and a
					// second use of it revokes the authorization
					$client = $this->clientService->refresh($client, $refresh_token, $scope);
				} elseif ($grant_type === 'client_credentials') {
					// a token for the app itself, bound to no account: what
					// Mastodon's own apps take before anybody has signed in
					$client = $this->clientService->issueAppToken($client);
				} else {
					return new DataResponse(
						[
							'error' => 'unsupported_grant_type',
							'error_description' => 'grant_type must be authorization_code, refresh_token, client_credentials or urn:ietf:params:oauth:grant-type:device_code',
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

			$answer = [
				// the scopes this token really carries, as checkTokenScope()
				// enforces them, not the scope of the token call
				'access_token' => $client->getToken(),
				'token_type' => 'Bearer',
				'scope' => implode(' ', $client->getAuthScopes()),
				'created_at' => $client->getCreation()
			];
			// RFC 6749 §5.1; an app token has neither
			$expiresIn = $this->clientService->expiresIn($client);
			if ($expiresIn !== null) {
				$answer['expires_in'] = $expiresIn;
			}
			if ($client->getRefreshToken() !== '') {
				$answer['refresh_token'] = $client->getRefreshToken();
			}

			return new DataResponse($answer, Http::STATUS_OK);
		} catch (ClientNotFoundException $e) {
			// A wrong client id / secret / code is a credential guess; throttle it so
			// the public token endpoint cannot be brute-forced.
			$response = new DataResponse(
				['error' => 'invalid_client', 'error_description' => 'unknown client_id'],
				Http::STATUS_UNAUTHORIZED
			);
			$response->throttle(['action' => 'socialOauthToken']);

			return $response;
		} catch (InvalidScopeException $e) {
			return new DataResponse(
				['error' => 'invalid_scope', 'error_description' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (InvalidGrantException $e) {
			// RFC 6749 §5.2: a code presented under another redirect_uri than
			// the one it was issued for is a 400, and a guess at somebody's code,
			// as is an unknown, spent or expired refresh token
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
	 * revoke it. Always answers 200 for an unknown token, so the endpoint is
	 * not an oracle; wrong client credentials are throttled like the token
	 * endpoint.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[BruteForceProtection(action: 'socialOauthToken')]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/revoke')]
	public function revoke(#[\SensitiveParameter] string $token, string $client_id = '', #[\SensitiveParameter] string $client_secret = ''): DataResponse {
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
	 * The narrow ones are what the routes ask for; `checkTokenScope()` accepts
	 * the broad ones in their place.
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
	 * The `issuer` must be the URL the document was fetched from minus the
	 * well-known suffix (§3.3), or a client rejects it. So where the
	 * domain-root rewrite works, this answers with the root addresses, and
	 * otherwise with the app's own. The rewrite cannot be detected from the
	 * request, so the setup check's cached result decides.
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
			'grant_types_supported' => ['authorization_code', 'client_credentials', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'],
			'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
			'code_challenge_methods_supported' => ClientService::CODE_CHALLENGE_METHODS,
			'service_documentation' => self::REPOSITORY,
		];

		// One origin has one authorization server document, and AT Protocol
		// requires its issuer to be the bare origin, so with Bluesky on the
		// document carries both servers' fields. The issuer has no trailing
		// slash: it is the fetch address minus the well-known suffix.
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
	 * Mastodon app's is shown on. Somebody without an account is asked for a
	 * username first, as on `authorize()`; the Bluesky identity is made when
	 * the consent is drawn (`AuthorizationServer::pending()`).
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
	 * Who the token belongs to, in OpenID Connect's shape, needing no scope
	 * beyond having a token. The claims are the four Mastodon sends; `sub` is
	 * the actor's ActivityPub id, not the internal Nextcloud user id.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/oauth/userinfo')]
	public function userinfo(): DataResponse {
		try {
			$client = $this->clientService->getFromTokenOfRequest($this->bearerToken());
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
	 * `Authorization: Basic` header (RFC 6749 §2.3.1). The body wins; the
	 * header's halves are form-urlencoded, so a secret with `+` or `%` is
	 * decoded that way.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function clientCredentials(string $clientId, #[\SensitiveParameter] string $clientSecret): array {
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
