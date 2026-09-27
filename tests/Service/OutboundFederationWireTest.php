<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\MediaBlocksRequest;
use OCA\Social\Db\RelayRequest;
use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Report;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\BlurService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\ImageConversionService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\ReportForwardService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\VideoThumbnailService;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\Config\IUserConfig;
use OCP\Files\IAppData;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What actually goes out on the wire, pinned byte for byte.
 *
 * Every other test in this suite stops at an app-level seam — a mocked
 * `CurlService`, a request object inspected after the fact. This one stops at
 * the last seam there is: `OCP\Http\Client\IClient::request()`, the call the
 * server's own HTTP client receives. Everything above it is real.
 *
 * It exists because an HTTP signature is checked by the *peer* and by nobody
 * here. If a signed header is dropped, re-ordered or re-cased, if the path the
 * signature covers stops matching the path the request is sent to, if the date
 * format shifts or the digest stops covering the body that is actually sent,
 * then every test in this repository still passes and every remote server
 * rejects every delivery — silently, as a queue that never drains.
 *
 * So the assertions here are deliberately literal. A refactor that changes any
 * of them has changed the wire, and has to say so out loud.
 */
#[AllowMockObjectsWithoutExpectations]
class OutboundFederationWireTest extends TestCase {
	/** TEST-NET-3: a public address the local-address guard lets through without asking DNS. */
	private const REMOTE = '203.0.113.10';
	private const ALICE = 'https://cloud.example.com/index.php/apps/social/users/alice';
	private const INSTANCE_ACTOR = 'https://cloud.example.com/index.php/apps/social/actor';

	private const ACCEPT_ACTIVITY_JSON
		= 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

	private static string $privateKey;
	private static string $publicKey;

	private IAppConfig|Stub $appConfig;
	private IConfig|MockObject $config;
	private IClient|MockObject $client;
	private FediverseService|Stub $fediverseService;
	private ActorsRequest|MockObject $actorsRequest;
	private InstanceActorService|Stub $instanceActorService;
	private ConfigService $configService;

	/** @var list<array{method: string, url: string, options: array}> */
	private array $sent = [];

	public static function setUpBeforeClass(): void {
		$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($res, $private);
		self::$privateKey = $private;
		self::$publicKey = openssl_pkey_get_details($res)['key'];
	}

