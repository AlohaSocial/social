<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ServerSettingsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The eight instance-wide settings the Server card writes.
 *
 * All of them were app config keys that only `occ config:app:set` could reach,
 * so nothing validated them either: a `max_size` of `-1` or of `words` was
 * accepted and found out about by whoever next tried to upload something.
 */
#[AllowMockObjectsWithoutExpectations]
class ServerSettingsServiceTest extends TestCase {
	private ConfigService|Stub $configService;
	private ActorsRequest|MockObject $actorsRequest;
	private ServerSettingsService $service;

	/** What the app values hold, so a write can be read back. */
	private array $stored = [];

	protected function setUp(): void {
		$this->stored = [];
		$this->configService = $this->createStub(ConfigService::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->stored[$key] ?? '');
		$this->configService->method('getAppValueInt')
			->willReturnCallback(fn (string $key): int => (int)($this->stored[$key] ?? 0));
		$this->configService->method('getAppValueBool')
			->willReturnCallback(fn (string $key): bool => ($this->stored[$key] ?? '0') === '1');
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value): void {
				$this->stored[$key] = $value;
			});

		$this->service = new ServerSettingsService($this->configService, $this->actorsRequest);
	}

	/** A call that should be taken, so a test can change one field of it. */
	private function save(array $overrides = []): array {
		$values = array_merge([
			'contactEmail' => 'admin@instance.example',
			'contactAccount' => '',
			'extendedDescription' => 'a friendly place',
			'maxSize' => 20,
			'maxVideoSize' => 4096,
			'imageMaxEdge' => 0,
			'imageQuality' => 85,
			'videoTranscode' => false,
			'videoMaxHeight' => 1080,
			'videoLadder' => false,
			'videoLadderHeights' => '360,720,1080',
			'videoQuota' => 0,
			'nsfwPolicy' => 'default',
			'inboxThrottle' => 300,
			'secureMode' => false,
			'publishBlocks' => false,
			'allowSelfSigned' => false,
		], $overrides);

		return $this->service->save(...$values);
	}

	public function testWhatWasSavedIsWhatIsReadBack(): void {
		$this->save(['secureMode' => true]);

		$this->assertSame([
			'contact_email' => 'admin@instance.example',
			'contact_account' => '',
			'extended_description' => 'a friendly place',
			'max_size' => 20,
			'max_video_size' => 4096,
			'image_max_edge' => 0,
			'image_quality' => 85,
			'video_transcode' => false,
			'video_max_height' => 1080,
			'video_ladder' => false,
			'video_ladder_heights' => '360,720,1080',
			'video_quota' => 0,
			'nsfw_policy' => 'default',
			'inbox_throttle' => 300,
			'secure_mode' => true,
			'publish_blocks' => false,
			'allow_self_signed' => false,
		], $this->service->current());
	}

	/**
	 * `AuthorizedFetchService` and the domain-blocks route compare against the
	 * literal `'1'`, so a switch written as `true` would be off.
	 */
	public function testTheSwitchesAreWrittenAsTheLiteralOneAndZero(): void {
		$this->save(['secureMode' => true, 'publishBlocks' => true]);

		$this->assertSame('1', $this->stored[ConfigService::SOCIAL_SECURE_MODE]);
		$this->assertSame('1', $this->stored[ConfigService::SOCIAL_PUBLISH_BLOCKS]);
		$this->assertSame('0', $this->stored[ConfigService::SOCIAL_SELF_SIGNED]);
	}

	public function testAnEmptyContactAddressIsAllowed(): void {
		$this->assertSame('', $this->save(['contactEmail' => ''])['contact_email']);
	}

	public function testAValidatedLocalSocialAccountCanBeTheInstanceContact(): void {
		$actor = (new Person())->setPreferredUsername('admin')->setUserId('admin')->setLocal(true);
		$this->actorsRequest->expects($this->once())->method('getFromUsername')
			->with('admin')->willReturn($actor);
		$this->actorsRequest->expects($this->once())->method('getFromUserId')
			->with('admin')->willReturn($actor);

		$this->assertSame('admin', $this->save(['contactAccount' => '@admin'])['contact_account']);
		$this->assertSame('admin', $this->stored[ConfigService::SOCIAL_CONTACT_ACCOUNT]);
	}

	public function testARemoteAccountCannotBeTheLocalInstanceContact(): void {
		$actor = (new Person())->setPreferredUsername('remote')->setLocal(false);
		$this->actorsRequest->method('getFromUsername')->willReturn($actor);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('contact_account');
		$this->save(['contactAccount' => 'remote']);
	}

	public function testAnUnknownAccountCannotBeTheInstanceContact(): void {
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('contact_account');
		$this->save(['contactAccount' => 'missing']);
	}

	public function testSurroundingSpaceIsNotPartOfTheAddress(): void {
		$this->assertSame(
			'admin@instance.example',
			$this->save(['contactEmail' => '  admin@instance.example  '])['contact_email']
		);
	}

	public static function refusals(): array {
		return [
			'not an address' => [['contactEmail' => 'admin at instance'], 'contact_email'],
			'an address past the column' => [['contactEmail' => str_repeat('a', 250) . '@e.example'], 'contact_email'],
			'a description past the ceiling' => [
				['extendedDescription' => str_repeat('x', ServerSettingsService::MAX_DESCRIPTION + 1)],
				'extended_description',
			],
			'no upload at all' => [['maxSize' => 0], 'max_size'],
			'a negative upload' => [['maxSize' => -1], 'max_size'],
			'more than ten gigabytes of picture' => [['maxSize' => 10241], 'max_size'],
			'no video at all' => [['maxVideoSize' => 0], 'max_video_size'],
			'more than a hundred gigabytes of video' => [['maxVideoSize' => 102401], 'max_video_size'],
			'a negative throttle' => [['inboxThrottle' => -1], 'inbox_throttle'],
			'a throttle past anything an inbox would take' => [['inboxThrottle' => 100001], 'inbox_throttle'],
		];
	}

	#[DataProvider('refusals')]
	public function testAValueOutOfRangeIsRefusedByName(array $overrides, string $field): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/' . $field . '/');

		$this->save($overrides);
	}

	/**
	 * A form that saved six of its eight fields and refused two would leave
	 * the administrator guessing which took.
	 */
	public function testARefusalWritesNothingAtAll(): void {
		try {
			$this->save(['contactEmail' => 'admin@instance.example', 'maxSize' => 0]);
			$this->fail('an impossible upload ceiling was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertSame([], $this->stored);
		}
	}

	/** Zero is not out of range here; it is what turns the throttle off. */
	public function testAThrottleOfZeroIsAcceptedBecauseThatIsHowItIsDisabled(): void {
		$this->assertSame(0, $this->save(['inboxThrottle' => 0])['inbox_throttle']);
	}

	public function testTheCardOwnsEveryKeyItWrites(): void {
		$this->save();

		$this->assertSame(
			ServerSettingsService::KEYS,
			array_values(array_intersect(ServerSettingsService::KEYS, array_keys($this->stored)))
		);
		$this->assertCount(count(ServerSettingsService::KEYS), $this->stored);
	}

	/**
	 * `0` is "store every upload exactly as it arrived", which is the default
	 * and the only value that loses nothing.
	 */
	public function testShrinkingIsOffUntilAnAdministratorAsksForIt(): void {
		$saved = $this->save();

		$this->assertSame(0, $saved['image_max_edge']);
	}

	public function testACeilingTooSmallToBeAPhotographIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/image_max_edge/');
		$this->save(['imageMaxEdge' => 64]);
	}

	public function testAQualityNobodyWouldWantIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/image_quality/');
		$this->save(['imageQuality' => 5]);
	}

	public function testASensibleCeilingIsKept(): void {
		$saved = $this->save(['imageMaxEdge' => 2560, 'imageQuality' => 82]);

		$this->assertSame(2560, $saved['image_max_edge']);
		$this->assertSame(82, $saved['image_quality']);
	}
}
