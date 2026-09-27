<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TActivityPubMocks.php';

class MentionTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	public function testIsAStreamOfTypeMention(): void {
		$mention = new Mention();

		$this->assertInstanceOf(Stream::class, $mention);
		$this->assertSame('Mention', $mention->getType());
	}

	public function testImportReadsTheStreamFields(): void {
		$mention = new Mention();

		$mention->import([
			'id' => 'https://a.example/n/1',
			'type' => 'Mention',
			'content' => '<p>@bob hi</p>',
			'attributedTo' => 'https://a.example/users/alice',
			'published' => '2024-05-01T12:00:00Z',
		]);

		$this->assertSame('<p>@bob hi</p>', $mention->getContent());
		$this->assertSame('https://a.example/users/alice', $mention->getAttributedTo());
		$this->assertSame(1714564800, $mention->getPublishedTime());
		$this->assertSame('Mention', $mention->jsonSerialize()['type']);
	}

	public function testImportFromDatabaseReadsTheRow(): void {
		$mention = new Mention();

		$mention->importFromDatabase(['nid' => 4, 'id' => 'https://a.example/n/1', 'type' => 'Mention', 'content' => 'x']);

		$this->assertSame(4, $mention->getNid());
		$this->assertSame('x', $mention->getContent());
	}
}
