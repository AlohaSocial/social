<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use InvalidArgumentException;
use OCA\Social\Model\ProfileLayout;
use PHPUnit\Framework\TestCase;

class ProfileLayoutTest extends TestCase {
	public function testOldProfilesRetainEveryTabAndOpenOnPosts(): void {
		self::assertSame(ProfileLayout::DEFAULT, ProfileLayout::normalize([]));
	}

	public function testAPhotoProfileCanHideOptionalTabsWithoutChangingTheirOrder(): void {
		self::assertSame(['default_tab' => 'photos', 'tabs' => ['posts', 'photos']], ProfileLayout::normalize([
			'default_tab' => 'photos', 'tabs' => ['photos', 'posts'],
		], true));
	}

	public function testAHiddenDefaultIsRefusedInsteadOfSilentlySaved(): void {
		$this->expectException(InvalidArgumentException::class);
		ProfileLayout::normalize(['default_tab' => 'videos', 'tabs' => ['posts', 'photos']], true);
	}

	public function testMalformedRemotePreferencesFallBackToTheExistingLayout(): void {
		foreach ([['tabs' => false], ['tabs' => ['private']], ['tabs' => []], ['default_tab' => ['photos']]] as $input) {
			self::assertSame(ProfileLayout::DEFAULT, ProfileLayout::normalize($input));
		}
	}
}
