<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use Exception;
use OCA\Social\Controller\FollowerController;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Relationship;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
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
 * Removing somebody from your own followers.
 *
 * The contract is narrow and easy to get wrong in both directions: it must end
 * the inbound follow and tell the other server — a follow dropped here and
 * still counted there goes on delivering — and it must leave everything else
 * alone. Removing a follower is not blocking them, and it is not unfollowing
 * them either.
 */
#[AllowMockObjectsWithoutExpectations]
class FollowerControllerTest extends TestCase {
	use TCacheActorServiceMock;

	private const VIEWER = 'https://cloud.example/users/alice';
	private const BOB = 'https://remote.example/users/bob';

	/** @var IRequest&Stub */
	private $request;
	private AccountService|Stub $accountService;
	private CacheActorService|MockObject $cacheActorService;
	private ClientService|Stub $clientService;
	private FollowService|Stub $followService;
	private IUserSession|Stub $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var string[] actor ids that follow the viewer */
	private array $followers = [self::BOB];
	/** @var string[] actor ids the viewer follows */
	private array $follows = [self::BOB];
	/** @var array<int, array> [method, actorId] of every call that changes something */
	private array $writes = [];
	private bool $csrf = true;

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/social/api/v1/accounts');
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$this->userSession = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($this->person(self::VIEWER, 1));
		$this->accountService->method('bumpActorCount')
			->willReturnCallback(function (string $actorId): void {
				$this->writes[] = ['bumpActorCount', $actorId];
			});

		$this->clientService = $this->createStub(ClientService::class);

		$this->cacheActorService = $this->cacheActorServiceMock();
		$this->cacheActorService->method('getFromNids')
			->willReturnCallback(function (array $nids): array {
				return ($nids === [2]) ? [$this->person(self::BOB, 2)] : [];
			});
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(function (string $id): Person {
				if ($id !== self::BOB) {
					throw new CacheActorDoesNotExistException('Record not found');
				}

				return $this->person($id, 2);
			});
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $account): Person {
				if ($account !== 'bob@remote.example') {
					throw new CacheActorDoesNotExistException('Record not found');
				}

				return $this->person(self::BOB, 2);
			});

		$this->followService = $this->createStub(FollowService::class);
		$this->followService->method('rejectFollowRequest')
			->willReturnCallback(function (Person $follower): void {
				if (!in_array($follower->getId(), $this->followers, true)) {
					throw new FollowNotFoundException('unknown follow');
				}

				$this->writes[] = ['rejectFollowRequest', $follower->getId()];
				$this->followers = array_values(array_diff($this->followers, [$follower->getId()]));
			});
		$this->followService->method('getRelationshipWith')
			->willReturnCallback(function (Person $target): Relationship {
				$relationship = new Relationship($target->getNid());
				$relationship->setFollowing(in_array($target->getId(), $this->follows, true));
				$relationship->setFollowedBy(in_array($target->getId(), $this->followers, true));

				return $relationship;
			});

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

	/**
	 * The bearer token is parsed in the constructor, so a test that presents
	 * one has to say so before the controller exists.
	 */
	private function controller(string $authorization = ''): FollowerController {
		// the getHeader() callback is registered once, in setUp(): a second
		// method() on the same mock never wins over the first
		$this->headers = ['Authorization' => $authorization];

		return new FollowerController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->cacheActorService,
			$this->clientService,
			$this->followService,
		);
	}

	private function token(array $scopes): SocialClient {
		$this->csrf = false;
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->willReturn($client);

		return $client;
	}

	public function testRemovingAFollowerEndsTheFollowAndTellsTheOtherServer(): void {
		// the Reject is FollowService's, not a second way of saying the same
		// thing: to the other side a follow rejected and a follow withdrawn
		// after being accepted are one statement
		$response = $this->controller()->remove('2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[['rejectFollowRequest', self::BOB], ['bumpActorCount', self::VIEWER]],
			$this->writes
		);
	}

	public function testTheAnswerIsTheRelationshipAsItNowStands(): void {
		$entity = $this->controller()->remove('2')->getData()->jsonSerialize();

		$this->assertFalse($entity['followed_by'], 'they no longer follow the viewer');
		$this->assertTrue($entity['following'], 'and the viewer still follows them');
	}

	public function testRemovingSomebodyWhoIsNotAFollowerIsNotAnError(): void {
		// a client that lost the answer and retried gets the same one back
		$this->followers = [];

		$response = $this->controller()->remove('2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()->jsonSerialize()['followed_by']);
		$this->assertSame([], $this->writes, 'nothing is federated and no count is touched');
	}

	public function testNothingElseAboutTheRelationshipIsTouched(): void {
		// removing a follower is not blocking them and not unfollowing them
		$this->controller()->remove('2');

		$this->assertSame(
			[['rejectFollowRequest', self::BOB], ['bumpActorCount', self::VIEWER]],
			$this->writes,
			'no block, no unfollow, no mute'
		);
		$this->assertSame([self::BOB], $this->follows);
	}

	public function testTheAccountMayBeNamedTheThreeWaysThisApiAcceptsOne(): void {
		foreach (['2', self::BOB, '@bob@remote.example'] as $id) {
			$this->followers = [self::BOB];
			$this->assertSame(Http::STATUS_OK, $this->controller()->remove($id)->getStatus());
		}
	}

	public function testAnAccountThatIsNotThereIsNotFound(): void {
		foreach (['99', '0', '', 'https://remote.example/users/nobody', 'nobody@nowhere'] as $id) {
			$response = $this->controller()->remove($id);

			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame('Record not found', $response->getData()['error']);
		}

		$this->assertSame([], $this->writes);
	}

	public function testWithoutCredentialsNothingIsRemoved(): void {
		$this->csrf = false;

		$response = $this->controller()->remove('2');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertStringContainsString(
			'invalid_token', $response->getHeaders()['WWW-Authenticate'] ?? ''
		);
		$this->assertSame([], $this->writes);
	}

	public function testATokenGrantedFollowMayRemoveAFollower(): void {
		$this->token(['follow']);

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer t')->remove('2')->getStatus());
	}

	public function testTheBroadWriteScopeCarriesTheGranularOne(): void {
		$this->token(['write']);

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer t')->remove('2')->getStatus());
	}

	public function testAnotherGranularWriteScopeIsNotThisOne(): void {
		// a grant to post is not a grant to change who may read what is posted
		$this->token(['write:statuses', 'read']);

		$response = $this->controller('Bearer t')->remove('2');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('WWW-Authenticate', $response->getHeaders());
		$this->assertSame([], $this->writes);
	}

	public function testAFailureThisSideIsNotPublished(): void {
		// this is a #[PublicPage] route, and echoing getMessage() publishes
		// whatever the failure happened to name
		$this->followService = $this->createStub(FollowService::class);
		$this->followService->method('rejectFollowRequest')
			->willThrowException(new Exception('SQLSTATE[42S02] table social_follow'));

		$response = $this->controller()->remove('2');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('internal server error', $response->getData()['error']);
	}
}
