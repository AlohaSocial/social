<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use InvalidArgumentException;
use OCA\Social\Atproto\Identity\CustomHandleService;
use OCA\Social\Atproto\Identity\HandleVerifier;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class CustomHandleServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	/** @var HandleVerifier&MockObject */
	private HandleVerifier $verifier;
	/** @var AtprotoIdentityRequest&MockObject */
	private AtprotoIdentityRequest $request;
	private CustomHandleService $handles;
	private Identity $alice;

	protected function setUp(): void {
		$this->alice = self::identity();
		$this->identities = $this->createMock(IdentityService::class);
		$this->verifier = $this->createMock(HandleVerifier::class);
		$this->verifier->method('refusal')->willReturnCallback(static fn (string $h): string => str_ends_with($h, '.social.test') ? 'ours' : '');
		$this->request = $this->createMock(AtprotoIdentityRequest::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->handles = new CustomHandleService($this->identities, $this->verifier, $this->request, $time, new NullLogger());
	}

	private static function identity(string $custom = '', int $failures = 0, string $state = Identity::STATE_ACTIVE): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, $custom !== '' ? $custom : 'alice.social.test', '', '', '', $state, '', 0, 'alice.social.test', $custom, $failures);
	}

	public function testAVerifiedDomainBecomesTheHandle(): void {
		$this->verifier->method('verify')->with('alice.example.org', self::DID)->willReturn('dns');
		$updated = self::identity('alice.example.org');
		$this->identities->expects($this->once())->method('useCustomHandle')->with($this->alice, 'alice.example.org')->willReturn($updated);

		$this->assertSame($updated, $this->handles->set($this->alice, '@Alice.Example.org'));
		$this->assertSame('alice.example.org', $updated->handle);
		$this->assertSame('alice.social.test', $updated->assignedHandle(), 'the assigned handle keeps resolving');
	}

	public function testADomainThatDoesNotNameTheDidOrIsRefusedOrTakenIsNotUsed(): void {
		$this->identities->expects($this->never())->method('useCustomHandle');
		$this->verifier->method('verify')->willReturn('');
		$this->request->method('handleExists')->willReturnCallback(static fn (string $h): bool => $h === 'bob.example.org');

		foreach (['alice.example.org' => 'names', 'bob.example.org' => 'another account', 'x.social.test' => 'ours'] as $handle => $message) {
			try {
				$this->handles->set($this->alice, $handle);
				$this->fail('used ' . $handle);
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}
		$this->expectException(InvalidArgumentException::class);
		$this->handles->set(self::identity('', 0, Identity::STATE_DEACTIVATED), 'alice.example.org');
	}

	public function testTheAssignedHandleComesBack(): void {
		$custom = self::identity('alice.example.org');
		$this->identities->expects($this->once())->method('useCustomHandle')->with($custom, '')->willReturn($this->alice);

		$this->assertSame($this->alice, $this->handles->clear($custom));
		$this->assertSame($this->alice, $this->handles->clear($this->alice), 'nothing to do without one');
	}

	public function testHandlesAreCheckedAgainAndABrokenOneShows(): void {
		$this->request->method('getCustomHandlesDue')->willReturn([self::identity('alice.example.org'), self::identity('gone.example.org')]);
		$this->verifier->method('verify')->willReturnCallback(static fn (string $h): string => $h === 'alice.example.org' ? 'https' : '');
		$checked = [];
		$this->request->method('customHandleChecked')->willReturnCallback(function (string $did, bool $ok) use (&$checked): void {
			$checked[] = $ok;
		});

		$this->assertSame(1, $this->handles->recheck());
		$this->assertSame([true, false], $checked);
		$this->assertFalse(self::identity('gone.example.org', 1)->customHandleBroken(), 'one failed check is not yet broken');
		$this->assertTrue(self::identity('gone.example.org', 2)->customHandleBroken());
	}
}
