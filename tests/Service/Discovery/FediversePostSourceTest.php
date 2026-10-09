<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Discovery;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\Discovery\FediversePostSource;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\StreamQueueService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class FediversePostSourceTest extends TestCase {
	public function testThePeersHashtagTimelinesAreReadAndEachPostFetchedFromItsOwnServer(): void {
		$directory = $this->createMock(FediverseDirectoryService::class);
		$directory->method('sources')->willReturn([
			new DirectorySource('social.test', DirectorySource::KIND_LOCAL, 'social.test'),
			new DirectorySource('mastodon.example', DirectorySource::KIND_MASTODON, 'Mastodon'),
			new DirectorySource('lemmy.example', DirectorySource::KIND_LEMMY, 'Lemmy'),
			new DirectorySource('down.example', DirectorySource::KIND_MASTODON, 'Down'),
		]);
		$asked = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveJson')->willReturnCallback(static function (string $method, string $url) use (&$asked): array {
			$asked[] = $url;
			if (str_contains($url, 'down.example')) {
				throw new \RuntimeException('timeout');
			}

			return [['uri' => 'https://a.example/notes/1'], ['uri' => 'https://held.example/notes/2'], ['uri' => 'nope'], ['uri' => 'https://b.example/notes/3']];
		});
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturnCallback(static fn (string $id): Stream => $id === 'https://held.example/notes/2' ? new Note() : throw new StreamNotFoundException());
		$fetched = [];
		$queue = $this->createMock(StreamQueueService::class);
		$queue->method('fetchNow')->willReturnCallback(static function (string $uri) use (&$fetched): void {
			$fetched[] = $uri;
		});
		$source = new FediversePostSource($directory, $curl, $streams, $queue, new NullLogger());

		$this->assertSame(2, $source->tagged('nextcloud', 10, null));
		$this->assertSame(['https://mastodon.example/api/v1/timelines/tag/nextcloud?limit=20', 'https://down.example/api/v1/timelines/tag/nextcloud?limit=20'], $asked, 'Mastodon-speaking peers only, not this server');
		$this->assertSame(['https://a.example/notes/1', 'https://b.example/notes/3'], $fetched);
		$this->assertSame(0, $source->matching('anything', 10, null), 'the fediverse has no public search of posts');
	}
}
