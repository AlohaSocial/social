<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\AppViewClient;
use OCA\Social\Atproto\PublicPublicationVerifier;
use OCA\Social\Atproto\Repository\Record;
use PHPUnit\Framework\TestCase;

class PublicPublicationVerifierTest extends TestCase {
	private function record(): Record {
		return Record::create('did:plc:abcdefghijklmnopqrstuvwx', 'app.bsky.feed.post', '3abcdefgh2345', ['$type' => 'app.bsky.feed.post', 'text' => 'Native publication']);
	}
	public function testOnlyMatchingPublicPostConfirmsBlueskyVisibility(): void {
		$record = $this->record();
		$appview = $this->createMock(AppViewClient::class);
		$appview->expects(self::once())->method('get')->with('app.bsky.feed.getPostThread', ['uri' => $record->getAtUri(), 'depth' => 0, 'parentHeight' => 0])
			->willReturn(['thread' => ['post' => ['uri' => $record->getAtUri(), 'cid' => $record->cid, 'author' => ['did' => $record->did]]]]);
		$result = (new PublicPublicationVerifier($appview))->verify($record);
		self::assertTrue($result['indexed']);
		self::assertSame('https://bsky.app/profile/did%3Aplc%3Aabcdefghijklmnopqrstuvwx/post/3abcdefgh2345', $result['url']);
	}
	public function testMissingOrHiddenPostDoesNotClaimPublicIndexing(): void {
		$appview = $this->createStub(AppViewClient::class);
		$appview->method('get')->willReturn(['thread' => ['$type' => 'app.bsky.feed.defs#notFoundPost']]);
		$result = (new PublicPublicationVerifier($appview))->verify($this->record());
		self::assertFalse($result['indexed']);
		self::assertNull($result['url']);
	}
	public function testStaleCidCannotConfirmTheCurrentPublication(): void {
		$record = $this->record();
		$appview = $this->createStub(AppViewClient::class);
		$appview->method('get')->willReturn(['thread' => ['post' => ['uri' => $record->getAtUri(), 'cid' => 'stale', 'author' => ['did' => $record->did]]]]);
		$this->expectException(\RuntimeException::class);
		(new PublicPublicationVerifier($appview))->verify($record);
	}
	public function testAnotherAuthorCannotConfirmPublication(): void {
		$record = $this->record();
		$appview = $this->createStub(AppViewClient::class);
		$appview->method('get')->willReturn(['thread' => ['post' => ['uri' => $record->getAtUri(), 'cid' => $record->cid, 'author' => ['did' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa']]]]);
		$this->expectException(\RuntimeException::class);
		(new PublicPublicationVerifier($appview))->verify($record);
	}
	public function testNetworkFailureIsNotReportedAsSuccess(): void {
		$appview = $this->createStub(AppViewClient::class);
		$appview->method('get')->willThrowException(new \RuntimeException('AppView unavailable'));
		$this->expectException(\RuntimeException::class);
		(new PublicPublicationVerifier($appview))->verify($this->record());
	}
}
