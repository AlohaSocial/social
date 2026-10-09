<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Reader\DetachedQuoteSweep;
use OCA\Social\Atproto\Reader\Postgates;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DetachedQuoteSweepTest extends TestCase {
	public function testAQuoteDetachedOnBlueskyIsWithdrawnHereAndThePlaceIsKept(): void {
		$quoted = (new Note())->setId('https://bsky.app/profile/did:plc:bob/post/3kq');
		$detached = (new Note())->setId('https://social.test/@alice/1')->setNid(11)->setQuote($quoted->getId());
		$kept = (new Note())->setId('https://social.test/@alice/2')->setNid(12)->setQuote($quoted->getId());
		$unpublished = (new Note())->setId('https://social.test/@alice/3')->setNid(13)->setQuote($quoted->getId());
		$values = [ConfigService::ATPROTO_DETACH_CURSOR => '10'];
		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $key) use (&$values): string {
			return $values[$key] ?? '';
		});
		$config->method('setAppValue')->willReturnCallback(static function (string $key, string $value) use (&$values): void {
			$values[$key] = $value;
		});
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getLocalQuotesOfBluesky')->willReturnOnConsecutiveCalls([$detached, $kept, $unpublished], []);
		$streams->method('getStreamById')->willReturn($quoted);
		$streams->expects($this->once())->method('updateDetails')->with($detached);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturnCallback(static fn (string $id): ?array => $id === $unpublished->getId() ? null : ['uri' => 'at://did:plc:alice/app.bsky.feed.post/' . md5($id), 'cid' => 'bafy']);
		$postgates = $this->createMock(Postgates::class);
		$postgates->method('detached')->willReturnCallback(static fn (Stream $q, string $uri): bool => $uri === 'at://did:plc:alice/app.bsky.feed.post/' . md5('https://social.test/@alice/1'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$sweep = new DetachedQuoteSweep($streams, $postgates, $refs, $config, $time);

		$this->assertSame(1, $sweep->run());
		$this->assertSame(Stream::QUOTE_REVOKED, $detached->getQuoteState());
		$this->assertNotSame(Stream::QUOTE_REVOKED, $kept->getQuoteState());
		$this->assertSame('13', $values[ConfigService::ATPROTO_DETACH_CURSOR]);
		$this->assertSame(0, $sweep->run());
		$this->assertSame('0', $values[ConfigService::ATPROTO_DETACH_CURSOR], 'it starts over');
	}
}
