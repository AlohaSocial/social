<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\RelationController;
use OCA\Social\Db\AccountNotesRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\DomainBlocksRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Relationship;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DomainBlockService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Tests\Mock\TCacheActorServiceMock;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The seven routes a client uses to block an instance, keep a note about an
 * account and feature one.
 *
 * Every row behind them belongs to exactly one account and is never anybody
 * else's business, so the contract checked here is as much about what a caller
 * is refused as about what they are given: the entity shape, the scopes, and —
 * on every route — that the answer is built out of the caller's own rows and
 * nobody else's. The services are the real ones, with only the database mocked
 * out, because "whose rows" is decided between the two.
 */
#[AllowMockObjectsWithoutExpectations]
class RelationControllerTest extends TestCase {
	use TCacheActorServiceMock;

	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';
	private const CAROL = 'https://remote.example/users/carol';

	/** @var IRequest&Stub */
	private $request;
	private AccountService|Stub $accountService;
	private CacheActorService|MockObject $cacheActorService;
	private ClientService|Stub $clientService;
	private FollowService|Stub $followService;
	private RelationshipService|MockObject $relationshipService;
	private IUserSession|Stub $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var array<string, string[]> actor id => blocked domains, newest first */
	private array $blocks = [];
	/** @var array<string, string> "author|subject" => note */
	private array $notes = [];
	/** @var array<string, bool> "actor|object|type" => true */
	private array $relations = [];
	/** @var array<string, bool> "follower|followed" => accepted */
	private array $follows = [self::ALICE . '|' . self::CAROL => true];
	/** @var array<int, array> every write, in order */
	private array $writes = [];
	/** the Nextcloud user the session is for */
	private string $uid = 'alice';
	private bool $csrf = true;

	/** @var array<string, string> user id => actor id */
	private const ACTORS = ['alice' => self::ALICE, 'bob' => self::BOB];

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/social/api/v1/domain_blocks');
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$this->userSession = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturnCallback(fn (): string => $this->uid);
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')
			->willReturnCallback(fn (string $userId): Person => $this->person(self::ACTORS[$userId], 1));

		$this->clientService = $this->createStub(ClientService::class);

