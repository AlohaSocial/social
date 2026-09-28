<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Controller\IntroductionController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class IntroductionControllerTest extends TestCase {
	public function testDismissalIsStoredForTheSignedInUser(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('setUserValue')
			->with('alice', Application::APP_ID, 'introduction_dismissed', '1');

		$response = (new IntroductionController($this->createStub(IRequest::class), 'alice', $config))->dismiss();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['dismissed' => true], $response->getData());
	}

	public function testAnonymousRequestCannotWriteDismissal(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setUserValue');

		$response = (new IntroductionController($this->createStub(IRequest::class), null, $config))->dismiss();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}
}
