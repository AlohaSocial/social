<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Identity\DidWebResolver;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class DidWebResolverTest extends TestCase {
	public function testFetchesTheDidWebDocumentWithoutFollowingRedirects(): void {
		$did = 'did:web:example.org:users:alice';
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->once())->method('doRequest')->with(
			'get',
			'https://example.org/users/alice/did.json',
			$this->callback(static fn (array $options): bool => $options['max_redirects'] === 0
				&& $options['timeout'] === 5
				&& $options['headers']['Accept'] === 'application/did+json, application/json'),
			$this->anything(),
			$this->anything(),
		)->willReturnCallback(static function (string $method, string $url, array $options, ?string &$contentType, ?int &$status): string {
			$status = 200;

			return '{"id":"did:web:example.org:users:alice"}';
		});

		$this->assertSame(['id' => $did], DidWebResolver::document($did, $curl));
	}

	public function testRejectsUnsafeAuthoritiesAndPathTraversalBeforeFetching(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->never())->method('doRequest');

		$this->assertNull(DidWebResolver::document('did:web:localhost', $curl));
		$this->assertNull(DidWebResolver::document('did:web:127.0.0.1', $curl));
		$this->assertNull(DidWebResolver::document('did:web:example.org:..:secret', $curl));
	}

	public function testRejectsDocumentsForAnotherDid(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(static function (string $method, string $url, array $options, ?string &$contentType, ?int &$status): string {
			$status = 200;

			return '{"id":"did:web:someone-else.example.org"}';
		});

		$this->assertNull(DidWebResolver::document('did:web:example.org', $curl));
	}

	public function testDistinguishesAnUnreachableDidWebHostFromAnUnknownDid(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willThrowException(new RuntimeException('unreachable'));

		$this->expectException(AtprotoException::class);
		DidWebResolver::document('did:web:offline.example.org', $curl);
	}
}
