<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Publisher\BlueskyVerifications;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The instance's verifications as records in its verifying account's
 * repository: one per verified DID, written again in place of the last.
 */
#[AllowMockObjectsWithoutExpectations]
class BlueskyVerificationsTest extends TestCase {
	/** @var list<array{string, string, ...}> what was asked of the publisher, in order */
	private array $calls = [];
	private bool $fail = false;

	private function verifications(): BlueskyVerifications {
		$publisher = $this->createMock(Publisher::class);
		$publisher->method('writeRecord')->willReturnCallback(function (Person $actor, string $collection, array $record, string $localId): bool {
			if ($this->fail) {
				throw new AtprotoException('the repository is gone');
			}
			$this->calls[] = ['write', $actor->getId(), $collection, $record, $localId];

			return true;
		});
		$publisher->method('removeRecord')->willReturnCallback(function (string $collection, string $localId): bool {
			$this->calls[] = ['remove', $collection, $localId];

			return true;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);

		return new BlueskyVerifications($publisher, $time, new NullLogger());
	}

	private static function verifier(): Person {
		return (new Person())->setId('https://social.test/@company')->setUserId('company');
	}

	public function testAVerificationIsARecordNamingTheAccountAsItIsNowInPlaceOfTheLast(): void {
		$this->assertTrue($this->verifications()->publish(self::verifier(), 'did:plc:anna', 'anna.social.test', 'Anna'));

		$record = [
			'$type' => 'app.bsky.graph.verification',
			'subject' => 'did:plc:anna',
			'handle' => 'anna.social.test',
			'displayName' => 'Anna',
			'createdAt' => '2025-10-09T08:53:20.000Z',
		];
		$this->assertSame([
			['remove', BlueskyVerifications::COLLECTION, 'verification:did:plc:anna'],
			['write', 'https://social.test/@company', BlueskyVerifications::COLLECTION, $record, 'verification:did:plc:anna'],
		], $this->calls, 'the AppView keeps one verification per issuer and subject: the old one goes first');
		(new Lexicon())->validateRecord($record);
	}

	public function testAnAccountWithoutADidOrAResolvingHandleIsNotPublished(): void {
		$this->assertFalse($this->verifications()->publish(self::verifier(), '', 'bob.example', 'Bob'));
		$this->assertFalse($this->verifications()->publish(self::verifier(), 'did:plc:bob', 'handle.invalid', 'Bob'));
		$this->assertFalse($this->verifications()->publish(self::verifier(), 'did:plc:bob', 'not a handle', 'Bob'));
		$this->assertSame([], $this->calls);
	}

	public function testAFailedWriteIsLoggedAndAnswersFalse(): void {
		$this->fail = true;

		$this->assertFalse($this->verifications()->publish(self::verifier(), 'did:plc:anna', 'anna.social.test', 'Anna'));
	}

	public function testWithdrawingRemovesTheRecordWhereverItIs(): void {
		$this->assertTrue($this->verifications()->withdraw('did:plc:anna'));
		$this->assertFalse($this->verifications()->withdraw(''));
		$this->assertSame([['remove', BlueskyVerifications::COLLECTION, 'verification:did:plc:anna']], $this->calls);
	}
}
