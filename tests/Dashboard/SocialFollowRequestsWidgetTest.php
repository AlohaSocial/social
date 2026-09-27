<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use OCA\Social\Dashboard\SocialFollowRequestsWidget;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCP\Dashboard\IConditionalWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class SocialFollowRequestsWidgetTest extends TestCase {
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var IUserSession&Stub */
	private $userSession;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var FollowService&MockObject */
	private $followService;
	/** @var FollowsRequest&MockObject */
	private $followsRequest;
	private SocialFollowRequestsWidget $widget;

	protected function setUp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);

		$this->widget = new SocialFollowRequestsWidget(
			$l10n,
			$this->urlGenerator,
			$this->userSession,
			$this->accountService,
			$this->cacheActorService,
			$this->followService,
			$this->followsRequest,
			new NullLogger()
		);
	}

	private function signedInAs(string $uid = 'alice'): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/** @return Person&MockObject */
	private function socialAccount(bool $locked, string $uid = 'alice'): Person {
		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn($uid);
		$this->accountService->method('getActorFromUserId')->with($uid, false)->willReturn($account);

		$viewer = $this->createMock(Person::class);
		$viewer->method('isLocked')->willReturn($locked);
		$viewer->method('getId')->willReturn('https://local.example/users/' . $uid);
		$this->cacheActorService->method('getFromLocalAccount')->with($uid)->willReturn($viewer);

		return $viewer;
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IConditionalWidget::class, $this->widget);
		$this->assertSame('social_follow_requests', $this->widget->getId());
		$this->assertSame('Social follow requests', $this->widget->getTitle());
		$this->assertSame(15, $this->widget->getOrder());
	}

	public function testALockedAccountIsOfferedTheWidget(): void {
		$this->signedInAs();
		$this->socialAccount(true);
		$this->followsRequest->expects($this->never())->method('countPendingRequests');

		$this->assertTrue($this->widget->isEnabled());
	}

	public function testAnOpenAccountWithRequestsStillWaitingIsOfferedTheWidget(): void {
		$this->signedInAs();
		$this->socialAccount(false);
		$this->followsRequest->method('countPendingRequests')->willReturn(2);

		$this->assertTrue(
			$this->widget->isEnabled(),
			'an account that unlocked itself can still have requests to answer'
		);
	}

	public function testAnOpenAccountWithNothingWaitingIsNotOfferedTheWidget(): void {
		$this->signedInAs();
		$this->socialAccount(false);
		$this->followsRequest->method('countPendingRequests')->willReturn(0);

		$this->assertFalse($this->widget->isEnabled());
	}

	public function testWithoutASocialAccountTheWidgetIsNotOffered(): void {
		$this->signedInAs();
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new AccountDoesNotExistException());

		$this->assertFalse($this->widget->isEnabled());
	}

	public function testWithoutASignedInUserTheWidgetIsNotOffered(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertFalse($this->widget->isEnabled());
	}

	public function testRowsNameTheAccountsWaitingForAnAnswer(): void {
		$this->signedInAs();
		$viewer = $this->socialAccount(true);
		$this->followService->expects($this->once())->method('setViewer')->with($viewer);

		$bob = $this->createStub(Person::class);
		$bob->method('getName')->willReturn('Bob B.');
		$bob->method('getAccount')->willReturn('bob@remote.example');
		$bob->method('getAvatar')->willReturn('https://remote.example/bob.png');
		$carol = $this->createStub(Person::class);
		$carol->method('getName')->willReturn('');
		$carol->method('getPreferredUsername')->willReturn('carol');
		$carol->method('getAccount')->willReturn('carol@remote.example');
		$this->followService->method('getPendingRequests')->willReturn([$bob, $carol]);
		$this->urlGenerator->method('linkToRoute')
			->with('social.navigation.navigatefollowrequests')
			->willReturn('/apps/social/follow_requests');

		$items = $this->widget->getItemsV2('alice');
		$list = $items->getItems();

		$this->assertCount(2, $list);
		$this->assertSame('Bob B.', $list[0]->getTitle());
		$this->assertSame('bob@remote.example', $list[0]->getSubtitle());
		$this->assertSame('https://remote.example/bob.png', $list[0]->getIconUrl());
		$this->assertSame('/apps/social/follow_requests', $list[0]->getLink());
		$this->assertSame('carol', $list[1]->getTitle(), 'falls back to the handle without a display name');
		$this->assertSame('No follow requests', $items->getEmptyContentMessage());
	}

	/**
	 * The query is what is bounded, not the list after it: slicing a full
	 * read built every pending request's account to show a handful.
	 */
	public function testTheTileAsksForNoMoreThanItCanShow(): void {
		$this->signedInAs();
		$this->socialAccount(true);
		$asked = [];
		$this->followService->method('getPendingRequests')
			->willReturnCallback(function (int $limit = 0) use (&$asked): array {
				$asked[] = $limit;

				return [];
			});

		$this->widget->getItemsV2('alice', null, 500);
		$this->widget->getItemsV2('alice', null, 5);

		$this->assertSame([20, 5], $asked);
	}

	public function testFailureLeavesTheTileEmptyWithAMessage(): void {
		$this->signedInAs();
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new AccountDoesNotExistException());

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Could not load follow requests', $items->getEmptyContentMessage());
	}
}
