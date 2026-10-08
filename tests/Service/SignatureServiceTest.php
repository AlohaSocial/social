<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCA\Social\Tests\Helper\RsaPssSigner;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class SignatureServiceTest extends TestCase {
	private const CLOUD_HOST = 'cloud.example.com';

	/** What the configured cloud URL answers as `host[:port]`. */
	private string $cloudAuthority = self::CLOUD_HOST;
	private const LOCAL_ACTOR = 'https://cloud.example.com/apps/social/@alice';
	private const REMOTE_ACTOR = 'https://remote.example/users/bob';
	private const REMOTE_KEY_ID = self::REMOTE_ACTOR . '#main-key';

	/** One RSA key pair for the whole class: generating 2048-bit keys per test is slow. */
	private static string $privateKey;
	private static string $publicKey;
	private static string $otherPublicKey;

	private ActorsRequest|MockObject $actorsRequest;
	private CacheActorService|MockObject $cacheActorService;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private FediverseService|MockObject $fediverseService;
	private SignatureService $service;
	/** the replay records, on the table an instance without a memcache uses */
	private InMemoryDurableCacheRequest $seenSignatures;
	/** @var array<string, mixed> backing store of the mocked key-attempt cache */
	private array $keyAttempts = [];
	/** @var array<string, int> the ttl each cache entry was written with */
	private array $cacheTtl = [];

	public static function setUpBeforeClass(): void {
		[self::$privateKey, self::$publicKey] = self::keyPair();
		[, self::$otherPublicKey] = self::keyPair();
	}

	/** @return array{string, string} private and public PEM */
	private static function keyPair(): array {
		$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($res, $private);

		return [$private, openssl_pkey_get_details($res)['key']];
	}

	protected function setUp(): void {
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->cacheActorsRequest = $this->createStub(CacheActorsRequest::class);
		// no key is in the local cache unless a test puts one there
		$this->cacheActorsRequest->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);
		$configService->method('getCloudAuthority')->willReturnCallback(fn (): string => $this->cloudAuthority);
		// the real one narrows the request timeout around the call; here it only
		// has to run what it is given
		$configService->method('withRequestTimeout')
			->willReturnCallback(fn (int $timeout, callable $action) => $action());

		// in-memory stand-ins for the two distributed caches
		$this->seenSignatures = new InMemoryDurableCacheRequest();
		$this->keyAttempts = [];
		$this->cacheTtl = [];
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			fn (string $prefix): ICache => $this->arrayCache($this->keyAttempts)
		);

		$this->service = new SignatureService(
			$this->cacheActorService,
			$this->cacheActorsRequest,
			$configService,
			new HttpSignatureService(
				$this->actorsRequest, $this->createStub(InstanceActorService::class), new NullLogger()
			),
			$cacheFactory,
			new NullLogger(),
			$this->durableCache(),
			$this->fediverseService,
		);
	}

	/**
	 * The LD replay records as an instance with no memcache keeps them: there,
	 * `createDistributed()` forgets every write, and a replay cache kept in it
	 * accepted the same signature twice.
	 */
	private function durableCache(): DurableCache {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static fn (): int => time());

		return new DurableCache($cacheFactory, $this->seenSignatures, $time);
	}

	/**
	 * @param array<string, mixed> $store
	 * @return ICache&MockObject
	 */
	private function arrayCache(array &$store): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use (&$store) {
				return $store[$key] ?? null;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value, $ttl = 0) use (&$store) {
				$store[$key] = $value;
				$this->cacheTtl[$key] = (int)$ttl;

				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key) use (&$store) {
				unset($store[$key]);

				return true;
			}
		);

		return $cache;
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function person(string $id, string $publicKey = '', string $privateKey = ''): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPublicKey($publicKey);
		$person->setPrivateKey($privateKey);

		return $person;
	}

	public function testGenerateKeysGivesTheActorAMatchingRsaPair(): void {
		$actor = new Person();

		$this->service->generateKeys($actor);

		$this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $actor->getPrivateKey());
		$this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $actor->getPublicKey());
		$details = openssl_pkey_get_details(openssl_pkey_get_private($actor->getPrivateKey()));
		$this->assertSame(2048, $details['bits']);
		$this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
		$this->assertSame($actor->getPublicKey(), $details['key'], 'public key belongs to the private key');
	}

	public function testSignRequestAddsDigestDateHostContentLengthAndSignature(): void {
		$url = 'https://remote.example/users/bob/inbox';
		$body = (string)json_encode(
			['type' => 'Create', 'id' => 'https://cloud.example.com/apps/social/@alice/1'],
			JSON_UNESCAPED_SLASHES
		);
		$queue = new RequestQueue('{}', new InstancePath($url, InstancePath::TYPE_INBOX), self::LOCAL_ACTOR);
		$this->actorsRequest->expects($this->once())
			->method('getFromId')
			->with(self::LOCAL_ACTOR)
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey));

		$headers = $this->service->signRequest($url, $body, $queue);

		$this->assertSame((string)strlen($body), $headers['content-length']);
		$this->assertSame('remote.example', $headers['host']);
		$this->assertSame('SHA-256=' . base64_encode(hash('sha256', $body, true)), $headers['digest']);
		$this->assertNotFalse(DateTime::createFromFormat(SignatureService::DATE_HEADER, $headers['date']));
		$this->assertEqualsWithDelta(time(), (new DateTime($headers['date']))->getTimestamp(), 5);

		$this->assertMatchesRegularExpression(
			'/^keyId="' . preg_quote(self::LOCAL_ACTOR, '/') . '#main-key",algorithm="rsa-sha256",'
			. 'headers="\(request-target\) content-length date host digest",signature="[A-Za-z0-9+\/=]+"$/',
			$headers['Signature'],
		);

		preg_match('/signature="([^"]+)"/', $headers['Signature'], $m);
		$signingString = implode("\n", [
			'(request-target): post /users/bob/inbox',
			'content-length: ' . strlen($body),
			'date: ' . $headers['date'],
			'host: remote.example',
			'digest: ' . $headers['digest'],
		]);
		$this->assertSame(1, openssl_verify($signingString, base64_decode($m[1], true), self::$publicKey, OPENSSL_ALGO_SHA256));
	}

	/**
	 * Build the headers of an HTTP-signed inbox POST, the way a remote server would send them.
	 *
	 * @return array<string, string> lower-cased header names
	 */
	private function signedHeaders(
		string $body,
		string $privateKey,
		array $overrides = [],
		string $headerList = '(request-target) host date digest content-length',
		string $algorithm = 'rsa-sha256',
		string $keyId = self::REMOTE_KEY_ID,
	): array {
		$headers = array_merge([
			'date' => gmdate(SignatureService::DATE_HEADER),
			'host' => self::CLOUD_HOST,
			'digest' => 'SHA-256=' . base64_encode(hash('sha256', $body, true)),
			'content-length' => (string)strlen($body),
		], $overrides);

		$lines = [];
		foreach (explode(' ', $headerList) as $key) {
			$lines[] = $key === '(request-target)' ? '(request-target): post /apps/social/@alice/inbox' : $key . ': ' . $headers[$key];
		}
		openssl_sign(implode("\n", $lines), $signed, $privateKey, $algorithm === 'rsa-sha512' ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA256);

		$headers['signature'] = sprintf(
			'keyId="%s",algorithm="%s",headers="%s",signature="%s"',
			$keyId,
			$algorithm,
			$headerList,
			base64_encode($signed),
		);

		return $headers;
	}

	private function incomingRequest(array $headers): IRequest|MockObject {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => $headers[strtolower($name)] ?? '');
		$request->method('getMethod')->willReturn('POST');
		$request->method('getRequestUri')->willReturn('/apps/social/@alice/inbox');
		$request->method('getServerProtocol')->willReturn('https');

		return $request;
	}

	public function testCheckRequestAcceptsAValidSignatureAndReturnsTheKeyOrigin(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::REMOTE_ACTOR, false)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$time = 0;
		$origin = $this->service->checkRequest($this->incomingRequest($headers), $body, $time);

		$this->assertSame('remote.example', $origin);
		$this->assertSame((new DateTime($headers['date']))->getTimestamp(), $time);
	}

	/** The access list refuses the signer's host. */
	private function refuseRemoteHost(): void {
		$this->fediverseService->method('authorized')
			->willReturnCallback(static function (string $host): bool {
				if ($host === 'remote.example') {
					throw new UnauthorizedFediverseException('Unauthorized Fediverse');
				}

				return true;
			});
	}

	public function testCheckRequestRefusesABlockedInstanceBeforeFetchingItsKey(): void {
		$this->refuseRemoteHost();
		$body = '{"type":"Follow"}';
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->checkRequest($this->incomingRequest($this->signedHeaders($body, self::$privateKey)), $body);
	}

	public function testCheckRequestRefusesABlockedInstanceSigningWithRfc9421BeforeFetchingItsKey(): void {
		$this->refuseRemoteHost();
		$body = '{"type":"Follow"}';
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->checkRequest($this->incomingRequest($this->messageSignedHeaders($body, self::$privateKey)), $body);
	}

	public function testCheckObjectRefusesABlockedActorHostBeforeFetchingItsKey(): void {
		$this->registerContextCache();
		$this->refuseRemoteHost();
		$received = $this->receivedSignedNote();
		$received->setActorId(self::REMOTE_ACTOR);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->checkObject($received);
	}

	public function testAnInstanceOnANonDefaultPortVerifiesTheHostWithItsPort(): void {
		// a peer signs the Host it connected to, port included
		$this->cloudAuthority = self::CLOUD_HOST . ':8443';
		$this->rebuildWith(null);
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['host' => self::CLOUD_HOST . ':8443']);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testTheConfiguredPortIsNotDroppedToVerifyAHostWithout(): void {
		// the substitution still binds the signature to this instance
		$this->cloudAuthority = self::CLOUD_HOST . ':8443';
		$this->rebuildWith(null);
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->expectException(SignatureException::class);

		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestAcceptsRsaSha512(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [], '(request-target) host date digest', 'rsa-sha512');
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	/** a signature that is not base64 is a bad signature, not an error in the server */
	public function testCheckRequestRejectsASignatureThatIsNotBase64(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$headers['signature'] = preg_replace('/signature="[^"]*"/', 'signature="not*base64!"', $headers['signature']);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('signature is not base64');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsATamperedBody(): void {
		$headers = $this->signedHeaders('{"type":"Follow"}', self::$privateKey);
		$tampered = '{"type":"Delete"}';
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('digest does not match the body');
		$this->service->checkRequest($this->incomingRequest($headers), $tampered);
	}

	/**
	 * A sender using chunked transfer encoding sends no Content-Length at all.
	 * Comparing the body against `(int)''` rejected every one of them.
	 */
	public function testCheckRequestAcceptsARequestWithoutContentLength(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body, self::$privateKey, ['content-length' => ''], '(request-target) host date digest'
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public static function digestVariantProvider(): array {
		$body = '{"type":"Follow"}';
		$sha256 = base64_encode(hash('sha256', $body, true));
		$sha512 = base64_encode(hash('sha512', $body, true));

		return [
			'RFC 3230, upper case' => ['SHA-256=' . $sha256],
			'lower case algorithm' => ['sha-256=' . $sha256],
			'no hyphen' => ['SHA256=' . $sha256],
			'several algorithms' => ['SHA-256=' . $sha256 . ',SHA-512=' . $sha512],
			'sha-512 only' => ['SHA-512=' . $sha512],
			'an algorithm we do not know, alongside one we do'
				=> ['id-sha-3=deadbeef,SHA-256=' . $sha256],
			'spaces around the list separator' => ['SHA-512=' . $sha512 . ', SHA-256=' . $sha256],
		];
	}

	#[DataProvider('digestVariantProvider')]
	public function testCheckRequestAcceptsEveryDigestFormOnTheWire(string $digest): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['digest' => $digest]);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	/** RFC 9530: what newer implementations are moving to. */
	public function testCheckRequestAcceptsAContentDigestOnItsOwn(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body,
			self::$privateKey,
			[
				'digest' => '',
				'content-digest' => 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':',
			],
			'(request-target) host date content-digest'
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestRejectsADigestWeCannotCompute(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['digest' => 'id-sha-3=deadbeef']);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('no digest algorithm we can compute');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAMissingDigest(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['digest' => '']);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('no digest header');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsASecondDigestThatDoesNotMatch(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [
			'digest' => 'SHA-256=' . base64_encode(hash('sha256', $body, true))
				. ',SHA-512=' . base64_encode(hash('sha512', 'something else', true)),
		]);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('digest does not match the body');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/**
	 * hs2019 names no hash: the key type decides, and for the RSA keys actors
	 * publish that is SHA-256.
	 */
	public function testCheckRequestAcceptsHs2019OverAnRsaKey(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body, self::$privateKey, [], '(request-target) host date digest', 'hs2019'
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	/**
	 * Mapping an unknown algorithm to sha256 made an Ed25519 signature fail as
	 * "signature cannot be checked", which describes nothing.
	 */
	public function testCheckRequestNamesAnAlgorithmItCannotVerify(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body, self::$privateKey, [], '(request-target) host date digest', 'ed25519'
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('unsupported signature algorithm: ed25519');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAWrongContentLength(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['content-length' => '3']);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('content-length');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAnExpiredDate(): void {
		$body = '{"type":"Follow"}';
		$expired = gmdate(SignatureService::DATE_HEADER, time() - SignatureService::DATE_PAST - 30);
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => $expired]);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('too old');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAFutureDate(): void {
		// without the upper bound, a request stamped into the future would stay
		// replayable until that date finally became "too old"
		$body = '{"type":"Follow"}';
		$future = gmdate(SignatureService::DATE_HEADER, time() + SignatureService::DATE_FUTURE + 30);
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => $future]);

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('from the future');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testARequestSignedTenMinutesAgoIsAccepted(): void {
		// a clock six minutes off, or a queue that took a while, used to lose
		// the delivery for good
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => gmdate(SignatureService::DATE_HEADER, time() - 600)]);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testARequestIsReplayedOnlyOnceItWasRemembered(): void {
		$headers = $this->signedHeaders('{"type":"Follow"}', self::$privateKey);
		$request = $this->incomingRequest($headers);
		$other = $this->incomingRequest($this->signedHeaders('{"type":"Undo"}', self::$privateKey));

		$this->assertFalse($this->service->isReplayed($request));
		$this->service->rememberRequest($request);

		$this->assertTrue($this->service->isReplayed($request));
		$this->assertFalse($this->service->isReplayed($other), 'another signature is another request');
	}

	public function testCheckRequestRejectsAMissingDate(): void {
		// an absent Date would parse as "now" and never age out
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$headers['date'] = '';

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('missing date');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/** @return array<string, array{string, int|null}> */
	public static function httpDates(): array {
		return [
			'IMF-fixdate' => ['Thu, 08 Oct 2026 13:15:31 GMT', 1791465331],
			'single-digit day' => ['Thu, 8 Oct 2026 13:15:31 GMT', 1791465331],
			'UTC for GMT' => ['Thu, 08 Oct 2026 13:15:31 UTC', 1791465331],
			'wrong weekday is not a different date' => ['Mon, 08 Oct 2026 13:15:31 GMT', 1791465331],
			'now' => ['now', null],
			'relative' => ['+30 min', null],
			'relative with a date in front' => ['Thu, 08 Oct 2026 13:15:31 GMT +1 day', null],
			'RFC 850' => ['Thursday, 08-Oct-26 13:15:31 GMT', null],
			'asctime' => ['Thu Oct  8 13:15:31 2026', null],
			'ISO 8601' => ['2026-10-08T13:15:31Z', null],
			'impossible date' => ['Thu, 31 Feb 2026 13:15:31 GMT', null],
			'impossible hour' => ['Thu, 08 Oct 2026 25:15:31 GMT', null],
			'local zone' => ['Thu, 08 Oct 2026 13:15:31 CEST', null],
		];
	}

	#[DataProvider('httpDates')]
	public function testParseHttpDate(string $header, ?int $expected): void {
		$this->assertSame($expected, SignatureService::parseHttpDate($header));
	}

	public function testCheckRequestRejectsARelativeDate(): void {
		// `now` never ages out, so the request stays replayable once the
		// replay record has expired
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => 'now']);

		$this->expectException(DateTimeException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testARejectedDateIsQuotedEscapedAndShortened(): void {
		$body = '{"type":"Follow"}';
		$date = "now\x1b[31m" . str_repeat('A', 5000);
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => $date]);

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('an unparsable date was accepted');
		} catch (DateTimeException $e) {
			$this->assertStringContainsString('now\033[31m', $e->getMessage());
			$this->assertStringNotContainsString("\x1b", $e->getMessage());
			$this->assertLessThan(400, strlen($e->getMessage()));
			$this->assertStringContainsString('more bytes', $e->getMessage());
		}
	}

	public function testAFailedSignatureNamesTheKeyAndTheBaseButNotTheRawBytes(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$otherPublicKey));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('a signature from another key was accepted');
		} catch (SignatureException $e) {
			$message = $e->getMessage();
			$this->assertStringContainsString(self::REMOTE_KEY_ID, $message);
			$this->assertStringContainsString('(request-target): post /apps/social/@alice/inbox\\nhost: ', $message);
			$this->assertStringNotContainsString("\n", $message);
			$this->assertStringNotContainsString('BEGIN PUBLIC KEY', $message);
			$this->assertTrue(mb_check_encoding($message, 'ASCII'), 'no raw signature bytes');
		}
	}

	public function testAMismatchedDigestIsQuotedShortened(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['digest' => 'SHA-256=' . str_repeat('x', 5000)]);

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('a wrong digest was accepted');
		} catch (SignatureException $e) {
			$this->assertStringContainsString('digest does not match', $e->getMessage());
			$this->assertLessThan(400, strlen($e->getMessage()));
		}
	}

	public function testCheckRequestAcceptsADateWithASingleDigitDay(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => gmdate('D, j M Y H:i:s \G\M\T')]);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestRejectsAnUnparsableDate(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, ['date' => 'not a date']);

		$this->expectException(DateTimeException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAMissingSignatureHeader(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		unset($headers['signature']);

		$this->expectException(MalformedArrayException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRefreshesTheKeyOnceThenRefusesABadSignature(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$lookups = [];
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->willReturnCallback(function (...$args) use (&$lookups) {
				$lookups[] = $args;

				return $this->person(self::REMOTE_ACTOR, self::$otherPublicKey);
			});

		// A signature that does not verify against either the cached or the refreshed
		// key is refused here, rather than being returned as an empty origin for a
		// later check to reject.
		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('a signature matching neither key must be refused');
		} catch (SignatureException) {
		}

		// the second lookup must bypass the cache, or the refresh is not a refresh
		$this->assertSame([[self::REMOTE_ACTOR, false], [self::REMOTE_ACTOR, true]], $lookups);
	}

	public function testCheckRequestAcceptsAfterRefreshingAStaleCachedKey(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->willReturnOnConsecutiveCalls(
				$this->person(self::REMOTE_ACTOR, self::$otherPublicKey),
				$this->person(self::REMOTE_ACTOR, self::$publicKey),
			);

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestWithAnUnknownActorIsRefused(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestContentException('not found', 404));

		// The signing key cannot be fetched, so the request cannot be verified and is
		// refused here rather than proceeding with an empty origin.
		$this->expectException(SignatureException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestSignalsAGoneActor(): void {
		$body = '{"type":"Delete"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestContentException('gone', 410));

		$this->expectException(SignatureIsGoneException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	#[DataProvider('incompleteSignedHeaderSets')]
	public function testCheckRequestRefusesASignatureThatDoesNotCoverEveryMandatoryHeader(string $headerList): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [], $headerList);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		// (request-target), host, date and digest must all be in the signed set, so the
		// signature binds the body and cannot be replayed elsewhere.
		$this->expectException(SignatureException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public static function incompleteSignedHeaderSets(): array {
		return [
			'missing (request-target)' => ['host date digest'],
			'missing host' => ['(request-target) date digest'],
			'missing date' => ['(request-target) host digest'],
			'missing digest' => ['(request-target) host date'],
		];
	}

	public function testCheckRequestRejectsAKeyIdWithoutHost(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey, [], '(request-target) host date digest', 'rsa-sha256', 'main-key');

		$this->expectException(InvalidOriginException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestAcceptsAFullySignedRequestFromAKnownActor(): void {
		// Guard proving the tightened checks did not break legitimate federation: a
		// request that signs every mandatory header — (request-target), host, date and
		// digest — plus content-length with a key that verifies is still accepted and
		// yields the key's origin.
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders(
			$body,
			self::$privateKey,
			[],
			'(request-target) host date digest content-length',
		);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::REMOTE_ACTOR, false)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$time = 0;
		$origin = $this->service->checkRequest($this->incomingRequest($headers), $body, $time);

		$this->assertSame('remote.example', $origin);
		$this->assertSame((new DateTime($headers['date']))->getTimestamp(), $time);
	}

	// RFC 9421 HTTP Message Signatures

	/**
	 * Build the headers of an RFC 9421-signed inbox POST, the way Mastodon 4.4+
	 * sends one: `("@method" "@target-uri" "content-digest")` with `created`,
	 * `keyid` and `alg` as signature parameters. The signature base is written
	 * out here by hand, independently of the implementation.
	 *
	 * @param array<string, int|string|null> $params a null value omits the parameter
	 * @param array<string, string> $values derived component values, overriding the request's own
	 * @return array<string, string> lower-cased header names
	 */
	private function messageSignedHeaders(
		string $body,
		string $privateKey,
		array $overrides = [],
		array $components = ['@method', '@target-uri', 'content-digest'],
		array $params = [],
		string $label = 'sig1',
		array $values = [],
	): array {
		$headers = array_merge([
			'date' => gmdate(SignatureService::DATE_HEADER),
			'host' => self::CLOUD_HOST,
			'content-digest' => 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':',
			'content-length' => (string)strlen($body),
		], $overrides);

		$params = array_merge(
			['created' => time(), 'keyid' => self::REMOTE_KEY_ID, 'alg' => 'rsa-v1_5-sha256'],
			$params
		);
		$serialized = '(' . implode(' ', array_map(fn (string $c): string => '"' . $c . '"', $components)) . ')';
		foreach ($params as $key => $value) {
			if ($value !== null) {
				$serialized .= ';' . $key . '=' . (is_int($value) ? $value : '"' . $value . '"');
			}
		}

		$values = array_merge([
			'@method' => 'POST',
			'@target-uri' => 'https://' . self::CLOUD_HOST . '/apps/social/@alice/inbox',
			'@authority' => self::CLOUD_HOST,
			'@path' => '/apps/social/@alice/inbox',
		], $values);
		$lines = [];
		foreach ($components as $component) {
			$lines[] = '"' . $component . '": ' . ($values[$component] ?? $headers[$component]);
		}
		$lines[] = '"@signature-params": ' . $serialized;
		$base = implode("\n", $lines);

		if ($params['alg'] === 'rsa-pss-sha512') {
			$signed = RsaPssSigner::sign($base, $privateKey);
		} else {
			// an "ed25519" label is signed like this too: the point of that
			// test is that the label is refused before anything is verified
			openssl_sign($base, $signed, $privateKey, OPENSSL_ALGO_SHA256);
		}

		$headers['signature-input'] = $label . '=' . $serialized;
		$headers['signature'] = $label . '=:' . base64_encode($signed) . ':';

		return $headers;
	}

	public function testCheckRequestAcceptsAnRfc9421SignatureTheWayMastodonSendsIt(): void {
		$body = '{"type":"Follow"}';
		$created = time() - 5;
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri', 'content-digest'], ['created' => $created]);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::REMOTE_ACTOR, false)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$time = 0;
		$origin = $this->service->checkRequest($this->incomingRequest($headers), $body, $time);

		$this->assertSame('remote.example', $origin);
		$this->assertSame($created, $time, 'the signature\'s own created is the request time');
	}

	/** The RFC's alternative to @target-uri, and a covered `date` instead of `created`. */
	public function testCheckRequestAcceptsAnRfc9421SignatureOverAuthorityPathAndDate(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders(
			$body, self::$privateKey, [], ['@method', '@authority', '@path', 'date', 'content-digest'], ['created' => null]
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$time = 0;
		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body, $time));
		$this->assertSame((new DateTime($headers['date']))->getTimestamp(), $time);
	}

	public function testCheckRequestAcceptsAnRfc9421SignatureWithoutADateHeaderWhenCreatedIsSet(): void {
		// RFC 9421 senders are not obliged to send Date at all
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, ['date' => '']);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestAcceptsAnRfc9421RsaPssSha512Signature(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri', 'content-digest'], ['alg' => 'rsa-pss-sha512']);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestAcceptsAnRfc9421SignatureOverALegacyDigestHeader(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders(
			$body,
			self::$privateKey,
			['content-digest' => '', 'digest' => 'SHA-256=' . base64_encode(hash('sha256', $body, true))],
			['@method', '@target-uri', 'digest']
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestRefreshesTheKeyOnceThenRefusesABadRfc9421Signature(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey);
		$lookups = [];
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->willReturnCallback(function (...$args) use (&$lookups) {
				$lookups[] = $args;

				return $this->person(self::REMOTE_ACTOR, self::$otherPublicKey);
			});

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('a signature matching neither key must be refused');
		} catch (SignatureException $e) {
			$this->assertStringContainsString('signature cannot be checked', $e->getMessage());
		}

		// the second lookup must bypass the cache, or the refresh is not a refresh
		$this->assertSame([[self::REMOTE_ACTOR, false], [self::REMOTE_ACTOR, true]], $lookups);
	}

	public function testCheckRequestAcceptsAnRfc9421SignatureAfterRefreshingAStaleCachedKey(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->willReturnOnConsecutiveCalls(
				$this->person(self::REMOTE_ACTOR, self::$otherPublicKey),
				$this->person(self::REMOTE_ACTOR, self::$publicKey),
			);

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestRejectsAnRfc9421SignatureSignedForAnotherHost(): void {
		// the authority signed is what the sender addressed; the one verified is this instance
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders(
			$body, self::$privateKey, ['host' => 'other.example'], ['@method', '@target-uri', 'content-digest'], [], 'sig1',
			['@target-uri' => 'https://other.example/apps/social/@alice/inbox']
		);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('signature cannot be checked');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAnExpiredRfc9421Signature(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri', 'content-digest'], ['expires' => time() - 1]);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('expired');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/** @return array<string, array{int, string}> */
	public static function createdOutsideTheWindow(): array {
		return [
			'too old' => [-SignatureService::DATE_PAST - 30, 'too old'],
			'from the future' => [SignatureService::DATE_FUTURE + 30, 'from the future'],
		];
	}

	/**
	 * The same window the Date header gets on the draft-cavage path.
	 */
	#[DataProvider('createdOutsideTheWindow')]
	public function testCheckRequestRejectsAnRfc9421SignatureCreatedOutsideTheDateWindow(int $offset, string $message): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri', 'content-digest'], ['created' => time() + $offset]);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage($message);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAnRfc9421RequestWithAnUnparsableDateHeader(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, ['date' => 'not a date']);

		$this->expectException(DateTimeException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRefusesAnRfc9421SignatureThatDoesNotCoverTheDigestOfAPost(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri']);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('header is not signed: digest');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/** @return array<string, array{list<string>, array<string, mixed>, string}> */
	public static function incompleteCoveredComponentSets(): array {
		return [
			'missing @method' => [['@target-uri', 'content-digest'], [], 'component is not signed: @method'],
			'missing @target-uri' => [['@method', 'content-digest'], [], 'component is not signed: @target-uri'],
			'@authority without @path' => [['@method', '@authority', 'content-digest'], [], 'component is not signed: @target-uri'],
			'neither date nor created' => [['@method', '@target-uri', 'content-digest'], ['created' => null], 'header is not signed: date'],
		];
	}

	/**
	 * Mirrors the mandatory set of the draft-cavage path: what is not covered is
	 * not bound, so a captured request could be replayed elsewhere or later.
	 */
	#[DataProvider('incompleteCoveredComponentSets')]
	public function testCheckRequestRefusesAnRfc9421SignatureThatDoesNotCoverEveryMandatoryComponent(array $components, array $params, string $message): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], $components, $params);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage($message);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRejectsAnRfc9421RequestWhoseBodyDoesNotMatchTheContentDigest(): void {
		$headers = $this->messageSignedHeaders('{"type":"Follow"}', self::$privateKey);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('digest does not match the body');
		$this->service->checkRequest($this->incomingRequest($headers), '{"type":"Delete"}');
	}

	public function testCheckRequestWithAnUnknownRfc9421KeyIdIsRefusedAndNotFetchedAgainImmediately(): void {
		// the same bounded fetch and negative cache as the draft-cavage path
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with(self::REMOTE_ACTOR, false)
			->willThrowException(new RequestContentException('not found', 404));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the first attempt to fail');
		} catch (SignatureException $e) {
			$this->assertStringContainsString('signing key could not be retrieved', $e->getMessage());
		}

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the second attempt to be refused without a fetch');
		} catch (SignatureException $e) {
			$this->assertStringContainsString('too recently', $e->getMessage());
			$this->assertSame(\OCP\AppFramework\Http::STATUS_SERVICE_UNAVAILABLE, $e->getCode());
		}
	}

	public function testCheckRequestSignalsAGoneActorForAnRfc9421Signature(): void {
		$body = '{"type":"Delete"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestContentException('gone', 410));

		$this->expectException(SignatureIsGoneException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/**
	 * Signature-Input decides the scheme. A request that announces RFC 9421 is
	 * read as RFC 9421 even when its Signature header would have verified as a
	 * draft-cavage one.
	 */
	public function testCheckRequestReadsARequestWithSignatureInputAsRfc9421OnlyAndNeverFallsBackToCavage(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$headers['signature-input'] = 'sig1=("@method" "@target-uri" "content-digest");created=' . time() . ';keyid="' . self::REMOTE_KEY_ID . '"';
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRefusesAnEd25519Rfc9421SignatureByName(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri', 'content-digest'], ['alg' => 'ed25519']);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('unsupported signature algorithm: ed25519');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestUsesTheFirstRfc9421LabelItCanVerify(): void {
		// a sender that signs with several keys: the Ed25519 one is skipped, the RSA one used
		$body = '{"type":"Follow"}';
		$created = time();
		$ed = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri', 'content-digest'], ['created' => $created, 'alg' => 'ed25519'], 'sig-ed');
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri', 'content-digest'], ['created' => $created], 'sig-rsa');
		$headers['signature-input'] = $ed['signature-input'] . ', ' . $headers['signature-input'];
		$headers['signature'] = $ed['signature'] . ', ' . $headers['signature'];
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testCheckRequestRefusesAnRfc9421SignatureWithoutAKeyId(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey, [], ['@method', '@target-uri', 'content-digest'], ['keyid' => null]);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('no usable signature');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	public function testCheckRequestRefusesAnRfc9421SignatureWhoseLabelHasNoSignatureValue(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->messageSignedHeaders($body, self::$privateKey);
		$headers['signature'] = str_replace('sig1=', 'other=', $headers['signature']);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('no usable signature');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	// bounding the pre-authentication key fetch

	public function testAKeyAlreadyInTheLocalCacheIsNeverFetched(): void {
		// the whole point: a known peer's delivery costs a database read
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$cached = $this->createMock(CacheActorsRequest::class);
		$cached->method('getFromId')->with(self::REMOTE_ACTOR)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));
		$this->replaceLocalActorCache($cached);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertSame('remote.example', $this->service->checkRequest($this->incomingRequest($headers), $body));
	}

	public function testTheFetchOfAnUnknownKeyIsTimeBounded(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$seen = [];
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);
		$configService->method('getCloudAuthority')->willReturnCallback(fn (): string => $this->cloudAuthority);
		$configService->method('withRequestTimeout')->willReturnCallback(
			function (int $timeout, callable $action) use (&$seen) {
				$seen[] = $timeout;

				return $action();
			}
		);
		$this->rebuildWith($configService);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->service->checkRequest($this->incomingRequest($headers), $body);

		$this->assertSame([SignatureService::UNKNOWN_KEY_TIMEOUT], $seen);
	}

	public function testAKeyThatCannotBeResolvedIsNotFetchedAgainImmediately(): void {
		// a flood naming the same unresolvable keyId costs one fetch, not one
		// fetch per request
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->willThrowException(new RequestContentException('unreachable'));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the first attempt to fail');
		} catch (\Exception $e) {
		}

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('too recently');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/**
	 * Three seconds had to cover DNS, TCP, TLS and the peer rendering its actor
	 * JSON. A small self-hosted instance does not manage that on first contact,
	 * and every miss then poisoned the negative cache below — so a follow to it
	 * simply never completed.
	 */
	public function testTheFirstKeyFetchGetsABudgetARealPeerCanMeet(): void {
		$this->assertGreaterThanOrEqual(
			5, SignatureService::UNKNOWN_KEY_TIMEOUT,
			'Mastodon alone allows 5s just to connect'
		);
		// still bounded, and CurlService::retrieveObject() retries unsigned
		// after a 401/403, so a worker can be held for roughly twice this
		$this->assertLessThanOrEqual(10, SignatureService::UNKNOWN_KEY_TIMEOUT);
	}

	/**
	 * A host that was unreachable, or slower than the budget, has said nothing
	 * about its keyId: remembering that for the full failure interval is what
	 * kept a slow-but-honest peer from ever federating, since every delivery in
	 * the window was refused without a fetch.
	 */
	public function testAPeerThatCouldNotBeReachedIsRetriedSoon(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')
			->willThrowException(new RequestNetworkException('timeout'));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the fetch to fail');
		} catch (RequestNetworkException $e) {
		}

		$this->assertSame(
			[SignatureService::KEY_UNREACHABLE_TTL],
			array_values($this->cacheTtl),
			'an unreachable host is not held against its keyId for the full interval'
		);
	}

	public function testAnAnswerThatIsNotAUsableKeyIsRememberedForLonger(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')
			->willThrowException(new RequestContentException('404'));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the fetch to fail');
		} catch (\Exception $e) {
		}

		$this->assertSame(
			[SignatureService::KEY_FAILURE_TTL],
			array_values($this->cacheTtl),
			'the peer answered, and the answer is a fact about the keyId'
		);
	}

	public function testASuccessfulFetchLeavesNoAttemptBehind(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->service->checkRequest($this->incomingRequest($headers), $body);

		$this->assertSame([], $this->keyAttempts, 'the in-flight marker is dropped on success');
	}

	/**
	 * The backoff is this instance's own, and temporary: a peer told to give up
	 * on the delivery would lose it for good, so the refusal has to read as
	 * "later", which is what the inbox turns into a 503.
	 */
	public function testTheBackoffRefusalAsksForARedelivery(): void {
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		$this->cacheActorService->method('getFromId')
			->willThrowException(new RequestNetworkException('timeout'));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the fetch to fail');
		} catch (RequestNetworkException $e) {
		}

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the second attempt to be refused without a fetch');
		} catch (SignatureException $e) {
			$this->assertStringContainsString('too recently', $e->getMessage());
			$this->assertSame(\OCP\AppFramework\Http::STATUS_SERVICE_UNAVAILABLE, $e->getCode());
		}
	}

	public function testAForcedRefreshIsThrottledPerKeyId(): void {
		// verification failing is free to trigger from outside, and each failure
		// used to force another fetch of the same key
		$body = '{"type":"Follow"}';
		$headers = $this->signedHeaders($body, self::$privateKey);
		// known locally, but with a key the signature does not verify against
		$cached = $this->createStub(CacheActorsRequest::class);
		$cached->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$otherPublicKey));
		$this->replaceLocalActorCache($cached);
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with(self::REMOTE_ACTOR, true)
			->willReturn($this->person(self::REMOTE_ACTOR, self::$otherPublicKey));

		try {
			$this->service->checkRequest($this->incomingRequest($headers), $body);
			$this->fail('expected the signature not to verify');
		} catch (SignatureException $e) {
		}

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('too recently');
		$this->service->checkRequest($this->incomingRequest($headers), $body);
	}

	/** Rebuilds the service around a different local actor cache. */
	private function replaceLocalActorCache(CacheActorsRequest $cache): void {
		$this->cacheActorsRequest = $cache;
		$this->rebuildWith(null);
	}

	private function rebuildWith(?ConfigService $configService): void {
		if ($configService === null) {
			$configService = $this->createStub(ConfigService::class);
			$configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);
			$configService->method('getCloudAuthority')->willReturnCallback(fn (): string => $this->cloudAuthority);
			$configService->method('withRequestTimeout')
				->willReturnCallback(fn (int $timeout, callable $action) => $action());
		}

		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			fn (string $prefix): ICache => $this->arrayCache($this->keyAttempts)
		);

		$this->service = new SignatureService(
			$this->cacheActorService,
			$this->cacheActorsRequest,
			$configService,
			new HttpSignatureService(
				$this->actorsRequest, $this->createStub(InstanceActorService::class), new NullLogger()
			),
			$cacheFactory,
			new NullLogger(),
			$this->durableCache(),
			$this->fediverseService,
		);
	}

	#[DataProvider('allowedContexts')]
	public function testDocumentLoaderServesEachShippedContext(string $url): void {
		$this->assertInstanceOf(\stdClass::class, SignatureService::documentLoader($url));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function allowedContexts(): array {
		return array_map(
			fn (string $url): array => [$url],
			array_keys(SignatureService::LOCAL_CONTEXTS),
		);
	}

	/**
	 * A document's @context is remote input. Resolving an arbitrary URL would open it
	 * with a PHP stream wrapper (SSRF, `file://`, the cloud metadata endpoint) and let
	 * a substituted context change the bytes a signature is computed over. Only the
	 * three shipped contexts may resolve; every other URL must be refused, never
	 * fetched.
	 *
	 * NOTE: the fix constructs `new JsonLdException($msg)` with a single argument, but
	 * that constructor requires a second `$type` argument, so the refusal currently
	 * surfaces as an uncatchable \ArgumentCountError rather than the intended, catchable
	 * \JsonLdException. This assertion pins the security property (a non-allowlisted URL
	 * /**
	 * A document's @context is remote input; resolving one that is not shipped would
	 * mean opening an attacker-chosen URL. An unknown context is refused with a
	 * catchable JsonLdException (not a fatal), so verify() treats it as unverifiable.
	 */
	#[DataProvider('rejectedContexts')]
	public function testDocumentLoaderRefusesAnyUrlOutsideTheAllowlist(string $url): void {
		$this->expectException(\JsonLdException::class);
		SignatureService::documentLoader($url);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function rejectedContexts(): array {
		return [
			'remote https context' => ['https://evil.example/context'],
			'local file scheme' => ['file:///etc/passwd'],
			'cloud metadata endpoint' => ['http://169.254.169.254/'],
		];
	}

	/**
	 * JSON-LD normalization loads the @context documents through
	 * SignatureService::documentLoader, which reads them from the app data
	 * folder. Serve the copies shipped in context/ so nothing hits the network.
	 */
	private function registerContextCache(): void {
		$folder = $this->createStub(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function (string $name) {
			$path = __DIR__ . '/../../context/' . $name;
			if (!is_file($path)) {
				throw new NotFoundException($name);
			}
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getMTime')->willReturn(time());
			$file->method('getContent')->willReturn(file_get_contents($path));

			return $file;
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('context')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('social')->willReturn($appData);
		\OC::$server->register(IAppDataFactory::class, $factory);
	}

	private function note(): Note {
		$note = new Note();
		$note->setId('https://cloud.example.com/apps/social/@alice/1');
		$note->setActorId(self::LOCAL_ACTOR);
		$note->setAttributedTo(self::LOCAL_ACTOR);
		$note->setContent('<p>Hello fediverse</p>');
		$note->setPublished('2026-09-07T10:00:00Z');
		$note->setTo(ACore::CONTEXT_PUBLIC);

		return $note;
	}

	public function testSignObjectAttachesAnRsaSignature2017(): void {
		$this->registerContextCache();
		$actor = $this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey);
		$note = $this->note();

		$this->service->signObject($actor, $note);

		$this->assertTrue($note->hasSignature());
		$signature = $note->getSignature();
		$this->assertSame('RsaSignature2017', $signature->getType());
		$this->assertSame(self::LOCAL_ACTOR . '#main-key', $signature->getCreator());
		$this->assertNotFalse(DateTime::createFromFormat(SignatureService::DATE_OBJECT, $signature->getCreated()));
		$this->assertNotSame('', $signature->getSignatureValue());
		$this->assertNotFalse(base64_decode($signature->getSignatureValue(), true));

		$json = json_decode(json_encode($note), true);
		$this->assertContains(ACore::CONTEXT_SECURITY, $json['@context']);
		$this->assertSame($signature->getSignatureValue(), $json['signature']['signatureValue']);
	}

	public function testCheckObjectVerifiesASignedObjectAndStampsItsOrigin(): void {
		$this->registerContextCache();
		$signed = $this->note();
		$this->service->signObject($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey), $signed);
		$received = new Note();
		$received->setSource(json_encode($signed, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::LOCAL_ACTOR);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::LOCAL_ACTOR, false)
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey));

		$this->assertTrue($this->service->checkObject($received));
		$this->assertSame(self::CLOUD_HOST, $received->getOrigin());
		$this->assertSame(SignatureService::ORIGIN_SIGNATURE, $received->getOriginSource());
		$this->assertSame((new DateTime($signed->getSignature()->getCreated()))->getTimestamp(), $received->getOriginCreationTime());
	}

	public function testCheckObjectRejectsATamperedObjectAfterRefreshingTheKey(): void {
		$this->registerContextCache();
		$signed = $this->note();
		$this->service->signObject($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey), $signed);
		$json = json_decode(json_encode($signed, JSON_UNESCAPED_SLASHES), true);
		$json['content'] = '<p>Tampered</p>';
		$received = new Note();
		$received->setSource(json_encode($json, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::LOCAL_ACTOR);
		$lookups = [];
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromId')
			->willReturnCallback(function (...$args) use (&$lookups) {
				$lookups[] = $args;

				return $this->person(self::LOCAL_ACTOR, self::$publicKey);
			});

		$this->assertFalse($this->service->checkObject($received));
		$this->assertSame('', $received->getOrigin());
		// the second lookup must bypass the cache, or the refresh is not a refresh
		$this->assertSame([[self::LOCAL_ACTOR, false], [self::LOCAL_ACTOR, true]], $lookups);
	}

	public function testCheckObjectRejectsASignatureFromAnotherKey(): void {
		$this->registerContextCache();
		$signed = $this->note();
		$this->service->signObject($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey), $signed);
		$received = new Note();
		$received->setSource(json_encode($signed, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::LOCAL_ACTOR);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::LOCAL_ACTOR, self::$otherPublicKey));

		$this->assertFalse($this->service->checkObject($received));
	}

	public function testCheckObjectWithoutSignatureIsFalse(): void {
		$received = $this->note();
		$received->setSource(json_encode($received, JSON_UNESCAPED_SLASHES));
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertFalse($this->service->checkObject($received));
	}

	/** A note the way it arrives on the wire, signed with the class key pair. */
	private function receivedSignedNote(?string $created = null): Note {
		$signed = $this->note();
		if ($created === null) {
			$this->service->signObject($this->person(self::LOCAL_ACTOR, self::$publicKey, self::$privateKey), $signed);
		} else {
			// signObject() with a chosen creation time: `created` is part of what
			// is signed, so an expired-window test needs a genuine old signature
			$signature = new LinkedDataSignature();
			$signature->setPrivateKey(self::$privateKey);
			$signature->setType('RsaSignature2017');
			$signature->setCreator(self::LOCAL_ACTOR . '#main-key');
			$signature->setCreated($created);
			$signature->setObject(json_decode(json_encode($signed), true));
			$signature->sign();
			$signed->setSignature($signature);
		}

		$received = new Note();
		$received->setSource(json_encode($signed, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::LOCAL_ACTOR);

		return $received;
	}

	public function testCheckObjectRejectsAReplayedSignature(): void {
		$this->registerContextCache();
		$received = $this->receivedSignedNote();
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey));

		$this->assertTrue($this->service->checkObject($received), 'first delivery is accepted');
		$this->assertFalse($this->service->checkObject($received), 'the same signature must not verify twice');
	}

	public function testCheckObjectRejectsASignatureOutsideItsWindow(): void {
		$this->registerContextCache();
		$received = $this->receivedSignedNote(
			gmdate(SignatureService::DATE_OBJECT, time() - SignatureService::LD_WINDOW - 3600)
		);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey));

		$this->assertFalse($this->service->checkObject($received));
		$this->assertSame('', $received->getOrigin());
	}

	public function testCheckObjectRejectsARelativeCreated(): void {
		// `now` was always inside the window, so the signature never aged out
		$this->registerContextCache();
		$received = $this->receivedSignedNote('now');
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey));

		$this->expectException(DateTimeException::class);
		$this->service->checkObject($received);
	}

	/** @return array<string, array{string, int|null}> */
	public static function objectDates(): array {
		return [
			'Z' => ['2026-10-08T13:15:31Z', 1791465331],
			'fraction' => ['2026-10-08T13:15:31.123Z', 1791465331],
			'offset' => ['2026-10-08T15:15:31+02:00', 1791465331],
			'relative' => ['now', null],
			'relative offset' => ['+1 day', null],
			'no zone' => ['2026-10-08T13:15:31', null],
			'impossible hour' => ['2026-10-08T25:15:31Z', null],
		];
	}

	#[DataProvider('objectDates')]
	public function testParseObjectDate(string $created, ?int $expected): void {
		$this->assertSame($expected, SignatureService::parseObjectDate($created));
	}

	public function testSignRequestWithAnEmptyPrivateKeyFailsLoudly(): void {
		$url = 'https://remote.example/users/bob/inbox';
		$queue = new RequestQueue('{}', new InstancePath($url, InstancePath::TYPE_INBOX), self::LOCAL_ACTOR);
		$this->actorsRequest->method('getFromId')
			->willReturn($this->person(self::LOCAL_ACTOR, self::$publicKey, ''));

		// an undecryptable or missing key used to emit base64('') as the signature
		$this->expectException(SignatureException::class);

		$this->service->signRequest($url, '{"type":"Create"}', $queue);
	}

	// assertSignerSpeaksFor(): whose key it was, not merely which server

	public function testTheActorsOwnKeyMaySpeakForTheActivity(): void {
		$activity = new Note();
		$activity->setActorId('https://remote.example/users/alice');

		$this->service->assertSignerSpeaksFor('https://remote.example/users/alice', $activity);
		$this->addToAssertionCount(1);
	}

	public function testANeighbourOnTheSameServerMayNot(): void {
		// same origin, different person: the host check alone let mallory edit,
		// delete and post as alice
		$activity = new Note();
		$activity->setActorId('https://remote.example/users/alice');

		$this->expectException(InvalidOriginException::class);
		$this->service->assertSignerSpeaksFor('https://remote.example/users/mallory', $activity);
	}

	public function testATrailingSlashOrADifferentCaseIsStillTheSameActor(): void {
		$activity = new Note();
		$activity->setActorId('https://Remote.example/users/alice/');

		$this->service->assertSignerSpeaksFor('https://remote.example/users/alice', $activity);
		$this->addToAssertionCount(1);
	}

	public function testAnActivityWithNoActorIsRefused(): void {
		$this->expectException(InvalidOriginException::class);
		$this->service->assertSignerSpeaksFor('https://remote.example/users/alice', new Note());
	}

	public function testAnUnsignedRequestCannotSpeakForAnybody(): void {
		$activity = new Note();
		$activity->setActorId('https://remote.example/users/alice');

		$this->expectException(InvalidOriginException::class);
		$this->service->assertSignerSpeaksFor('', $activity);
	}

	// authorized fetch: the signature on a GET

	/**
	 * A GET as a peer running authorized fetch sends one: no body, so no
	 * digest and no content length, and `(request-target)` naming the object
	 * being fetched.
	 */
	private function signedGetHeaders(
		string $privateKey,
		array $overrides = [],
		string $headerList = '(request-target) host date',
		string $target = '/apps/social/@alice/abc123',
	): array {
		$headers = array_merge([
			'date' => gmdate(SignatureService::DATE_HEADER),
			'host' => self::CLOUD_HOST,
		], $overrides);

		$lines = [];
		foreach (explode(' ', $headerList) as $key) {
			$lines[] = $key === '(request-target)'
				? '(request-target): get ' . $target
				: $key . ': ' . $headers[$key];
		}
		openssl_sign(implode("\n", $lines), $signed, $privateKey, OPENSSL_ALGO_SHA256);

		$headers['signature'] = sprintf(
			'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
			self::REMOTE_KEY_ID, $headerList, base64_encode($signed)
		);

		return $headers;
	}

	private function incomingGet(array $headers, string $target = '/apps/social/@alice/abc123'): IRequest|MockObject {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name) => $headers[strtolower($name)] ?? ''
		);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getRequestUri')->willReturn($target);
		$request->method('getServerProtocol')->willReturn('https');

		return $request;
	}

	/**
	 * Signature verification ran on inbox POSTs only, so this instance could
	 * not tell one remote reader from another.
	 */
	public function testCheckGetRequestNamesWhoSignedAFetch(): void {
		$headers = $this->signedGetHeaders(self::$privateKey);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$signer = '';
		$origin = $this->service->checkGetRequest($this->incomingGet($headers), $signer);

		$this->assertSame('remote.example', $origin);
		$this->assertSame(self::REMOTE_ACTOR, $signer);
	}

	/**
	 * The ordinary case — every crawler, every link preview, every server not
	 * running authorized fetch — and it is not an error.
	 */
	public function testCheckGetRequestIsQuietForAnUnsignedFetch(): void {
		$signer = '';

		$this->assertSame('', $this->service->checkGetRequest($this->incomingGet([]), $signer));
		$this->assertSame('', $signer);
	}

	/** A GET has no body, so there is no digest for the signature to bind. */
	public function testCheckGetRequestDoesNotAskForADigest(): void {
		$headers = $this->signedGetHeaders(self::$privateKey);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->assertArrayNotHasKey('digest', $headers);
		$this->assertSame('remote.example', $this->service->checkGetRequest($this->incomingGet($headers)));
	}

	/**
	 * A signature that is present and wrong is an error: the sender is
	 * claiming to be somebody.
	 */
	public function testCheckGetRequestRefusesASignatureFromAnotherKey(): void {
		$headers = $this->signedGetHeaders(self::$privateKey);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$otherPublicKey));

		$this->expectException(SignatureException::class);

		$this->service->checkGetRequest($this->incomingGet($headers));
	}

	/**
	 * Without a date inside the signature, a captured fetch can be replayed
	 * for as long as the key lives.
	 */
	public function testCheckGetRequestRefusesASignatureThatCoversNoDate(): void {
		$headers = $this->signedGetHeaders(
			self::$privateKey, [], '(request-target) host'
		);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('date');

		$this->service->checkGetRequest($this->incomingGet($headers));
	}

	/** And a date outside the replay window is refused on its own. */
	public function testCheckGetRequestRefusesAStaleDate(): void {
		$headers = $this->signedGetHeaders(
			self::$privateKey, ['date' => gmdate(SignatureService::DATE_HEADER, time() - 86400)]
		);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->expectException(SignatureException::class);

		$this->service->checkGetRequest($this->incomingGet($headers));
	}

	/** A captured fetch must not be replayable against another instance. */
	public function testCheckGetRequestRefusesASignatureThatCoversNoTarget(): void {
		$headers = $this->signedGetHeaders(self::$privateKey, [], 'host date');
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::REMOTE_ACTOR, self::$publicKey));

		$this->expectException(SignatureException::class);
		$this->expectExceptionMessage('(request-target)');

		$this->service->checkGetRequest($this->incomingGet($headers));
	}
}
