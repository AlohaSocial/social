<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActorCascadeService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\AuthorizedFetchService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FeedService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\InboxLimiter;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StoryService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who a signed delivery speaks for, decided by the real `SignatureService`.
 *
 * The controller tests stub `checkRequest()`, and the signature tests stop at
 * the origin it returns, so nothing else follows the signer out-parameter
 * from the keyId of a real signature to the actor check in the inbox. These
 * do, for both signature schemes: a signed POST goes through
 * `ActivityPubController::inbox()` / `sharedInbox()` with only the key
 * lookup and the activity parser stood in for. Two accounts share one host
 * here, which is the case the host-level origin check cannot tell apart.
 */
#[AllowMockObjectsWithoutExpectations]
class InboxSignerBindingTest extends TestCase {
	private const CLOUD_HOST = 'cloud.example.com';
	private const BOB = 'https://remote.example/users/bob';
	private const MALLORY = 'https://remote.example/users/mallory';

	/** @var array<string, array{string, string}> private and public PEM, by actor */
	private static array $keys = [];

	private string $requestUri = '/apps/social/@alice/inbox';
	/** @var array<string, string> lower-cased request headers */
	private array $headers = [];
	private string $body = '';
	/** @var list<ACore> what reached the activity handlers */
	private array $parsed = [];
	/** @var list<string> objects queued to be fetched from their origin */
	private array $fetched = [];

	private SignatureService $signatureService;
	private ImportService&MockObject $importService;
	private ActivityPubController $controller;

	public static function setUpBeforeClass(): void {
		foreach ([self::BOB, self::MALLORY] as $actor) {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			openssl_pkey_export($key, $private);
			self::$keys[$actor] = [$private, openssl_pkey_get_details($key)['key']];
		}
	}

	protected function setUp(): void {
		$this->parsed = [];
		$this->fetched = [];

		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[strtolower($name)] ?? '');
		$request->method('getMethod')->willReturn('POST');
		$request->method('getRequestUri')->willReturnCallback(fn (): string => $this->requestUri);
		$request->method('getServerProtocol')->willReturn('https');
		\OC::$server->register(IRequest::class, $request);

		$fediverse = $this->createStub(FediverseService::class);
		$fediverse->method('authorized')->willReturn(true);

		$this->signatureService = $this->signatureService($fediverse);

		$this->importService = $this->createMock(ImportService::class);
		$this->importService->method('importFromJson')->willReturnCallback(
			fn (string $json): ACore => self::activityFrom($json)
		);
		$this->importService->method('parseIncomingRequest')->willReturnCallback(
			function (ACore $activity): void {
				$this->parsed[] = $activity;
			}
		);

		$limiter = $this->createStub(InboxLimiter::class);
		$limiter->method('readBody')->willReturnCallback(fn (): string => $this->body);

		$cacheActorService = $this->createStub(CacheActorService::class);
		$cacheActorService->method('getFromLocalAccount')->willReturn(new Person());

		$queue = $this->createStub(StreamQueueService::class);
		$queue->method('queueFetch')->willReturnCallback(function (string $token, string $id): bool {
			$this->fetched[] = $id;

			return true;
		});

		$config = $this->createStub(ConfigService::class);
		$config->method('getCloudHost')->willReturn(self::CLOUD_HOST);

		$this->controller = new class($request, $this->createStub(SocialPubController::class), $fediverse, $cacheActorService, $this->signatureService, $queue, $this->importService, $limiter, $this->createStub(AccountService::class), $this->createStub(FollowService::class), $this->createStub(StreamService::class), $this->createStub(StreamRequest::class), $this->createStub(PinService::class), $this->createStub(InstanceActorService::class), $this->createStub(AuthorizedFetchService::class), $this->createStub(StoryService::class), $this->createStub(FeedService::class), $this->createStub(QuoteGrantRequest::class), $this->createStub(\OCP\IConfig::class), $config, new NullLogger()) extends ActivityPubController {
			public function async(string $result = ''): void {
			}
		};
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/** @return array<string, array{string}> */
	public static function schemes(): array {
		return ['draft-cavage' => ['cavage'], 'RFC 9421' => ['rfc9421']];
	}

