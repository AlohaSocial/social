<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use InvalidArgumentException;
use OCA\Social\AlohaDirectory\DirectoryApiException;
use OCA\Social\AlohaDirectory\DirectoryClient;
use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\DirectoryKey;
use OCA\Social\AlohaDirectory\DirectoryParticipation;
use OCA\Social\AlohaDirectory\HeartbeatBuilder;
use OCA\Social\AlohaDirectory\ProfileBuilder;
use OCA\Social\Model\Instance;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class DirectoryParticipationTest extends TestCase {
	private AppConfigStore $store;
	private DirectoryConfig $config;
	private DirectoryClient&MockObject $client;
	private ProfileBuilder $profile;
	/** @var list<string> */
	private array $missing = [];
	private int $now = 1794586000;
	private DirectoryParticipation $participation;

	protected function setUp(): void {
		$this->store = new AppConfigStore();
		$cipher = $this->createStub(PrivateKeyCipher::class);
		$cipher->method('seal')->willReturnCallback(static fn (string $value): string => 'sealed:' . $value);
		$cipher->method('open')->willReturnCallback(static fn (string $value): string => str_starts_with($value, 'sealed:') ? substr($value, 7) : '');
		$this->config = new DirectoryConfig($this->store->mock($this), $cipher);
		$this->client = $this->createMock(DirectoryClient::class);

		$this->profile = $this->createStub(ProfileBuilder::class);
		$this->profile->method('build')->willReturn(['identity' => ['name' => 'Cloud'], 'etag' => 'W/"1"']);
		$this->profile->method('missing')->willReturnCallback(fn (): array => $this->missing);
		$heartbeat = $this->createStub(HeartbeatBuilder::class);
		$heartbeat->method('build')->willReturn(['domain' => 'cloud.example.com']);
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudHost')->willReturn('cloud.example.com');
		$instanceService = $this->createStub(InstanceService::class);
		$instanceService->method('getLocal')->willReturn((new Instance())->setEmail('admin@example.com'));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->participation = new DirectoryParticipation(
			$this->config, $this->client, $this->profile, $heartbeat, $configService, $instanceService, $time, new NullLogger(),
		);
	}

	private function enrol(): void {
		$this->client->method('enrol')->willReturn(['challenge' => 'the-challenge']);
		$this->client->method('verify')->willReturn(['status' => 'queued']);
		$this->participation->enrol();
	}

	public function testEnrollingServesTheChallengeAndAsksForVerification(): void {
		$this->client->expects($this->once())->method('enrol')
			->with('cloud.example.com', $this->isInstanceOf(DirectoryKey::class), 'admin@example.com')
			->willReturn(['challenge' => 'the-challenge']);
		$this->client->expects($this->once())->method('verify');

		$state = $this->participation->enrol();

		$this->assertSame(DirectoryParticipation::PHASE_VERIFYING, $state['phase']);
		$this->assertSame('the-challenge', $this->config->challenge());
		$this->assertSame($state['pendingKeyId'], $this->config->key(DirectoryConfig::PENDING_KEY)?->keyId());
		$this->assertTrue($this->config->advertises());
	}

	public function testAnIncompleteProfileCannotEnrol(): void {
		$this->missing = ['operator.name'];
		$this->client->expects($this->never())->method('enrol');

		$this->expectException(InvalidArgumentException::class);
		$this->participation->enrol();
	}

	public function testAFailedVerificationCanBeRetried(): void {
		$this->enrol();
		$this->client->method('verificationStatus')->willReturn(['status' => 'failed', 'error' => 'challenge_mismatch', 'message' => 'wrong challenge']);

		$state = $this->participation->checkVerification();
		$this->assertSame(DirectoryParticipation::PHASE_PENDING, $state['phase']);
		$this->assertSame('challenge_mismatch', $state['verification']['error']);

		$this->assertSame(DirectoryParticipation::PHASE_VERIFYING, $this->participation->requestVerification()['phase']);
	}

	public function testAnExpiredChallengeMustEnrolAgain(): void {
		$this->enrol();
		$this->client->method('verificationStatus')->willReturn(['status' => 'failed']);
		$this->participation->checkVerification();
		$this->now += 3601;

		try {
			$this->participation->requestVerification();
			$this->fail('an expired challenge cannot be verified');
		} catch (InvalidArgumentException) {
			$this->assertSame(DirectoryParticipation::PHASE_NONE, $this->participation->phase());
			$this->assertSame('', $this->config->challenge());
		}
	}

	public function testOnceVerifiedTheKeyIsInUseAndProfileAndHeartbeatAreSent(): void {
		$this->enrol();
		$pending = $this->config->key(DirectoryConfig::PENDING_KEY)?->keyId();
		$this->client->method('verificationStatus')->willReturn(['status' => 'verified', 'state' => 'applied']);
		$this->client->expects($this->once())->method('putProfile')->willReturn(['accepted' => true, 'pending_review' => false]);
		$this->client->expects($this->once())->method('putHeartbeat')->willReturn(['accepted' => true, 'state' => 'applied', 'profile_requested' => false]);
		$this->client->expects($this->once())->method('status')->willReturn(['state' => 'applied', 'checks' => []]);

		$state = $this->participation->checkVerification();

		$this->assertSame(DirectoryParticipation::PHASE_ENROLLED, $state['phase']);
		$this->assertSame($pending, $this->config->key()?->keyId());
		$this->assertNull($this->config->key(DirectoryConfig::PENDING_KEY));
		$this->assertSame('', $this->config->challenge(), 'the domain proof is not served any longer');
		$this->assertSame('applied', $state['listing']);
	}

	public function testTheHeartbeatIsHourlyAndBacksOffAfterAFailure(): void {
		$this->enrolled();
		$calls = 0;
		$this->client->method('putHeartbeat')->willReturnCallback(function () use (&$calls): array {
			$calls++;
			if ($calls === 2) {
				throw new DirectoryApiException(0, 'unreachable', 'down');
			}

			return ['accepted' => true, 'state' => 'listed'];
		});
		$this->client->method('status')->willReturn(['state' => 'listed']);
		$this->client->method('putProfile')->willReturn(['accepted' => true]);

		$this->participation->sync();
		$this->assertSame(1, $calls);
		$this->now += 600;
		$this->participation->sync();
		$this->assertSame(1, $calls, 'not due yet');

		$this->now += 3600;
		$this->participation->sync();
		$this->assertSame(2, $calls, 'due, and it fails');
		$this->now += 120;
		$this->participation->sync();
		$this->assertSame(2, $calls, 'backing off');
		$this->now += 300;
		$this->participation->sync();
		$this->assertSame(3, $calls, 'tried again after five minutes');
	}

	public function testAProfileRequestedByTheDirectoryIsSentAgain(): void {
		$this->enrolled();
		$this->client->method('putHeartbeat')->willReturn(['accepted' => true, 'state' => 'listed', 'profile_requested' => true]);
		$this->client->expects($this->once())->method('putProfile')->willReturn(['accepted' => true]);

		$this->participation->sendHeartbeat();
	}

	public function testWithdrawingForgetsTheKeyEvenWhenTheDirectoryNoLongerKnowsIt(): void {
		$this->enrolled();
		$this->client->method('withdraw')->willThrowException(new DirectoryApiException(401, 'unknown_key', 'Unknown key id.'));

		$state = $this->participation->withdraw();

		$this->assertSame(DirectoryParticipation::PHASE_NONE, $state['phase']);
		$this->assertNull($this->config->key());
		$this->assertFalse($this->config->advertises());
	}

	public function testATemporaryFailureDoesNotWithdraw(): void {
		$this->enrolled();
		$this->client->method('withdraw')->willThrowException(new DirectoryApiException(503, 'http_503', 'busy'));

		try {
			$this->participation->withdraw();
			$this->fail('a busy directory must be reported');
		} catch (DirectoryApiException) {
			$this->assertSame(DirectoryParticipation::PHASE_ENROLLED, $this->participation->phase());
		}
	}

	public function testRotatingTheKeyKeepsTheNewOne(): void {
		$this->enrolled();
		$old = $this->config->key()?->keyId();
		$this->client->expects($this->once())->method('rotateKey');

		$state = $this->participation->rotateKey();

		$this->assertNotSame($old, $state['keyId']);
		$this->assertSame($state['keyId'], $this->config->key()?->keyId());
	}

	public function testAnswersNeedText(): void {
		$this->enrolled();

		$this->expectException(InvalidArgumentException::class);
		$this->participation->comment('   ');
	}

	private function enrolled(): void {
		$key = DirectoryKey::generate();
		$this->config->setKey($key);
		$this->config->saveSettings(['advertise' => true]);
		$this->config->setJson(DirectoryConfig::STATE, ['phase' => DirectoryParticipation::PHASE_ENROLLED, 'keyId' => $key->keyId()]);
	}
}
