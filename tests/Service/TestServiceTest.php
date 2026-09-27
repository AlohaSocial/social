<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\TestService;
use OCA\Social\Tools\Model\SimpleDataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only the input validation of TestService::testWebfinger() is covered here.
 *
 * The diagnostic steps themselves cannot run on PHP 8: OCA\Social\Model\Test
 * never initialises SimpleDataStore::$data (its constructor skips
 * parent::__construct()), so Test::aArray()/a() throw a TypeError as soon as
 * the "actor-link" step records its result.
 */
class TestServiceTest extends TestCase {
	private CurlService|MockObject $curlService;
	private TestService $service;

	protected function setUp(): void {
		$this->curlService = $this->createMock(CurlService::class);
		AP::set($this->createMock(AP::class));
		$this->service = new TestService(
			$this->curlService,
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	/** @return array<string, array{string}> */
	public static function invalidAccountProvider(): array {
		return [
			'no host' => ['bob'],
			'leading at only' => ['@bob'],
			'empty host' => ['bob@'],
			'empty user' => ['@mastodon.example'],
			'spaces' => ['bob smith@mastodon.example'],
			'url instead of handle' => ['https://mastodon.example/users/bob'],
			'empty' => [''],
		];
	}

	#[DataProvider('invalidAccountProvider')]
	public function testRejectsAccountsThatAreNotAddressesBeforeAnyLookup(string $account): void {
		$this->curlService->expects($this->never())->method('hostMeta');
		$this->curlService->expects($this->never())->method('retrieveJson');
		$store = new SimpleDataStore(['account' => $account]);

		try {
			$this->service->testWebfinger($store);
			$this->fail('expected InvalidResourceException');
		} catch (InvalidResourceException $e) {
			$this->assertSame('account format is not valid', $e->getMessage());
			$this->assertSame([], $store->gArray('tests'), 'no diagnostic step was recorded');
		}
	}

	public function testAValidHandleReachesTheHostMetaLookupWithItsHost(): void {
		// the leading @ is stripped and the host is what gets probed; the run
		// itself cannot complete (see the class comment), so stop it right here
		$this->curlService->expects($this->once())
			->method('hostMeta')
			->with('mastodon.example', ['https', 'http'])
			->willThrowException(new \RuntimeException('stop here'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('stop here');
		$this->service->testWebfinger(new SimpleDataStore(['account' => '@bob@mastodon.example']));
	}
}
