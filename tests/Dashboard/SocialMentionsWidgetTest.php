<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use OCA\Social\Dashboard\SocialMentionsWidget;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class SocialMentionsWidgetTest extends TestCase {
	/** @var IL10N&Stub */
	private $l10n;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var StreamService&Stub */
	private $streamService;
	private SocialMentionsWidget $widget;

	protected function setUp(): void {
		$this->l10n = $this->createStub(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->streamService = $this->createStub(StreamService::class);

		$this->widget = new SocialMentionsWidget(
			$this->l10n,
			$this->urlGenerator,
			$this->accountService,
			$this->cacheActorService,
			$this->streamService,
			new NullLogger()
		);
	}

	private function viewer(string $uid = 'alice'): void {
		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn($uid);
		$this->accountService->method('getActorFromUserId')->with($uid, false)->willReturn($account);
		$this->cacheActorService->method('getFromLocalAccount')->with($uid)
			->willReturn($this->createStub(Person::class));
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IAPIWidgetV2::class, $this->widget);
		$this->assertSame('social_mentions', $this->widget->getId());
		$this->assertSame('Aloha Social mentions', $this->widget->getTitle());
		$this->assertSame(12, $this->widget->getOrder());
	}

	public function testItOpensTheNotificationsPage(): void {
		$this->urlGenerator->method('linkToRoute')
			->with('social.Navigation.timeline', ['path' => 'notifications'])
			->willReturn('/apps/social/timeline/notifications');

		$this->assertSame('/apps/social/timeline/notifications', $this->widget->getUrl());
		$this->assertSame('Open notifications', $this->widget->getWidgetButtons('alice')[0]->getText());
	}

	public function testItAsksTheNotificationsProbeForMentionsOnly(): void {
		$this->viewer();
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [];
		});

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame(ProbeOptions::NOTIFICATIONS, $options->getProbe());
		$this->assertSame(
			['mention'],
			$options->getTypes(),
			'a follow or a favourite is not something to answer'
		);
		$this->assertSame('Nobody has mentioned you yet', $items->getEmptyContentMessage());
	}

	public function testARowShowsThePostThatMentionsTheReader(): void {
		$this->viewer();
		$status = $this->createStub(Note::class);
		$status->method('getContent')->willReturn('<p>hey @alice</p>');
		$status->method('getAttributedTo')->willReturn('https://remote.example/users/bob');
		$notification = $this->createStub(SocialAppNotification::class);
		$notification->method('hasObject')->willReturn(true);
		$notification->method('getObject')->willReturn($status);
		$notification->method('getAttributedTo')->willReturn('https://remote.example/users/bob');
		$notification->method('getNid')->willReturn(5150);
		$this->streamService->method('getTimeline')->willReturn([$notification]);
		$bob = $this->createStub(Person::class);
		$bob->method('getName')->willReturn('Bob B.');
		$bob->method('getAvatar')->willReturn('https://remote.example/bob.png');
		$this->cacheActorService->method('getFromId')->willReturn($bob);

		$list = $this->widget->getItemsV2('alice')->getItems();

		$this->assertCount(1, $list);
		$this->assertSame('hey @alice', $list[0]->getTitle(), 'the wrapped status carries the text');
		$this->assertSame('Bob B.', $list[0]->getSubtitle());
		$this->assertSame('https://remote.example/bob.png', $list[0]->getIconUrl());
		$this->assertSame('5150', $list[0]->getSinceId());
	}

	public function testANotificationWithoutAStatusIsSkipped(): void {
		$this->viewer();
		$notification = $this->createStub(SocialAppNotification::class);
		$notification->method('hasObject')->willReturn(false);
		$this->streamService->method('getTimeline')->willReturn([$notification]);

		$this->assertSame([], $this->widget->getItemsV2('alice')->getItems());
	}
}
