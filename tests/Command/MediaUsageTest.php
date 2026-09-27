<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\MediaUsage;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MediaUsageService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `occ social:media:usage` says, and how it decides it.
 *
 * The decision that matters is local against remote: an upload is the only
 * copy there is and a cached remote file is one that can be thrown away and
 * fetched again, so an administrator sizing a disk needs them apart. There is
 * no column that says which, so it is read off the document id.
 */
class MediaUsageTest extends TestCase {
	private const CLOUD = 'https://cloud.example.com';

	private CacheDocumentsRequest|Stub $cacheDocumentsRequest;
	private CacheDocumentService|Stub $cacheDocumentService;
	private ConfigService|Stub $configService;
	private CommandTester $tester;

	protected function setUp(): void {
		$this->cacheDocumentsRequest = $this->createStub(CacheDocumentsRequest::class);
		$this->cacheDocumentService = $this->createStub(CacheDocumentService::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getCloudUrl')->willReturn(self::CLOUD);

		// the real service over the same mocks: what this test is about is the
		// walk, and a mocked service would assert that the command called
		// something rather than that the numbers came out right
		$this->tester = new CommandTester(new MediaUsage(
			new MediaUsageService(
				$this->cacheDocumentsRequest,
				$this->cacheDocumentService,
				$this->configService,
				$this->createStub(\OCA\Social\Service\RemoteMediaQuotaService::class)
			),
			$this->configService
		));
	}

	/**
	 * @param list<array<string, mixed>> $rows one page, then nothing
	 * @param array<string, int> $sizes copy name => bytes; anything else is missing
	 */
	private function documentsAre(array $rows, array $sizes): void {
		$nid = 0;
		foreach ($rows as &$row) {
			$row += [
				'nid' => ++$nid, 'id' => '', 'url' => '', 'account' => '',
				'local_copy' => '', 'resized_copy' => '', 'actor_local' => null,
			];
		}
		unset($row);

		$this->cacheDocumentsRequest->method('getUsagePage')->willReturnCallback(
			static fn (int $limit, int $after = 0): array => ($after === 0) ? $rows : []
		);
		$this->cacheDocumentService->method('cachedFileSize')->willReturnCallback(
			static fn (string $name): ?int => $sizes[$name] ?? null
		);
	}

	private function json(): array {
		$this->assertSame(0, $this->tester->execute(['--output' => 'json']));

		return json_decode(trim($this->tester->getDisplay()), true);
	}

	public function testAnUploadHereIsNotCountedAsSomebodyElsesFile(): void {
		$this->documentsAre([
			['id' => self::CLOUD . '/documents/local/aaa', 'local_copy' => 'mine', 'resized_copy' => 'mine-small'],
			['id' => 'https://remote.example/media/bbb', 'local_copy' => 'theirs'],
		], ['mine' => 1000, 'mine-small' => 200, 'theirs' => 4000]);

		$usage = $this->json();

		$this->assertSame(['files' => 2, 'bytes' => 1200], $usage['local']['attachments']);
		$this->assertSame(['files' => 1, 'bytes' => 4000], $usage['remote']['attachments']);
		$this->assertSame(5200, $usage['bytes']);
		$this->assertSame(2, $usage['rows']);
	}

	/**
	 * A remote Social instance gives its own uploads the same id shape, so the
	 * marker alone would count another server's pictures as ours.
	 */
	public function testAnotherSocialInstancesUploadIsStillRemote(): void {
		$this->documentsAre([
			['id' => 'https://other.example/documents/local/ccc', 'local_copy' => 'theirs'],
		], ['theirs' => 500]);

		$usage = $this->json();

		$this->assertSame(0, $usage['local']['attachments']['files']);
		$this->assertSame(['files' => 1, 'bytes' => 500], $usage['remote']['attachments']);
	}

	/**
	 * A cached avatar hangs off the actor it belongs to, which is what the
	 * join answers; a local one has no parent at all and is recognised by the
	 * id this app gave it.
	 */
	public function testAvatarsAndHeadersAreCountedApartFromAttachments(): void {
		$this->documentsAre([
			['id' => 'https://remote.example/users/bob#icon', 'local_copy' => 'bob-face', 'actor_local' => false],
			['id' => self::CLOUD . '/documents/header/ddd', 'local_copy' => 'my-banner'],
			['id' => 'https://remote.example/media/eee', 'local_copy' => 'a-photo'],
		], ['bob-face' => 300, 'my-banner' => 900, 'a-photo' => 7000]);

		$usage = $this->json();

		$this->assertSame(['files' => 1, 'bytes' => 300], $usage['remote']['avatars']);
		$this->assertSame(['files' => 1, 'bytes' => 900], $usage['local']['avatars']);
		$this->assertSame(['files' => 1, 'bytes' => 7000], $usage['remote']['attachments']);
	}

	/**
	 * A row whose bytes are gone is a fact worth printing; counted as zero it
	 * would look like a file that happens to be empty.
	 */
	public function testACopyThatIsNotInAppdataIsReportedRatherThanCountedAsNothing(): void {
		$this->documentsAre([
			['id' => 'https://remote.example/media/fff', 'local_copy' => 'vanished'],
		], []);

		$usage = $this->json();

		$this->assertSame(1, $usage['missing']);
		$this->assertSame(0, $usage['files']);
		$this->assertSame(0, $usage['bytes']);
	}

	public function testAStreamedVideoCostsNothingHereAndIsSaidSo(): void {
		$this->documentsAre([
			['id' => 'https://peertube.example/videos/ggg', 'local_copy' => Document::COPY_STREAMED, 'resized_copy' => 'poster'],
		], ['poster' => 60]);

		$usage = $this->json();

		$this->assertSame(1, $usage['streamed']);
		$this->assertSame(0, $usage['missing'], 'a pointer is not a lost file');
		$this->assertSame(60, $usage['bytes'], 'the poster is ours and the video is not');
	}

	public function testALocalAvatarServedByNextcloudIsNotThisAppsBytes(): void {
		$this->documentsAre([
			['id' => self::CLOUD . '/documents/avatar/hhh', 'local_copy' => 'avatar'],
		], []);

		$usage = $this->json();

		$this->assertSame(1, $usage['elsewhere']);
		$this->assertSame(0, $usage['missing']);
	}

	public function testThePlainReportNamesBothHalvesAndTheTotal(): void {
		$this->documentsAre([
			['id' => self::CLOUD . '/documents/local/iii', 'local_copy' => 'mine'],
			['id' => 'https://remote.example/media/jjj', 'local_copy' => 'theirs'],
		], ['mine' => 2 * 1024 * 1024, 'theirs' => 3 * 1024 * 1024]);

		$this->assertSame(0, $this->tester->execute([]));
		$display = $this->tester->getDisplay();

		$this->assertStringContainsString('Uploaded here', $display);
		$this->assertStringContainsString('Cached from other servers', $display);
		$this->assertStringContainsString('2.0 MiB', $display);
		$this->assertStringContainsString('3.0 MiB', $display);
		$this->assertStringContainsString('5.0 MiB', $display);
	}

	public function testAnUnconfiguredInstanceSaysSoRatherThanGuessing(): void {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException('no address'));
		$tester = new CommandTester(new MediaUsage(
			new MediaUsageService(
				$this->cacheDocumentsRequest,
				$this->cacheDocumentService,
				$configService,
				$this->createStub(\OCA\Social\Service\RemoteMediaQuotaService::class)
			),
			$configService
		));

		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('not configured', $tester->getDisplay());
	}
}
