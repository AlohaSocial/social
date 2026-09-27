<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\WellKnown;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\WellKnown\JrdResponse;
use OCA\Social\WellKnown\WebfingerHandler;
use OCA\Social\WellKnown\XrdResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class WebfingerHandlerTest extends TestCase {
	private const ACTOR_URL = 'https://cloud.example/index.php/apps/social/@alice';
	private const INSTANCE_ACTOR_URL = 'https://cloud.example/index.php/apps/social/actor';

	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var FediverseService&Stub */
	private $fediverseService;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var InstanceActorService&Stub */
	private $instanceActorService;
	/** @var IRequest&MockObject */
	private $request;
	/** @var IRequestContext&Stub */
	private $context;
	private WebfingerHandler $handler;

	protected function setUp(): void {
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->fediverseService = $this->createStub(FediverseService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->instanceActorService = $this->createStub(InstanceActorService::class);
		$this->instanceActorService->method('getId')->willReturn(self::INSTANCE_ACTOR_URL);
		$this->request = $this->createMock(IRequest::class);
		$this->context = $this->createStub(IRequestContext::class);
		$this->context->method('getHttpRequest')->willReturn($this->request);

		$this->configService->method('getCloudUrl')->willReturnCallback(
			fn (bool $noPhp = false): string => $noPhp ? 'https://cloud.example' : 'https://cloud.example/index.php'
		);
		$this->configService->method('getSocialUrl')->willReturn('https://cloud.example/index.php/apps/social/');
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');

		\OC::$server->register(IRequest::class, $this->request);

		$this->handler = new WebfingerHandler(
			$this->cacheActorsRequest,
			$this->cacheActorService,
			$this->fediverseService,
			$this->configService,
			$this->instanceActorService
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function resource(string $resource): void {
		$this->request->method('getParam')->with('resource')->willReturn($resource);
	}

	/** @return Person&MockObject */
	private function localActor(string $username, bool $local = true): Person {
		$actor = $this->createMock(Person::class);
		$actor->method('getId')->willReturn('https://cloud.example/index.php/apps/social/@' . $username);
		$actor->method('getPreferredUsername')->willReturn($username);
		$actor->method('isLocal')->willReturn($local);

		return $actor;
	}

	private function jsonOf(IResponse $response): array {
		$http = $response->toHttpResponse();
		$this->assertInstanceOf(JSONResponse::class, $http);

		return $http->getData();
	}

	// handle() dispatch

	public function testJailedInstanceLeavesThePreviousResponseUntouched(): void {
		$this->fediverseService->method('jailed')->willThrowException(new UnauthorizedFediverseException());
		$previous = $this->createStub(IResponse::class);
		$this->cacheActorService->expects($this->never())->method('getFromLocalAccount');

		$this->assertSame($previous, $this->handler->handle('webfinger', $this->context, $previous));
	}

	public function testUnknownServicesLeaveThePreviousResponseUntouched(): void {
		$previous = $this->createStub(IResponse::class);

		$this->assertSame($previous, $this->handler->handle('openid-configuration', $this->context, $previous));
		$this->assertNull($this->handler->handle('openid-configuration', $this->context, null));
	}

	public function testNodeinfoAdvertisesTheSchemaEndpoint(): void {
		$response = $this->handler->handle('NodeInfo', $this->context, null);

		$this->assertInstanceOf(JrdResponse::class, $response);
		$this->assertSame([
			'links' => [
				[
					'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
					'href' => 'https://cloud.example/index.php/apps/social/.well-known/nodeinfo/2.0',
				],
				[
					'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
					'href' => 'https://cloud.example/index.php/apps/social/.well-known/nodeinfo/2.1',
				],
			],
		], $this->jsonOf($response));
	}

	/**
	 * Oldest first, and not by accident: a consumer that reads the document
	 * properly takes the highest version it understands, and one that naively
	 * takes the first link keeps getting the 2.0 it was getting before 2.1
	 * was reachable at all.
	 */
	public function testNodeinfoOffersTheOldestSchemaFirst(): void {
		$links = $this->jsonOf($this->handler->handle('NodeInfo', $this->context, null))['links'];

		$this->assertStringEndsWith('/2.0', $links[0]['rel']);
		$this->assertStringEndsWith('/2.1', $links[array_key_last($links)]['rel']);
	}

	public function testHostMetaAdvertisesTheWebfingerTemplateWithoutIndexPhp(): void {
		$response = $this->handler->handle('host-meta', $this->context, null);

		$this->assertInstanceOf(XrdResponse::class, $response);
		$this->assertStringContainsString(
			'<Link rel="lrdd"  template="https://cloud.example/.well-known/webfinger?resource={uri}"/>',
			$response->toHttpResponse()->render()
		);
	}

	public function testHostMetaIsSkippedWhenTheCloudUrlIsNotConfigured(): void {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$handler = new WebfingerHandler($this->cacheActorsRequest, $this->cacheActorService, $this->fediverseService, $configService, $this->instanceActorService);
		$previous = $this->createStub(IResponse::class);

		$this->assertSame($previous, $handler->handle('host-meta', $this->context, $previous));
	}

	// handleWebfinger()

	public function testLocalAccountIsDescribedWithSelfAndProfileLinks(): void {
		$this->resource('acct:alice@cloud.example');
		$this->cacheActorService->method('getFromLocalAccount')->with('alice@cloud.example')->willReturn($this->localActor('alice'));

		$response = $this->handler->handle('webfinger', $this->context, null);

		$this->assertInstanceOf(JrdResponse::class, $response);
		$http = $response->toHttpResponse();
		$this->assertSame(Http::STATUS_OK, $http->getStatus());
		$this->assertSame([
			'subject' => 'acct:alice@cloud.example',
			'aliases' => [self::ACTOR_URL, 'https://cloud.example/index.php/u/alice'],
			'links' => [
				['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::ACTOR_URL],
				// the *social* profile: this is where a peer sends a reader who
				// clicks the handle, and the Nextcloud user page carries none
				// of the account's posts. It stays an alias, above.
				['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => self::ACTOR_URL],
				['rel' => 'http://ostatus.org/schema/1.0/subscribe', 'template' => 'https://cloud.example/index.php/apps/social/ostatus/follow/?uri={uri}'],
			],
		], $this->jsonOf($response));
	}

	public function testSubjectWithoutAcctPrefixIsLookedUpVerbatim(): void {
		$this->resource('alice@cloud.example');
		$this->cacheActorService->expects($this->once())->method('getFromLocalAccount')->with('alice@cloud.example')
			->willReturn($this->localActor('alice'));

		$json = $this->jsonOf($this->handler->handleWebfinger($this->context, null));

		$this->assertSame('acct:alice@cloud.example', $json['subject']);
	}

	/** @return array<string, array{string}> */
	public static function looseSubjects(): array {
		return [
			'leading @' => ['acct:@alice@cloud.example'],
			'user case' => ['acct:Alice@cloud.example'],
			'host case' => ['acct:alice@CLOUD.example'],
			'no scheme' => ['alice@cloud.example'],
			'actor id' => [self::ACTOR_URL],
		];
	}

	/**
	 * Whatever form of the handle found the account, the answer names it in
	 * one: a consumer that splits `acct:@alice@host` on `@` reads a user with
	 * no name.
	 */
	#[DataProvider('looseSubjects')]
	public function testTheSubjectIsTheAccountsOwnHandleNotTheQuery(string $resource): void {
		$this->resource($resource);
		$this->cacheActorService->method('getFromLocalAccount')->willReturn($this->localActor('alice'));
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->localActor('alice'));

		$json = $this->jsonOf($this->handler->handleWebfinger($this->context, null));

		$this->assertSame('acct:alice@cloud.example', $json['subject']);
	}

	public function testAMissingResourceParameterIsABadRequest(): void {
		// RFC 7033, section 4.2: the resource parameter is required
		$this->resource('');
		$this->request->method('getRequestUri')->willReturn('/.well-known/webfinger');
		$this->cacheActorService->expects($this->never())->method('getFromLocalAccount');

		$response = $this->handler->handleWebfinger($this->context, null);

		$this->assertInstanceOf(JrdResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->toHttpResponse()->getStatus());
	}

	public function testAnEmptyRequestUriRaisesNoWarning(): void {
		$this->resource('');
		$this->request->method('getRequestUri')->willReturn('');

		$response = $this->handler->handleWebfinger($this->context, null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->toHttpResponse()->getStatus());
	}

	public function testResourceIsReadFromTheRequestUriWhenParamsAreMissing(): void {
		$this->resource('');
		$this->request->method('getRequestUri')->willReturn('/.well-known/webfinger?resource=acct%3Aalice%40cloud.example&rel=self');
		$this->cacheActorService->expects($this->once())->method('getFromLocalAccount')->with('alice@cloud.example')
			->willReturn($this->localActor('alice'));

		$json = $this->jsonOf($this->handler->handleWebfinger($this->context, null));

		$this->assertSame('acct:alice@cloud.example', $json['subject']);
	}

	public function testUnknownLocalUserLeavesThePreviousResponseUntouched(): void {
		$this->resource('acct:ghost@cloud.example');
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new ActorDoesNotExistException());
		$this->cacheActorsRequest->expects($this->never())->method('getFromId');
		$previous = $this->createStub(IResponse::class);

		$this->assertNull($this->handler->handleWebfinger($this->context, $previous));
		$this->assertSame($previous, $this->handler->handle('webfinger', $this->context, $previous));
	}

	public function testUnconfiguredAppLeavesThePreviousResponseUntouched(): void {
		$this->resource('acct:alice@cloud.example');
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new SocialAppConfigException());
		$previous = $this->createStub(IResponse::class);

		$this->assertSame($previous, $this->handler->handle('webfinger', $this->context, $previous));
	}

	public function testActorIdFallsBackToTheActorCache(): void {
		$this->resource(self::ACTOR_URL);
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->cacheActorsRequest->expects($this->once())->method('getFromId')->with(self::ACTOR_URL)
			->willReturn($this->localActor('alice'));

		$json = $this->jsonOf($this->handler->handleWebfinger($this->context, null));

		$this->assertSame('acct:alice@cloud.example', $json['subject']);
		$this->assertSame(self::ACTOR_URL, $json['links'][0]['href']);
	}

	public function testUncachedUnknownActorIsAnEmpty404(): void {
		$this->resource('acct:nobody@cloud.example');
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$response = $this->handler->handleWebfinger($this->context, $this->createStub(IResponse::class));

		$this->assertInstanceOf(JrdResponse::class, $response);
		$this->assertTrue($response->isEmpty());
		$http = $response->toHttpResponse();
		$this->assertInstanceOf(DataResponse::class, $http);
		$this->assertSame(Http::STATUS_NOT_FOUND, $http->getStatus());
	}

	public function testRemoteActorsAreNotServed(): void {
		$this->resource('acct:bob@remote.example');
		$this->cacheActorService->method('getFromLocalAccount')->willReturn($this->localActor('bob', false));

		$response = $this->handler->handleWebfinger($this->context, null);

		$this->assertInstanceOf(JrdResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->toHttpResponse()->getStatus());
	}

	public function testAppSubjectAnnotatesThePreviousJrdWithTheAppLink(): void {
		$this->resource(Application::APP_SUBJECT);
		$this->configService->method('getAppValue')->with('installed_version')->willReturn('0.10.1');
		$this->cacheActorService->expects($this->never())->method('getFromLocalAccount');
		$previous = new JrdResponse(Application::APP_SUBJECT);

		$response = $this->handler->handleWebfinger($this->context, $previous);

		$this->assertSame($previous, $response);
		$this->assertSame([[
			'rel' => Application::APP_REL,
			'type' => 'application/json',
			'href' => 'https://cloud.example/index.php/apps/social/',
			'properties' => ['app' => 'social', 'name' => 'Social', 'version' => '0.10.1'],
		]], $this->jsonOf($response)['links']);
	}

	public function testAppSubjectWithoutPreviousResponseYieldsNothing(): void {
		$this->resource(Application::APP_SUBJECT);

		$this->assertNull($this->handler->handleWebfinger($this->context, null));
	}

	public function testTheEmittedLinksComeFromTheActorsOwnIdNotTheRequestHost(): void {
		// An instance answering on more than one trusted domain used to describe the
		// actor with whichever host asked, while the actor document it points at
		// keeps the configured id: a remote server then fetched a document whose
		// `id` disagreed with the `href` that sent it there.
		$this->resource('acct:alice@localhost');
		$this->cacheActorService->method('getFromLocalAccount')->willReturn($this->localActor('alice'));

		$json = $this->jsonOf($this->handler->handleWebfinger($this->context, null));

		$this->assertSame(self::ACTOR_URL, $json['links'][0]['href']);
		$this->assertSame(self::ACTOR_URL, $json['aliases'][0]);
	}

	public function testAnActorWithoutAStoredIdIsA404(): void {
		$this->resource('acct:alice@cloud.example');
		$actor = $this->createStub(Person::class);
		$actor->method('getId')->willReturn('');
		$actor->method('isLocal')->willReturn(true);
		$this->cacheActorService->method('getFromLocalAccount')->willReturn($actor);

		$response = $this->handler->handleWebfinger($this->context, null);

		$this->assertInstanceOf(JrdResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->toHttpResponse()->getStatus());
	}

	/**
	 * A peer that checks one of our signatures dereferences the `keyId`; a peer
	 * that wants to know what signed at all looks the host up as a handle. That
	 * is `acct:<host>@<host>`, and it has to lead to the instance actor.
	 */
	public function testTheInstanceActorIsDiscoverableUnderTheHostHandle(): void {
		$this->resource('acct:cloud.example@cloud.example');
		$this->cacheActorService->expects($this->never())->method('getFromLocalAccount');

		$json = $this->jsonOf($this->handler->handleWebfinger($this->context, null));

		$this->assertSame('acct:cloud.example@cloud.example', $json['subject']);
		$this->assertSame([self::INSTANCE_ACTOR_URL], $json['aliases']);
		$this->assertSame(
			[['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::INSTANCE_ACTOR_URL]],
			$json['links']
		);
	}

	/** A domain is not case-sensitive, and a peer may ask in any case. */
	public function testTheInstanceActorHandleIsMatchedRegardlessOfCase(): void {
		$this->resource('acct:Cloud.Example@CLOUD.example');

		$json = $this->jsonOf($this->handler->handleWebfinger($this->context, null));

		$this->assertSame([self::INSTANCE_ACTOR_URL], $json['aliases']);
	}

	/**
	 * The instance handle is reserved, but nothing else is: every other subject
	 * still has to reach the account lookup.
	 */
	public function testAnOrdinaryHandleIsStillLookedUpAsALocalAccount(): void {
		$this->resource('acct:alice@cloud.example');
		$this->cacheActorService->expects($this->once())
			->method('getFromLocalAccount')
			->willReturn($this->localActor('alice'));

		$json = $this->jsonOf($this->handler->handleWebfinger($this->context, null));

		$this->assertSame(self::ACTOR_URL, $json['links'][0]['href']);
	}
}
