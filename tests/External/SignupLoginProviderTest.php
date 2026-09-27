<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\External;

use OCA\Social\External\SignupLoginProvider;
use OCA\Social\Service\ExternalUserService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class SignupLoginProviderTest extends TestCase {
	private function provider(bool $room, string $mode): SignupLoginProvider {
		$service = $this->createStub(ExternalUserService::class);
		$service->method('hasRoom')->willReturn($room);
		$service->method('mode')->willReturn($mode);
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/apps/social/signup');
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new SignupLoginProvider($service, $url, $l10n);
	}

	public function testTheLoginPageOffersRegistrationWhileThereIsRoom(): void {
		$logins = $this->provider(true, ExternalUserService::MODE_APPROVAL)->getAlternativeLogins();

		$this->assertCount(1, $logins);
		$this->assertSame('Create an account', $logins[0]->getLabel());
		$this->assertSame('/apps/social/signup', $logins[0]->getLink());
	}

	public function testNothingIsOfferedWhenFullOffOrByInvitationOnly(): void {
		$this->assertSame([], $this->provider(false, ExternalUserService::MODE_OPEN)->getAlternativeLogins());
		$this->assertSame([], $this->provider(true, ExternalUserService::MODE_INVITE)->getAlternativeLogins());
	}
}