	/** The out-parameter is the key's owner: the keyId without its fragment. */
	#[DataProvider('schemes')]
	public function testTheSignerOfASignedPostIsTheOwnerOfTheKey(string $scheme): void {
		$this->deliver($scheme, self::MALLORY, $this->follow(self::MALLORY));

		$time = 0;
		$signer = '';
		$origin = $this->signatureService->checkRequest($this->request(), $this->body, $time, $signer);

		$this->assertSame('remote.example', $origin);
		$this->assertSame(self::MALLORY, $signer);
		$this->assertGreaterThan(0, $time);
	}

	#[DataProvider('schemes')]
	public function testAnActivityOfTheSignerItselfIsTakenIn(string $scheme): void {
		$this->deliver($scheme, self::BOB, $this->follow(self::BOB));

		$response = $this->controller->inbox('alice');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $this->parsed);
		$this->assertSame(self::BOB, $this->parsed[0]->getActorId());
		$this->assertSame('remote.example', $this->parsed[0]->getOrigin());
	}

	/**
	 * Mallory holds a valid key on bob's server. The origin matches, so only
	 * the signer binding stands between her and following as bob.
	 */
	#[DataProvider('schemes')]
	public function testANeighbourOnTheSameHostCannotSpeakForAnotherAccount(string $scheme): void {
		$this->deliver($scheme, self::MALLORY, $this->follow(self::BOB));

		$response = $this->controller->inbox('alice');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->parsed);
	}

	#[DataProvider('schemes')]
	public function testTheSharedInboxHoldsTheSignerToTheActivityToo(string $scheme): void {
		$this->requestUri = '/apps/social/inbox';
		$this->deliver($scheme, self::MALLORY, $this->follow(self::BOB));

		$response = $this->controller->sharedInbox();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->parsed);
	}

	/**
	 * An Update of bob's profile that a neighbour signed is not applied from
	 * the body she wrote: it is treated as forwarded, and bob's profile is
	 * fetched from bob's own document instead.
	 */
	#[DataProvider('schemes')]
	public function testANeighboursUpdateOfAProfileIsRefetchedNotApplied(string $scheme): void {
		$this->deliver($scheme, self::MALLORY, $this->updateOf(self::BOB, self::BOB));

		$response = $this->controller->inbox('alice');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame([], $this->parsed);
		$this->assertSame([self::BOB], $this->fetched);
	}

	/**
	 * The other way round: the neighbour signs as herself and names herself
	 * as the actor, with bob's profile as the object. The signer binding
	 * passes, so the person handler's actor check is what refuses it.
	 */
	#[DataProvider('schemes')]
	public function testANeighbourSigningAsHerselfStillCannotUpdateAnotherProfile(string $scheme): void {
		$this->deliver($scheme, self::MALLORY, $this->updateOf(self::MALLORY, self::BOB));
		$this->controller->inbox('alice');
		$this->assertCount(1, $this->parsed, 'the signer binding let the activity through');
		$update = $this->parsed[0];
		$this->assertSame(self::MALLORY, $update->getActorId());

		$actorService = $this->createMock(ActorService::class);
		$actorService->expects($this->never())->method('update');
		$actorService->expects($this->never())->method('save');
		$person = new PersonInterface(
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(StreamRequest::class),
			$this->createStub(StreamDestRequest::class),
			$actorService,
			$this->createStub(ActorCascadeService::class),
			$this->createStub(IJobList::class),
		);

		$this->expectException(InvalidOriginException::class);

		$person->activity($update, $update->getObject());
	}

	private function follow(string $actor): array {
		return [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $actor . '#follows/1',
			'type' => 'Follow',
			'actor' => $actor,
			'object' => 'https://' . self::CLOUD_HOST . '/apps/social/@alice',
		];
	}

	private function updateOf(string $actor, string $profile): array {
		return [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $actor . '#updates/1',
			'type' => 'Update',
			'actor' => $actor,
			'object' => ['id' => $profile, 'type' => 'Person', 'preferredUsername' => 'bob'],
		];
	}

	/**
	 * The activity a parser would make of the body, with only what the inbox
	 * and the person handler read.
	 */
	private static function activityFrom(string $json): ACore {
		$data = json_decode($json, true);
		if ($data['type'] === 'Update') {
			$activity = new Update();
			$profile = new Person();
			$profile->setId($data['object']['id']);
			$activity->setObject($profile);
		} else {
			$activity = new Follow();
			$activity->setObjectId($data['object']);
		}

		$activity->setId($data['id']);
		$activity->setActorId($data['actor']);
		$activity->setSource($json);

		return $activity;
	}

	/** Signs the activity as the given actor with the given scheme. */
	private function deliver(string $scheme, string $signer, array $activity): void {
		$this->body = (string)json_encode($activity, JSON_UNESCAPED_SLASHES);
		$keyId = $signer . '#main-key';
		$private = self::$keys[$signer][0];
		$headers = [
			'host' => self::CLOUD_HOST,
			'date' => gmdate(SignatureService::DATE_HEADER),
			'content-length' => (string)strlen($this->body),
		];

		if ($scheme === 'cavage') {
			$headers['digest'] = 'SHA-256=' . base64_encode(hash('sha256', $this->body, true));
			$list = '(request-target) host date digest';
			$base = '(request-target): post ' . $this->requestUri . "\nhost: " . $headers['host']
				. "\ndate: " . $headers['date'] . "\ndigest: " . $headers['digest'];
			openssl_sign($base, $signed, $private, OPENSSL_ALGO_SHA256);
			$headers['signature'] = sprintf(
				'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"', $keyId, $list, base64_encode($signed)
			);
		} else {
			$headers['content-digest'] = 'sha-256=:' . base64_encode(hash('sha256', $this->body, true)) . ':';
			$params = '("@method" "@target-uri" "content-digest");created=' . time() . ';keyid="' . $keyId . '";alg="rsa-v1_5-sha256"';
			$base = '"@method": POST' . "\n" . '"@target-uri": https://' . self::CLOUD_HOST . $this->requestUri
				. "\n" . '"content-digest": ' . $headers['content-digest'] . "\n" . '"@signature-params": ' . $params;
			openssl_sign($base, $signed, $private, OPENSSL_ALGO_SHA256);
			$headers['signature-input'] = 'sig1=' . $params;
			$headers['signature'] = 'sig1=:' . base64_encode($signed) . ':';
		}

		$this->headers = $headers;
	}

	private function request(): IRequest {
		return \OC::$server->get(IRequest::class);
	}

	/** The real service, with the key lookup answered from the two key pairs. */
	private function signatureService(FediverseService $fediverse): SignatureService {
		$cacheActorService = $this->createStub(CacheActorService::class);
		$cacheActorService->method('getFromId')->willReturnCallback(static function (string $id): Person {
			if (!isset(self::$keys[$id])) {
				throw new CacheActorDoesNotExistException();
			}
			$person = new Person();
			$person->setId($id);
			$person->setPublicKey(self::$keys[$id][1]);

			return $person;
		});
		$cacheActorsRequest = $this->createStub(CacheActorsRequest::class);
		$cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$config = $this->createStub(ConfigService::class);
		$config->method('getCloudHost')->willReturn(self::CLOUD_HOST);
		$config->method('getCloudAuthority')->willReturn(self::CLOUD_HOST);
		$config->method('withRequestTimeout')->willReturnCallback(fn (int $timeout, callable $action) => $action());

		$attempts = $this->createStub(ICache::class);
		$caches = $this->createStub(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($attempts);
		$caches->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static fn (): int => time());

		return new SignatureService(
			$cacheActorService,
			$cacheActorsRequest,
			$config,
			new HttpSignatureService($this->createStub(ActorsRequest::class), $this->createStub(InstanceActorService::class), new NullLogger()),
			$caches,
			new NullLogger(),
			new DurableCache($caches, new InMemoryDurableCacheRequest(), $time),
			$fediverse,
		);
	}
}
