<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Activity;

use OCA\Social\Activity\Filter;
use OCA\Social\Activity\Setting;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class SettingAndFilterTest extends TestCase {
	public function testTheSettingIsInTheStreamByDefaultAndNeverASecondBell(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$setting = new Setting($l10n);

		$this->assertSame('social', $setting->getIdentifier());
		$this->assertSame('social', $setting->getGroupIdentifier());
		$this->assertSame('Aloha Social', $setting->getGroupName());
		$this->assertStringContainsString('<strong>followed</strong>', $setting->getName());
		$this->assertTrue($setting->canChangeStream());
		$this->assertTrue($setting->isDefaultEnabledStream());
		$this->assertTrue($setting->canChangeMail());
		$this->assertFalse($setting->isDefaultEnabledMail(), 'mail is opt-in');
		$this->assertFalse($setting->canChangeNotification(), 'this app has a bell of its own');
		$this->assertFalse($setting->isDefaultEnabledNotification());
	}

	public function testTheFilterNarrowsTheStreamToThisApp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->with('social', 'social-dark.svg')->willReturn('/apps/social/img/social-dark.svg');
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);
		$filter = new Filter($l10n, $urlGenerator);

		$this->assertSame('social', $filter->getIdentifier());
		$this->assertSame('Aloha Social', $filter->getName());
		$this->assertSame(['social'], $filter->allowedApps());
		$this->assertSame(['a', 'b'], $filter->filterTypes(['a', 'b']), 'the app is the filter; every type of it passes');
		$this->assertSame('https://cloud.example/apps/social/img/social-dark.svg', $filter->getIcon());
	}
}
