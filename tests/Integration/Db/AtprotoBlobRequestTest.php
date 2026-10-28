<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Db\AtprotoBlobRequest;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

class AtprotoBlobRequestTest extends TestCase {
	private const DID = 'did:plc:blobtabletestaaaaaaaaaaaa';
	private const OTHER = 'did:plc:blobtabletestbbbbbbbbbbbb';
	private AtprotoBlobRequest $blobs;

	protected function setUp(): void {
		$this->blobs = Server::get(AtprotoBlobRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
	}

	private function cleanup(): void {
		$this->blobs->deleteByDid(self::DID);
		$this->blobs->deleteByDid(self::OTHER);
	}

	public function testDuplicateBlobRegistrationPreservesTheOriginalMappingAndTransaction(): void {
		$connection = Server::get(IDBConnection::class);
		$connection->beginTransaction();
		try {
			$cid = Cid::forRaw('identical bytes');
			$first = new BlobRef(self::DID, $cid, 'https://blob-itest.example/first', 'image/png', 15);
			$overlap = new BlobRef(self::DID, $cid, 'https://blob-itest.example/second', 'image/png', 15);
			$this->assertTrue($this->blobs->put($first));
			$this->assertFalse($this->blobs->put($overlap));
			$this->assertSame($first->documentId, $this->blobs->get(self::DID, $cid->toString())?->documentId);
			$this->assertNull($this->blobs->get(self::OTHER, $cid->toString()));
			$this->assertTrue($this->blobs->put(new BlobRef(self::OTHER, $cid, 'https://blob-itest.example/other', 'image/png', 15)));
			$this->assertSame('https://blob-itest.example/other', $this->blobs->get(self::OTHER, $cid->toString())?->documentId);
		} finally {
			$connection->rollBack();
		}
	}
}
