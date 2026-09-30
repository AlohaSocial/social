<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use ArrayObject;
use OCA\Social\Controller\OAuthController;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Instance;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class OAuthControllerTest extends TestCase {
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

	protected function setUp(): void {
		$this->userSession = $this->createStub(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->instanceService = $this->createStub(InstanceService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->clientService = $this->createMock(ClientService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->checkService = $this->createStub(CheckService::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = $this->controllerWith($this->clientService);
	}

	/** The controller under test, on one ClientService — a mock or the real one. */
	private function controllerWith(ClientService $clientService): OAuthController {
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
			$this->initialState
		);
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

	private function knownClient(string $clientId = 'client-1', string $appName = 'Tusky'): SocialClient {
		$client = new SocialClient();
		$client->setAppClientId($clientId)->setAppName($appName);
		$this->clientService->method('getFromClientId')->with($clientId)->willReturn($client);

		return $client;
	}

	// nodeinfo2()

	public function testNodeinfo2DescribesTheLocalInstance(): void {
		$instance = new Instance();
		$instance->setTitle('My Social')->setVersion('0.10.1')->setUsage(['users' => ['total' => 3]])->setRegistrations(true);
		$this->instanceService->method('getLocal')->willReturn($instance);
		$response = $this->controller->nodeinfo2();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'version' => '2.0',
			// the schema wants ^[a-z0-9-]+$ here, and crawlers group instances
			// by it; the human title goes to metadata.nodeName
			'software' => ['name' => 'nextcloud-social', 'version' => '0.10.1'],
			'protocols' => ['activitypub'],
			'services' => ['inbound' => [], 'outbound' => []],
			// the schema names every top-level key and allows no other, so the
			// old `rootUrl` is gone
			'usage' => ['users' => ['total' => 3]],
			'openRegistrations' => true,
			'metadata' => ['nodeName' => 'My Social', 'nodeDescription' => ''],
		], $response->getData());
	}

	public function testNodeinfo21AddsTheRepositoryAndHomepage(): void {
		$instance = new Instance();
		$instance->setTitle('My Social')->setShortDescription('A cosy corner')->setVersion('0.10.1');
		$this->instanceService->method('getLocal')->willReturn($instance);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/social/');

		$data = $this->controller->nodeinfo21()->getData();

		$this->assertSame('2.1', $data['version']);
		$this->assertSame([
			'name' => 'nextcloud-social',
			'version' => '0.10.1',
			'repository' => 'https://github.com/alohasocial/social',
			'homepage' => 'https://github.com/alohasocial/social',
		], $data['software']);
		$this->assertSame(['nodeName' => 'My Social', 'nodeDescription' => 'A cosy corner'], $data['metadata']);
		$this->assertSame(['inbound' => [], 'outbound' => []], $data['services']);
	}

	public function testNodeinfo2FallsBackToAppDefaultsWithoutAnInstance(): void {
		$this->instanceService->method('getLocal')->willThrowException(new InstanceDoesNotExistException());
		$this->configService->method('getAppValue')->with('installed_version')->willReturn('0.10.1');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/social/');

		$data = $this->controller->nodeinfo2()->getData();

		$this->assertSame(['name' => 'nextcloud-social', 'version' => '0.10.1'], $data['software']);
		$this->assertSame('Nextcloud Social', $data['metadata']['nodeName']);
		$this->assertSame([], $data['usage']);
		$this->assertFalse($data['openRegistrations']);
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
			'scopes' => 'read write',
			'client_id' => 'cid',
			'client_secret' => 'csecret',
			// Mastodon's Application entity always carries both, and a client
			// that decodes this into a typed struct fails without them
			'redirect_uri' => 'https://tusky.app/callback',
			'vapid_key' => '',
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

	public function testAppsDefaultsToReadScope(): void {
		$this->clientService->method('createApp');

		$this->assertSame('read', $this->controller->apps('App', 'https://a/cb')->getData()['scopes']);
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
		$this->knownClient();

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
		$this->knownClient();

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
		$this->knownClient();

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
		$this->knownClient();

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

	public function testAuthorizeRejectsARedirectUriTheClientDidNotRegister(): void {
		// The consent GET now confirms the redirect_uri against the client's registered
		// URIs before rendering, so a code can never be steered to a forged link. A
		// rejected redirect_uri is refused before the consent page is prepared.
		$this->loggedIn();
		$client = $this->knownClient();
		$this->clientService->expects($this->once())->method('confirmData')
			->with($client, $this->callback(fn (array $data): bool => $data['redirect_uri'] === 'https://evil.example/steal'))
			->willThrowException(new ClientException('unknown redirect_uri'));
		$this->initialState->expects($this->never())->method('provideInitialState');

		$response = $this->controller->authorize('client-1', 'https://evil.example/steal', 'code', 'read');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unknown redirect_uri'], $response->getData());
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
		$this->knownClient();
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
		$this->knownClient();
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
		$this->knownClient();
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

	public function testAuthorizingRejectsMismatchingClientData(): void {
		$this->loggedIn();
		$this->knownClient();
		$this->clientService->method('confirmData')->willThrowException(new ClientException('wrong scopes'));
		$this->clientService->expects($this->never())->method('authClient');

		$response = $this->controller->authorizing('client-1', self::OOB, 'code', 'admin');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'wrong scopes'], $response->getData());
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
		$this->assertSame(['error' => 'missing code'], $response->getData());
	}

	public function testTokenRejectsUnknownGrantTypes(): void {
		$this->knownClient();

		$response = $this->controller->token(self::OOB, 'password', 'client-1', 'secret');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid value for grant_type'], $response->getData());
	}

	public function testTokenClientCredentialsGrantIsRefused(): void {
		$this->knownClient();
		$this->clientService->expects($this->never())->method('exchangeCode');

		$response = $this->controller->token(self::OOB, 'client_credentials', 'client-1', 'secret');

		// Falling through would have returned whatever token the client row held from
		// some user's authorization-code grant.
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unsupported_grant_type'], $response->getData());
	}

	public function testTokenRejectsUnknownClientIds(): void {
		$this->clientService->method('getFromClientId')->willThrowException(new ClientNotFoundException());

		$response = $this->controller->token(self::OOB, 'authorization_code', 'nope', 'secret', 'c');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unknown client_id'], $response->getData());
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
			$clientRequest, $hasher, $clientAuthRequest
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
			->setAuthScopes(['read', 'write'])->setLastUpdate(time());
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

	public function testAuthorizeShowsTheScopesAndWhereTheCodeIsGoing(): void {
		$this->loggedIn();
		$this->knownClient();
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
		$this->knownClient();
		$states = $this->recordInitialState();

		$this->controller->authorize('client-1', 'https://app.example/cb?v=2', 'code', 'read', 'xyz789');

		$this->assertSame(
			'https://app.example/cb?v=2&error=access_denied&state=xyz789', $states['denyUrl']
		);
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
	 * to be implemented: `client_credentials` was advertised and answered
	 * `unsupported_grant_type`.
	 */
	public function testTheMetadataDocumentOnlyAdvertisesWhatIsImplemented(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://nc.example/apps/social/');

		$data = $this->controller->oauthMetadata()->getData();

		$this->assertSame(['authorization_code'], $data['grant_types_supported']);
		$this->assertSame(['S256'], $data['code_challenge_methods_supported']);
		$this->assertSame(
			['client_secret_post', 'client_secret_basic'], $data['token_endpoint_auth_methods_supported']
		);
		$this->assertSame('https://nc.example/apps/social/oauth/token', $data['token_endpoint']);
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

	// the apps this account has signed in to

	public function testTheAuthorizedAppsAreTheOnesThisAccountGranted(): void {
		$this->loggedIn();
		$tusky = new SocialClient();
		$tusky->setAuthId(4)->setAppName('Tusky')->setAppWebsite('https://tusky.app')
			->setAuthScopes(['read', 'write'])->setAuthCreation(1757000000)
			->setLastUpdate(1757800000)->setToken('hashed');
		$this->clientService->method('getAuthorizationsOf')->with('alice')->willReturn([$tusky]);

		$response = $this->controller->authorizedApps();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([[
			'id' => 4,
			'name' => 'Tusky',
			'website' => 'https://tusky.app',
			'scopes' => ['read', 'write'],
			'created_at' => 1757000000,
			'last_used_at' => 1757800000,
			'signed_in' => true,
		]], $response->getData());
	}

	/**
	 * The token is stored hashed and nothing on this page wants it; a list of
	 * tokens is the one thing this route must never be.
	 */
	public function testTheAuthorizedAppsNeverCarryTheToken(): void {
		$this->loggedIn();
		$client = new SocialClient();
		$client->setAuthId(4)->setAppName('Tusky')->setToken('hashed');
		$this->clientService->method('getAuthorizationsOf')->willReturn([$client]);

		$row = $this->controller->authorizedApps()->getData()[0];

		$this->assertArrayNotHasKey('token', $row);
		$this->assertStringNotContainsString('hashed', json_encode($row));
	}

	/** A code that was never exchanged is not a sign-in, and is not shown as one. */
	public function testAnAuthorizationWhoseCodeWasNeverExchangedIsMarked(): void {
		$this->loggedIn();
		$client = new SocialClient();
		$client->setAuthId(9)->setAppName('Something')->setToken('');
		$this->clientService->method('getAuthorizationsOf')->willReturn([$client]);

		$this->assertFalse($this->controller->authorizedApps()->getData()[0]['signed_in']);
	}

	public function testTheAuthorizedAppsNeedAnAccount(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->authorizedApps()->getStatus());
	}

	public function testSigningAnAppOutTakesTheAuthorizationBack(): void {
		$this->loggedIn();
		$this->clientService->expects($this->once())->method('revokeAuthorizationOf')
			->with('alice', 4)->willReturn(true);

		$response = $this->controller->revokeAuthorizedApp(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * The id is a number a caller can count upwards: an authorization that is
	 * not this account's own has to be unreachable, and the answer has to be
	 * the one an id that does not exist gets, or the pair of answers would say
	 * which ids exist.
	 */
	public function testSigningOutSomebodyElsesAppIsNotFound(): void {
		$this->loggedIn();
		$this->clientService->method('revokeAuthorizationOf')->willReturn(false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->revokeAuthorizedApp(4)->getStatus());
	}

	public function testSigningAnAppOutNeedsAnAccount(): void {
		$this->clientService->expects($this->never())->method('revokeAuthorizationOf');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->revokeAuthorizedApp(4)->getStatus());
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
}
