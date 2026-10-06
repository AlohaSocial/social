<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StatusRevisionsRequest;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\StatusRevision;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The earlier versions of an edited post, in the order they were written.
 */
class StatusRevisionsRequestTest extends TestCase {
	private const POST = 'https://itest.example/posts/edited';
	private const OTHER = 'https://itest.example/posts/untouched';

	private StatusRevisionsRequest $revisions;

	protected function setUp(): void {
		parent::setUp();
		$this->revisions = Server::get(StatusRevisionsRequest::class);
		$this->revisions->deleteByStreamId(self::POST);
		$this->revisions->deleteByStreamId(self::OTHER);
	}

	protected function tearDown(): void {
		$this->revisions->deleteByStreamId(self::POST);
		$this->revisions->deleteByStreamId(self::OTHER);
		parent::tearDown();
	}

	private function revision(string $content, bool $sensitive = false): StatusRevision {
		return (new StatusRevision())
			->setStreamId(self::POST)
			->setContent($content)
			->setSpoilerText($sensitive ? 'cw' : '')
			->setSensitive($sensitive)
			->setPublished('2026-09-01T10:00:00Z');
	}

	public function testRevisionsComeBackOldestFirstWithEverythingTheyHeld(): void {
		$first = $this->revision('<p>first</p>');
		$this->revisions->save($first);
		$this->revisions->save($this->revision('<p>second</p>', true));

		$this->assertGreaterThan(0, $first->getId());
		$read = $this->revisions->getByStreamId(self::POST);
		$this->assertSame(['<p>first</p>', '<p>second</p>'], array_map(static fn (StatusRevision $r): string => $r->getContent(), $read));
		$this->assertTrue($read[1]->isSensitive());
		$this->assertSame('cw', $read[1]->getSpoilerText());
		$this->assertSame(self::POST, $read[0]->getStreamId(), 'handed back as the status asked about, not its hash');
	}

	public function testEachRevisionKeepsTheAttachmentsOfItsVersion(): void {
		$this->revisions->save($this->revision('<p>a</p>')->setMediaAttachments([
			(new MediaAttachment())->setId('11')->setType('image')->setDescription('Before'),
		]));
		$this->revisions->save($this->revision('<p>a</p>')->setMediaAttachments([
			(new MediaAttachment())->setId('11')->setType('image')->setDescription('After'),
		]));

		$read = $this->revisions->getByStreamId(self::POST);
		$this->assertSame('Before', $read[0]->getMediaAttachments()[0]->getDescription());
		$this->assertSame('After', $read[1]->getMediaAttachments()[0]->getDescription());
	}

	public function testAPostThatWasNeverEditedHasNone(): void {
		$this->revisions->save($this->revision('<p>only</p>'));

		$this->assertTrue($this->revisions->hasRevisions(self::POST));
		$this->assertFalse($this->revisions->hasRevisions(self::OTHER));
		$this->assertSame([], $this->revisions->getByStreamId(self::OTHER));
	}

	public function testDeletingThePostsRevisionsLeavesNone(): void {
		$this->revisions->save($this->revision('<p>a</p>'));
		$this->revisions->deleteByStreamId(self::POST);

		$this->assertFalse($this->revisions->hasRevisions(self::POST));
	}
}
