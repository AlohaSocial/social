<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ReviewController;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\HeldPost;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\PostReviewService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What an author can see and do about their own held posts.
 *
 * The one thing this feature must not do is lose somebody's writing without
 * telling them, and the one thing it must not do twice over is let anybody see
 * anybody else's.
 */
#[AllowMockObjectsWithoutExpectations]
class ReviewControllerTest extends TestCase {
	private AccountService|MockObject $accountService;
	private PostReviewService|MockObject $postReviewService;

	protected function setUp(): void {
		parent::setUp();
		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));

		$this->accountService = $this->createMock(AccountService::class);
		$this->postReviewService = $this->createMock(PostReviewService::class);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	private function controller(?string $userId = 'alice'): ReviewController {
		return new ReviewController(
			$this->createStub(IRequest::class),
			$userId,
			$this->accountService,
			$this->postReviewService,
			new NullLogger()
		);
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId('https://cloud.example/@alice');

		return $actor;
	}

	public function testTheCallerSeesTheirOwnHeldPostsWithTheReasonInWords(): void {
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($this->alice());
		$held = (new HeldPost())->setId(7)->setReason(HeldPost::REASON_FIRST_POST)
			->setParams(['text' => 'hello everybody']);
		$this->postReviewService->method('forActor')->willReturn([$held]);
		$this->postReviewService->method('reasonText')->willReturn('the first post of a new account');

		$response = $this->controller()->held();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$held], $response->getData()['held']);
		$this->assertSame(['the first post of a new account'], $response->getData()['reasons']);
	}

	public function testThereIsNothingHereForACallerWithoutASession(): void {
		$this->postReviewService->expects($this->never())->method('forActor');

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED, $this->controller(null)->held()->getStatus()
		);
	}

	public function testAnAuthorCanTakeTheirOwnPostBack(): void {
		$actor = $this->alice();
		$this->accountService->method('getActorFromUserId')->willReturn($actor);
		$this->postReviewService->expects($this->once())->method('withdraw')->with($actor, 7);

		$response = $this->controller()->withdraw(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('7', $response->getData()['withdrawn']);
	}

	/**
	 * Somebody else's held post and one that does not exist answer the same,
	 * so that this route cannot be used to learn what anybody else is waiting
	 * on.
	 */
	public function testSomebodyElsesHeldPostIsTheSameAsOneThatDoesNotExist(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->postReviewService->method('withdraw')
			->willThrowException(new ItemNotFoundException('no such held post'));

		$response = $this->controller()->withdraw(7);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('no such held post', $response->getData()['error']);
	}

	public function testNobodyWithoutASessionTakesAnythingBack(): void {
		$this->postReviewService->expects($this->never())->method('withdraw');

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED, $this->controller(null)->withdraw(7)->getStatus()
		);
	}
}
