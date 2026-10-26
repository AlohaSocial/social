<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Identity\DnsLookup;
use OCA\Social\Atproto\Identity\HandleVerifier;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class HandleVerifierTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var array<string, string[]> */
	private array $txt = [];
	/** @var array<string, array{0: int, 1: string}> */
	private array $https = [];
	/** @var string[] */
	private array $fetched = [];
	private HandleVerifier $verifier;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('handleHost')->willReturn('social.test');
		$dns = $this->createMock(DnsLookup::class);
		$dns->method('txt')->willReturnCallback(fn (string $name): array => $this->txt[$name] ?? []);
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $method, string $url, array $options, &$contentType = null, &$status = null): string {
			$this->fetched[] = $url;
			$this->assertSame(0, $options['max_redirects'], 'the file is read where the handle is, not where it sends');
			if (!isset($this->https[$url])) {
				throw new RuntimeException('unreachable');
			}
			$status = $this->https[$url][0];

			return $this->https[$url][1];
		});
		$this->verifier = new HandleVerifier($config, $dns, $curl);
	}

	public function testATxtRecordNamesTheDid(): void {
		$this->txt['_atproto.alice.example.org'] = ['v=spf1 -all', 'did=' . self::DID];

		$this->assertSame('dns', $this->verifier->verify('alice.example.org', self::DID));
		$this->assertSame([], $this->fetched, 'no request when DNS answers');
	}

	public function testOrTheWellKnownFileDoes(): void {
		$this->https['https://alice.example.org/.well-known/atproto-did'] = [200, self::DID . "\n"];

		$this->assertSame('https', $this->verifier->verify('alice.example.org', self::DID));
	}

	public function testAnotherDidOrNoAnswerIsNoHandle(): void {
		$this->txt['_atproto.alice.example.org'] = ['did=did:plc:z72i7hdynmk6r22z27h6tvur'];
		$this->https['https://alice.example.org/.well-known/atproto-did'] = [404, self::DID];
		$this->assertSame('', $this->verifier->verify('alice.example.org', self::DID));

		$this->assertSame('', $this->verifier->verify('nothing.example.org', self::DID), 'unreachable');
	}

	public function testResolvesATxtPublishedHandle(): void {
		$this->txt['_atproto.alice.example.org'] = ['did=' . self::DID];

		$this->assertSame(self::DID, $this->verifier->resolve('Alice.Example.ORG'));
		$this->assertSame([], $this->fetched);
	}

	public function testResolvesAWellKnownPublishedHandle(): void {
		$this->https['https://alice.example.org/.well-known/atproto-did'] = [200, self::DID . "\n"];

		$this->assertSame(self::DID, $this->verifier->resolve('alice.example.org'));
	}

	public function testDoesNotResolveMalformedOrLocallyAssignedHandles(): void {
		$this->assertNull($this->verifier->resolve('not a handle'));
		$this->assertNull($this->verifier->resolve('alice.social.test'));
		$this->assertNull($this->verifier->resolve('bad.example.org'));
	}

	public function testWhatCannotBeACustomHandle(): void {
		$this->assertSame('', $this->verifier->refusal('alice.example.org'));
		$this->assertNotSame('', $this->verifier->refusal('alice.social.test'), 'this server\'s namespace');
		$this->assertNotSame('', $this->verifier->refusal('social.test'));
		$this->assertNotSame('', $this->verifier->refusal('not a domain'));
		$this->assertNotSame('', $this->verifier->refusal('alice.invalid'));
		$this->assertSame('alice.example.org', HandleVerifier::normal(' @Alice.Example.ORG. '));
	}
}
