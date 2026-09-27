<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ExternalMediaQuota;
use PHPUnit\Framework\TestCase;

class ExternalMediaQuotaTest extends TestCase {
	private function quota(int $megabytes, int $used): ExternalMediaQuota {
		$backend = $this->createStub(ExternalUserBackend::class);
		$backend->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'alice');
		$backend->method('canonicalUid')->willReturnCallback(static fn (string $uid): ?string => ($uid === 'alice' || $uid === 'Alice') ? 'alice' : null);
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueInt')->willReturn($megabytes);
		$documents = $this->createStub(CacheDocumentsRequest::class);
		$documents->method('localBytesOf')->willReturn($used);

		return new ExternalMediaQuota($backend, $config, $documents);
	}

	public function testAnUploadThatFitsIsAcceptedAndOneThatWouldGoOverIsNot(): void {
		$quota = $this->quota(10, 9 * 1048576);

		$this->assertTrue($quota->fits('alice', 1048576));
		$this->assertFalse($quota->fits('alice', 1048577));
	}

	public function testOnlyExternalUsersHaveAQuota(): void {
		$quota = $this->quota(1, 50 * 1048576);

		$this->assertTrue($quota->fits('bob', 50 * 1048576));
		$this->assertTrue($quota->fits('', 50 * 1048576));
		$this->assertFalse($quota->fits('alice', 1));
	}

	public function testZeroIsNoQuota(): void {
		$this->assertTrue($this->quota(0, PHP_INT_MAX >> 2)->fits('alice', 1048576));
	}

	public function testTheUsageIsShownToExternalUsersOnly(): void {
		$quota = $this->quota(1024, 12345);

		$this->assertSame(['quota' => 1024, 'used' => 12345], $quota->usageOf('Alice'));
		$this->assertNull($quota->usageOf('bob'));
		$this->assertNull($quota->usageOf(''));
	}
}
