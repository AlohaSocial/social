<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RemoteOutboxPageReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RemoteOutboxPageReaderTest extends TestCase {
	private const OUTBOX = 'https://remote.example/users/alice/outbox';

	private function actor(): Person {
		return (new Person())->setId('https://remote.example/users/alice')->setOutbox(self::OUTBOX);
	}

	public function testFirstAndNextCanBeActivityStreamsLinks(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects(self::exactly(2))->method('retrieveObject')->willReturnMap([
			[self::OUTBOX, true, ['first' => ['type' => 'Link', 'href' => '?page=1']]],
			[self::OUTBOX . '?page=1', true, ['items' => [['type' => 'Note']], 'next' => ['type' => 'Link', 'href' => '?page=2']]],
		]);

		$page = (new RemoteOutboxPageReader($curl, new NullLogger()))->read($this->actor());

		self::assertTrue($page['success']);
		self::assertSame([['type' => 'Note']], $page['items']);
		self::assertSame(self::OUTBOX . '?page=1', $page['pageUrl']);
		self::assertSame(self::OUTBOX . '?page=2', $page['nextUrl']);
	}

	public function testAnEmbeddedPageDefinesTheBaseForItsRelativeContinuation(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects(self::once())->method('retrieveObject')->willReturn([
			'first' => [
				'id' => 'https://remote.example/collections/alice/page/1',
				'type' => 'OrderedCollectionPage',
				'orderedItems' => [['type' => 'Note']],
				'next' => '2',
			],
		]);

		$page = (new RemoteOutboxPageReader($curl, new NullLogger()))->read($this->actor());

		self::assertTrue($page['success']);
		self::assertSame('https://remote.example/collections/alice/page/1', $page['pageUrl']);
		self::assertSame('https://remote.example/collections/alice/page/2', $page['nextUrl']);
	}

	public function testAReferenceObjectForFirstIsFetchedRatherThanReadAsAnEmptyPage(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects(self::exactly(2))->method('retrieveObject')->willReturnMap([
			[self::OUTBOX, true, ['first' => ['id' => self::OUTBOX . '?page=1']]],
			[self::OUTBOX . '?page=1', true, ['orderedItems' => [['type' => 'Note']]]],
		]);

		$page = (new RemoteOutboxPageReader($curl, new NullLogger()))->read($this->actor());

		self::assertSame([['type' => 'Note']], $page['items']);
	}

	public function testPageLinksCannotEscapeTheOutboxServerOrLoopForever(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects(self::exactly(3))->method('retrieveObject')->willReturnOnConsecutiveCalls(
			['first' => ['type' => 'Link', 'href' => 'https://other.example/outbox']],
			['items' => [], 'next' => self::OUTBOX],
			['first' => ['id' => 'https://other.example/outbox', 'items' => []]],
		);
		$reader = new RemoteOutboxPageReader($curl, new NullLogger());

		self::assertFalse($reader->read($this->actor())['success']);
		self::assertSame('', $reader->read($this->actor())['nextUrl']);
		self::assertFalse($reader->read($this->actor())['success']);
	}
}
