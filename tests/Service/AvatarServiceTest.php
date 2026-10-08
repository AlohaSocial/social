<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\MultipartBodyService;
use OCP\IAvatar;
use OCP\IAvatarManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The profile picture, which is the Nextcloud account's and not this app's.
 *
 * `setFromTempFile()` takes only a file the request uploaded, which
 * `MultipartBodyService::isUpload()` decides; the double here vouches for the
 * paths in `$uploaded` and nothing else.
 */
#[AllowMockObjectsWithoutExpectations]
class AvatarServiceTest extends TestCase {
	private const USER = 'alice';

	private IAvatarManager|Stub $avatarManager;
	private IUserManager|Stub $userManager;
	private AccountService|Stub $accountService;
	private AvatarService $service;

	/** the avatar core holds for the account */
	private IAvatar|Stub $avatar;
	private bool $custom = false;
	private ?string $stored = null;
	private bool $removed = false;
	/** @var string[] the usernames whose actor cache was refreshed */
	private array $refreshed = [];
	/** @var string[] files to clean up */
	private array $files = [];
	/** @var string[] the paths the request is taken to have uploaded */
	private array $uploaded = [];

	protected function setUp(): void {
		parent::setUp();

		$this->avatar = $this->createStub(IAvatar::class);
		$this->avatar->method('isCustomAvatar')->willReturnCallback(fn (): bool => $this->custom);
		$this->avatar->method('set')->willReturnCallback(function ($data): void {
			$this->stored = (string)$data;
		});
		$this->avatar->method('remove')->willReturnCallback(function (): void {
			$this->removed = true;
		});

		$this->avatarManager = $this->createStub(IAvatarManager::class);
		$this->avatarManager->method('getAvatar')->willReturn($this->avatar);

		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(
			fn (string $userId): ?IUser => ($userId === self::USER) ? $this->user(true) : null
		);

		$actor = new Person();
		$actor->setPreferredUsername(self::USER);
		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($actor);
		$this->accountService->method('cacheLocalActorByUsername')
			->willReturnCallback(function (string $username): void {
				$this->refreshed[] = $username;
			});

		$this->service = $this->build();
	}

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
		parent::tearDown();
	}

	private function build(): AvatarService {
		$multipartBodyService = $this->createStub(MultipartBodyService::class);
		$multipartBodyService->method('isUpload')
			->willReturnCallback(fn (string $path): bool => in_array($path, $this->uploaded, true));

		return new AvatarService(
			$this->avatarManager, $this->userManager, $this->accountService, new NullLogger(), $multipartBodyService
		);
	}

	private function user(bool $canChange): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('canChangeAvatar')->willReturn($canChange);

		return $user;
	}

	/** A one-pixel PNG, because the bytes decide what a picture is. */
	private function png(): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'avatar');
		file_put_contents($path, base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true
		));
		$this->files[] = $path;

		return $path;
	}

	private function file(string $contents): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'avatar');
		file_put_contents($path, $contents);
		$this->files[] = $path;

		return $path;
	}

	public function testThePictureIsStoredAndTheActorsIconToldToCatchUp(): void {
		$this->assertTrue($this->service->restoreFromArchive(self::USER, $this->png()));

		$this->assertNotNull($this->stored);
		// the actor's icon is a copy of the account's, and nothing refreshes it
		// on its own
		$this->assertSame([self::USER], $this->refreshed);
	}

	/**
	 * The profile looks unchanged whether or not the write happened, so only a
	 * refusal tells somebody on LDAP or SAML why their picture did not change.
	 */
	public function testAnAccountWhoseAvatarLivesElsewhereIsToldSo(): void {
		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('get')->willReturn($this->user(false));
		$service = $this->build();

		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('managed outside Nextcloud');
		$service->setFromTempFile(self::USER, ['tmp_name' => $this->png()]);
	}

	public function testAnAccountThatIsNotThereIsRefused(): void {
		$this->expectException(InvalidActionException::class);
		$this->service->setFromTempFile('nobody', ['tmp_name' => $this->png()]);
	}

	public function testAnUploadedPictureBecomesTheAvatar(): void {
		$path = $this->png();
		$this->uploaded[] = $path;

		$this->service->setFromTempFile(self::USER, ['tmp_name' => $path]);

		$this->assertNotNull($this->stored);
		$this->assertSame([self::USER], $this->refreshed);
	}

	/** A Bluesky app's picture is a file this server holds, not an upload of this request. */
	public function testAFileThisServerHoldsBecomesTheAvatarWithTheSameChecks(): void {
		$this->service->setFromFile(self::USER, $this->png());
		$this->assertNotNull($this->stored);
		$this->assertSame([self::USER], $this->refreshed);

		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('JPEG, PNG, GIF or WebP');
		$this->service->setFromFile(self::USER, $this->file('<?php phpinfo();'));
	}

	public function testAPictureThatIsNotSquareIsCutToItsMiddleSquare(): void {
		$image = imagecreatetruecolor(64, 48);
		ob_start();
		imagepng($image);
		$wide = $this->file((string)ob_get_clean());

		$this->service->setFromFile(self::USER, $wide);

		$size = getimagesizefromstring((string)$this->stored);
		$this->assertSame([48, 48], [$size[0] ?? 0, $size[1] ?? 0]);
		$this->assertSame(64, getimagesize($wide)[0], 'the file it came from is left as it was');
	}

	public function testAFileThisServerHoldsIsNotTheAvatarOfAnAccountWhoseAvatarLivesElsewhere(): void {
		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('get')->willReturn($this->user(false));

		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('managed outside Nextcloud');
		$this->build()->setFromFile(self::USER, $this->png());
	}

	/** A path the request did not upload is never read, whatever it holds. */
	public function testAFileTheRequestDidNotUploadIsRefused(): void {
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('no avatar found');
		$this->service->setFromTempFile(self::USER, ['tmp_name' => $this->png()]);
	}

	public function testARequestWithNoFileOnItIsRefused(): void {
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('no avatar found');
		$this->service->setFromTempFile(self::USER, []);
	}

	/**
	 * A client may call anything an image, and core is handed this to render.
	 */
	public function testBytesThatAreNotAPictureAreRefusedWhateverTheyAreCalled(): void {
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('JPEG, PNG, GIF or WebP');
		$this->service->restoreFromArchive(self::USER, $this->file('<?php phpinfo();'));
	}

	/** The checks `update_credentials` makes before it writes anything. */
	public function testCheckingAnUploadRefusesWhatSettingItWouldAndWritesNothing(): void {
		$path = $this->file('<?php phpinfo();');
		$this->uploaded[] = $path;

		try {
			$this->service->checkUpload(self::USER, ['tmp_name' => $path]);
			$this->fail('bytes that are not a picture were accepted');
		} catch (InvalidActionException $e) {
			$this->assertStringContainsString('JPEG, PNG, GIF or WebP', $e->getMessage());
		}

		$this->assertNull($this->stored);
		$this->assertSame([], $this->refreshed);
	}

	public function testCheckingAnUploadedPictureWritesNothing(): void {
		$path = $this->png();
		$this->uploaded[] = $path;

		$this->assertSame($path, $this->service->checkUpload(self::USER, ['tmp_name' => $path]));
		$this->assertNull($this->stored);
		$this->assertSame([], $this->refreshed);
	}

	public function testAnEmptyFileIsRefused(): void {
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('empty');
		$this->service->restoreFromArchive(self::USER, $this->file(''));
	}

	public function testAPictureCoreWouldNotTakeIsReportedRatherThanSwallowed(): void {
		$this->avatar = $this->createStub(IAvatar::class);
		$this->avatar->method('set')->willThrowException(new RuntimeException('no'));
		$this->avatarManager = $this->createStub(IAvatarManager::class);
		$this->avatarManager->method('getAvatar')->willReturn($this->avatar);
		$service = $this->build();

		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('could not be stored');
		$service->restoreFromArchive(self::USER, $this->png());
	}

	public function testRemovingAPictureLeavesTheGeneratedInitials(): void {
		$this->service->remove(self::USER);

		$this->assertTrue($this->removed);
		$this->assertSame([self::USER], $this->refreshed);
	}

	/**
	 * A client that retries a failed delete must not be told the second
	 * attempt was wrong: the account ends up in the state that was asked for
	 * either way.
	 */
	public function testRemovingAPictureThatWasNeverThereIsNotAnError(): void {
		$this->custom = false;

		$this->service->remove(self::USER);

		$this->assertTrue($this->removed);
	}

	public function testRemovingIsRefusedWhereTheBackendOwnsThePicture(): void {
		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('get')->willReturn($this->user(false));
		$service = $this->build();

		$this->expectException(InvalidActionException::class);
		$service->remove(self::USER);
	}

	/**
	 * An archive is read alongside whatever is already here, and core's own
	 * migrator carries the account's avatar too — in an order this app does
	 * not decide. So a picture that is already there wins.
	 */
	public function testAnArchivedPictureNeverReplacesOneTheAccountAlreadyHas(): void {
		$this->custom = true;

		$this->assertFalse($this->service->restoreFromArchive(self::USER, $this->png()));
		$this->assertNull($this->stored);
	}

	public function testAnArchiveRestoreForAnAccountThatCannotHaveOneIsSkippedQuietly(): void {
		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('get')->willReturn($this->user(false));
		$service = $this->build();

		$this->assertFalse($service->restoreFromArchive(self::USER, $this->png()));
		$this->assertNull($this->stored);
	}
}
