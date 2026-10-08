<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Notification;

use OCA\Social\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class NotifierTest extends TestCase {
	/** @var IL10N&MockObject */
	private $l10n;
	/** @var IFactory&MockObject */
	private $factory;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	private Notifier $notifier;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->factory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);

		$this->notifier = new Notifier(
			$this->l10n,
			$this->factory,
			$this->urlGenerator
		);
	}

	/** @return INotification&MockObject */
	private function notification(string $app, string $subject, array $actions = []): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn([]);
		$notification->method('getActions')->willReturn($actions);

		return $notification;
	}

	/** @return IAction&MockObject */
	private function action(string $label): IAction {
		$action = $this->createMock(IAction::class);
		$action->method('getLabel')->willReturn($label);
		$action->method('setParsedLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();

		return $action;
	}

	/** @return IL10N&MockObject */
	private function translationsFor(string $language): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text, $params = []): string => '[' . $language . '] '
				. ((array)$params === [] ? $text : vsprintf($text, (array)$params))
		);
		$l10n->method('n')->willReturnCallback(
			fn (string $one, string $many, int $count): string
				=> str_replace('%n', (string)$count, ($count === 1) ? $one : $many)
		);
		$this->factory->method('get')->with('social', $language)->willReturn($l10n);

		return $l10n;
	}

	public function testIdIsTheAppId(): void {
		$this->assertSame('social', $this->notifier->getID());
	}

	public function testNameIsTranslated(): void {
		$this->l10n->method('t')->with('Aloha Social')->willReturn('Sozial');

		$this->assertSame('Sozial', $this->notifier->getName());
	}

	public function testNotificationsOfOtherAppsAreRejected(): void {
		$notification = $this->notification('spreed', 'report_new');
		$this->factory->expects($this->never())->method('get');

		$this->expectException(\InvalidArgumentException::class);

		$this->notifier->prepare($notification, 'en');
	}

	public function testUnknownSubjectsAreRejected(): void {
		$this->translationsFor('en');
		$notification = $this->notification('social', 'new_follower');

		$this->expectException(\InvalidArgumentException::class);

		$this->notifier->prepare($notification, 'en');
	}

	public function testTheAlphaMigrationNoticeIsNoLongerANotificationType(): void {
		// `update_alpha3` announced the 2019 alpha2 -> alpha3 data migration and was
		// the only thing UpdateService ever sent; nothing has called UpdateService
		// since, so both it and this branch are gone. What is left of the branch
		// would be an untranslated English subject and a link to a forum post from
		// that year.
		$this->translationsFor('en');
		$this->urlGenerator->method('imagePath')->willReturn('/apps/social/img/social_dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example/apps/social/img/social_dark.svg');
		$notification = $this->notification('social', 'update_alpha3');
		$notification->expects($this->never())->method('setParsedSubject');

		$this->expectException(\InvalidArgumentException::class);

		$this->notifier->prepare($notification, 'en');
	}

	public function testReportsCarryNoActionsToParse(): void {
		// nothing this app sends carries an action any more: the 'help' button
		// belonged to the retired update notice
		$this->translationsFor('en');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/settings/admin/social');
		$dismiss = $this->action('dismiss');

		$notification = $this->notification('social', 'report_new', [$dismiss]);
		$notification->expects($this->never())->method('addParsedAction');

		$this->notifier->prepare($notification, 'en');
	}

	/** @return INotification&MockObject */
	private function reportNotification(array $params): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('social');
		$notification->method('getSubject')->willReturn('report_new');
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('getActions')->willReturn([]);

		return $notification;
	}

	/** @return INotification&MockObject with the actions it was given captured */
	private function notificationWith(string $subject, array $params, array &$actions): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('social');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->method('createAction')->willReturnCallback(function () use (&$actions): IAction {
			$index = count($actions);
			$actions[$index] = ['label' => '', 'link' => '', 'method' => '', 'primary' => false];
			$action = $this->createMock(IAction::class);
			$action->method('setLabel')->willReturnSelf();
			$action->method('setParsedLabel')->willReturnCallback(function (string $label) use (&$actions, $index, $action): IAction {
				$actions[$index]['label'] = $label;
				return $action;
			});
			$action->method('setPrimary')->willReturnCallback(function (bool $primary) use (&$actions, $index, $action): IAction {
				$actions[$index]['primary'] = $primary;
				return $action;
			});
			$action->method('setLink')->willReturnCallback(function (string $link, string $method) use (&$actions, $index, $action): IAction {
				$actions[$index]['link'] = $link;
				$actions[$index]['method'] = $method;
				return $action;
			});
			return $action;
		});
		$notification->method('addAction')->willReturnSelf();

		return $notification;
	}

	/**
	 * A follow request is answered where it is seen: Accept and Decline on the
	 * bell entry, POSTing to the routes a Mastodon client uses for the same.
	 */
	public function testAFollowRequestOffersAcceptAndDecline(): void {
		$this->translationsFor('en');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $params = []): string => 'https://cloud.example/' . $route . '/' . ($params['id'] ?? '')
		);
		$actions = [];
		$notification = $this->notificationWith('follow_request', ['account' => 'Bob', 'link' => 'https://cloud.example/apps/social/@bob', 'nid' => 42], $actions);
		$notification->expects($this->exactly(2))->method('addAction');

		$this->notifier->prepare($notification, 'en');

		$this->assertSame(
			[
				['label' => '[en] Accept', 'link' => 'https://cloud.example/social.AccountApi.followRequestAuthorize/42', 'method' => 'POST', 'primary' => true],
				['label' => '[en] Decline', 'link' => 'https://cloud.example/social.AccountApi.followRequestReject/42', 'method' => 'POST', 'primary' => false],
			],
			$actions
		);
	}

	public function testAFollowRequestWithoutAKnownFollowerOffersNothingToClick(): void {
		$this->translationsFor('en');
		$actions = [];
		$notification = $this->notificationWith('follow_request', ['account' => 'Bob', 'link' => ''], $actions);
		$notification->expects($this->never())->method('addAction');

		$this->notifier->prepare($notification, 'en');
	}

	public function testAClosedPollAndASubscribedPostAreWorded(): void {
		$this->translationsFor('en');
		$actions = [];
		foreach (['poll' => '[en] The poll by Bob has ended', 'status' => '[en] Bob posted'] as $subject => $expected) {
			$notification = $this->notificationWith($subject, ['account' => 'Bob', 'link' => 'https://cloud.example/apps/social/@bob/9'], $actions);
			$notification->expects($this->once())->method('setParsedSubject')->with($expected);
			$notification->expects($this->once())->method('setLink')->with('https://cloud.example/apps/social/@bob/9');

			$this->notifier->prepare($notification, 'en');
		}
	}

	public function testANewLocalReportLinksToTheAdminSettings(): void {
		$this->translationsFor('en');
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('settings.AdminSettings.index', ['section' => 'social'])
			->willReturn('https://cloud.example/settings/admin/social');

		$notification = $this->reportNotification([
			'reporter' => 'https://cloud.example/apps/social/@alice',
			'account' => 'https://cloud.example/apps/social/@bob',
			'local' => true,
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('[en] New report about https://cloud.example/apps/social/@bob');
		$notification->expects($this->once())->method('setParsedMessage')
			->with($this->stringContains('administration settings'));
		$notification->expects($this->once())->method('setLink')
			->with('https://cloud.example/settings/admin/social#reports');

		$this->assertSame($notification, $this->notifier->prepare($notification, 'en'));
	}

	public function testANewRemoteReportSaysSo(): void {
		$this->translationsFor('en');

		$notification = $this->reportNotification([
			'reporter' => 'https://mastodon.social/actor',
			'account' => 'https://cloud.example/apps/social/@bob',
			'local' => false,
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('[en] New report about https://cloud.example/apps/social/@bob from another instance');

		$this->notifier->prepare($notification, 'en');
	}
	// -- the digest -------------------------------------------------------------

	/** @return INotification&MockObject */
	private function digest(array $params): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('social');
		$notification->method('getSubject')->willReturn('digest');
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->method('setRichSubject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();

		return $notification;
	}

	public function testADigestSaysHowMuchIsNewAndListsTheCountsInOrder(): void {
		$this->translationsFor('en');
		$notification = $this->digest([
			'counts' => ['follow' => 1, 'favourite' => 6, 'reblog' => 4, 'mention' => 3],
			'total' => 14,
			'link' => 'https://cloud.example/apps/social/timeline/notifications',
		]);

		$notification->expects($this->once())->method('setParsedSubject')
			->with('14 new notifications in Aloha Social');
		$notification->expects($this->once())->method('setParsedMessage')
			->with('3 mentions, 6 favourites, 4 boosts, 1 new follower');
		$notification->expects($this->once())->method('setLink')
			->with('https://cloud.example/apps/social/timeline/notifications');
		$notification->expects($this->once())->method('setRichSubject')
			->with('14 new notifications in {app}', $this->callback(
				fn (array $rich): bool => $rich['app']['type'] === 'highlight'
					&& $rich['app']['name'] === '[en] Aloha Social'
					&& $rich['app']['link'] === 'https://cloud.example/apps/social/timeline/notifications'
			));

		$this->notifier->prepare($notification, 'en');
	}

	public function testADigestOfOneIsSingular(): void {
		$this->translationsFor('en');
		$notification = $this->digest([
			'counts' => ['follow_request' => 1],
			'total' => 1,
			'link' => 'https://cloud.example/apps/social/timeline/notifications',
		]);

		$notification->expects($this->once())->method('setParsedSubject')
			->with('1 new notification in Aloha Social');
		$notification->expects($this->once())->method('setParsedMessage')
			->with('1 follow request');

		$this->notifier->prepare($notification, 'en');
	}

	public function testADigestWithoutAWebLinkPointsNowhere(): void {
		$this->translationsFor('en');
		$notification = $this->digest(['counts' => ['poll' => 2], 'total' => 2, 'link' => 'javascript:alert(1)']);

		$notification->expects($this->never())->method('setLink');
		$notification->expects($this->never())->method('setRichSubject');
		$notification->expects($this->once())->method('setParsedMessage')->with('2 polls that ended');

		$this->notifier->prepare($notification, 'en');
	}

	public function testADigestKeepsTheAppIcon(): void {
		$this->translationsFor('en');
		$this->urlGenerator->method('imagePath')->willReturn('/apps/social/img/social_dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example/apps/social/img/social_dark.svg');
		$notification = $this->digest(['counts' => ['status' => 1], 'total' => 1, 'link' => '']);

		$notification->expects($this->once())->method('setIcon')
			->with('https://cloud.example/apps/social/img/social_dark.svg');

		$this->notifier->prepare($notification, 'en');
	}

	public function testADigestEndsBySayingHowManyPeopleAreWaiting(): void {
		$this->translationsFor('en');
		$notification = $this->digest([
			'counts' => ['favourite' => 2],
			'total' => 2,
			'waiting' => 3,
			'link' => 'https://cloud.example/apps/social/timeline/notifications',
		]);

		$notification->expects($this->once())->method('setParsedSubject')
			->with('2 new notifications in Aloha Social');
		$notification->expects($this->once())->method('setParsedMessage')
			->with("2 favourites\n3 people are waiting to reach you");

		$this->notifier->prepare($notification, 'en');
	}

	public function testOnePersonWaitingIsSingular(): void {
		$this->translationsFor('en');
		$notification = $this->digest(['counts' => ['mention' => 1], 'total' => 1, 'waiting' => 1, 'link' => '']);

		$notification->expects($this->once())->method('setParsedMessage')
			->with("1 mention\n1 person is waiting to reach you");

		$this->notifier->prepare($notification, 'en');
	}
}
