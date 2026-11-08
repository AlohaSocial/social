<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\AboutController;
use OCA\Social\Service\AboutService;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class AboutControllerTest extends TestCase {
	public function testItAnswersWhatTheServiceSaysToSignedInUsersOnly(): void {
		$about = ['name' => 'Cloud', 'rules' => []];
		$service = $this->createMock(AboutService::class);
		$service->expects($this->once())->method('about')->willReturn($about);

		$response = (new AboutController($this->createStub(IRequest::class), $service))->info();

		$this->assertSame($about, $response->getData());
		$this->assertSame([], (new \ReflectionMethod(AboutController::class, 'info'))->getAttributes(PublicPage::class));
	}
}
