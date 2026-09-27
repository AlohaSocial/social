<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\RenditionsRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VideoQuotaService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * How much video one account may keep, and what counts towards it.
 */
class VideoQuotaServiceTest extends TestCase {
	private CacheDocumentsRequest|Stub $cacheDocumentsRequest;
	private ConfigService|Stub $configService;
	private IConfig|Stub $config;
	private VideoQuotaService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->cacheDocumentsRequest = $this->createStub(CacheDocumentsRequest::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->config = $this->createStub(IConfig::class);

		$this->service = new VideoQuotaService(
			$this->cacheDocumentsRequest,
			$this->createStub(RenditionsRequest::class),
			$this->configService,
			$this->config,
		);
	}

	private function quota(int $megabytes): void {
		$this->configService->method('getAppValueInt')->willReturn($megabytes);
	}

	/**
	 * An instance that has been running without one and acquires it on
	 * upgrade would start refusing uploads from exactly the accounts that use
	 * it most.
	 */
	public function testThereIsNoQuotaUntilAnAdministratorSetsOne(): void {
		$this->quota(0);
		$this->cacheDocumentsRequest->method('videoBytesOf')->willReturn(999_999_999_999);

		$this->assertSame(VideoQuotaService::UNLIMITED, $this->service->quota());
		$this->assertTrue($this->service->fits('alice', 1_000_000_000));
	}

	public function testAnUploadThatFitsIsAccepted(): void {
		$this->quota(100);
		$this->cacheDocumentsRequest->method('videoBytesOf')->willReturn(50 * 1048576);

		$this->assertTrue($this->service->fits('alice', 40 * 1048576));
	}

	/** The incoming file counts towards it, not just what is already there. */
	public function testAnUploadThatWouldGoOverIsRefused(): void {
		$this->quota(100);
		$this->cacheDocumentsRequest->method('videoBytesOf')->willReturn(90 * 1048576);

		$this->assertFalse($this->service->fits('alice', 20 * 1048576));
	}

	/** Exactly to the line is within it. */
	public function testFillingItExactlyIsAllowed(): void {
		$this->quota(100);
		$this->cacheDocumentsRequest->method('videoBytesOf')->willReturn(60 * 1048576);

		$this->assertTrue($this->service->fits('alice', 40 * 1048576));
	}

	/**
	 * An upload with no account behind it is not somebody's upload. Nothing
	 * reaches this without one today; the check is here so that a caller that
	 * one day does is not silently held to a quota belonging to nobody.
	 */
	public function testAnUploadWithNoAccountIsNotHeldToAnybodysQuota(): void {
		$this->quota(1);
		$this->cacheDocumentsRequest->method('videoBytesOf')->willReturn(0);

		$this->assertTrue($this->service->fits('', 100 * 1048576));
	}

	// --- where the files are ----------------------------------------------

	public function testTheStoreIsTheDataDirectoryUnlessOneIsConfigured(): void {
		$this->config->method('getSystemValue')->willReturn(null);

		$this->assertSame(
			['object_store' => false, 'class' => '', 'bucket' => ''],
			$this->service->storage()
		);
	}

	public function testAConfiguredObjectStoreIsReported(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'objectstore')
				? ['class' => '\\OC\\Files\\ObjectStore\\S3', 'arguments' => ['bucket' => 'nextcloud']]
				: null
		);

		$this->assertSame(
			['object_store' => true, 'class' => '\\OC\\Files\\ObjectStore\\S3', 'bucket' => 'nextcloud'],
			$this->service->storage()
		);
	}

	/** A multibucket setup is an object store too, and says so. */
	public function testAMultibucketStoreCountsAsAnObjectStore(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'objectstore_multibucket')
				? ['class' => '\\OC\\Files\\ObjectStore\\S3', 'arguments' => []]
				: null
		);

		$storage = $this->service->storage();

		$this->assertTrue($storage['object_store']);
		$this->assertSame('', $storage['bucket']);
	}
}
