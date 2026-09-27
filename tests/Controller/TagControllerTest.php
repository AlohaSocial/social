<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\TagController;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\TimelineRevisionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The four routes a client uses to follow a hashtag.
 *
 * What following one *does* is the home timeline's business and is tested with
 * it; this is the contract a client is handed — the entity shape, who is
 * allowed to ask, what a tag that is not one answers, and the cursor the list
 * pages on.
 */
class TagControllerTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	/** @var IRequest&MockObject */
	private $request;
	private AccountService|MockObject $accountService;
	private ClientService|MockObject $clientService;
	private FollowedTagsRequest|MockObject $followedTagsRequest;
	private TimelineRevisionService|MockObject $timelineRevisionService;
	private HashtagService|MockObject $hashtagService;
	private IUserSession|MockObject $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var string[] tags that are followed, per test */
	private array $followed = [];
	/** @var array<int, array{string, string}> [method, tag] of every write */
	private array $writes = [];
	private string $uri = '/index.php/apps/social/api/v1/followed_tags';
	private bool $csrf = true;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturnCallback(fn (): string => $this->uri);
		$this->request->method('getParam')->willReturn('');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $route, array $args): string
					=> 'https://cloud.example/apps/social/timeline/' . $args['path']
			);
		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createMock(AccountService::class);
		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$this->accountService->method('getActorFromUserId')->willReturn($viewer);

		$this->clientService = $this->createMock(ClientService::class);

		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->hashtagService->method('tagEntity')
			->willReturnCallback(
				static fn (string $tag, ?bool $following = null): array => array_filter([
					'name' => $tag,
					'url' => 'https://cloud.example/apps/social/timeline/tags/' . $tag,
					'history' => [],
					'following' => $following,
				], static fn ($value): bool => $value !== null)
			);

		$this->followedTagsRequest = $this->createMock(FollowedTagsRequest::class);
		$this->timelineRevisionService = $this->createMock(TimelineRevisionService::class);
		$this->followedTagsRequest->method('isFollowing')
			->willReturnCallback(fn (string $actor, string $tag): bool => in_array($tag, $this->followed, true));
		$this->followedTagsRequest->method('save')
			->willReturnCallback(function (string $actor, string $tag): void {
				$this->writes[] = ['save', $tag];
				$this->followed[] = $tag;
			});
		$this->followedTagsRequest->method('delete')
			->willReturnCallback(function (string $actor, string $tag): void {
				$this->writes[] = ['delete', $tag];
				$this->followed = array_values(array_diff($this->followed, [$tag]));
			});

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/**
	 * The bearer token is parsed in the constructor, so a test that presents
	 * one has to say so before the controller exists.
	 */
	private function controller(string $authorization = ''): TagController {
		// the getHeader() callback is registered once, in setUp(): a second
		// method() on the same mock never wins over the first
		$this->headers = ['Authorization' => $authorization];

		return new TagController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->hashtagService,
			$this->followedTagsRequest,
			$this->timelineRevisionService,
		);
	}

	/** @param array<array{id: int, hashtag: string}> $rows */
	private function page(array $rows): void {
		$this->followedTagsRequest->method('getByActor')->willReturn($rows);
	}

	public function testFollowingATagAnswersWithTheTagFollowed(): void {
		$response = $this->controller()->follow('nextcloud');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['name' => 'nextcloud', 'url' => 'https://cloud.example/apps/social/timeline/tags/nextcloud', 'history' => [], 'following' => true],
			$response->getData()
		);
		$this->assertSame([['save', 'nextcloud']], $this->writes);
	}

	public function testTheTagIsStoredNormalised(): void {
		// a client may send '#NextCloud'; what is stored has to be what
		// social_stream_tag can be compared with, or the follow matches nothing
		$response = $this->controller()->follow('#NextCloud');

		$this->assertSame([['save', 'nextcloud']], $this->writes);
		$this->assertSame('nextcloud', $response->getData()['name']);
	}

	public function testFollowingATagTwiceIsNotAnError(): void {
		$this->controller()->follow('nextcloud');
		$response = $this->controller()->follow('nextcloud');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['following']);
	}

	public function testUnfollowingAnswersWithTheTagNotFollowed(): void {
		$this->followed = ['nextcloud'];

		$response = $this->controller()->unfollow('nextcloud');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['following']);
		$this->assertSame([['delete', 'nextcloud']], $this->writes);
	}

	public function testUnfollowingSomethingNeverFollowedIsNotAnError(): void {
		$response = $this->controller()->unfollow('nextcloud');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['following']);
	}

	public function testALookupSaysWhetherTheViewerFollowsIt(): void {
		$this->followed = ['nextcloud'];

		$this->assertTrue($this->controller()->get('nextcloud')->getData()['following']);
		$this->assertFalse($this->controller()->get('php')->getData()['following']);
	}

	public function testALookupIsCaseInsensitive(): void {
		// #NextCloud and #nextcloud are one tag to a reader
		$this->followed = ['nextcloud'];

		$this->assertTrue($this->controller()->get('#NextCloud')->getData()['following']);
	}

	public function testATagThatIsNotOneIsRefused(): void {
		// storing it would be a row nothing can ever match
		foreach (['#', '   ', ''] as $notATag) {
			$response = $this->controller()->follow($notATag);
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			$this->assertArrayHasKey('error', $response->getData());
		}

		$this->assertSame([], $this->writes, 'nothing is written');
	}

	public function testTheListIsTagEntitiesTheViewerFollows(): void {
		$this->page([
			['id' => 9, 'hashtag' => 'nextcloud', 'creation' => 0],
			['id' => 4, 'hashtag' => 'php', 'creation' => 0],
		]);

		$response = $this->controller()->followedTags();

		$this->assertSame(['nextcloud', 'php'], array_column($response->getData(), 'name'));
		$this->assertSame(
			[true, true],
			array_column($response->getData(), 'following'),
			'everything on this list is followed, and a client reads the key rather than the route'
		);
	}

	public function testTheListPagesOnTheRowIdRatherThanTheTag(): void {
		// a tag can be unfollowed and followed again, so its name is not a
		// cursor; the row id is what moves in one direction
		$this->uri = '/index.php/apps/social/api/v1/followed_tags?limit=2';
		$this->page([
			['id' => 9, 'hashtag' => 'nextcloud', 'creation' => 0],
			['id' => 4, 'hashtag' => 'php', 'creation' => 0],
		]);

		$link = $this->controller()->followedTags(2)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString('max_id=4', $link);
		$this->assertStringContainsString('rel="next"', $link);
		$this->assertStringContainsString('min_id=9', $link);
		$this->assertStringContainsString('rel="prev"', $link);
		$this->assertStringContainsString('limit=2', $link, 'the caller\'s own filters survive');
	}

	public function testAShortPageIsTheLastOne(): void {
		$this->page([['id' => 9, 'hashtag' => 'nextcloud', 'creation' => 0]]);

		$link = $this->controller()->followedTags(20)->getHeaders()['Link'] ?? '';

		$this->assertStringNotContainsString('rel="next"', $link);
		$this->assertStringContainsString('rel="prev"', $link);
	}

	public function testAnEmptyListCarriesNoCursorAtAll(): void {
		$this->page([]);

		$response = $this->controller()->followedTags();

		$this->assertSame([], $response->getData());
		$this->assertArrayNotHasKey('Link', $response->getHeaders());
	}

	public function testAnUnauthenticatedCallerIsToldSoRatherThanServedNothing(): void {
		$this->csrf = false;
		$this->page([]);

		foreach ([$this->controller()->followedTags(), $this->controller()->get('nextcloud'),
			$this->controller()->follow('nextcloud'), $this->controller()->unfollow('nextcloud')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertArrayHasKey('error', $response->getData());
		}

		$this->assertSame([], $this->writes);
	}

	public function testABearerTokenIsAcceptedWithoutASession(): void {
		// which is the only way a Mastodon client ever calls this
		$this->csrf = false;
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes(['read', 'write']);
		$this->clientService->method('getFromToken')->with('sometoken')->willReturn($client);

		$response = $this->controller('Bearer sometoken')->follow('nextcloud');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testARevokedTokenIsA401(): void {
		$this->csrf = false;
		$this->clientService->method('getFromToken')
			->willThrowException(new ClientNotFoundException('the access_token was revoked'));

		$response = $this->controller('Bearer stale')->get('nextcloud');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('the access_token was revoked', $response->getData()['error']);
	}

	public function testATokenWithoutTheWriteScopeMayNotFollow(): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes(['read']);
		$this->clientService->method('getFromToken')->willReturn($client);

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('Bearer readonly')->follow('nextcloud')->getStatus()
		);
		$this->assertSame([], $this->writes);
		// reading the list is a read
		$this->page([]);
		$this->assertSame(
			Http::STATUS_OK,
			$this->controller('Bearer readonly')->followedTags()->getStatus()
		);
	}

	public function testAnUnrelatedGranularScopeMayNotFollow(): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes(['read:statuses', 'write:statuses']);
		$this->clientService->method('getFromToken')->willReturn($client);

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('Bearer statuses')->follow('nextcloud')->getStatus()
		);
		$this->assertSame([], $this->writes);
	}

	/** what Mastodon asks for, and what a client that asks for exactly that is granted */
	public function testTheFollowsScopesAreEnough(): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes(['read:follows', 'write:follows']);
		$this->clientService->method('getFromToken')->willReturn($client);

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer follows')->follow('nextcloud')->getStatus());
		$this->page([]);
		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer follows')->followedTags()->getStatus());
	}
}
