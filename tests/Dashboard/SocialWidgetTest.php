<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use OCA\Social\Dashboard\SocialWidget;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SocialWidgetTest extends TestCase {
	/** @var IL10N&MockObject */
	private $l10n;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	private SocialWidget $widget;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->widget = new SocialWidget($this->l10n, $this->urlGenerator);
	}

	public function testIsADashboardWidgetWithAStableId(): void {
		$this->assertInstanceOf(IWidget::class, $this->widget);
		$this->assertSame('social_notifications', $this->widget->getId());
		$this->assertSame(10, $this->widget->getOrder());
		$this->assertSame('icon-social', $this->widget->getIconClass());
	}

	public function testTitleIsTranslated(): void {
		$this->l10n->method('t')->with('Social notifications')->willReturn('Soziale Benachrichtigungen');

		$this->assertSame('Soziale Benachrichtigungen', $this->widget->getTitle());
	}

	public function testUrlPointsAtThePageAndNotAtTheApi(): void {
		$this->urlGenerator->method('linkToRoute')
			->with('social.Navigation.timeline', ['path' => 'notifications'])
			->willReturn('/apps/social/timeline/notifications');

		$this->assertSame('/apps/social/timeline/notifications', $this->widget->getUrl());
	}
}