	protected function setUp(): void {
		$this->appConfig = $this->createStub(IAppConfig::class);
		$this->appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default): string => match ($key) {
				ConfigService::SOCIAL_MAX_SIZE => '10',
				'installed_version' => '0.17.0',
				default => $default,
			});
		$this->config = $this->createMock(IConfig::class);
		$this->configService = new ConfigService(
			'alice',
			$this->appConfig,
			$this->createStub(IUserConfig::class),
			$this->config,
			$this->createStub(IURLGenerator::class),
		);

		$this->fediverseService = $this->createStub(FediverseService::class);
		$this->fediverseService->method('authorized')->willReturn(true);

		$this->client = $this->createMock(IClient::class);
		$this->client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options): IResponse {
				$this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

				return $this->answer('{"ok":true}');
			}
		);

		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->instanceActorService = $this->createStub(InstanceActorService::class);
	}

	private function answer(string $body, int $code = 200, string $contentType = 'application/json'): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($code);
		$response->method('getHeader')->willReturnCallback(
			static fn (string $key): string => strtolower($key) === 'content-type' ? $contentType : ''
		);
		$response->method('getBody')->willReturnCallback(static function () use ($body) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, $body);
			rewind($stream);

			return $stream;
		});

		return $response;
	}

	private function curlService(): CurlService {
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		return new CurlService(
			$this->configService,
			$this->fediverseService,
			$clientService,
			$this->httpSignatureService(),
			new NullLogger(),
		);
	}

	private function httpSignatureService(): HttpSignatureService {
		return new HttpSignatureService($this->actorsRequest, $this->instanceActorService, new NullLogger());
	}

	private function signatureService(CurlService $curlService): SignatureService {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createStub(ICache::class));

		return new SignatureService(
			$this->createStub(CacheActorService::class),
			$this->createStub(CacheActorsRequest::class),
			$this->configService,
			$this->httpSignatureService(),
			$cacheFactory,
			new NullLogger(),
			$this->createStub(DurableCache::class),
		);
	}

	/** The local actor a delivery is signed as. */
	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE);
		$alice->setPrivateKey(self::$privateKey);

		return $alice;
	}

	/** The instance's own actor, which is what signs an outbound fetch. */
	private function signsFetchesAsTheInstanceActor(): void {
		$actor = new InstanceActor();
		$actor->setId(self::INSTANCE_ACTOR);
		$actor->setPrivateKey(self::$privateKey);
		$this->instanceActorService->method('getSigningActor')->willReturn($actor);
	}

	/** @return array{method: string, url: string, options: array} */
	private function onlyRequest(): array {
		$this->assertCount(1, $this->sent, 'exactly one request was expected');

		return $this->sent[0];
	}

	/**
	 * The draft-cavage `Signature` header, split into its four parts, in the
	 * order they were written.
	 *
	 * @return array<string, string>
	 */
	private function signatureParts(string $header): array {
		$parts = [];
		foreach (explode(',', $header) as $entry) {
			[$k, $v] = explode('=', $entry, 2);
			$parts[$k] = trim($v, '"');
		}

		return $parts;
	}

	private function assertSignsExactly(string $signingString, string $signatureHeader): void {
		$this->assertSame(
			1,
			openssl_verify(
				$signingString,
				base64_decode($this->signatureParts($signatureHeader)['signature'], true),
				self::$publicKey,
				OPENSSL_ALGO_SHA256
			),
			"the signature does not cover:\n" . $signingString
		);
	}

	/** `Sun, 06 Nov 1994 08:49:37 GMT` — RFC 1123, in GMT, two-digit day. */
	private function assertRfc1123(string $date): void {
		$this->assertMatchesRegularExpression(
			'/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} GMT$/',
			$date
		);
	}

	// ----------------------------------------------------------------- delivery

	private function queue(string $inbox, string $activity, int $timeout = ActivityService::TIMEOUT_ASYNC): RequestQueue {
		$queue = new RequestQueue();
		$queue->setInstance(new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH));
		$queue->setAuthor(self::ALICE);
		$queue->setActivity($activity);
		$queue->setTimeout($timeout);

		return $queue;
	}

	private function activityService(CurlService $curlService): ActivityService {
		$service = new ActivityService(
			$this->createStub(FollowsRequest::class),
			$this->createStub(CacheActorsRequest::class),
			$this->signatureService($curlService),
			$this->createStub(RequestQueueService::class),
			$curlService,
			$this->configService,
			$this->actorsRequest,
			$this->createStub(RelayRequest::class),
			$this->createStub(\OCA\Social\Db\HostBreakerRequest::class),
			new NullLogger(),
		);
		$service->manageInit();

		return $service;
	}

	private function deliver(string $activity): array {
		$this->actorsRequest->method('getFromId')->with(self::ALICE)->willReturn($this->alice());
		$curlService = $this->curlService();
		$this->activityService($curlService)
			->manageRequest($this->queue('https://' . self::REMOTE . '/inbox', $activity));

		return $this->onlyRequest();
	}

	/**
	 * The whole of an outbound delivery: the method, the URL, every header in
	 * the order it is written, and the body.
	 */
	public function testADeliveryPostGoesOutExactlyLikeThis(): void {
		$activity = '{"@context":"https://www.w3.org/ns/activitystreams","id":"'
			. self::ALICE . '/note/1/activity","type":"Create","actor":"' . self::ALICE . '"}';

		$sent = $this->deliver($activity);
		$headers = $sent['options']['headers'];

		$this->assertSame('post', $sent['method']);
		$this->assertSame('https://' . self::REMOTE . '/inbox', $sent['url']);

		// the body is the queued activity re-encoded with unescaped slashes
		$body = (string)json_encode(json_decode($activity, true), JSON_UNESCAPED_SLASHES);
		$this->assertSame($body, $sent['options']['body']);

		// the header names, in the order they are written: the user agent, then
		// the four signed ones in the order of the signed header list, then the
		// signature itself, then the content type added by ConfigService
		$this->assertSame(
			['user-agent', 'content-length', 'date', 'host', 'digest', 'Signature', 'Content-Type'],
			array_keys($headers)
		);

		$this->assertSame('Nextcloud Social 0.17.0', $headers['user-agent']);
		$this->assertSame((string)strlen($body), $headers['content-length']);
		$this->assertRfc1123($headers['date']);
		$this->assertSame(self::REMOTE, $headers['host']);
		$this->assertSame('SHA-256=' . base64_encode(hash('sha256', $body, true)), $headers['digest']);
		$this->assertSame('application/activity+json', $headers['Content-Type']);
	}

	/** The exact bytes a delivery signature covers. */
	public function testTheStringADeliverySignsIsExactlyThis(): void {
		$activity = '{"id":"' . self::ALICE . '/note/1/activity","type":"Create"}';

		$sent = $this->deliver($activity);
		$headers = $sent['options']['headers'];

		$signingString = implode("\n", [
			'(request-target): post /inbox',
			'content-length: ' . $headers['content-length'],
			'date: ' . $headers['date'],
			'host: ' . self::REMOTE,
			'digest: ' . $headers['digest'],
		]);

		$this->assertSignsExactly($signingString, $headers['Signature']);
	}

	/** The `Signature` header, assembled in exactly this order and spelling. */
	public function testTheDeliverySignatureHeaderIsAssembledLikeThis(): void {
		$sent = $this->deliver('{"type":"Create"}');
		$parts = $this->signatureParts($sent['options']['headers']['Signature']);

		$this->assertSame(['keyId', 'algorithm', 'headers', 'signature'], array_keys($parts));
		$this->assertSame(self::ALICE . '#main-key', $parts['keyId']);
		$this->assertSame('rsa-sha256', $parts['algorithm']);
		$this->assertSame('(request-target) content-length date host digest', $parts['headers']);
		$this->assertStringStartsWith(
			'keyId="' . self::ALICE . '#main-key",algorithm="rsa-sha256",'
			. 'headers="(request-target) content-length date host digest",signature="',
			$sent['options']['headers']['Signature']
		);
	}

	/** The digest covers the body that is actually sent, not the one it was built from. */
	public function testTheDigestCoversTheBodyOnTheWire(): void {
		$sent = $this->deliver('{"type":"Create","object":{"type":"Note","content":"a/b"}}');

		$this->assertSame(
			'SHA-256=' . base64_encode(hash('sha256', $sent['options']['body'], true)),
			$sent['options']['headers']['digest']
		);
		$this->assertSame(
			(string)strlen($sent['options']['body']),
			$sent['options']['headers']['content-length']
		);
	}

	// -------------------------------------------------------------------- fetch

	/** The whole of a signed ActivityPub GET. */
	public function testASignedFetchGoesOutExactlyLikeThis(): void {
		$this->signsFetchesAsTheInstanceActor();

		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob/outbox?page=2');

		$sent = $this->onlyRequest();
		$headers = $sent['options']['headers'];

		$this->assertSame('get', $sent['method']);
		$this->assertSame('https://' . self::REMOTE . '/users/bob/outbox?page=2', $sent['url']);
		$this->assertArrayNotHasKey('body', $sent['options'], 'a GET carries no body');

		$this->assertSame(['user-agent', 'Accept', 'host', 'date', 'Signature'], array_keys($headers));
		$this->assertSame(self::REMOTE, $headers['host']);
		$this->assertRfc1123($headers['date']);

		$signingString = implode("\n", [
			'(request-target): get /users/bob/outbox?page=2',
			'host: ' . self::REMOTE,
			'date: ' . $headers['date'],
		]);
		$this->assertSignsExactly($signingString, $headers['Signature']);

		$parts = $this->signatureParts($headers['Signature']);
		$this->assertSame(self::INSTANCE_ACTOR . '#main-key', $parts['keyId']);
		$this->assertSame('(request-target) host date', $parts['headers']);
	}

	/**
	 * The path the signature covers is the path the request is sent to. A
	 * signature over anything else verifies here and nowhere else.
	 */
	public function testTheSignedTargetIsThePathTheRequestIsSentTo(): void {
		$this->signsFetchesAsTheInstanceActor();

		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob');

		$sent = $this->onlyRequest();
		$this->assertSame('https://' . self::REMOTE . '/users/bob', $sent['url']);
		$this->assertSignsExactly(
			implode("\n", [
				'(request-target): get /users/bob',
				'host: ' . self::REMOTE,
				'date: ' . $sent['options']['headers']['date'],
			]),
			$sent['options']['headers']['Signature']
		);
	}

	/**
	 * An ActivityPub fetch asks for activity+json twice: once from the fetch
	 * itself and once from the app-wide content negotiation. Harmless, and
	 * pinned here because it is on the wire.
	 */
	public function testAFetchAsksForActivityJson(): void {
		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob');

		$this->assertSame(
			'application/activity+json, ' . self::ACCEPT_ACTIVITY_JSON,
			$this->onlyRequest()['options']['headers']['Accept']
		);
	}

	/** Without a key to sign with, the fetch goes out as it did before signatures existed. */
	public function testAnUnsignableFetchStillGoesOut(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn(null);

		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob');

		$headers = $this->onlyRequest()['options']['headers'];
		$this->assertArrayNotHasKey('Signature', $headers);
		$this->assertSame(['user-agent', 'Accept'], array_keys($headers));
	}

	// ------------------------------------------------------------ report forward

	public function testAForwardedReportGoesOutSignedByTheInstance(): void {
		$actor = new InstanceActor();
		$actor->setId(self::INSTANCE_ACTOR);
		$actor->setPrivateKey(self::$privateKey);
		$this->instanceActorService->method('getSigningActor')->willReturn($actor);

		$target = new Person();
		$target->setId('https://' . self::REMOTE . '/users/bob');
		$target->setInbox('https://' . self::REMOTE . '/users/bob/inbox');
		$target->setLocal(false);

		$report = new Report();
		$report->setId(7);
		$report->setLocal(true);
		$report->setComment('spam');

		$service = new ReportForwardService(
			$this->instanceActorService,
			$this->httpSignatureService(),
			$this->curlService(),
			$this->createStub(\OCA\Social\Db\StreamRequest::class),
			new NullLogger(),
		);

		$this->assertTrue($service->forward($report, $target));

		$sent = $this->onlyRequest();
		$headers = $sent['options']['headers'];

		$this->assertSame('post', $sent['method']);
		$this->assertSame('https://' . self::REMOTE . '/users/bob/inbox', $sent['url']);
		$this->assertSame(
			['user-agent', 'content-length', 'date', 'host', 'digest', 'Signature', 'Content-Type'],
			array_keys($headers)
		);
		$this->assertSame(self::INSTANCE_ACTOR, $this->flagOf($sent['options']['body'])['actor']);

		$this->assertSignsExactly(
			implode("\n", [
				'(request-target): post /users/bob/inbox',
				'content-length: ' . $headers['content-length'],
				'date: ' . $headers['date'],
				'host: ' . self::REMOTE,
				'digest: ' . $headers['digest'],
			]),
			$headers['Signature']
		);
		$this->assertSame(
			'(request-target) content-length date host digest',
			$this->signatureParts($headers['Signature'])['headers']
		);
		$this->assertSame($this->signatureParts($headers['Signature'])['keyId'], $actor->getKeyId());
	}

	private function flagOf(string $body): array {
		return json_decode($body, true);
	}

	// ------------------------------------------------------ transport behaviour

	/** WebFinger tries https first and falls through to http when it cannot connect. */
	public function testWebfingerFallsThroughToHttp(): void {
		$attempts = [];
		$this->client = $this->createMock(IClient::class);
		$this->client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use (&$attempts): IResponse {
				$attempts[] = $url;
				if (str_starts_with($url, 'https://')) {
					throw new \Exception('TLS handshake failed');
				}
				$this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

				return $this->answer('{"subject":"acct:bob@' . self::REMOTE . '"}');
			}
		);

		$account = 'bob@' . self::REMOTE;
		$this->curlService()->webfingerAccount($account);

		$this->assertSame([
			'https://' . self::REMOTE . '/.well-known/host-meta',
			'http://' . self::REMOTE . '/.well-known/host-meta',
			'https://' . self::REMOTE . '/.well-known/webfinger?resource=acct%3Abob%40' . self::REMOTE,
			'http://' . self::REMOTE . '/.well-known/webfinger?resource=acct%3Abob%40' . self::REMOTE,
		], $attempts);
	}

	/** WebFinger and host-meta opt out of the ActivityPub content negotiation. */
	public function testWebfingerDoesNotAskForActivityJson(): void {
		$this->client = $this->createMock(IClient::class);
		$this->client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options): IResponse {
				$this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

				return $this->answer('{"subject":"acct:bob@' . self::REMOTE . '"}');
			}
		);
		$account = 'bob@' . self::REMOTE;
		$this->curlService()->webfingerAccount($account);

		$this->assertSame(['user-agent'], array_keys($this->sent[0]['options']['headers']));
	}

	/** A local address is refused before anything leaves the server. */
	public function testTheLocalAddressGuardHoldsForEveryRequest(): void {
		$this->client->expects($this->never())->method('request');

		$this->expectException(RequestServerException::class);
		$this->curlService()->retrieveObject('https://127.0.0.1/users/bob');
	}

	public function testTheClientIsToldWhetherLocalAddressesAreAllowed(): void {
		$this->config->method('getSystemValueBool')
			->with('allow_local_remote_servers', false)->willReturn(true);

		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob');

		$this->assertTrue($this->onlyRequest()['options']['nextcloud']['allow_local_address']);
	}

	/** The transport policy every federation request goes out with. */
	public function testTheTransportPolicyIsTheSameForEveryRequest(): void {
		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob');

		$options = $this->onlyRequest()['options'];
		$this->assertFalse($options['http_errors'], 'the status code belongs to the caller');
		$this->assertTrue($options['stream'], 'an endless body must not fill memory');
		$this->assertFalse(
			$options['allow_redirects'],
			'followed by CurlService itself, so the block list applies to every hop'
		);
		$this->assertArrayNotHasKey('verify', $options, 'peer verification is on unless self-signed is allowed');
		$this->assertFalse($options['nextcloud']['allow_local_address']);
	}

	public function testSelfSignedCertificatesAreAcceptedWhenTheInstanceSaysSo(): void {
		$this->appConfig = $this->createStub(IAppConfig::class);
		$this->appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default): string => match ($key) {
				ConfigService::SOCIAL_MAX_SIZE => '10',
				ConfigService::SOCIAL_SELF_SIGNED => '1',
				default => $default,
			});
		$this->configService = new ConfigService(
			'alice', $this->appConfig, $this->createStub(IUserConfig::class), $this->config,
			$this->createStub(IURLGenerator::class),
		);

		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob');

		$this->assertFalse($this->onlyRequest()['options']['verify']);
	}

	/** The queue row's timeout is the one the transport gets. */
	public function testADeliveryUsesTheQueueRowsTimeout(): void {
		$this->actorsRequest->method('getFromId')->willReturn($this->alice());
		$curlService = $this->curlService();
		$queue = $this->queue('https://' . self::REMOTE . '/inbox', '{"type":"Create"}', ActivityService::TIMEOUT_LIVE);

		$this->activityService($curlService)->manageRequest($queue);

		$options = $this->onlyRequest()['options'];
		$this->assertSame(ActivityService::TIMEOUT_LIVE, $options['timeout']);
		$this->assertSame(ActivityService::TIMEOUT_LIVE, $options['connect_timeout'], 'no separate budget was asked for');
	}

	/** A bounded call overrides it, connect budget and all. */
	public function testABoundedCallOverridesTheRequestTimeouts(): void {
		$this->signsFetchesAsTheInstanceActor();
		$curlService = $this->curlService();

		$this->configService->withRequestTimeout(
			SignatureService::UNKNOWN_KEY_TIMEOUT,
			fn () => $curlService->retrieveObject('https://' . self::REMOTE . '/users/bob'),
			SignatureService::UNKNOWN_KEY_CONNECT_TIMEOUT,
		);

		$options = $this->onlyRequest()['options'];
		$this->assertSame(SignatureService::UNKNOWN_KEY_TIMEOUT, $options['timeout']);
		$this->assertSame(SignatureService::UNKNOWN_KEY_CONNECT_TIMEOUT, $options['connect_timeout']);
	}

	/** The default, when nobody asks for anything else. */
	public function testTheDefaultRequestTimeoutIsTenSeconds(): void {
		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob');

		$options = $this->onlyRequest()['options'];
		$this->assertSame(10, $options['timeout']);
		$this->assertSame(10, $options['connect_timeout']);
	}

	/** An answer larger than the `max_size` app setting is refused rather than read. */
	public function testABodyOverTheSizeLimitIsRefused(): void {
		$this->client = $this->createMock(IClient::class);
		$this->client->method('request')->willReturn($this->answer(str_repeat('a', 10 * 1048576 + 1)));

		$this->expectException(RequestResultSizeException::class);
		$this->curlService()->retrieveObject('https://' . self::REMOTE . '/users/bob');
	}

	/** A cached document is fetched without the ActivityPub headers. */
	public function testAMediaFetchDoesNotAskForActivityJson(): void {
		$service = new CacheDocumentService(
			$this->createStub(IAppData::class),
			$this->curlService(),
			$this->createStub(BlurService::class),
			$this->configService,
			$this->createStub(ImageConversionService::class),
			$this->createStub(VideoThumbnailService::class),
			$this->createStub(ITempManager::class),
			$this->createStub(MediaBlocksRequest::class),
			$this->createStub(\OCA\Social\Service\VideoQuotaService::class),
			$this->unlimitedDomainQuota(),
			$this->noExternalQuota(),
			new NullLogger(),
		);

		$service->retrieveContent('https://' . self::REMOTE . '/media/1.png?sig=abc');

		$sent = $this->onlyRequest();
		$this->assertSame('https://' . self::REMOTE . '/media/1.png?sig=abc', $sent['url']);
		$this->assertSame(['user-agent'], array_keys($sent['options']['headers']));
	}

	/** No per-domain media quota, which is what an instance has by default. */
	private function unlimitedDomainQuota(): \OCA\Social\Service\RemoteMediaQuotaService {
		$quota = $this->createMock(\OCA\Social\Service\RemoteMediaQuotaService::class);
		$quota->method('fits')->willReturn(true);

		return $quota;
	}

	/** Nobody here is an external user with a media quota. */
	private function noExternalQuota(): \OCA\Social\Service\ExternalMediaQuota {
		$quota = $this->createStub(\OCA\Social\Service\ExternalMediaQuota::class);
		$quota->method('fits')->willReturn(true);

		return $quota;
	}
}
