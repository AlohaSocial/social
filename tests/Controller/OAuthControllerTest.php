<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use ArrayObject;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\OAuthRequest;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Controller\OAuthController;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\InvalidGrantException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Instance;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DeviceAuthorizationService;
use OCA\Social\Service\ExternalSignupService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\VapidService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class OAuthControllerTest extends TestCase {
	public function testWebPushScopeIsAdvertisedByOAuthDiscovery(): void {
		self::assertContains('push', OAuthController::SCOPES);
	}

	/** @var AuthorizationServer&MockObject */
	private AuthorizationServer $atprotoOAuth;
	/** @var AtprotoConfig&MockObject */
	private AtprotoConfig $atprotoConfig;
	private const OOB = 'urn:ietf:wg:oauth:2.0:oob';

	/** @var IUserSession&Stub */
	private $userSession;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var InstanceService&Stub */
	private $instanceService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var ClientService&MockObject */
	private $clientService;
	/** @var ConfigService&MockObject */
	private $configService;
	private CheckService|Stub $checkService;
	/** @var IInitialState&MockObject */
	private $initialState;
	/** @var IRequest&MockObject */
	private $request;
	private OAuthController $controller;
	private ExternalSignupService $externalSignupService;

	protected function setUp(): void {
		$this->atprotoOAuth = $this->createMock(AuthorizationServer::class);
		$this->atprotoConfig = $this->createMock(AtprotoConfig::class);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->instanceService = $this->createStub(InstanceService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->clientService = $this->createMock(ClientService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->checkService = $this->createStub(CheckService::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->request = $this->createMock(IRequest::class);
		$this->externalSignupService = $this->createStub(ExternalSignupService::class);
		$this->externalSignupService->method('acceptsPublicRegistrations')->willReturn(true);

		$this->controller = $this->controllerWith($this->clientService);
	}

	/** The controller under test, on one ClientService — a mock or the real one. */
	private function controllerWith(ClientService $clientService, ?DeviceAuthorizationService $deviceAuthorization = null): OAuthController {
		return new OAuthController(
			$this->request,
			$this->userSession,
			$this->urlGenerator,
			$this->instanceService,
			$this->accountService,
			$clientService,
			$this->configService,
			$this->checkService,
			new NullLogger(),
			$this->initialState,
			$this->atprotoOAuth,
			$this->atprotoConfig,
			$this->createMock(DpopNonce::class),
			$this->vapidService(),
			$deviceAuthorization ?? $this->createStub(DeviceAuthorizationService::class),
			$this->externalSignupService,
			null,
			null,
		);
	}

	private function vapidService(): VapidService {
		$vapid = $this->createStub(VapidService::class);
		$vapid->method('publicKey')->willReturn('instance-vapid-key');

		return $vapid;
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function loggedIn(
		string $uid = 'alice',
		string $accountName = 'Alice Ackermann',
		?string $profileName = null,
	): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($accountName);
		$this->userSession->method('getUser')->willReturn($user);
		$actor = $this->createStub(Person::class);
		$actor->method('getPreferredUsername')->willReturn($uid);
		// what Person::getDisplayName() does when the profile carries no name
		$actor->method('getDisplayName')->willReturn($profileName ?? $uid);
		$this->accountService->method('getActorFromUserId')->with($uid)->willReturn($actor);
	}

	/** Collects everything handed to the consent page, readable after the call. */
	private function recordInitialState(): ArrayObject {
		$states = new ArrayObject();
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $value) use ($states): void {
				$states[$key] = $value;
			});

		return $states;
	}

	/** @param string[] $redirectUris what the client registered; a request may name only one of these */
	private function knownClient(
		string $clientId = 'client-1', string $appName = 'Tusky', array $redirectUris = [self::OOB],
	): SocialClient {
		$client = new SocialClient();
		$client->setAppClientId($clientId)->setAppName($appName)->setAppRedirectUris($redirectUris);
		$this->clientService->method('getFromClientId')->with($clientId)->willReturn($client);

		return $client;
	}

	// nodeinfo2()

	public function testNodeinfo2DescribesTheLocalInstance(): void {
		$instance = new Instance();
		$instance->setTitle('My Aloha Social')->setVersion('0.10.1')->setUsage(['users' => ['total' => 3]])->setRegistrations(true);
		$this->instanceService->method('getLocal')->willReturn($instance);
		$response = $this->controller->nodeinfo2();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'version' => '2.0',
			// the schema wants ^[a-z0-9-]+$ here, and crawlers group instances
			// by it; the human title goes to metadata.nodeName
			'software' => ['name' => 'aloha-social', 'version' => '0.10.1'],
			'protocols' => ['activitypub'],
			'services' => ['inbound' => [], 'outbound' => []],
			// the schema names every top-level key and allows no other, so the
			// old `rootUrl` is gone
			'usage' => ['users' => ['total' => 3]],
			'openRegistrations' => true,
			'metadata' => ['nodeName' => 'My Aloha Social', 'nodeDescription' => ''],
		], $response->getData());
	}

	public function testNodeinfo21AddsTheRepositoryAndHomepage(): void {
		$instance = new Instance();
		$instance->setTitle('My Aloha Social')->setShortDescription('A cosy corner')->setVersion('0.10.1');
		$this->instanceService->method('getLocal')->willReturn($instance);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/social/');

		$data = $this->controller->nodeinfo21()->getData();

		$this->assertSame('2.1', $data['version']);
		$this->assertSame([
			'name' => 'aloha-social',
			'version' => '0.10.1',
			'repository' => 'https://github.com/alohasocial/social',
			'homepage' => 'https://github.com/alohasocial/social',
		], $data['software']);
		$this->assertSame(['nodeName' => 'My Aloha Social', 'nodeDescription' => 'A cosy corner'], $data['metadata']);
		$this->assertSame(['inbound' => [], 'outbound' => []], $data['services']);
	}

	public function testNodeinfo2FallsBackToAppDefaultsWithoutAnInstance(): void {
		$this->instanceService->method('getLocal')->willThrowException(new InstanceDoesNotExistException());
		$this->externalSignupService->method('acceptsPublicRegistrations')->willReturn(false);
		$this->configService->method('getAppValue')->with('installed_version')->willReturn('0.10.1');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/social/');

		$data = $this->controller->nodeinfo2()->getData();

		$this->assertSame(['name' => 'aloha-social', 'version' => '0.10.1'], $data['software']);
		$this->assertSame('Aloha Social', $data['metadata']['nodeName']);
		$this->assertSame(
			'{"users":{}}', json_encode($data['usage']),
			'an object with the users member the NodeInfo schema requires, not an empty list'
		);
		$this->assertFalse($data['openRegistrations']);
	}

	public function testNodeinfoRegistrationStatusUsesSocialSignupInsteadOfNextcloudSignup(): void {
		$instance = new Instance();
		$instance->setTitle('Aloha')->setVersion('0.10.1')->setUsage(['users' => ['total' => 1]])->setRegistrations(false);
		$this->instanceService->method('getLocal')->willReturn($instance);
		$this->externalSignupService->method('acceptsPublicRegistrations')->willReturn(true);

		$data = $this->controller->nodeinfo2()->getData();

		$this->assertTrue($data['openRegistrations']);
	}

	// apps()

	public function testAppsRegistersAClientAndReturnsItsCredentials(): void {
		$this->clientService->expects($this->once())->method('createApp')
			->willReturnCallback(function (SocialClient $client): void {
				$this->assertSame('Tusky', $client->getAppName());
				$this->assertSame('https://tusky.app', $client->getAppWebsite());
				$this->assertSame(['https://tusky.app/callback'], $client->getAppRedirectUris());
				$this->assertSame(['read', 'write'], $client->getAppScopes());
				$client->setId(7)->setAppClientId('cid')->setAppClientSecret('csecret');
			});

		$response = $this->controller->apps('Tusky', 'https://tusky.app/callback', 'https://tusky.app', 'read write');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			// a string, as every id in Mastodon's API is
			'id' => '7',
			'name' => 'Tusky',
			'website' => 'https://tusky.app',
			'scopes' => ['read', 'write'],
			'redirect_uris' => ['https://tusky.app/callback'],
			'client_id' => 'cid',
			'client_secret' => 'csecret',
			// Mastodon's Application entity always carries both, and a client
			// that decodes this into a typed struct fails without them
			'redirect_uri' => 'https://tusky.app/callback',
			'vapid_key' => 'instance-vapid-key',
		], $response->getData());
	}

	/**
	 * Mastodon's API takes several redirect URIs newline-separated in one
	 * field. They used to be wrapped into a single entry, which
	 * `ClientService::confirmData()` then compared a lone URI against — so a
	 * client registered with more than one could never authorize at all.
	 */
	public function testAppsSplitsNewlineSeparatedRedirectUris(): void {
		$this->clientService->expects($this->once())->method('createApp')
			->with($this->callback(
				fn (SocialClient $c): bool
					=> $c->getAppRedirectUris() === ['https://a/cb', 'https://b/cb']
			));

		$this->controller->apps('App', "https://a/cb\nhttps://b/cb\n");
	}

	public function testAppsIgnoresBlankAndDuplicateRedirectUris(): void {
		$this->clientService->expects($this->once())->method('createApp')
			->with($this->callback(
				fn (SocialClient $c): bool => $c->getAppRedirectUris() === ['https://a/cb']
			));

		$this->controller->apps('App', "https://a/cb\n\n  https://a/cb  \n");
	}

	public function testAppsAcceptsAListOfRedirectUris(): void {
		$this->clientService->expects($this->once())->method('createApp')
			->with($this->callback(fn (SocialClient $c): bool => $c->getAppRedirectUris() === ['https://a/cb', 'https://b/cb']));

		$this->controller->apps('App', ['https://a/cb', 'https://b/cb']);
	}

	/**
	 * A redirect URI with a scheme a browser runs rather than follows is a
	 * link on the consent page that would run in the account's session.
	 * `ClientService::createApp()` refuses it, and the refusal is the 422
	 * Mastodon answers a registration that fails validation with -- not the
	 * Nextcloud error page an uncaught exception produced.
	 */
	public function testAppsAnswersARefusedRegistrationWith422(): void {
		$this->clientService->method('createApp')
			->willThrowException(new ClientException('invalid redirect_uri: javascript:alert(1)'));

		$response = $this->controller->apps('App', 'javascript:alert(1)');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'invalid redirect_uri: javascript:alert(1)'], $response->getData());
	}

	public function testAppsDefaultsToReadScope(): void {
		$this->clientService->method('createApp');

		$this->assertSame(['read'], $this->controller->apps('App', 'https://a/cb')->getData()['scopes']);
	}

	/**
	 * Mastodon 4.3's Application: `scopes` and `redirect_uris` are lists. A
	 * client decoding them into `[String]` failed on the one space-separated
	 * string this used to answer with, and with it the registration.
	 */
	public function testAppsAnswersTheScopesAndRedirectUrisAsLists(): void {
		$this->clientService->method('createApp');

		$data = $this->controller->apps('App', "https://a/cb\nhttps://b/cb", '', 'read write follow')->getData();

		$this->assertSame(['read', 'write', 'follow'], $data['scopes']);
		$this->assertSame(['https://a/cb', 'https://b/cb'], $data['redirect_uris']);
		$this->assertSame('https://a/cb', $data['redirect_uri'], 'the first one, as the deprecated key always said');
	}

	/**
	 * Ice Cubes decodes this response into `InstanceApp`, whose `id` is a
	 * `String` and whose `website` is a `URL?` -- because that is the shape
	 * Mastodon sends. A JSON number for `id`, or `""` for `website`, fails to
	 * decode, and the registration then succeeds with a 200 the client cannot
	 * read: its sign-in stops there, with a 200 in the web server's log and
	 * nothing at all in the app's.
	 */
	public function testTheRegistrationIsShapedTheWayATypedClientDecodesIt(): void {
		$this->clientService->method('createApp')
			->willReturnCallback(static function (SocialClient $client): void {
				$client->setId(7)->setAppClientId('cid')->setAppClientSecret('csecret');
			});

		$data = $this->controller->apps('Tusky', 'https://tusky.app/callback', '', 'read')->getData();

		$this->assertIsString($data['id']);
		$this->assertNull($data['website'], 'an absent website is null, never the empty string');
	}

	// authorize()

	public function testAuthorizeRendersTheConsentPageForAKnownClient(): void {
		$this->loggedIn();
		$this->knownClient();
		$states = $this->recordInitialState();

		$response = $this->controller->authorize('client-1', self::OOB, 'code', 'read write');

		$this->assertSame('Tusky', $states['appName']);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('oauth2', $response->getTemplateName());
		$this->assertSame([
			'request' => [
				'clientId' => 'client-1',
				'redirectUri' => self::OOB,
				'responseType' => 'code',
				'scope' => 'read write',
				'state' => '',
				'codeChallenge' => '',
				'codeChallengeMethod' => '',
			],
		], $response->getParams());
	}

	/** The consent page names the application's website, which it may not have. */
	public function testTheConsentPageCarriesTheApplicationsWebsite(): void {
		$this->loggedIn();
		$this->knownClient()->setAppWebsite('https://tusky.app');
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', self::OOB, 'code', 'read');

		$this->assertSame('https://tusky.app', $states['appWebsite']);
	}

	/**
	 * Which account is being handed over is half of what is being asked, and
	 * on a server where somebody holds more than one, the name of the
	 * application alone does not answer it.
	 */
	public function testTheConsentPageNamesTheAccountBeingHandedOver(): void {
		$this->loggedIn('aiko', 'Aiko Tanaka', 'Aiko');
		$this->knownClient();
		$this->configService->method('getSocialAddress')->willReturn('example.com');
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', self::OOB, 'code', 'read');

		$this->assertSame([
			'uid' => 'aiko',
			'displayName' => 'Aiko',
			'handle' => '@aiko@example.com',
		], $states['account']);
	}

	/**
	 * `Person::getDisplayName()` falls back to the handle when the profile
	 * carries no name of its own, which on the consent page reads as the
	 * username twice over -- once as the name and once as the handle under it.
	 */
	public function testAProfileWithNoNameOfItsOwnIsShownUnderTheNextcloudName(): void {
		$this->loggedIn('aiko', 'Aiko Tanaka');
		$this->knownClient();
		$this->configService->method('getSocialAddress')->willReturn('example.com');
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', self::OOB, 'code', 'read');

		$this->assertSame('Aiko Tanaka', $states['account']['displayName']);
	}

	/**
	 * Not inside the app. This asks somebody to hand an application their
	 * account, and the app's content area is a flex container, so a page
	 * mounted into it took the width of its own contents and sat against the
	 * left edge of the window rather than in the middle of it.
	 */
	public function testTheConsentPageIsAGuestPage(): void {
		$this->loggedIn();
		$this->knownClient();

		$response = $this->controller->authorize('client-1', self::OOB, 'code', 'read');

		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
	}

	/**
	 * Nextcloud's default policy is `form-action 'self'`, and a browser applies
	 * that to where a submission *ends up*. Granting an application whose
	 * redirect_uri is anywhere but this server therefore submitted the form,
	 * took the 303 to the client and had the navigation blocked -- no error, no
	 * page, the button apparently doing nothing.
	 */
	public function testTheConsentPageLetsTheFormReachTheClientsRedirectUri(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['https://app.example/callback']);

		$response = $this->controller->authorize(
			'client-1', 'https://app.example/callback', 'code', 'read'
		);

		$this->assertStringContainsString(
			'https://app.example',
			$response->getContentSecurityPolicy()->buildPolicy()
		);
	}

	/**
	 * A source expression is not a URI. `form-action 'self' icecubesapp://` is
	 * not something a browser can parse, so it drops the expression, leaves
	 * `'self'`, and blocks the redirect exactly as if nothing had been added --
	 * the Authorize button goes on doing nothing for the one kind of client
	 * that most needs it to work. A scheme-source is the scheme and one colon.
	 */
	public function testACustomSchemeBecomesASchemeSource(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['tusky://oauth']);

		$policy = $this->controller->authorize('client-1', 'tusky://oauth', 'code', 'read')
			->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringContainsString('tusky:', $policy);
		$this->assertStringNotContainsString('tusky://', $policy);
	}

	/**
	 * `icecubesapp://` -- a scheme, two slashes and nothing else -- is what
	 * Ice Cubes registers, and `parse_url()` answers `false` for it, having no
	 * host to find. Reading the scheme that way lost it and added nothing at
	 * all, which is a widening that silently does not happen.
	 */
	public function testASchemeWithNothingAfterItIsStillRead(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['icecubesapp://']);

		$policy = $this->controller->authorize('client-1', 'icecubesapp://', 'code', 'read')
			->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringContainsString('icecubesapp:', $policy);
	}

	/**
	 * A source expression carries no path, and the path is not ours to
	 * constrain: the code goes to the URI the client registered, which was
	 * checked before the page was drawn.
	 */
	public function testAnHttpsUriBecomesItsOrigin(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['https://app.example:8443/callback?x=1']);

		$policy = $this->controller->authorize(
			'client-1', 'https://app.example:8443/callback?x=1', 'code', 'read'
		)->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringContainsString('https://app.example:8443', $policy);
		$this->assertStringNotContainsString('/callback', $policy);
	}

	/** Out-of-band is answered by a page on this server, so nothing is widened. */
	public function testOutOfBandWidensNothing(): void {
		$this->loggedIn();
		$this->knownClient();

		$policy = $this->controller->authorize('client-1', self::OOB, 'code', 'read')
			->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringNotContainsString('urn:ietf', $policy);
	}

	public function testAuthorizeCarriesTheClientsStateToTheConsentPage(): void {
		$this->loggedIn();
		$this->knownClient();

		$response = $this->controller->authorize('client-1', self::OOB, 'code', 'read', 'xyz789');

		$this->assertSame('xyz789', $response->getParams()['request']['state']);
	}

	/**
	 * A refused consent request is answered, not thrown: an exception out of
	 * this route reached the browser as a Nextcloud HTML error page — with a
	 * stack trace where debug is on — instead of an error the client can read.
	 */
	public function testAuthorizeRejectsNonCodeResponseTypes(): void {
		$this->loggedIn();
		$this->clientService->expects($this->never())->method('getFromClientId');

		$response = $this->controller->authorize('client-1', self::OOB, 'token');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid response type'], $response->getData());
	}

	public function testAuthorizeRejectsUnknownClients(): void {
		$this->loggedIn();
		$this->clientService->method('getFromClientId')->willThrowException(new ClientNotFoundException('unknown'));

		$response = $this->controller->authorize('nope', self::OOB, 'code');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unknown'], $response->getData());
	}

	/**
	 * RFC 6749 §3.1.2.3: the redirect_uri has to be, exactly, one the client
	 * registered -- compared before the consent page is prepared, so a forged
	 * link steers nothing. The answer is Mastodon's `invalid_request` shape,
	 * and never a redirect, since the one parameter that is wrong is where the
	 * redirect would go.
	 */
	public function testAuthorizeRejectsARedirectUriTheClientDidNotRegister(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['https://app.example/cb']);
		$this->clientService->expects($this->never())->method('confirmData');
		$this->initialState->expects($this->never())->method('provideInitialState');

		$response = $this->controller->authorize('client-1', 'https://evil.example/steal', 'code', 'read');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);
		$this->assertArrayHasKey('error_description', $response->getData());
	}

	/** A prefix, another path, or another port of a registered URI is not the registered URI. */
	public function testAuthorizeComparesTheRedirectUriExactly(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['https://app.example/cb']);

		foreach (['https://app.example/cb/../steal', 'https://app.example/cb?x=1', 'https://app.example:8443/cb', 'http://app.example/cb'] as $uri) {
			$response = $this->controller->authorize('client-1', $uri, 'code', 'read');

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $uri);
			$this->assertSame('invalid_request', $response->getData()['error'], $uri);
		}
	}

	// authorizing()

	public function testAuthorizingIssuesACodeBoundToTheUser(): void {
		$this->loggedIn('alice');
		$client = $this->knownClient();
		$this->clientService->expects($this->once())->method('confirmData')
			->with($client, $this->callback(fn (array $data): bool => $data['app_scopes'] === 'read write'));
		$this->clientService->expects($this->once())->method('authClient')
			->willReturnCallback(function (SocialClient $c): void {
				$this->assertSame(['read', 'write'], $c->getAuthScopes());
				$this->assertSame('alice', $c->getAuthAccount());
				$this->assertSame('alice', $c->getAuthUserId());
				$c->setAuthCode('auth-code-1');
			});

		$states = $this->recordInitialState();

		$response = $this->controller->authorizing('client-1', self::OOB, 'code', 'read write');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('auth-code-1', $states['code']);
	}

	/**
	 * The out-of-band answer is a page, not a JSON body.
	 *
	 * What reaches this route is a browser submitting the consent form, so a
	 * `DataResponse` rendered as `{"code":"..."}` on a blank white document --
	 * one click after a consent screen promising the code would be shown. It
	 * is a page with the code on it, and `state` has no part in it: there is
	 * no redirect here for a client to correlate.
	 */
	public function testTheOutOfBandAnswerIsAPageAndNotAJsonBody(): void {
		$this->loggedIn('alice');
		$this->knownClient();
		$this->clientService->method('authClient')
			->willReturnCallback(static fn (SocialClient $c) => $c->setAuthCode('auth-code-1'));
		$states = $this->recordInitialState();

		$response = $this->controller->authorizing('client-1', self::OOB, 'code', 'read', 'xyz789');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('oauth2', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		$this->assertSame('auth-code-1', $states['code']);
		$this->assertSame('Tusky', $states['appName']);
	}

	/**
	 * `state` is what a web client compares against what it stored, to know the
	 * redirect answers its own request. It used to be dropped, so a client
	 * following the spec rejected the redirect and one that did not was open to
	 * having somebody else's code injected.
	 */
	public function testAuthorizingRedirectsWithTheCodeAndTheState(): void {
		$this->loggedIn('alice');
		$this->knownClient('client-1', 'Tusky', ['https://elk.example/oauth/callback']);
		$this->clientService->method('authClient')
			->willReturnCallback(static fn (SocialClient $c) => $c->setAuthCode('auth-code-1'));

		$response = $this->controller->authorizing(
			'client-1', 'https://elk.example/oauth/callback', 'code', 'read', 'xyz789'
		);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(
			'https://elk.example/oauth/callback?code=auth-code-1&state=xyz789',
			$response->getRedirectURL()
		);
	}

	public function testARedirectUriThatAlreadyHasAQueryStringStaysValid(): void {
		$this->loggedIn('alice');
		$this->knownClient('client-1', 'Tusky', ['https://elk.example/cb?instance=cloud.example']);
		$this->clientService->method('authClient')
			->willReturnCallback(static fn (SocialClient $c) => $c->setAuthCode('c1'));

		$response = $this->controller->authorizing(
			'client-1', 'https://elk.example/cb?instance=cloud.example', 'code', 'read', 's1'
		);

		// concatenating '?code=' produced a URL with two question marks in it,
		// and the client could not read the code out of it
		$this->assertSame(
			'https://elk.example/cb?instance=cloud.example&code=c1&state=s1',
			$response->getRedirectURL()
		);
	}

	public function testAFragmentOnTheRedirectUriStaysAtTheEnd(): void {
		$this->loggedIn('alice');
		$this->knownClient('client-1', 'Tusky', ['https://elk.example/cb#/done']);
		$this->clientService->method('authClient')
			->willReturnCallback(static fn (SocialClient $c) => $c->setAuthCode('c1'));

		$response = $this->controller->authorizing(
			'client-1', 'https://elk.example/cb#/done', 'code', 'read'
		);

		$this->assertSame('https://elk.example/cb?code=c1#/done', $response->getRedirectURL());
	}

	public function testAuthorizingRejectsNonCodeResponseTypes(): void {
		$this->loggedIn();
		$this->clientService->expects($this->never())->method('authClient');

		$response = $this->controller->authorizing('client-1', self::OOB, 'token');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid response type'], $response->getData());
	}

	public function testAuthorizingRejectsUnknownClients(): void {
		$this->loggedIn();
		$this->clientService->method('getFromClientId')->willThrowException(new ClientNotFoundException('unknown client'));

		$response = $this->controller->authorizing('nope', self::OOB, 'code');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unknown client'], $response->getData());
	}

	/**
	 * The POST is what issues the code, so it has to apply the same rule as
	 * the GET: a consent form whose redirect_uri field was edited in the
	 * browser issues nothing.
	 */
	public function testAuthorizingRejectsARedirectUriTheClientDidNotRegister(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['https://app.example/cb']);
		$this->clientService->expects($this->never())->method('authClient');

		$response = $this->controller->authorizing('client-1', 'https://evil.example/steal', 'code', 'read');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);
	}

	/** RFC 6749 §4.1.3: the token exchange has to present the URI the code was sent to. */
	public function testAuthorizingRecordsTheRedirectUriOnTheAuthorization(): void {
		$this->loggedIn('alice');
		$this->knownClient('client-1', 'Tusky', [self::OOB, 'https://app.example/cb']);
		$bound = null;
		$this->clientService->method('authClient')
			->willReturnCallback(function (SocialClient $c) use (&$bound): void {
				$bound = $c->getAuthRedirectUri();
				$c->setAuthCode('c1');
			});

		$this->controller->authorizing('client-1', 'https://app.example/cb', 'code', 'read');

		$this->assertSame('https://app.example/cb', $bound);
	}

	public function testAuthorizingRejectsMismatchingClientData(): void {
		$this->loggedIn();
		$this->knownClient();
		$this->clientService->method('confirmData')->willThrowException(new ClientException('wrong scopes'));
		$this->clientService->expects($this->never())->method('authClient');

		$response = $this->controller->authorizing('client-1', self::OOB, 'code', 'admin');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'wrong scopes'], $response->getData());
	}

	/**
	 * A failure of this server is not the client's to read: its message names
	 * tables, hosts and files. It is logged, and the answer is `server_error`,
	 * as the token endpoint's is.
	 */
	public function testAnAuthorizationThatFailsHereSaysServerErrorAndNothingMore(): void {
		$this->loggedIn();
		$this->clientService->method('getFromClientId')
			->willThrowException(new \RuntimeException('SQLSTATE[HY000]: no such table oc_social_client'));

		foreach ([
			$this->controller->authorize('client-1', self::OOB, 'code', 'read'),
			$this->controller->authorizing('client-1', self::OOB, 'code', 'read'),
		] as $response) {
			$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
			$this->assertSame(['error' => 'server_error'], $response->getData());
		}
	}

	/** Somebody signed in to Nextcloud who has not set up their account here. */
	private function signedInWithoutAnAccount(string $uid = 'jane.doe'): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn('Jane Doe');
		$this->userSession->method('getUser')->willReturn($user);
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new ActorDoesNotExistException('Actor not found for user: ' . $uid));
		$this->accountService->method('generateHandleFromUserId')->with($uid)->willReturn('jane_doe');
		$this->configService->method('getSocialAddress')->willReturn('Social.Example');
	}

	/**
	 * Somebody whose first visit is from an app is asked for a username on
	 * the sign-in page itself, once the request has passed every check, and
	 * the page carries the app's request exactly as it came: reloading it
	 * after the account is made is what shows the consent.
	 */
	public function testAuthorizeOffersSetupToAReaderWithoutAnAccount(): void {
		$this->signedInWithoutAnAccount();
		$this->knownClient('client-1', 'Tusky', ['tusky://oauth']);
		$this->clientService->expects($this->once())->method('confirmData');
		$this->atprotoConfig->method('isEnabled')->willReturn(true);
		$states = $this->recordInitialState();

		$response = $this->controller->authorize(
			'client-1', 'tusky://oauth', 'code', 'read write', 'st-1', 'the-challenge', 'S256'
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('oauth2', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		$this->assertSame([
			'request' => [
				'clientId' => 'client-1',
				'redirectUri' => 'tusky://oauth',
				'responseType' => 'code',
				'scope' => 'read write',
				'state' => 'st-1',
				'codeChallenge' => 'the-challenge',
				'codeChallengeMethod' => 'S256',
			],
		], $response->getParams());
		$this->assertSame([
			'suggestedHandle' => 'jane_doe',
			'socialAddress' => 'Social.Example',
			'bluesky' => ['enabled' => true, 'host' => 'social.example'],
		], $states['setup']);
		$this->assertArrayNotHasKey('account', $states->getArrayCopy());
		$this->assertSame('Tusky', $states['appName']);
		$this->assertSame(['read', 'write'], $states['scopes']);
		$this->assertSame('tusky://oauth', $states['redirectUri']);
		$this->assertSame('tusky://oauth?error=access_denied&state=st-1', $states['denyUrl']);
		$this->assertStringContainsString('tusky:', $response->getContentSecurityPolicy()->buildPolicy());
	}

	/**
	 * A request that would be refused with an account is refused without
	 * one, and nothing about an account is offered on the way.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public static function invalidRequests(): array {
		return [
			'an unregistered redirect_uri' => ['client-1', 'https://evil.example/steal', 'code', '', 'invalid_request'],
			'another response type' => ['client-1', 'tusky://oauth', 'token', '', 'invalid response type'],
			'a PKCE method nobody implements' => ['client-1', 'tusky://oauth', 'code', 'plain', 'unsupported code_challenge_method'],
			'an unknown client' => ['nope', 'tusky://oauth', 'code', '', 'unknown'],
		];
	}

	#[DataProvider('invalidRequests')]
	public function testAnInvalidRequestIsRefusedWithoutOfferingSetup(
		string $clientId, string $redirectUri, string $responseType, string $method, string $error,
	): void {
		$this->signedInWithoutAnAccount();
		$client = new SocialClient();
		$client->setAppClientId('client-1')->setAppName('Tusky')->setAppRedirectUris(['tusky://oauth']);
		$this->clientService->method('getFromClientId')->willReturnCallback(
			static fn (string $id): SocialClient => ($id === 'client-1') ? $client : throw new ClientNotFoundException('unknown')
		);
		$this->initialState->expects($this->never())->method('provideInitialState');
		$this->accountService->expects($this->never())->method('generateHandleFromUserId');

		$response = $this->controller->authorize(
			$clientId, $redirectUri, $responseType, 'read', '', $method === '' ? '' : 'the-challenge', $method
		);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame($error, $response->getData()['error']);
	}

	public function testAReaderWithAnAccountIsShownTheConsentAndNoSetup(): void {
		$this->loggedIn();
		$this->knownClient();
		$states = $this->recordInitialState();
		$this->accountService->expects($this->never())->method('generateHandleFromUserId');

		$this->controller->authorize('client-1', self::OOB, 'code', 'read');

		$this->assertArrayHasKey('account', $states->getArrayCopy());
		$this->assertArrayNotHasKey('setup', $states->getArrayCopy());
	}

	/**
	 * No code without an account: the token it becomes would name nobody.
	 * The consent form answered without one is told where to set it up.
	 */
	public function testAuthorizingIssuesNoCodeWithoutAnAccount(): void {
		$this->signedInWithoutAnAccount();
		$this->knownClient('client-1', 'Tusky', [self::OOB]);
		$this->clientService->expects($this->never())->method('authClient');
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(static fn (string $route): string => 'https://cloud.example/' . $route);
		\OC::$server->register(IURLGenerator::class, $this->urlGenerator);

		$response = $this->controller->authorizing('client-1', self::OOB, 'code', 'read');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('account-not-set-up', $response->getData()['code']);
		$this->assertSame('https://cloud.example/social.Navigation.navigate', $response->getData()['setup_url']);
		// what Nextcloud calls them is not the app's to read
		$this->assertStringNotContainsString('jane.doe', json_encode($response->getData()) ?: '');
	}

	// the Bluesky app's sign-in

	private const REQUEST_URI = 'urn:ietf:params:oauth:request_uri:req-1';
	private const ATPROTO_CLIENT = 'https://app.example/client-metadata.json';

	/** A pushed request of a Bluesky app, waiting for an answer. */
	private function atprotoRequest(): array {
		$this->atprotoConfig->method('isEnabled')->willReturn(true);
		$request = new OAuthRequest(1, 'req-1', self::ATPROTO_CLIENT, [], [
			'redirect_uri' => 'https://app.example/callback',
			'scope' => 'atproto transition:generic',
			'state' => 'st-2',
		], 'jkt');
		$requested = ['request' => $request, 'metadata' => ['client_name' => 'Skeets'], 'scopes' => ['atproto', 'transition:generic']];
		$this->atprotoOAuth->method('requested')->with(self::ATPROTO_CLIENT, self::REQUEST_URI)->willReturn($requested);
		$this->atprotoOAuth->method('refusal')->willReturn('https://app.example/callback?error=access_denied');

		return $requested;
	}

	public function testABlueskyLoginSendsAVisitorToNextcloudLoginAndPreservesTheRequest(): void {
		$authorizeUrl = '/oauth/authorize?client_id=' . rawurlencode(self::ATPROTO_CLIENT)
			. '&request_uri=' . rawurlencode(self::REQUEST_URI);
		$this->request->method('getRequestUri')->willReturn($authorizeUrl);
		$this->urlGenerator->expects($this->once())
			->method('linkToRoute')
			->with('core.login.showLoginForm', ['redirect_url' => $authorizeUrl])
			->willReturn('/index.php/login?redirect_url=' . rawurlencode($authorizeUrl));

		$response = $this->controller->authorize(self::ATPROTO_CLIENT, '', '', '', '', '', '', self::REQUEST_URI);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/index.php/login?redirect_url=' . rawurlencode($authorizeUrl), $response->getRedirectURL());
		$this->assertNotEmpty((new \ReflectionMethod(OAuthController::class, 'authorize'))->getAttributes(PublicPage::class));
	}

	public function testABlueskyAppsSignInOffersSetupToAReaderWithoutAnAccount(): void {
		$this->signedInWithoutAnAccount();
		$this->atprotoRequest();
		$this->atprotoOAuth->expects($this->never())->method('pending');
		$states = $this->recordInitialState();

		$response = $this->controller->authorize(self::ATPROTO_CLIENT, '', '', 'read', '', '', '', self::REQUEST_URI);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('oauth2', $response->getTemplateName());
		$this->assertSame('jane_doe', $states['setup']['suggestedHandle']);
		$this->assertTrue($states['setup']['bluesky']['enabled']);
		$this->assertArrayNotHasKey('account', $states->getArrayCopy());
		$this->assertSame('atproto', $states['protocol']);
		$this->assertSame(['atproto', 'transition:generic'], $states['scopes']);
		$this->assertSame('https://app.example/callback?error=access_denied', $states['denyUrl']);
		$this->assertStringContainsString('https://app.example', $response->getContentSecurityPolicy()->buildPolicy());
	}

	/**
	 * Once the account exists, the consent names its Bluesky handle, which
	 * `pending()` makes for it when it has none yet.
	 */
	public function testABlueskyAppsSignInShowsTheConsentForAnAccount(): void {
		$this->loggedIn('alice');
		$requested = $this->atprotoRequest();
		$identity = new Identity(1, 'https://cloud.example/@alice', 'did:plc:alice', 'alice.cloud.example', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->atprotoOAuth->expects($this->once())->method('pending')
			->with(self::ATPROTO_CLIENT, self::REQUEST_URI, 'alice')
			->willReturn($requested + ['identity' => $identity]);
		$states = $this->recordInitialState();

		$this->controller->authorize(self::ATPROTO_CLIENT, '', '', 'read', '', '', '', self::REQUEST_URI);

		$this->assertSame('@alice.cloud.example', $states['account']['handle']);
		$this->assertArrayNotHasKey('setup', $states->getArrayCopy());
	}

	public function testABlueskyAppsSpentRequestIsRefusedWithoutOfferingSetup(): void {
		$this->signedInWithoutAnAccount();
		$this->atprotoConfig->method('isEnabled')->willReturn(true);
		$this->atprotoOAuth->method('requested')
			->willThrowException(OAuthException::invalidRequest('This sign-in request has expired or was answered already; start again from the app'));
		$this->initialState->expects($this->never())->method('provideInitialState');

		$response = $this->controller->authorize(self::ATPROTO_CLIENT, '', '', 'read', '', '', '', self::REQUEST_URI);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);
	}

	// token()

	public function testTokenExchangesAnAuthorizationCodeForABearerToken(): void {
		$client = $this->knownClient();
		$client->setAuthScopes(['read']);
		$client->setCreation(1700000000);
		$confirmations = [];
		$this->clientService->method('confirmData')->willReturnCallback(function (SocialClient $c, array $data) use (&$confirmations): void {
			$confirmations[] = $data;
		});
		$exchanged = [];
		$this->clientService->expects($this->once())->method('exchangeCode')
			->willReturnCallback(
				function (SocialClient $c, string $code) use (&$exchanged): SocialClient {
					$exchanged[] = $code;

					return $c->setToken('bearer-token');
				}
			);

		$response = $this->controller->token(self::OOB, 'authorization_code', 'client-1', 'secret', 'auth-code-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'access_token' => 'bearer-token',
			'token_type' => 'Bearer',
			'scope' => 'read',
			'created_at' => 1700000000,
		], $response->getData());
		// neither the code nor a scope is among what confirmData compares: the
		// code is what finds the authorization, and the scopes are the ones on
		// the authorization it finds
		$this->assertSame([
			['client_secret' => 'secret', 'redirect_uri' => self::OOB],
		], $confirmations);
		$this->assertSame(['auth-code-1'], $exchanged);
	}

	public function testTokenHandsTheRedirectUriToTheExchange(): void {
		$client = $this->knownClient('client-1', 'Tusky', ['https://app.example/cb']);
		$client->setAuthScopes(['read']);
		$this->clientService->method('confirmData');
		$presented = [];
		$this->clientService->method('exchangeCode')
			->willReturnCallback(
				function (SocialClient $c, string $code, string $verifier, string $redirectUri) use (&$presented): SocialClient {
					$presented[] = $redirectUri;

					return $c->setToken('bearer-token');
				}
			);

		$this->controller->token('https://app.example/cb', 'authorization_code', 'client-1', 'secret', 'auth-code-1');

		$this->assertSame(['https://app.example/cb'], $presented);
	}

	/**
	 * RFC 6749 §5.2: a code presented under another redirect_uri than the one
	 * it was issued for is `invalid_grant`, a 400 -- and a guess at somebody
	 * else's code, so it is throttled like a wrong secret.
	 */
	public function testTokenAnswersInvalidGrantWhenTheRedirectUriIsNotTheOneTheCodeWasIssuedFor(): void {
		$this->knownClient('client-1', 'Tusky', [self::OOB, 'https://app.example/cb']);
		$this->clientService->method('confirmData');
		$this->clientService->method('exchangeCode')
			->willThrowException(new InvalidGrantException('redirect_uri does not match the authorization request'));

		$response = $this->controller->token('https://app.example/cb', 'authorization_code', 'client-1', 'secret', 'auth-code-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_grant', $response->getData()['error']);
		$this->assertArrayHasKey('error_description', $response->getData());
		$this->assertTrue($response->isThrottled());
	}

	/**
	 * The scope the token really carries, not the one the token call asked
	 * for. Tusky omits `scope` on the token call, which defaults to `read`
	 * here — so a client that had been granted write was told it only had
	 * read, and hid its compose button while writes in fact worked.
	 */
	public function testTokenAnswersWithTheGrantedScopesNotTheRequestedOnes(): void {
		$client = $this->knownClient();
		$client->setAuthScopes(['read', 'write', 'follow']);
		$client->setCreation(1700000000);
		$this->clientService->method('confirmData');
		$this->clientService->method('exchangeCode')
			->willReturnCallback(fn (SocialClient $c): SocialClient => $c->setToken('bearer-token'));

		$response = $this->controller->token(self::OOB, 'authorization_code', 'client-1', 'secret', 'auth-code-1');

		$this->assertSame('read write follow', $response->getData()['scope']);
	}

	public function testDeviceGrantIssuesATokenForTheApprovingAccountOnce(): void {
		$client = $this->knownClient();
		$client->setAppScopes(['read', 'write']);
		$actor = (new Person())->setPreferredUsername('alice');
		$this->accountService->expects(self::once())->method('getActorFromUserId')->with('alice')->willReturn($actor);
		$this->clientService->expects(self::once())->method('authClient')->willReturnCallback(
			static fn (SocialClient $authorized): SocialClient => $authorized->setAuthCode('issued-code')
		);
		$this->clientService->expects(self::once())->method('exchangeCode')
			->with($this->identicalTo($client), 'issued-code', '', self::OOB)
			->willReturnCallback(static fn (SocialClient $authorized): SocialClient => $authorized->setToken('device-token'));
		$deviceAuthorization = $this->createMock(DeviceAuthorizationService::class);
		$deviceAuthorization->expects(self::once())->method('poll')->with('device-code', 'client-1')
			->willReturn(['user_id' => 'alice', 'scopes' => 'read']);
		$controller = $this->controllerWith($this->clientService, $deviceAuthorization);

		$response = $controller->token('', 'urn:ietf:params:oauth:grant-type:device_code', 'client-1', '', '', '', 'device-code');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('device-token', $response->getData()['access_token']);
		self::assertSame('read', $response->getData()['scope']);
	}

	public function testDeviceGrantReturnsPendingErrorsWithoutIssuingAToken(): void {
		$this->knownClient();
		$deviceAuthorization = $this->createMock(DeviceAuthorizationService::class);
		$deviceAuthorization->expects(self::once())->method('poll')->willReturn(['error' => 'authorization_pending']);
		$this->clientService->expects(self::never())->method('authClient');
		$controller = $this->controllerWith($this->clientService, $deviceAuthorization);

		$response = $controller->token('', 'urn:ietf:params:oauth:grant-type:device_code', 'client-1', '', '', '', 'device-code');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'authorization_pending'], $response->getData());
	}

	public function testDeviceGrantRequiresARegisteredClientSecretWhenOneExists(): void {
		$client = $this->knownClient();
		$client->setAppClientSecret('stored-secret-hash');
		$this->clientService->expects(self::once())->method('confirmData')
			->with($this->identicalTo($client), ['client_secret' => ''])
			->willThrowException(new ClientException('wrong client_secret'));
		$deviceAuthorization = $this->createMock(DeviceAuthorizationService::class);
		$deviceAuthorization->expects(self::never())->method('poll');
		$controller = $this->controllerWith($this->clientService, $deviceAuthorization);

		$response = $controller->token('', 'urn:ietf:params:oauth:grant-type:device_code', 'client-1', '', '', '', 'device-code');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertTrue($response->isThrottled());
	}

	public function testDeviceAuthorizationRequiresARegisteredClientSecretWhenOneExists(): void {
		$client = $this->knownClient();
		$client->setAppClientSecret('stored-secret-hash');
		$this->clientService->expects(self::once())->method('confirmData')
			->with($this->identicalTo($client), ['app_scopes' => 'read', 'client_secret' => ''])
			->willThrowException(new ClientException('wrong client_secret'));
		$deviceAuthorization = $this->createMock(DeviceAuthorizationService::class);
		$deviceAuthorization->expects(self::never())->method('create');
		$controller = $this->controllerWith($this->clientService, $deviceAuthorization);

		$response = $controller->deviceAuthorization('client-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertTrue($response->isThrottled());
	}

	public function testTokenThrottlesAWrongClientSecret(): void {
		$this->knownClient();
		$this->clientService->method('confirmData')
			->willThrowException(new ClientException('wrong client_secret'));

		$response = $this->controller->token(self::OOB, 'authorization_code', 'client-1', 'guess', 'c');

		// A credential guess is throttled so /oauth/token cannot be brute-forced.
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testTokenThrottlesAnUnknownClient(): void {
		$this->clientService->method('getFromClientId')
			->willThrowException(new ClientNotFoundException('unknown'));

		$response = $this->controller->token(self::OOB, 'authorization_code', 'nope', 'secret', 'c');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testTokenRequiresACodeForTheAuthorizationCodeGrant(): void {
		$this->knownClient();
		$this->clientService->expects($this->never())->method('exchangeCode');

		$response = $this->controller->token(self::OOB, 'authorization_code', 'client-1', 'secret');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid_request', 'error_description' => 'missing code'], $response->getData());
	}

	public function testTokenRejectsUnknownGrantTypes(): void {
		$this->knownClient();

		$response = $this->controller->token(self::OOB, 'password', 'client-1', 'secret');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('unsupported_grant_type', $response->getData()['error']);
		$this->assertArrayHasKey('error_description', $response->getData());
	}

	/**
	 * RFC 6749 §4.4: a token for the app itself. What comes back is the
	 * app's own token, read `read` and bound to no account -- never a token
	 * some account's authorization-code grant left on the app.
	 */
	public function testTokenClientCredentialsGrantIssuesAnAppToken(): void {
		$client = $this->knownClient();
		$client->setCreation(1700000000);
		$this->clientService->expects($this->never())->method('exchangeCode');
		$this->clientService->expects($this->once())->method('issueAppToken')
			->with($this->identicalTo($client))
			->willReturnCallback(function (SocialClient $c): SocialClient {
				$issued = new SocialClient();
				$issued->setToken('app-token')->setAuthScopes(['read'])->setAuthUserId('');
				$issued->setCreation(1700000000);

				return $issued;
			});

		$response = $this->controller->token(self::OOB, 'client_credentials', 'client-1', 'secret');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'access_token' => 'app-token',
			'token_type' => 'Bearer',
			'scope' => 'read',
			'created_at' => 1700000000,
		], $response->getData());
	}

	public function testTokenRejectsUnknownClientIds(): void {
		$this->clientService->method('getFromClientId')->willThrowException(new ClientNotFoundException());

		$response = $this->controller->token(self::OOB, 'authorization_code', 'nope', 'secret', 'c');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'invalid_client', 'error_description' => 'unknown client_id'], $response->getData());
	}

	/**
	 * A failure that is not the caller's -- the database, a malformed row --
	 * is answered with a fixed body and goes to the log; what went wrong is
	 * not for an anonymous caller, and it is not a credential guess to throttle.
	 */
	public function testTokenAnswersAServerFailureWithAFixedBody(): void {
		$this->knownClient();
		$this->clientService->method('exchangeCode')
			->willThrowException(new \RuntimeException('SQLSTATE[HY000]: social_client_auth is gone'));

		$response = $this->controller->token(self::OOB, 'authorization_code', 'client-1', 'secret', 'c');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'server_error'], $response->getData());
		$this->assertFalse($response->isThrottled());
	}

	public function testTokenRejectsAWrongClientSecret(): void {
		$this->knownClient();
		$this->clientService->method('confirmData')->willThrowException(new ClientException('wrong client_secret'));
		$this->clientService->expects($this->never())->method('exchangeCode');

		$response = $this->controller->token(self::OOB, 'authorization_code', 'client-1', 'wrong', 'c');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'wrong client_secret'], $response->getData());
	}

	/**
	 * The regression this whole endpoint turned on.
	 *
	 * `token()` used to hand `confirmData()` the scope of the *token* call and
	 * have it compared against `social_client.auth_scopes` — a column no writer
	 * has touched since authorizations moved to `social_client_auth`. For every
	 * client registered after that migration the column is empty, so even the
	 * default `read` was refused as an invalid scope and the exchange answered
	 * 401 with a brute-force strike. Nothing caught it because every test mocked
	 * `confirmData` away; this one runs the real thing against a client in the
	 * shape `ClientRequest::saveApp()` writes.
	 */
	public function testTokenIssuesATokenToAClientRegisteredAfterTheAuthorizationSplit(): void {
		$hasher = new SecretHasher();
		$clientRequest = $this->createMock(ClientRequest::class);
		$clientAuthRequest = $this->createStub(ClientAuthRequest::class);
		$clientService = new ClientService(
			$clientRequest,
			$hasher,
			$clientAuthRequest,
			$this->createStub(\OCA\Social\Service\ConfigService::class),
			$this->createStub(\OCA\Social\Service\PushSubscriptionService::class),
			$this->createStub(\OCA\Social\Db\DeviceAuthorizationRequest::class),
			new NullLogger(),
		);

		// exactly the columns saveApp() writes, read back the way
		// getFromClientId() reads them: no auth_scopes, because an app row no
		// longer carries an authorization
		$appRow = (new SocialClient())->importFromDatabase([
			'id' => 3,
			'app_name' => 'Tusky',
			'app_website' => '',
			'app_redirect_uris' => json_encode([self::OOB]),
			'app_client_id' => 'client-1',
			'app_client_secret' => $hasher->hash('s3cret'),
			'app_scopes' => json_encode(['read', 'write']),
			'creation' => '2026-09-18 10:00:00',
			'last_update' => '2026-09-18 10:00:00',
		]);
		$this->assertSame([], $appRow->getAuthScopes(), 'a registration carries no granted scopes');
		$clientRequest->method('getFromClientId')->with('client-1')->willReturn($appRow);

		$granted = (new SocialClient())->setId(3)->setAuthUserId('alice')
			->setAuthScopes(['read', 'write'])->setLastUpdate(time())
			->setAuthRedirectUri(self::OOB);
		$clientAuthRequest->method('getByCode')->willReturn($granted);
		$clientAuthRequest->method('exchange')
			->willReturnCallback(fn (): SocialClient => $granted->setToken('bearer-token'));

		$response = $this->controllerWith($clientService)
			->token(self::OOB, 'authorization_code', 'client-1', 's3cret', 'auth-code-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('bearer-token', $response->getData()['access_token']);
		$this->assertSame('read write', $response->getData()['scope']);
	}

	/**
	 * `client_secret_basic`, which the discovery document has always claimed
	 * and the endpoint never read.
	 */
	public function testTokenAcceptsClientCredentialsInABasicAuthorizationHeader(): void {
		$this->request->method('getHeader')->with('Authorization')
			->willReturn('Basic ' . base64_encode('client-1:s3c%2Bret'));
		$client = $this->knownClient();
		$client->setAuthScopes(['read']);
		$confirmations = [];
		$this->clientService->method('confirmData')
			->willReturnCallback(function (SocialClient $c, array $data) use (&$confirmations): void {
				$confirmations[] = $data;
			});
		$this->clientService->method('exchangeCode')
			->willReturnCallback(fn (SocialClient $c): SocialClient => $c->setToken('bearer-token'));

		$response = $this->controller->token(self::OOB, 'authorization_code', '', '', 'auth-code-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// the halves are form-urlencoded inside the header (RFC 6749 §2.3.1)
		$this->assertSame('s3c+ret', $confirmations[0]['client_secret']);
	}

	public function testTokenHandsTheCodeVerifierToTheExchange(): void {
		$client = $this->knownClient();
		$client->setAuthScopes(['read']);
		$this->clientService->method('confirmData');
		$verifiers = [];
		$this->clientService->method('exchangeCode')
			->willReturnCallback(
				function (SocialClient $c, string $code, string $verifier) use (&$verifiers): SocialClient {
					$verifiers[] = $verifier;

					return $c->setToken('bearer-token');
				}
			);

		$this->controller->token(self::OOB, 'authorization_code', 'client-1', 'secret', 'auth-code-1', 'the-verifier');

		$this->assertSame(['the-verifier'], $verifiers);
	}

	// PKCE (RFC 7636)

	public function testAuthorizeCarriesAPkceChallengeToTheConsentForm(): void {
		$this->loggedIn();
		$this->knownClient();

		$response = $this->controller->authorize(
			'client-1', self::OOB, 'code', 'read', 'st', 'the-challenge', 'S256'
		);

		$this->assertSame('the-challenge', $response->getParams()['request']['codeChallenge']);
		$this->assertSame('S256', $response->getParams()['request']['codeChallengeMethod']);
	}

	/**
	 * An omitted method means `plain` in RFC 7636, which this server neither
	 * implements nor advertises. Recording the challenge and never checking it
	 * would be a code that is bound to nothing while the client believes it is.
	 */
	public function testAuthorizeRefusesAChallengeWithAMethodItDoesNotImplement(): void {
		$this->loggedIn();

		foreach (['', 'plain', 'S512'] as $method) {
			$response = $this->controller->authorize(
				'client-1', self::OOB, 'code', 'read', '', 'the-challenge', $method
			);

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'unsupported code_challenge_method'], $response->getData());
		}
	}

	public function testAuthorizingBindsTheAuthorizationToTheChallenge(): void {
		$this->loggedIn('alice');
		$this->knownClient();
		$bound = null;
		$this->clientService->method('authClient')
			->willReturnCallback(function (SocialClient $c) use (&$bound): void {
				$bound = [$c->getAuthCodeChallenge(), $c->getAuthCodeChallengeMethod()];
				$c->setAuthCode('c1');
			});

		$this->controller->authorizing('client-1', self::OOB, 'code', 'read', '', 'the-challenge', 'S256');

		$this->assertSame(['the-challenge', 'S256'], $bound);
	}

	// what the consent page is told

	public function testDevicePagesOmitAccountStateWhenNoSocialAccountIsAvailable(): void {
		$states = $this->recordInitialState();

		$this->controller->device('ABCD-EFGH');

		self::assertArrayNotHasKey('account', $states);
		self::assertArrayNotHasKey('deviceAccount', $states);
		self::assertNotContains(null, iterator_to_array($states));

		$states = $this->recordInitialState();
		$this->controller->deviceDecision('ABCD-EFGH', 'allow');

		self::assertArrayNotHasKey('account', $states);
		self::assertArrayNotHasKey('deviceAccount', $states);
		self::assertNotContains(null, iterator_to_array($states));
	}

	public function testAuthorizeShowsTheScopesAndWhereTheCodeIsGoing(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['https://app.example/cb']);
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', 'https://app.example/cb', 'code', 'read write:statuses', 'xyz');

		$this->assertSame(['read', 'write:statuses'], $states['scopes']);
		$this->assertSame('https://app.example/cb', $states['redirectUri']);
	}

	/**
	 * RFC 6749 §4.1.2.1: refusing consent is an answer the client is owed.
	 * The Deny button used to be a link to the app, so a client that had sent
	 * somebody to the consent page waited for a redirect that never came.
	 */
	public function testDenyingConsentSendsAccessDeniedBackToTheClient(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['https://app.example/cb?v=2']);
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', 'https://app.example/cb?v=2', 'code', 'read', 'xyz789');

		$this->assertSame(
			'https://app.example/cb?v=2&error=access_denied&state=xyz789', $states['denyUrl']
		);
	}

	/** A native client's own scheme is a registered destination like any other. */
	public function testDenyingConsentFollowsACustomSchemeBackToTheClient(): void {
		$this->loggedIn();
		$this->knownClient('client-1', 'Tusky', ['tusky://oauth']);
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', 'tusky://oauth', 'code', 'read', 's1');

		$this->assertSame('tusky://oauth?error=access_denied&state=s1', $states['denyUrl']);
	}

	/**
	 * A redirect URI registered before the scheme was checked may be a
	 * `javascript:` one. The Deny button is a link the person clicks, so it
	 * is not handed that; the refusal lands on the app instead.
	 */
	public function testDenyingConsentNeverLinksASchemeABrowserWouldRun(): void {
		$this->loggedIn();
		$this->urlGenerator->method('linkToRoute')->with('social.Navigation.navigate')
			->willReturn('/apps/social/');

		foreach (['javascript://%0aalert(1)', 'data://text/html,x', 'vbscript://x', 'file:///tmp/x'] as $uri) {
			$this->setUp();
			$this->loggedIn();
			$this->urlGenerator->method('linkToRoute')->willReturn('/apps/social/');
			$this->knownClient('client-1', 'Tusky', [$uri]);
			$states = $this->recordInitialState();

			$this->controller->authorize('client-1', $uri, 'code', 'read', 'xyz');

			$this->assertSame('/apps/social/', $states['denyUrl'], $uri);
		}
	}

	/** The website is a link to click, so only an address a browser follows is offered. */
	public function testTheConsentPageDropsAWebsiteABrowserWouldRun(): void {
		$this->loggedIn();
		$this->knownClient()->setAppWebsite('javascript:alert(1)');
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', self::OOB, 'code', 'read');

		$this->assertSame('', $states['appWebsite']);
	}

	public function testDenyingAnOutOfBandRequestFallsBackToTheApp(): void {
		$this->loggedIn();
		$this->knownClient();
		$this->urlGenerator->method('linkToRoute')->with('social.Navigation.navigate')
			->willReturn('/apps/social/');
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', self::OOB, 'code', 'read', 'xyz789');

		$this->assertSame('/apps/social/', $states['denyUrl']);
	}

	// the discovery document

	/**
	 * A Mastodon 4.3 client acts on what this says. Everything named here has
	 * to be implemented: `client_credentials` was once advertised while the
	 * endpoint answered it `unsupported_grant_type`.
	 */
	public function testTheMetadataDocumentOnlyAdvertisesWhatIsImplemented(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://nc.example/apps/social/');

		$data = $this->controller->oauthMetadata()->getData();

		$this->assertSame(['authorization_code', 'client_credentials', 'urn:ietf:params:oauth:grant-type:device_code'], $data['grant_types_supported']);
		$this->assertSame(['S256'], $data['code_challenge_methods_supported']);
		$this->assertSame(
			['client_secret_post', 'client_secret_basic'], $data['token_endpoint_auth_methods_supported']
		);
		$this->assertSame('https://nc.example/apps/social/oauth/token', $data['token_endpoint']);
		$this->assertSame('https://nc.example/apps/social/oauth/device_authorization', $data['device_authorization_endpoint']);
	}

	/**
	 * RFC 8414 §3.3: the issuer has to be the URL the document was fetched
	 * from, minus the well-known suffix. A client asking the domain root and
	 * told the issuer is the app path rejects the document -- before it opens
	 * a browser, so nothing on this server sees it fail, and the report is
	 * "Mastodon clients cannot sign in" with every endpoint answering.
	 */
	public function testTheDocumentAdvertisesTheRootWhereTheRewriteIsInPlace(): void {
		$this->checkService->method('clientApiRootIsKnownGood')->willReturn(true);
		$this->configService->method('getSocialUrl')->willReturn('https://nc.example/apps/social/');
		$this->configService->method('getCloudUrl')->willReturn('https://nc.example');

		$data = $this->controller->oauthMetadata()->getData();

		$this->assertSame('https://nc.example/', $data['issuer']);
		$this->assertSame('https://nc.example/oauth/authorize', $data['authorization_endpoint']);
		$this->assertSame('https://nc.example/oauth/token', $data['token_endpoint']);
		$this->assertSame('https://nc.example/api/v1/apps', $data['app_registration_endpoint']);
	}

	public function testTheRootDocumentIncludesAtprotoDiscoveryWhenBlueskyIsReady(): void {
		$this->atprotoConfig->method('isEnabled')->willReturn(true);
		$this->atprotoOAuth->method('metadata')->willReturn([
			'issuer' => 'https://nc.example',
			'pushed_authorization_request_endpoint' => 'https://nc.example/oauth/par',
			'require_pushed_authorization_requests' => true,
			'authorization_response_iss_parameter_supported' => true,
			'dpop_signing_alg_values_supported' => ['ES256'],
			'client_id_metadata_document_supported' => true,
			'token_endpoint_auth_signing_alg_values_supported' => ['ES256'],
			'response_modes_supported' => ['query', 'fragment'],
			'protected_resources' => ['https://nc.example'],
		]);
		$this->checkService->method('clientApiRootIsKnownGood')->willReturn(true);
		$this->configService->method('getSocialUrl')->willReturn('https://nc.example/apps/social/');
		$this->configService->method('getCloudUrl')->willReturn('https://nc.example');

		$data = $this->controller->oauthMetadata()->getData();

		$this->assertSame('https://nc.example', $data['issuer']);
		$this->assertSame('https://nc.example/oauth/par', $data['pushed_authorization_request_endpoint']);
		$this->assertTrue($data['require_pushed_authorization_requests']);
		$this->assertSame(['ES256'], $data['dpop_signing_alg_values_supported']);
		$this->assertContains('atproto', $data['scopes_supported']);
		$this->assertSame('https://nc.example/oauth/token', $data['token_endpoint']);
	}

	/**
	 * Without the rewrite the root answers nothing at all, so the app's own
	 * addresses are both the honest answer and the only reachable one.
	 */
	public function testTheDocumentAdvertisesTheAppPathWithoutTheRewrite(): void {
		$this->checkService->method('clientApiRootIsKnownGood')->willReturn(false);
		$this->configService->method('getSocialUrl')->willReturn('https://nc.example/apps/social/');

		$data = $this->controller->oauthMetadata()->getData();

		$this->assertSame('https://nc.example/apps/social/', $data['issuer']);
		$this->assertSame('https://nc.example/apps/social/oauth/token', $data['token_endpoint']);
	}

	// revoke()

	public function testRevokeClearsTheToken(): void {
		$client = $this->knownClient();
		$this->clientService->expects($this->once())
			->method('revokeToken')
			->with($this->identicalTo($client), 'tok');

		$response = $this->controller->revoke('tok', 'client-1', 's3cret');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testRevokeOfAnUnknownTokenIsStillASuccess(): void {
		$this->knownClient();
		$this->clientService->method('revokeToken')->willThrowException(new ClientNotFoundException());

		$this->assertSame(Http::STATUS_OK, $this->controller->revoke('gone', 'client-1', 's3cret')->getStatus());
	}

	public function testRevokeThrottlesWrongClientCredentials(): void {
		$this->knownClient();
		$this->clientService->method('confirmData')->willThrowException(new ClientException('wrong client_secret'));
		$this->clientService->expects($this->never())->method('revokeToken');

		$response = $this->controller->revoke('tok', 'client-1', 'wrong');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	/**
	 * Every schema this controller says it serves has a method that serves it,
	 * and a route that reaches it.
	 *
	 * 2.1 was written, tested and unreachable for its whole life: the document
	 * was correct, the test called the method directly, and nothing registered
	 * a URL for it or pointed at one. What is checked here is therefore not
	 * the document -- that is covered above -- but that each version can be
	 * fetched at all, which is the part that was missing.
	 */
	public function testEverySchemaItAdvertisesHasARoute(): void {
		$reflection = new \ReflectionClass(OAuthController::class);

		$routed = [];
		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			foreach ($method->getAttributes(FrontpageRoute::class) as $attribute) {
				$routed[] = $attribute->newInstance()->getUrl();
			}
		}

		foreach (OAuthController::NODEINFO_SCHEMAS as $schema) {
			$this->assertContains(
				'/.well-known/nodeinfo/' . $schema,
				$routed,
				'NODEINFO_SCHEMAS names ' . $schema . ', so a route has to serve it'
			);
		}
	}

	/** 2.1 is 2.0 plus two fields, and that is the whole of the difference. */
	public function testTheTwoSchemasDifferOnlyInWhereTheSoftwareLives(): void {
		$two = $this->controller->nodeinfo2()->getData();
		$twoOne = $this->controller->nodeinfo21()->getData();

		$this->assertSame('2.0', $two['version']);
		$this->assertSame('2.1', $twoOne['version']);
		$this->assertSame('https://github.com/alohasocial/social', $twoOne['software']['repository']);
		$this->assertSame('https://github.com/alohasocial/social', $twoOne['software']['homepage']);
		$this->assertArrayNotHasKey('repository', $two['software']);

		unset($two['version'], $twoOne['version'], $two['software'], $twoOne['software']);
		$this->assertSame($two, $twoOne);
	}

	/**
	 * Registration is public and every call is a row, so it is limited for a
	 * caller without a session — which is every client registering itself —
	 * as well as for one with.
	 */
	public function testRegistrationIsRateLimitedForEveryCaller(): void {
		$method = new \ReflectionMethod(OAuthController::class, 'apps');

		$this->assertNotSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\AnonRateLimit::class));
		$this->assertNotSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class));
	}
}
