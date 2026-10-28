<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\AtprotoChatMessageRequest;
use OCA\Social\Db\StreamRequest;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

class AtprotoChatMessageTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const OTHER = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const NOTE = 'https://chat-message-itest.example/posts/one';
	private const KEPT = 'https://chat-message-itest.example/posts/two';
	private AtprotoChatMessageRequest $messages;
	private StreamRequest $streams;

	protected function setUp(): void {
		parent::setUp();
		$this->messages = Server::get(AtprotoChatMessageRequest::class);
		$this->streams = Server::get(StreamRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->streams->deleteRelatedTo([md5(self::NOTE), md5(self::KEPT)]);
	}

	public function testRepeatedRecordingKeepsTheOriginalMessageWithoutAbortingTheTransaction(): void {
		$connection = Server::get(IDBConnection::class);
		$connection->beginTransaction();
		try {
			$this->messages->remember(self::DID, self::NOTE, 'conversation', 'message');
			$this->messages->remember(self::DID, self::NOTE, 'conversation', 'message');
			$this->assertSame(['convoId' => 'conversation', 'messageId' => 'message'], $this->messages->location(self::DID, self::NOTE));
			$this->assertNull($this->messages->location(self::OTHER, self::NOTE));
		} finally {
			$connection->rollBack();
		}
	}

	public function testEachAccountHasItsOwnMappingForTheSameLocalMessage(): void {
		$this->messages->remember(self::DID, self::NOTE, 'first-conversation', 'first-message');
		$this->messages->remember(self::OTHER, self::NOTE, 'second-conversation', 'second-message');

		$this->assertSame('first-message', $this->messages->location(self::DID, self::NOTE)['messageId']);
		$this->assertSame('second-message', $this->messages->location(self::OTHER, self::NOTE)['messageId']);
	}

	public function testDeletingMessageRelationsRemovesAllItsMappingsAndKeepsOtherMessages(): void {
		$this->messages->remember(self::DID, self::NOTE, 'first', 'deleted');
		$this->messages->remember(self::OTHER, self::NOTE, 'second', 'also-deleted');
		$this->messages->remember(self::DID, self::KEPT, 'first', 'retained');

		$this->streams->deleteRelatedTo([md5(self::NOTE)]);

		$this->assertNull($this->messages->location(self::DID, self::NOTE));
		$this->assertNull($this->messages->location(self::OTHER, self::NOTE));
		$this->assertSame('retained', $this->messages->location(self::DID, self::KEPT)['messageId']);
	}
}
