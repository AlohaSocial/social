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
	public function testResolvesUriAndCreateObjectReferencesWithABoundedBudget(): void {
		$url = 'https://remote.example/posts/1';
		$note = ['id' => $url, 'type' => 'Note', 'attributedTo' => $this->actor()->getId()];
		$curl = $this->createMock(CurlService::class);
		$curl->expects(self::exactly(2))->method('retrieveObject')->with($url, true, 3)->willReturn($note);
		$reader = new RemoteOutboxPageReader($curl, new NullLogger());
		$remaining = 2;

		self::assertSame($note, $reader->resolveEntry($this->actor(), $url, $remaining)['item']);
		$create = ['type' => 'Create', 'actor' => $this->actor()->getId(), 'object' => $url];
		self::assertSame($note, $reader->resolveEntry($this->actor(), $create, $remaining)['item']['object']);
		self::assertSame(0, $remaining);
		self::assertTrue($reader->resolveEntry($this->actor(), $url, $remaining)['deferred']);
	}

	public function testReferencesCannotImportForeignOrSubstitutedObjects(): void {
		$url = 'https://remote.example/posts/1';
		$curl = $this->createMock(CurlService::class);
		$curl->expects(self::once())->method('retrieveObject')->with($url, true, 3)
			->willReturn(['id' => 'https://other.example/post', 'type' => 'Note']);
		$reader = new RemoteOutboxPageReader($curl, new NullLogger());
		$remaining = 6;

		foreach (['https://other.example/post', 'https://evil@remote.example/post', $url] as $reference) {
			self::assertNull($reader->resolveEntry($this->actor(), $reference, $remaining)['item']);
		}
	}

	public function testBoostObjectsAreLeftForTheOriginVerifiedCacheWorker(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects(self::never())->method('retrieveObject');
		$reader = new RemoteOutboxPageReader($curl, new NullLogger());
		$remaining = 6;
		$announce = ['type' => 'Announce', 'actor' => $this->actor()->getId(), 'object' => 'https://other.example/post'];

		self::assertSame($announce, $reader->resolveEntry($this->actor(), $announce, $remaining)['item']);
		self::assertSame(6, $remaining);
	}

}