		$this->cacheActorService = $this->cacheActorServiceMock();
		$this->cacheActorService->method('getFromNids')
			->willReturnCallback(function (array $nids): array {
				$byNid = [1 => self::ALICE, 2 => self::BOB, 3 => self::CAROL];
				$actors = [];
				foreach ($nids as $nid) {
					if (isset($byNid[$nid])) {
						$actors[] = $this->person($byNid[$nid], $nid);
					}
				}

				return $actors;
			});
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(fn (string $id): Person => $this->person($id, 3));
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $account): Person {
				throw new CacheActorDoesNotExistException('Record not found');
			});

		$this->followService = $this->createStub(FollowService::class);
		$this->followService->method('getRelationshipWith')
			->willReturnCallback(static fn (Person $target): Relationship => new Relationship($target->getNid()));

		$this->relationshipService = $this->createMock(RelationshipService::class);
		$this->relationshipService->method('getRelated')
			->willReturnCallback(function (Person $viewer, string $type, int $limit): array {
				$accounts = [];
				foreach ($this->relations as $key => $unused) {
					[$actorId, $objectId, $rowType] = explode('|', $key);
					if ($actorId === $viewer->getId() && $rowType === $type) {
						$accounts[] = $this->person($objectId, 3);
					}
				}

				return array_slice($accounts, 0, $limit);
			});

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function person(string $id, int $nid): Person {
		$person = new Person();
		$person->setId($id);
		$person->setNid($nid);

		return $person;
	}

	private function domainBlockService(): DomainBlockService {
		$request = $this->createStub(DomainBlocksRequest::class);
		$request->method('getByActor')
			->willReturnCallback(fn (string $actorId, int $limit): array
				=> array_slice($this->blocks[$actorId] ?? [], 0, $limit));
		$request->method('save')
			->willReturnCallback(function (string $actorId, string $domain): void {
				$this->writes[] = ['block', $actorId, $domain];
				$this->blocks[$actorId] ??= [];
				if (!in_array($domain, $this->blocks[$actorId], true)) {
					array_unshift($this->blocks[$actorId], $domain);
				}
			});
		$request->method('delete')
			->willReturnCallback(function (string $actorId, string $domain): void {
				$this->writes[] = ['unblock', $actorId, $domain];
				$this->blocks[$actorId] = array_values(array_diff($this->blocks[$actorId] ?? [], [$domain]));
			});

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialAddress')->willReturn('cloud.example');
		$configService->method('getCloudHost')->willReturn('cloud.example');

		return new DomainBlockService($request, $configService);
	}

	private function accountRelationService(DomainBlockService $domainBlockService): AccountRelationService {
		$notes = $this->createStub(AccountNotesRequest::class);
		$notes->method('save')
			->willReturnCallback(function (string $actorId, string $objectId, string $note): void {
				$this->writes[] = ['note', $actorId, $objectId, $note];
				$this->notes[$actorId . '|' . $objectId] = $note;
			});
		$notes->method('delete')
			->willReturnCallback(function (string $actorId, string $objectId): void {
				$this->writes[] = ['note-delete', $actorId, $objectId];
				unset($this->notes[$actorId . '|' . $objectId]);
			});
		$notes->method('getNote')
			->willReturnCallback(fn (string $actorId, string $objectId): string
				=> $this->notes[$actorId . '|' . $objectId] ?? '');

		$relations = $this->createStub(ActorRelationRequest::class);
		$relations->method('save')
			->willReturnCallback(function (string $actorId, string $objectId, string $type): void {
				$this->writes[] = ['endorse', $actorId, $objectId, $type];
				$this->relations[$actorId . '|' . $objectId . '|' . $type] = true;
			});
		$relations->method('delete')
			->willReturnCallback(function (string $actorId, string $objectId, string $type): void {
				$this->writes[] = ['unendorse', $actorId, $objectId, $type];
				unset($this->relations[$actorId . '|' . $objectId . '|' . $type]);
			});
		$relations->method('exists')
			->willReturnCallback(fn (string $actorId, string $objectId, string $type): bool
				=> isset($this->relations[$actorId . '|' . $objectId . '|' . $type]));

		$follows = $this->createStub(FollowsRequest::class);
		$follows->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $objectId): Follow {
				$key = $actorId . '|' . $objectId;
				if (!array_key_exists($key, $this->follows)) {
					throw new FollowNotFoundException('not following');
				}

				return (new Follow())->setAccepted($this->follows[$key]);
			});

		return new AccountRelationService(
			$notes,
			$relations,
			$follows,
			$this->createStub(MuteExpiryRequest::class),
			$domainBlockService,
			$this->relationshipService,
		);
	}

	/**
	 * The bearer token is parsed in the constructor, so a test that presents
	 * one has to say so before the controller exists.
	 */
	private function controller(string $authorization = ''): RelationController {
		// the getHeader() callback is registered once, in setUp(): a second
		// method() on the same mock never wins over the first
		$this->headers = ['Authorization' => $authorization];
		$domainBlockService = $this->domainBlockService();

		return new RelationController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->cacheActorService,
			$this->clientService,
			$this->followService,
			$this->accountRelationService($domainBlockService),
			$domainBlockService,
		);
	}

	private function token(array $scopes): SocialClient {
		$this->csrf = false;
		$client = new SocialClient();
		$client->setAuthUserId($this->uid);
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->willReturn($client);

		return $client;
	}

	public function testBlockingAnInstanceAnswersTheWayMastodonDoes(): void {
		$response = $this->controller()->blockDomain('remote.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([['block', self::ALICE, 'remote.example']], $this->writes);
	}

	public function testTheBlockedInstancesAreAFlatListOfDomains(): void {
		// Mastodon answers this route with strings, not entities
		$this->blocks[self::ALICE] = ['remote.example', 'other.example'];

		$response = $this->controller()->domainBlocks();

		$this->assertSame(['remote.example', 'other.example'], $response->getData());
	}

	public function testWhatIsTypedIsBlockedInTheFormTheTimelineCompares(): void {
		$this->controller()->blockDomain('@carol@Remote.Example');

		$this->assertSame([['block', self::ALICE, 'remote.example']], $this->writes);
	}

	public function testADomainThatIsNotOneIsRefusedAndNothingIsStored(): void {
		$response = $this->controller()->blockDomain('not a domain');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
		$this->assertSame([], $this->writes);
	}

	public function testBlockingTheInstanceTheAccountIsOnIsRefused(): void {
		$response = $this->controller()->blockDomain('cloud.example');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testUnblockingAnInstanceThatWasNotBlockedAnswersTheSame(): void {
		$response = $this->controller()->unblockDomain('remote.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testOneAccountsBlockListIsNeverAnothersAnswer(): void {
		$this->controller()->blockDomain('remote.example');

		$this->uid = 'bob';
		$response = $this->controller()->domainBlocks();

		$this->assertSame([], $response->getData());
	}

	public function testANoteIsKeptAndHandedBackOnTheRelationship(): void {
		$response = $this->controller()->note('3', 'met at a conference');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData()->jsonSerialize();
		$this->assertSame('met at a conference', $data['note']);
		$this->assertSame([['note', self::ALICE, self::CAROL, 'met at a conference']], $this->writes);
	}

	public function testAnEmptyCommentClearsTheNote(): void {
		$controller = $this->controller();
		$controller->note('3', 'met at a conference');
		$response = $controller->note('3', '');

		$this->assertSame('', $response->getData()->jsonSerialize()['note']);
		$this->assertSame([], $this->notes);
	}

	public function testOneAccountsNoteIsNeverAnothersRelationship(): void {
		$this->controller()->note('3', 'met at a conference');

		$this->uid = 'bob';
		$response = $this->controller()->note('3', '');

		$this->assertSame('', $response->getData()->jsonSerialize()['note']);
		$this->assertSame(
			'met at a conference',
			$this->notes[self::ALICE . '|' . self::CAROL],
			"bob's write did not reach alice's note"
		);
	}

	public function testFeaturingAnAccountAnswersARelationshipThatSaysSo(): void {
		$response = $this->controller()->pin('3');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()->jsonSerialize()['endorsed']);
		$this->assertSame([['endorse', self::ALICE, self::CAROL, 'endorse']], $this->writes);
	}

	public function testUnfeaturingAnAccountAnswersARelationshipThatSaysSo(): void {
		$controller = $this->controller();
		$controller->pin('3');
		$response = $controller->unpin('3');

		$this->assertFalse($response->getData()->jsonSerialize()['endorsed']);
		$this->assertSame([], $this->relations);
	}

	public function testFeaturingAnAccountTheViewerDoesNotFollowIsRefused(): void {
		$this->uid = 'bob';
		$response = $this->controller()->pin('3');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('Account must be followed', $response->getData()['error']);
		$this->assertSame([], $this->writes);
	}

	public function testTheFeaturedAccountsAreTheViewersOwn(): void {
		$this->controller()->pin('3');

		$response = $this->controller()->endorsements();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([self::CAROL], array_map(static fn (Person $p): string => $p->getId(), $response->getData()));

		$this->uid = 'bob';
		$this->assertSame([], $this->controller()->endorsements()->getData());
	}

	public function testAnAccountThatIsNotThereIsARecordNotFound(): void {
		foreach ([$this->controller()->note('404', 'x'), $this->controller()->pin('404')] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame('Record not found', $response->getData()['error']);
		}

		$this->assertSame([], $this->writes);
	}

	public function testWithoutCredentialsEveryRouteIsA401(): void {
		$this->csrf = false;
		$controller = $this->controller();

		foreach ([
			$controller->domainBlocks(),
			$controller->blockDomain('remote.example'),
			$controller->unblockDomain('remote.example'),
			$controller->note('3', 'x'),
			$controller->pin('3'),
			$controller->unpin('3'),
			$controller->endorsements(),
		] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertArrayHasKey('error', $response->getData());
		}

		$this->assertSame([], $this->writes);
	}

	public function testARevokedTokenIsA401(): void {
		$this->csrf = false;
		$this->clientService->method('getFromToken')
			->willThrowException(new ClientNotFoundException('the access_token was revoked'));

		$response = $this->controller('Bearer stale')->domainBlocks();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('the access_token was revoked', $response->getData()['error']);
	}

	public function testABearerTokenIsAcceptedWithoutASession(): void {
		// which is the only way a Mastodon client ever calls this
		$this->token(['read:blocks', 'write:blocks']);

		$this->assertSame(
			Http::STATUS_OK, $this->controller('Bearer t')->blockDomain('remote.example')->getStatus()
		);
	}

	public function testTheBroadScopeCarriesTheGranularOne(): void {
		$this->token(['read']);

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer t')->domainBlocks()->getStatus());
	}

	public function testAnotherGranularReadScopeIsNotThisOne(): void {
		// a token granted the reader's statuses has not been granted the
		// instances they refuse to hear from
		$this->token(['read:statuses', 'write:statuses']);

		$response = $this->controller('Bearer t')->domainBlocks();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('WWW-Authenticate', $response->getHeaders());
	}

	public function testATokenThatMayOnlyReadMayNotWrite(): void {
		$this->token(['read:blocks', 'read:accounts']);
		$controller = $this->controller('Bearer readonly');

		foreach ([
			$controller->blockDomain('remote.example'),
			$controller->unblockDomain('remote.example'),
			$controller->note('3', 'x'),
			$controller->pin('3'),
			$controller->unpin('3'),
		] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}

		$this->assertSame([], $this->writes);

		// and reading is still a read
		$this->assertSame(Http::STATUS_OK, $controller->domainBlocks()->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->endorsements()->getStatus());
	}

	public function testTheBlockListIsNotTheAccountsWriteScope(): void {
		// Mastodon scopes the domain block routes on blocks, not on accounts
		$this->token(['write:accounts', 'read:accounts']);

		$this->assertSame(
			Http::STATUS_FORBIDDEN, $this->controller('Bearer t')->blockDomain('remote.example')->getStatus()
		);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('Bearer t')->domainBlocks()->getStatus());
	}

	public function testAPageOfFeaturedAccountsIsNoLongerThanMastodonAllows(): void {
		$this->relationshipService = $this->createMock(RelationshipService::class);
		$this->relationshipService->expects($this->once())
			->method('getRelated')
			->with($this->anything(), 'endorse', 80)
			->willReturn([]);

		$this->controller()->endorsements(500);
	}
}
