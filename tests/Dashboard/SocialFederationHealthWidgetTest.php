<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use Exception;
use OCA\Social\Dashboard\SocialFederationHealthWidget;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\ModeratorService;
use OCP\Dashboard\IConditionalWidget;
use OCP\IDateTimeFormatter;
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
class SocialFederationHealthWidgetTest extends TestCase {
	/** @var IURLGenerator&Stub */
	private $urlGenerator;
	/** @var IUserSession&Stub */
	private $userSession;
	/** @var ModeratorService&MockObject */
	private $moderatorService;
	/** @var FederationHealthService&MockObject */
	private $federationHealthService;
	private SocialFederationHealthWidget $widget;

	protected function setUp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count): string
				=> str_replace('%n', (string)$count, $count === 1 ? $one : $many)
		);
		$this->urlGenerator = $this->createStub(IURLGenerator::class);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->moderatorService = $this->createMock(ModeratorService::class);
		$this->federationHealthService = $this->createMock(FederationHealthService::class);
		$formatter = $this->createStub(IDateTimeFormatter::class);
		$formatter->method('formatTimeSpan')->willReturn('3 days ago');

		$this->widget = new SocialFederationHealthWidget(
			$l10n,
			$this->urlGenerator,
			$this->userSession,
			$this->moderatorService,
			$formatter,
			$this->federationHealthService,
			new NullLogger()
		);
	}

	private function signedInAs(string $uid, bool $moderator): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->moderatorService->method('isModerator')->with($uid)->willReturn($moderator);
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IConditionalWidget::class, $this->widget);
		$this->assertSame('social_federation_health', $this->widget->getId());
		$this->assertSame('Aloha Social federation health', $this->widget->getTitle());
		$this->assertSame(21, $this->widget->getOrder());
	}

	/** An administrator, or whoever the Social settings have been delegated to. */
	public function testAnyoneWhoMayModerateIsOfferedTheWidget(): void {
		$this->signedInAs('alice', true);
		$this->assertTrue($this->widget->isEnabled());
	}

	public function testSomebodyWhoMayNotModerateIsNotOfferedTheWidget(): void {
		$this->signedInAs('bob', false);
		$this->assertFalse($this->widget->isEnabled());
	}

	/** Which instances this one cannot reach is not for every client to read. */
	public function testTheRowsAreNotHandedToSomebodyWhoMayNotModerate(): void {
		$this->moderatorService->method('isModerator')->with('bob')->willReturn(false);
		$this->federationHealthService->expects($this->never())->method('summary');

		$this->assertSame([], $this->widget->getItemsV2('bob')->getItems());
	}

	public function testAHealthyQueueSaysSoRatherThanShowingRows(): void {
		$this->moderatorService->method('isModerator')->willReturn(true);
		$this->federationHealthService->method('summary')->willReturn([
			'waiting' => 4, 'running' => 0, 'failing' => 0, 'atRisk' => 0,
			'maxTries' => 15, 'truncated' => false, 'instances' => [],
		]);

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Everything is being delivered', $items->getEmptyContentMessage());
	}

	public function testTheTotalsLeadSoTroubleIsVisibleEvenWithoutANamedHost(): void {
		$this->moderatorService->method('isModerator')->willReturn(true);
		$this->federationHealthService->method('summary')->willReturn([
			'waiting' => 30, 'running' => 1, 'failing' => 12, 'atRisk' => 3,
			'maxTries' => 15, 'truncated' => false, 'instances' => [],
		]);
		$this->urlGenerator->method('linkToRoute')->willReturn('/settings/admin/social');

		$list = $this->widget->getItemsV2('alice')->getItems();

		$this->assertCount(1, $list, 'failures with no attributable host still have to show');
		$this->assertSame('12 deliveries are failing', $list[0]->getTitle());
		$this->assertSame('3 at risk of being dropped after 15 tries', $list[0]->getSubtitle());
		$this->assertSame('/settings/admin/social#federation', $list[0]->getLink());
	}

	public function testEachFailingInstanceGetsARow(): void {
		$this->moderatorService->method('isModerator')->willReturn(true);
		$this->federationHealthService->method('summary')->willReturn([
			'waiting' => 30, 'running' => 0, 'failing' => 5, 'atRisk' => 1,
			'maxTries' => 15, 'truncated' => false,
			'instances' => [
				['host' => 'dead.example', 'requests' => 4, 'tries' => 14, 'last' => 1714564800],
				['host' => 'slow.example', 'requests' => 1, 'tries' => 1, 'last' => 0],
			],
		]);
		$this->urlGenerator->method('linkToRoute')->willReturn('/settings/admin/social');

		$list = $this->widget->getItemsV2('alice')->getItems();

		$this->assertCount(3, $list, 'the summary row plus one per host');
		$this->assertSame('dead.example', $list[1]->getTitle());
		$this->assertSame('4 deliveries, 14 tries, last tried 3 days ago', $list[1]->getSubtitle());
		$this->assertSame('slow.example', $list[2]->getTitle());
		$this->assertSame(
			'1 delivery, 1 try',
			$list[2]->getSubtitle(),
			'a host never tried has no last attempt to report'
		);
	}

	public function testFailureLeavesTheTileEmptyWithAMessage(): void {
		$this->moderatorService->method('isModerator')->willReturn(true);
		$this->federationHealthService->method('summary')->willThrowException(new Exception('nope'));

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Could not load federation health', $items->getEmptyContentMessage());
	}
}
