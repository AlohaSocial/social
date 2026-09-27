<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use OCA\Social\Dashboard\SocialTimelineWidget;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class SocialTimelineWidgetTest extends TestCase {
	/** @var IL10N&Stub */
	private $l10n;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var StreamService&MockObject */
	private $streamService;
	private SocialTimelineWidget $widget;

	protected function setUp(): void {
		$this->l10n = $this->createStub(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->streamService = $this->createMock(StreamService::class);

		$this->widget = new SocialTimelineWidget(
			$this->l10n,
			$this->urlGenerator,
			$this->accountService,
			$this->cacheActorService,
			$this->streamService,
			new NullLogger()
		);
	}

	/** @return Person&MockObject */
	private function viewer(string $uid = 'alice'): Person {
		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn($uid);
		$this->accountService->method('getActorFromUserId')->with($uid, false)->willReturn($account);
		$viewer = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromLocalAccount')->with($uid)->willReturn($viewer);

		return $viewer;
	}

	/** @return Note&MockObject */
	private function note(string $content, string $attributedTo, int $nid): Note {
		$note = $this->createMock(Note::class);
		$note->method('getContent')->willReturn($content);
		$note->method('getAttributedTo')->willReturn($attributedTo);
		$note->method('getNid')->willReturn($nid);

		return $note;
	}

	/** @return Person&MockObject */
	private function author(string $name, string $username, string $avatar): Person {
		$author = $this->createMock(Person::class);
		$author->method('getName')->willReturn($name);
		$author->method('getPreferredUsername')->willReturn($username);
		$author->method('getAvatar')->willReturn($avatar);

		return $author;
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IAPIWidgetV2::class, $this->widget);
		$this->assertInstanceOf(IIconWidget::class, $this->widget);
		$this->assertInstanceOf(IButtonWidget::class, $this->widget);
		$this->assertInstanceOf(IReloadableWidget::class, $this->widget);
		$this->assertSame('social_timeline', $this->widget->getId());
		$this->assertSame('Social timeline', $this->widget->getTitle());
		$this->assertSame(11, $this->widget->getOrder());
		$this->assertSame('icon-social', $this->widget->getIconClass());
		$this->assertSame(300, $this->widget->getReloadInterval());
	}

	public function testIconAndUrlComeFromTheUrlGenerator(): void {
		$this->urlGenerator->method('imagePath')->with('social', 'social-dark.svg')->willReturn('/apps/social/img/social-dark.svg');
		$this->urlGenerator->method('linkToRoute')
			->with('social.Navigation.timeline', ['path' => 'home'])
			->willReturn('/apps/social/timeline/home');

		$this->assertSame('/apps/social/img/social-dark.svg', $this->widget->getIconUrl());
		$this->assertSame(
			'/apps/social/timeline/home',
			$this->widget->getUrl(),
			'a widget opens its own timeline, not whichever one is the default'
		);
	}

	public function testMoreButtonOpensTheTimeline(): void {
		$this->urlGenerator->method('linkToRoute')
			->with('social.Navigation.timeline', ['path' => 'home'])
			->willReturn('/apps/social/timeline/home');

		$buttons = $this->widget->getWidgetButtons('alice');

		$this->assertCount(1, $buttons);
		$this->assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
		$this->assertSame('/apps/social/timeline/home', $buttons[0]->getLink());
		$this->assertSame('Open timeline', $buttons[0]->getText());
	}

	public function testItemIconsAreRoundedBecauseTheyAreAvatars(): void {
		$this->assertInstanceOf(IOptionWidget::class, $this->widget);
		$this->assertTrue($this->widget->getWidgetOptions()->withRoundItemIcons());
	}

	public function testItemsAreBuiltFromTheViewersHomeTimeline(): void {
		$viewer = $this->viewer();
		$viewer->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [
				$this->note('<p>Hello <b>world</b>, this is a fairly long post that should be truncated at one hundred and twenty characters for the dashboard tile.</p>', 'https://remote.example/users/bob', 4001),
				$this->createMock(Announce::class),
				$this->note('', 'https://remote.example/users/carol', 4002),
			];
		});
		$this->cacheActorService->method('getFromId')->willReturnMap([
			['https://remote.example/users/bob', false, $this->author('Bob B.', 'bob', 'https://remote.example/bob.png')],
			['https://remote.example/users/carol', false, $this->author('', 'carol', '')],
		]);
		$this->urlGenerator->method('linkToRoute')->with('social.Navigation.timeline', ['path' => 'home'])->willReturn('/apps/social/timeline/home');

		$items = $this->widget->getItemsV2('alice', null, 5);

		$this->assertSame(ProbeOptions::HOME, $options->getProbe());
		$this->assertSame(5, $options->getLimit());
		$this->assertSame('Follow some accounts to see their posts here', $items->getEmptyContentMessage());
		$this->assertSame(
			'', $items->getHalfEmptyContentMessage(),
			'the dashboard prints this above the rows, so a widget with rows must not send one'
		);
		$list = $items->getItems();
		$this->assertCount(2, $list, 'a boost whose subject did not resolve has nothing to show');
		$this->assertSame(
			'Hello world, this is a fairly long post that should be truncated at one hundred and twenty characters for the dashboard ',
			$list[0]->getTitle(),
			'markup is stripped before truncating'
		);
		$this->assertSame(120, mb_strlen($list[0]->getTitle()));
		$this->assertSame('Bob B.', $list[0]->getSubtitle());
		$this->assertSame('https://remote.example/bob.png', $list[0]->getIconUrl());
		$this->assertSame('/apps/social/timeline/home', $list[0]->getLink());
		$this->assertSame(
			'4001',
			$list[0]->getSinceId(),
			'the sinceId is the stream nid, which is what setSince() paginates on'
		);
		$this->assertSame('(no content)', $list[1]->getTitle());
		$this->assertSame('carol', $list[1]->getSubtitle(), 'falls back to the username without a display name');
	}

	public function testABoostShowsTheBoostedPostAndWhoBoostedIt(): void {
		$this->viewer();
		$boosted = $this->note('the original', 'https://remote.example/users/bob', 4001);
		$announce = $this->createStub(Announce::class);
		$announce->method('hasObject')->willReturn(true);
		$announce->method('getObject')->willReturn($boosted);
		$announce->method('getAttributedTo')->willReturn('https://remote.example/users/carol');
		$announce->method('getNid')->willReturn(4100);
		$this->streamService->method('getTimeline')->willReturn([$announce]);
		$this->cacheActorService->method('getFromId')->willReturnMap([
			['https://remote.example/users/bob', false, $this->author('Bob B.', 'bob', 'https://remote.example/bob.png')],
			['https://remote.example/users/carol', false, $this->author('Carol C.', 'carol', '')],
		]);
		$list = $this->widget->getItemsV2('alice')->getItems();

		$this->assertCount(1, $list, 'a boost is a row, not something to drop');
		$this->assertSame('the original', $list[0]->getTitle());
		$this->assertSame('Carol C. boosted Bob B.', $list[0]->getSubtitle());
		$this->assertSame(
			'https://remote.example/bob.png',
			$list[0]->getIconUrl(),
			'the avatar belongs to whoever wrote what is shown'
		);
		$this->assertSame('4100', $list[0]->getSinceId(), 'paging follows the boost, not the post it repeats');
	}

	public function testSinceAsksOnlyForNewerRowsWithoutInvertingTheOrder(): void {
		$this->viewer();
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [];
		});

		$this->widget->getItemsV2('alice', '4001');

		$this->assertSame(4001, $options->getSince());
		$this->assertSame(0, $options->getMinId(), 'minId would hand back the oldest rows instead of the newest');
	}

	/**
	 * The sinceId is a nid; one wider than a PHP int is asked for as the
	 * string it came as, where an `(int)` cast clamped it to PHP_INT_MAX.
	 */
	public function testAWideSinceIsAskedForExactly(): void {
		$this->viewer();
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [];
		});

		$this->widget->getItemsV2('alice', '92233720368547758070');

		$this->assertSame('92233720368547758070', $options->getSince());
	}

	public function testAnUnusableSinceIsIgnored(): void {
		$this->viewer();
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [];
		});

		$this->widget->getItemsV2('alice', 'not-a-number');

		$this->assertSame(0, $options->getSince());
	}

	public function testLimitIsCappedAtTwenty(): void {
		$this->viewer();
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [];
		});

		$this->widget->getItemsV2('alice', null, 50);

		$this->assertSame(20, $options->getLimit());
	}

	public function testUnresolvableAuthorFallsBackToTheActorId(): void {
		$this->viewer();
		$this->streamService->method('getTimeline')->willReturn([$this->note('hi', 'https://remote.example/users/gone', 1)]);
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$items = $this->widget->getItemsV2('alice')->getItems();

		$this->assertSame('https://remote.example/users/gone', $items[0]->getSubtitle());
		$this->assertSame('', $items[0]->getIconUrl());
	}

	/**
	 * "No recent posts" used to be sent whether or not there were posts, and
	 * the dashboard renders it above the rows: the widget announced an empty
	 * timeline directly on top of the posts it had just listed.
	 */
	public function testAnEmptyTimelineStillCarriesBothMessages(): void {
		$this->viewer();
		$this->streamService->method('getTimeline')->willReturn([]);

		$items = $this->widget->getItemsV2('alice', null, 5);

		$this->assertSame([], $items->getItems());
		$this->assertSame('Follow some accounts to see their posts here', $items->getEmptyContentMessage());
		$this->assertSame('No recent posts', $items->getHalfEmptyContentMessage());
	}

	public function testFailuresYieldAnEmptyWidgetWithAnErrorMessage(): void {
		$this->accountService->method('getActorFromUserId')->willThrowException(new AccountDoesNotExistException());
		$this->streamService->expects($this->never())->method('getTimeline');

		$items = $this->widget->getItemsV2('nobody');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Could not load timeline', $items->getEmptyContentMessage());
		$this->assertSame('Could not load timeline', $items->getHalfEmptyContentMessage());
	}
}
