<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use InvalidArgumentException;
use OCA\Social\Controller\InterestsController;
use OCA\Social\Exceptions\InterestNotRemovableException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\InterestFeedService;
use OCA\Social\Service\InterestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The routes of For you: who may call them, and what they answer. */
#[AllowMockObjectsWithoutExpectations]
class InterestsControllerTest extends TestCase {
	private InterestService|MockObject $interestService;
	private InterestFeedService|MockObject $feedService;
	private bool $signedIn = true;
	private bool $enabled = true;
	private array $params = [];
	private string $uri = '/index.php/apps/social/api/v1/timelines/interests?limit=2';

	private function controller(): InterestsController {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('passesCSRFCheck')->willReturn(true);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$request->method('getRequestUri')->willReturnCallback(fn (): string => $this->uri);
		// Response::getHeaders() asks the server for the request
		\OC::$server->register(IRequest::class, $request);

		$session = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturnCallback(fn () => $this->signedIn ? $user : null);

		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn(
			(new Person())->setId('https://cloud.example/users/alice')->setUserId('alice')
		);

		$this->interestService ??= $this->createMock(InterestService::class);
		$this->interestService->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$this->feedService ??= $this->createMock(InterestFeedService::class);

		$filters = $this->createStub(FilterService::class);
		$filters->method('apply')->willReturnArgument(0);

		return new InterestsController(
			$request, $session, new NullLogger(), $accounts, $this->createStub(ClientService::class),
			$this->interestService, $this->feedService, $filters
		);
	}

	protected function setUp(): void {
		$this->interestService = $this->createMock(InterestService::class);
		$this->feedService = $this->createMock(InterestFeedService::class);
	}

	public function testTheStateIsTheReadersOwn(): void {
		$this->interestService->method('state')->willReturnCallback(
			fn (Person $actor): array => ['owner' => $actor->getUserId()]
		);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['owner' => 'alice'], $response->getData());
	}

	public function testNobodySignedInIsA401(): void {
		$this->signedIn = false;

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->index()->getStatus());
	}

	public function testSwitchedOffEveryRouteIsA404AndNothingIsLearned(): void {
		$this->enabled = false;
		$this->interestService->expects($this->never())->method('recordEvents');
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->signals([['status_id' => '1', 'kind' => 'open']])->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->timeline()->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->less('1')->getStatus());
	}

	public function testARefusalIsA422WithTheReason(): void {
		$this->interestService->method('add')->willThrowException(new InvalidArgumentException('not a hashtag'));
		$this->interestService->method('remove')->willThrowException(
			new InterestNotRemovableException('Unfollow #cats to remove it from your interests')
		);
		$controller = $this->controller();

		$added = $controller->add('two words');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $added->getStatus());
		$this->assertSame(['error' => 'not a hashtag'], $added->getData());

		$removed = $controller->remove('cats');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $removed->getStatus());
		$this->assertStringContainsString('Unfollow', $removed->getData()['error']);
	}

	public function testSignalsAreHandedOnAndAnsweredWithNoContent(): void {
		$events = [['status_id' => '1', 'kind' => 'dwell', 'ms' => 4000]];
		$this->interestService->expects($this->once())->method('recordEvents')
			->with($this->isInstanceOf(Person::class), $events);

		$this->assertSame(Http::STATUS_NO_CONTENT, $this->controller()->signals($events)->getStatus());
	}

	public function testSignalsThatAreNotAListAreNothing(): void {
		$this->interestService->expects($this->once())->method('recordEvents')
			->with($this->isInstanceOf(Person::class), []);

		$this->controller()->signals('garbage');
	}

	public function testOnlyTheSwitchesARequestNamesAreSaved(): void {
		$this->params = ['paused' => true, 'unrelated' => 'x'];
		$this->interestService->expects($this->once())->method('saveSettings')
			->with($this->isInstanceOf(Person::class), ['paused' => true])
			->willReturn([]);

		$this->controller()->settings();
	}

	public function testLessLikeThisOnAPostThatIsNotThereIsA404(): void {
		$this->interestService->method('lessLikeThis')->willThrowException(new ItemNotFoundException());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->less('404')->getStatus());
	}

	public function testAFullPageOfTheFeedLinksToTheNextByItsLastPost(): void {
		$posts = [];
		foreach (['1790000000000000009', '1789000000000000001'] as $nid) {
			$post = new Note();
			$post->setNid($nid);
			$posts[] = $post;
		}
		$this->feedService->expects($this->once())->method('page')
			->with($this->isInstanceOf(Person::class), 2, '1790000000000000100', 0)
			->willReturn($posts);

		$response = $this->controller()->timeline(2, '1790000000000000100');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			'</index.php/apps/social/api/v1/timelines/interests?limit=2&max_id=1789000000000000001>; rel="next"',
			$response->getHeaders()['Link']
		);
	}

	public function testAShortPageIsTheLastOne(): void {
		$post = new Note();
		$post->setNid('5');
		$this->feedService->method('page')->willReturn([$post]);

		$this->assertArrayNotHasKey('Link', $this->controller()->timeline(20)->getHeaders());
	}

	public function testACursorThatIsNotAnIdIsNoCursor(): void {
		$this->feedService->expects($this->once())->method('page')
			->with($this->isInstanceOf(Person::class), 20, '0', 0)
			->willReturn([]);

		$this->controller()->timeline(20, 'abc');
	}

	/**
	 * `media` is photos or videos; Mastodon's `only_media` is the two together;
	 * anything else is the whole feed, as the other media parameters treat an
	 * unknown kind.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: string}>
	 */
	public static function mediaParams(): array {
		return [
			'the whole feed' => ['', false, ''],
			'photos' => ['photos', false, 'photos'],
			'videos' => ['videos', false, 'videos'],
			'only_media' => ['', true, 'media'],
			'a kind named beats only_media' => ['videos', true, 'videos'],
			'unknown' => ['podcasts', false, ''],
			'unknown with only_media' => ['image', true, 'media'],
			'the internal name' => ['media', false, ''],
		];
	}

	#[DataProvider('mediaParams')]
	public function testTheFeedIsNarrowedOnlyToTheKindsItOffers(string $media, bool $onlyMedia, string $expected): void {
		$this->feedService->expects($this->once())->method('page')
			->with($this->isInstanceOf(Person::class), 20, '0', 0, $expected)
			->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->timeline(20, 0, 0, $media, $onlyMedia)->getStatus());
	}

	public function testTheNextPageKeepsTheKind(): void {
		$this->uri = '/index.php/apps/social/api/v1/timelines/interests?limit=1&media=photos';
		$post = new Note();
		$post->setNid('1789000000000000001');
		$this->feedService->method('page')->willReturn([$post]);

		$this->assertSame(
			'</index.php/apps/social/api/v1/timelines/interests?limit=1&media=photos&max_id=1789000000000000001>; rel="next"',
			$this->controller()->timeline(1, 0, 0, 'photos')->getHeaders()['Link']
		);
	}
}
