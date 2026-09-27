<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Listeners\ExternalPageListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ExternalPageListenerTest extends TestCase {
	protected function setUp(): void {
		\OC_Util::$styles = [];
	}

	private function listener(bool $external): ExternalPageListener {
		$user = $this->createStub(IUser::class);
		$user->method('getBackend')->willReturn($external ? $this->createStub(ExternalUserBackend::class) : null);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ExternalPageListener($session);
	}

	private function page(bool $loggedIn): BeforeTemplateRenderedEvent {
		return new BeforeTemplateRenderedEvent($loggedIn, new TemplateResponse('social', 'main'));
	}

	public function testAnExternalUsersPagesHideWhatTheyCannotUse(): void {
		$this->listener(true)->handle($this->page(true));

		$this->assertSame(['social/external-scope'], \OC_Util::$styles);
		$this->assertFileExists(__DIR__ . '/../../css/external-scope.css');
	}

	public function testNobodyElsesPagesChange(): void {
		$this->listener(false)->handle($this->page(true));
		$this->listener(true)->handle($this->page(false));

		$this->assertSame([], \OC_Util::$styles);
	}
}
