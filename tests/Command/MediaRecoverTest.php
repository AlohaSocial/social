<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\AP;
use OCA\Social\Command\MediaRecover;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

class MediaRecoverTest extends TestCase {
	use TActivityPubMocks;

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	private const ATTACHMENT = [
		'type' => 'Document',
		'mediaType' => 'image/jpeg',
		'url' => 'https://6-28.mastodon.xyz/media_attachments/files/117/304/original/image.jpeg',
	];

	private function row(): array {
		return $this->rowWith([self::ATTACHMENT]);
	}

	/**
	 * A stored post whose source names its attachments in the given shape.
	 *
	 * @param mixed $attachment what the sender put under `attachment`
	 *
	 * @return array the row, as getMissingRemoteAttachments() hands it over
	 */
	private function rowWith(mixed $attachment): array {
		return [
			'nid' => '1789918808550952384',
			'id' => 'https://mastodon.xyz/users/nextcloud/statuses/117304119017398739',
			'subtype' => '',
			'source' => json_encode([
				'type' => 'Note',
				'attachment' => $attachment,
			], JSON_UNESCAPED_SLASHES),
		];
	}

	public function testDryRunOnlyReportsStoredRemoteMedia(): void {
		$request = $this->createMock(StreamRequest::class);
		$request->expects($this->once())->method('getMissingRemoteAttachments')->willReturn([$this->row()]);
		$request->expects($this->never())->method('setRecoveredRemoteAttachments');
		$tester = new CommandTester(new MediaRecover($request));

		$this->assertSame(0, $tester->execute(['--dry-run' => true, '--limit' => 1]));
		$this->assertStringContainsString('1 affected post(s), 0 recovered', $tester->getDisplay());
	}

	public function testRestoresMediaWithoutRewritingThePost(): void {
		$this->installActivityPub(mocked: [DocumentInterface::class]);
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/social/media/test.jpeg');
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
		$this->apInterface(DocumentInterface::class)->expects($this->once())->method('save');

		$request = $this->createMock(StreamRequest::class);
		$request->method('getMissingRemoteAttachments')->willReturnOnConsecutiveCalls([$this->row()], []);
		$request->expects($this->once())->method('setRecoveredRemoteAttachments')
			->with(
				$this->row()['id'],
				$this->callback(function (string $json): bool {
					$media = json_decode($json, true);
					return count($media) === 1
						&& $media[0]['type'] === 'image'
						&& str_contains($media[0]['remote_url'], '6-28.mastodon.xyz');
				}),
				'',
			)->willReturn(true);
		$tester = new CommandTester(new MediaRecover($request));

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('1 affected post(s), 1 recovered', $tester->getDisplay());
	}

	/**
	 * One attachment is written either as a list of one or as the object on
	 * its own, and both are on the wire. Only the list was read, so a post
	 * sent the other way was passed over in silence by the command whose whole
	 * job is to give it its picture back.
	 */
	public function testAnAttachmentSentOnItsOwnIsRecoveredToo(): void {
		$this->installActivityPub(mocked: [DocumentInterface::class]);
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/social/media/test.jpeg');
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
		$this->apInterface(DocumentInterface::class)->expects($this->once())->method('save');

		$request = $this->createMock(StreamRequest::class);
		$request->method('getMissingRemoteAttachments')
			->willReturnOnConsecutiveCalls([$this->rowWith(self::ATTACHMENT)], []);
		$request->expects($this->once())->method('setRecoveredRemoteAttachments')->willReturn(true);
		$tester = new CommandTester(new MediaRecover($request));

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('1 affected post(s), 1 recovered', $tester->getDisplay());
	}

	/**
	 * The write only touches a post whose attachments are still empty, so
	 * nothing changed means somebody else filled them while this was running.
	 * Unsaid, the summary read "1 affected, 0 recovered, 0 failed" and there
	 * was no telling that from a bug in the command.
	 */
	public function testAPostFilledInBySomethingElseIsSaidSoRatherThanNothing(): void {
		$this->installActivityPub();
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/social/media/test.jpeg');
		\OC::$server->register(IURLGenerator::class, $urlGenerator);

		$request = $this->createStub(StreamRequest::class);
		$request->method('getMissingRemoteAttachments')->willReturnOnConsecutiveCalls([$this->row()], []);
		$request->method('setRecoveredRemoteAttachments')->willReturn(false);
		$tester = new CommandTester(new MediaRecover($request));

		$this->assertSame(0, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('Already filled, left alone', $display);
		$this->assertStringContainsString('1 already filled', $display);
	}

	/** A post whose source names no attachment at all is still passed over. */
	public function testAPostWithNothingToRecoverIsNotCounted(): void {
		$request = $this->createMock(StreamRequest::class);
		$request->method('getMissingRemoteAttachments')
			->willReturnOnConsecutiveCalls([$this->rowWith([])], []);
		$request->expects($this->never())->method('setRecoveredRemoteAttachments');
		$tester = new CommandTester(new MediaRecover($request));

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('0 affected post(s)', $tester->getDisplay());
	}
}
