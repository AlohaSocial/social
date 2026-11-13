<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\MediaAttachment;
use PHPUnit\Framework\TestCase;

/**
 * The `attachments` column holds the client entity of each attachment. A post
 * and an edit that names its media ids build the attachments to be sent out as
 * ActivityPub Documents, which have no local id and no Mastodon type; stored in
 * that shape, every read of the post served `"id": ""` and `"type": "unknown"`
 * and the next edit could not name the media again.
 */
class StreamAttachmentsColumnTest extends TestCase {
	private const SOURCE = __DIR__ . '/../../lib/Db/StreamRequest.php';

	private function attachment(int $format): MediaAttachment {
		return (new MediaAttachment())
			->setId('103')
			->setType('image')
			->setMediaType('image/png')
			->setUrl('https://cloud.example.org/media/103.png')
			->setDescription('a red square')
			->setExportFormat($format);
	}

	public function testAnAttachmentBuiltForActivityPubIsStoredAsTheClientEntity(): void {
		$stored = json_decode(StreamRequest::attachmentsColumn([$this->attachment(ACore::FORMAT_ACTIVITYPUB)]), true);

		$this->assertIsArray($stored);
		$this->assertCount(1, $stored);
		$this->assertSame('103', $stored[0]['id']);
		$this->assertSame('image', $stored[0]['type']);
		$this->assertSame('image/png', $stored[0]['media_type']);
		$this->assertSame('a red square', $stored[0]['description']);
	}

	public function testTheFormatTheAttachmentWasBuiltForDoesNotChangeTheColumn(): void {
		$this->assertSame(
			StreamRequest::attachmentsColumn([$this->attachment(ACore::FORMAT_LOCAL)]),
			StreamRequest::attachmentsColumn([$this->attachment(ACore::FORMAT_ACTIVITYPUB)]),
		);
	}

	public function testNoAttachmentsIsAnEmptyList(): void {
		$this->assertSame('[]', StreamRequest::attachmentsColumn([]));
	}

	/** The unit suite has no database, so the two writes are read instead. */
	public function testANewPostAndAnEditBothStoreTheColumnThisWay(): void {
		$source = (string)file_get_contents(self::SOURCE);

		$this->assertSame(
			2,
			substr_count($source, 'self::attachmentsColumn($stream->getAttachments())'),
			'save() and update() both have to store the client entity'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/json_encode\(\s*\$stream->getAttachments\(\)/',
			$source,
			'encoding the attachments directly stores whichever format they were built for'
		);
	}
}
