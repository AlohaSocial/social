<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Actor;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\VerificationService;
use PHPUnit\Framework\TestCase;

/**
 * The account entity's `verification`: this instance's verification of the
 * account, read from the service rather than from the cached actor.
 */
class PersonVerificationTest extends TestCase {
	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private static function alice(): array {
		$person = new Person();
		$person->setId('https://cloud.example.org/apps/social/@alice');
		$person->setPreferredUsername('alice');
		$person->setUrlSocial('https://cloud.example.org/apps/social/');

		return $person->exportAsLocal();
	}

	public function testAVerifiedAccountSaysInWhoseNameAndWhen(): void {
		$verifications = $this->createStub(VerificationService::class);
		$verifications->method('exportOf')->willReturnCallback(static fn (string $id): ?array => $id === 'https://cloud.example.org/apps/social/@alice'
			? ['by' => 'Example Inc', 'issuer' => 'did:plc:company', 'created_at' => '2026-10-09T10:00:00.000Z']
			: null);
		\OC::$server->register(VerificationService::class, $verifications);

		$this->assertSame(['by' => 'Example Inc', 'issuer' => 'did:plc:company', 'created_at' => '2026-10-09T10:00:00.000Z'], self::alice()['verification']);
	}

	public function testAnAccountNobodyVerifiedSaysNull(): void {
		$verifications = $this->createStub(VerificationService::class);
		$verifications->method('exportOf')->willReturn(null);
		\OC::$server->register(VerificationService::class, $verifications);

		$this->assertNull(self::alice()['verification']);
	}

	public function testWithoutTheServiceTheEntityStillExports(): void {
		$this->assertArrayHasKey('verification', self::alice());
		$this->assertNull(self::alice()['verification']);
	}
}
