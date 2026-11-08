<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\OpaqueRecordQuota;
use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The per-account cap on records in collections Social has no role for.
 */
#[AllowMockObjectsWithoutExpectations]
class OpaqueRecordQuotaTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const MB = 1024 * 1024;

	private function quota(int $maxRecords, int $maxMb, int $records, int $bytes, ?int &$reads = null): OpaqueRecordQuota {
		$reads = 0;
		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValueInt')->willReturnCallback(static fn (string $key): int => match ($key) {
			ConfigService::ATPROTO_OPAQUE_RECORDS => $maxRecords,
			ConfigService::ATPROTO_OPAQUE_MB => $maxMb,
			default => 0,
		});
		$repositories = $this->createMock(AtprotoRepoRequest::class);
		$repositories->method('usageOutside')->willReturnCallback(function (string $did, array $collections) use ($records, $bytes, &$reads): array {
			$reads++;
			$this->assertSame(self::DID, $did);
			$this->assertSame(WriteService::roleCollections(), $collections);

			return ['records' => $records, 'bytes' => $bytes];
		});

		return new OpaqueRecordQuota($config, $repositories);
	}

	public static function within(): array {
		return [
			'room for one more' => [10000, 50, 9999, 10 * self::MB, 1, 100],
			'exactly at the byte limit' => [10000, 50, 10, 50 * self::MB - 100, 1, 100],
			'records unlimited' => [0, 50, 1_000_000, 0, 1, 100],
			'bytes unlimited' => [10000, 0, 0, 900 * self::MB, 1, 100],
			'a replacement that adds no record at the record limit' => [10000, 50, 10000, 0, 0, 100],
		];
	}

	#[DataProvider('within')]
	public function testAWriteWithinTheLimitsPasses(int $maxRecords, int $maxMb, int $records, int $bytes, int $adds, int $addsBytes): void {
		$this->quota($maxRecords, $maxMb, $records, $bytes)->assertRoom(self::DID, $adds, $addsBytes);
		$this->addToAssertionCount(1);
	}

	public static function over(): array {
		return [
			'one record too many' => [10000, 50, 10000, 0, 1, 10],
			'one byte too many' => [10000, 50, 10, 50 * self::MB, 0, 1],
		];
	}

	#[DataProvider('over')]
	public function testAWritePastALimitIsRefusedWithTheLimits(int $maxRecords, int $maxMb, int $records, int $bytes, int $adds, int $addsBytes): void {
		try {
			$this->quota($maxRecords, $maxMb, $records, $bytes)->assertRoom(self::DID, $adds, $addsBytes);
			$this->fail('the write went past the cap');
		} catch (XrpcException $e) {
			$this->assertSame(400, $e->status);
			$this->assertSame('InvalidRequest', $e->error);
			$this->assertStringContainsString('10,000 records and 50 MB', $e->getMessage());
		}
	}

	public function testAWriteThatFreesRoomOrHasNoLimitDoesNotReadTheUsage(): void {
		$this->quota(10000, 50, 20000, 100 * self::MB, $reads)->assertRoom(self::DID, -1, -500);
		$this->quota(0, 0, 20000, 100 * self::MB, $unlimited)->assertRoom(self::DID, 1, 500);

		$this->assertSame(0, $reads, 'an account over the cap can still delete');
		$this->assertSame(0, $unlimited);
	}
}
