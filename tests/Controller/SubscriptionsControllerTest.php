<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\SubscriptionsController;
use OCA\Social\Service\SubscriptionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SubscriptionsControllerTest extends TestCase {
	private SubscriptionService|MockObject $subscriptionService;
	private array $uploads = [];

	protected function setUp(): void {
		parent::setUp();
		$_FILES = [];
		$this->subscriptionService = $this->createMock(SubscriptionService::class);
	}

	protected function tearDown(): void {
		foreach ($this->uploads as $upload) {
			@unlink($upload);
		}
		$this->uploads = [];
		$_FILES = [];
		parent::tearDown();
	}

	private function controller(?string $userId = 'alice'): SubscriptionsController {
		return new SubscriptionsController(
			$this->createStub(IRequest::class),
			$userId,
			$this->subscriptionService,
			new NullLogger(),
		);
	}

	public function testTakeoutRejectsOversizedUploadMetadataBeforeReadingTheFile(): void {
		$_FILES['file'] = [
			'error' => UPLOAD_ERR_OK,
			'size' => 5 * 1024 * 1024 + 1,
			'tmp_name' => '/does/not/exist',
		];
		$this->subscriptionService->expects($this->never())->method('importTakeout');

		$response = $this->controller()->takeout();

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		$this->assertSame(['error' => 'that file is larger than 5 MB'], $response->getData());
	}

	public function testTakeoutCapsTheActualReadWhenUploadSizeIsMissing(): void {
		$path = tempnam(sys_get_temp_dir(), 'social-takeout-');
		$this->assertNotFalse($path);
		$this->uploads[] = $path;
		file_put_contents($path, str_repeat('x', 5 * 1024 * 1024 + 1));
		$_FILES['file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path];
		$this->subscriptionService->expects($this->never())->method('importTakeout');

		$response = $this->controller()->takeout();

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
	}

	public function testTakeoutPassesAValidCsvToTheImporter(): void {
		$path = tempnam(sys_get_temp_dir(), 'social-takeout-');
		$this->assertNotFalse($path);
		$this->uploads[] = $path;
		$csv = "Channel Id,Channel Url\nUC1234567890123456789012,https://youtube.com/channel/UC1234567890123456789012\n";
		file_put_contents($path, $csv);
		$_FILES['file'] = ['error' => UPLOAD_ERR_OK, 'size' => strlen($csv), 'tmp_name' => $path];
		$this->subscriptionService->expects($this->once())->method('importTakeout')
			->with('alice', $csv)
			->willReturn(1);

		$response = $this->controller()->takeout();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['subscribed' => 1], $response->getData());
	}
}
