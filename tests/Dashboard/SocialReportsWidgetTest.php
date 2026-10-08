<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use Exception;
use OCA\Social\Dashboard\SocialReportsWidget;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Report;
use OCA\Social\Service\ModeratorService;
use OCA\Social\Service\ReportService;
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
class SocialReportsWidgetTest extends TestCase {
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var IUserSession&Stub */
	private $userSession;
	/** @var ModeratorService&MockObject */
	private $moderatorService;
	/** @var ReportService&MockObject */
	private $reportService;
	private SocialReportsWidget $widget;

	protected function setUp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->moderatorService = $this->createMock(ModeratorService::class);
		$this->reportService = $this->createMock(ReportService::class);

		$this->widget = new SocialReportsWidget(
			$l10n,
			$this->urlGenerator,
			$this->userSession,
			$this->moderatorService,
			$this->reportService,
			new NullLogger()
		);
	}

	private function signedInAs(string $uid, bool $moderator): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->moderatorService->method('isModerator')->with($uid)->willReturn($moderator);
	}

	/** @return Report&MockObject */
	private function report(int $id, string $category, string $comment, ?Person $target): Report {
		$report = $this->createMock(Report::class);
		$report->method('getId')->willReturn($id);
		$report->method('getCategory')->willReturn($category);
		$report->method('getComment')->willReturn($comment);
		$report->method('getAccountId')->willReturn('https://remote.example/users/spammer');
		$report->method('getTargetAccount')->willReturn($target);

		return $report;
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IConditionalWidget::class, $this->widget);
		$this->assertSame('social_reports', $this->widget->getId());
		$this->assertSame('Aloha Social reports', $this->widget->getTitle());
		$this->assertSame(20, $this->widget->getOrder());
	}

	/**
	 * A moderator is an administrator or whoever the Social settings have been
	 * delegated to — the same rule the moderation routes enforce. Offered to
	 * the `admin` group alone, the widget was missing for exactly the people
	 * who had been given the job.
	 */
	public function testAnyoneWhoMayModerateIsOfferedTheWidget(): void {
		$this->signedInAs('alice', true);

		$this->assertTrue($this->widget->isEnabled());
	}

	public function testSomebodyWhoMayNotModerateIsNotOfferedTheWidget(): void {
		$this->signedInAs('bob', false);

		$this->assertFalse($this->widget->isEnabled());
	}

	public function testWithoutASignedInUserTheWidgetIsNotOffered(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertFalse($this->widget->isEnabled());
	}

	public function testRowsNameWhoWasReportedAndWhy(): void {
		$this->moderatorService->method('isModerator')->willReturn(true);
		$target = $this->createStub(Person::class);
		$target->method('getAccount')->willReturn('spammer@remote.example');
		$target->method('getAvatar')->willReturn('https://remote.example/spammer.png');
		$this->reportService->method('getReports')->willReturn([
			$this->report(7, 'spam', 'posting casino links', $target),
			$this->report(8, 'other', '', null),
		]);
		$this->urlGenerator->method('linkToRoute')
			->with('settings.AdminSettings.index', ['section' => 'social'])
			->willReturn('/settings/admin/social');

		$items = $this->widget->getItemsV2('alice');
		$list = $items->getItems();

		$this->assertCount(2, $list);
		$this->assertSame('spammer@remote.example', $list[0]->getTitle());
		$this->assertSame('spam – posting casino links', $list[0]->getSubtitle());
		$this->assertSame('https://remote.example/spammer.png', $list[0]->getIconUrl());
		$this->assertSame('/settings/admin/social#reports', $list[0]->getLink());
		$this->assertSame('7', $list[0]->getSinceId());
		$this->assertSame(
			'https://remote.example/users/spammer',
			$list[1]->getTitle(),
			'an unresolved target still has to be identifiable'
		);
		$this->assertSame('other', $list[1]->getSubtitle(), 'no comment leaves just the category');
		$this->assertSame('No reports to review', $items->getEmptyContentMessage());
	}

	/**
	 * The rows are asked for by user id, and the check is made on that id
	 * rather than only on what the dashboard offers: the reports name accounts
	 * somebody has complained about, which is not public.
	 */
	public function testTheRowsAreNotHandedToSomebodyWhoMayNotModerate(): void {
		$this->moderatorService->method('isModerator')->with('bob')->willReturn(false);
		$this->reportService->expects($this->never())->method('getReports');

		$this->assertSame([], $this->widget->getItemsV2('bob')->getItems());
	}

	public function testFailureLeavesTheTileEmptyWithAMessage(): void {
		$this->moderatorService->method('isModerator')->willReturn(true);
		$this->reportService->method('getReports')->willThrowException(new Exception('nope'));

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Could not load reports', $items->getEmptyContentMessage());
	}
}
