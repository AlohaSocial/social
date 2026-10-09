<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use InvalidArgumentException;
use OCA\Social\Atproto\Reader\BlueskyFeeds;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Controller\AtprotoFeedsController;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The routes of Bluesky's feeds and lists: who may call them, and what they answer. */
#[AllowMockObjectsWithoutExpectations]
class AtprotoFeedsControllerTest extends TestCase {
	private BlueskyFeeds&MockObject $feeds;
	private LinkPreviewService&MockObject $cards;
	private bool $enabled = true;
	private bool $signedIn = true;

	private function controller(): AtprotoFeedsController {
		$session = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn ? $user : null);
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setUserId('alice'));

		return new AtprotoFeedsController(
			$this->createStub(IRequest::class), $session, $config, $accounts, $this->feeds, $this->cards, $this->createStub(PlaceService::class), new NullLogger(),
		);
	}

	protected function setUp(): void {
		$this->feeds = $this->createMock(BlueskyFeeds::class);
		$this->cards = $this->createMock(LinkPreviewService::class);
	}

	public function testNothingIsAnsweredWhileBlueskyIsOffOrNoOneIsSignedIn(): void {
		$this->feeds->expects($this->never())->method('saved');
		$this->enabled = false;
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->saved()->getStatus());
		$this->enabled = true;
		$this->signedIn = false;
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->saved()->getStatus());
	}

	public function testTheSavedOnesAndTheOneAdded(): void {
		$cats = ['uri' => 'at://did:plc:bob/app.bsky.feed.generator/cats', 'type' => 'feed', 'name' => 'Cats', 'description' => '', 'avatar' => '', 'creator' => 'bob.test', 'pinned' => true];
		$this->feeds->method('saved')->willReturn([$cats]);
		$this->feeds->method('add')->with($this->anything(), 'https://bsky.app/profile/bob.test/feed/cats')->willReturn($cats);

		$this->assertSame(['feeds' => [$cats]], $this->controller()->saved()->getData());
		$this->assertSame(['feed' => $cats], $this->controller()->add('https://bsky.app/profile/bob.test/feed/cats')->getData());
	}

	public function testWhatThePersonGotWrongIsTheirsAndWhatBlueskyDidNotAnswerIsNot(): void {
		$this->feeds->method('add')->willThrowException(new InvalidArgumentException('That is not the address of a Bluesky feed or list'));
		$this->feeds->method('page')->willThrowException(new AtprotoException('upstream down'));

		$wrong = $this->controller()->add('cats');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $wrong->getStatus());
		$this->assertSame(['error' => 'That is not the address of a Bluesky feed or list'], $wrong->getData());
		$down = $this->controller()->timeline('at://did:plc:bob/app.bsky.feed.generator/cats');
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $down->getStatus());
		$this->assertSame(['error' => 'Bluesky did not answer'], $down->getData(), 'nothing of the upstream error');
	}

	public function testAPageIsTheFeedsPostsWithTheirCards(): void {
		$post = new Note();
		$this->feeds->expects($this->once())->method('page')->with($this->anything(), 'at://did:plc:bob/app.bsky.graph.list/3kl', '77', 10)->willReturn([$post]);
		$this->cards->expects($this->once())->method('attachCards')->with([$post]);

		$this->assertSame([$post], $this->controller()->timeline('at://did:plc:bob/app.bsky.graph.list/3kl', 10, '77')->getData());
	}

	public function testRemovingAnswersNothing(): void {
		$this->feeds->expects($this->once())->method('remove')->with($this->anything(), 'at://did:plc:bob/app.bsky.feed.generator/cats');

		$this->assertSame([], $this->controller()->remove('at://did:plc:bob/app.bsky.feed.generator/cats')->getData());
	}
}
