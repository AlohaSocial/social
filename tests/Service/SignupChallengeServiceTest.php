<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\DurableCache;
use OCA\Social\Service\ExternalUserService;
use OCA\Social\Service\SignupChallengeService;
use OCA\Social\Tests\Helper\FrozenClock;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SignupChallengeServiceTest extends TestCase {
	private const NOW = 3000000;

	private InMemoryDurableCacheRequest $table;
	private FrozenClock $clock;
	private int $bits = 12;
	private int $ids = 0;

	protected function setUp(): void {
		$this->table = new InMemoryDurableCacheRequest();
		$this->clock = new FrozenClock(self::NOW);
	}

	private function service(string $secret = 'secret'): SignupChallengeService {
		$users = $this->createStub(ExternalUserService::class);
		$users->method('proofOfWorkBits')->willReturnCallback(fn (): int => $this->bits);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (): string => 'id' . ++$this->ids);
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn($secret);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);

		return new SignupChallengeService($users, new DurableCache($cacheFactory, $this->table, $this->clock), $random, $config, $this->clock);
	}

	/** The work a browser does: the first nonce, counted up in base 36, that reaches the bits. */
	public static function solve(string $challenge, int $bits): string {
		for ($i = 0; ; $i++) {
			$nonce = base_convert((string)$i, 10, 36);
			if (SignupChallengeService::leadingZeroBits(hash('sha256', $challenge . $nonce, true)) >= $bits) {
				return $nonce;
			}
		}
	}

	/** @return array<string, array{string, int}> */
	public static function digests(): array {
		return [
			'nothing' => ['', 0],
			'a set first bit' => ["\x80\x00", 0],
			'one zero byte' => ["\x00\xff", 8],
			'a byte and three bits' => ["\x00\x1f\x00", 11],
			'the lowest bit' => ["\x01", 7],
			'all zero' => ["\x00\x00", 16],
		];
	}

	#[DataProvider('digests')]
	public function testLeadingZeroBitsAreCounted(string $bytes, int $bits): void {
		$this->assertSame($bits, SignupChallengeService::leadingZeroBits($bytes));
	}

	public function testAnIssuedChallengeNamesItsDifficultyAndExpiry(): void {
		$this->bits = 16;
		$issued = $this->service()->issue();

		$this->assertSame(16, $issued['bits']);
		$this->assertSame(self::NOW + SignupChallengeService::TTL, $issued['expires']);
		$this->assertStringStartsWith((self::NOW + SignupChallengeService::TTL) . '.16.id1.', $issued['challenge']);
	}

	public function testASolvedChallengeIsAcceptedOnce(): void {
		$service = $this->service();
		$challenge = $service->issue()['challenge'];
		$nonce = self::solve($challenge, 12);

		$this->assertTrue($service->verify($challenge, $nonce));
		$this->assertFalse($service->verify($challenge, $nonce));
		$this->assertFalse($this->service()->verify($challenge, $nonce));
	}

	public function testAWrongAnswerIsRefusedAndDoesNotUseTheChallengeUp(): void {
		$service = $this->service();
		$challenge = $service->issue()['challenge'];
		$nonce = self::solve($challenge, 12);
		$wrong = '0';
		for ($i = 0; SignupChallengeService::leadingZeroBits(hash('sha256', $challenge . $wrong, true)) >= 12; $i++) {
			$wrong = 'x' . $i;
		}

		$this->assertFalse($service->verify($challenge, $wrong));
		$this->assertTrue($service->verify($challenge, $nonce));
	}

	public function testAnExpiredChallengeIsRefused(): void {
		$service = $this->service();
		$challenge = $service->issue()['challenge'];
		$nonce = self::solve($challenge, 12);

		$this->clock->set(self::NOW + SignupChallengeService::TTL);
		$this->assertFalse($service->verify($challenge, $nonce));
	}

	/** The difficulty and expiry are signed: lowering either breaks the signature. */
	public function testAChangedChallengeIsRefused(): void {
		$service = $this->service();
		$challenge = $service->issue()['challenge'];
		[$expires, , $id, $mac] = explode('.', $challenge);

		foreach ([
			$expires . '.1.' . $id . '.' . $mac,
			($expires + 3600) . '.12.' . $id . '.' . $mac,
			$expires . '.12.other.' . $mac,
		] as $forged) {
			$this->assertFalse($service->verify($forged, self::solve($forged, 12)), $forged);
		}
		$this->assertFalse($this->service('another secret')->verify($challenge, self::solve($challenge, 12)));
	}

	public function testMalformedInputIsRefused(): void {
		$service = $this->service();
		$challenge = $service->issue()['challenge'];

		$this->assertFalse($service->verify('', '1'));
		$this->assertFalse($service->verify('a.b.c', '1'));
		$this->assertFalse($service->verify($challenge, ''));
		$this->assertFalse($service->verify($challenge, 'not-alphanumeric'));
		$this->assertFalse($service->verify($challenge, str_repeat('a', SignupChallengeService::NONCE_MAX_LENGTH + 1)));
	}
}
