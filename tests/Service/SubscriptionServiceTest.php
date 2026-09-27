<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FeedsRequest;
use OCA\Social\Service\FeedDiscoveryService;
use OCA\Social\Service\FeedParserService;
use OCA\Social\Service\SubscriptionService;
use OCA\Social\Tests\Helper\EndlessStream;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class SubscriptionServiceTest extends TestCase {
	private const FEED = '<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title>'
		. '<link>https://blog.example/</link><item><guid>1</guid><title>One</title>'
		. '<link>https://blog.example/1</link></item></channel></rss>';

	private FeedsRequest|MockObject $feedsRequest;
	private FeedDiscoveryService|Stub $discovery;
	private IClient|MockObject $client;
	private SubscriptionService $service;
	/** @var array<array<string, mixed>> the options of every request made */
	private array $requests = [];

	protected function setUp(): void {
		$this->feedsRequest = $this->createMock(FeedsRequest::class);
		$this->discovery = $this->createStub(FeedDiscoveryService::class);
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$this->service = new SubscriptionService(
			$this->feedsRequest, $this->discovery, new FeedParserService(), $clientService, new NullLogger()
		);
	}

	/** @param string|resource $body */
	private function answers($body): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturn('');
		$this->client->method('get')->willReturnCallback(function (string $url, array $options) use ($response): IResponse {
			$this->requests[] = $options;

			return $response;
		});
	}

	private function feed(): array {
		return ['id' => 7, 'url' => 'https://blog.example/feed', 'etag' => '', 'modified_at' => ''];
	}

	public function testAFeedIsReadAndItsEntriesStored(): void {
		$this->answers(self::FEED);
		$this->feedsRequest->expects($this->once())->method('addItem')->willReturn(true);
		$this->feedsRequest->expects($this->once())->method('recordRead')
			->with(7, 'Blog', 'https://blog.example/', '', '', '');

		$this->assertSame(1, $this->service->refresh($this->feed()));
		$this->assertTrue($this->requests[0]['stream'] ?? false, 'the body is streamed, so the ceiling bounds memory');
	}

	/**
	 * A feed that answers with more than the ceiling is refused having read
	 * one byte past it — not buffered whole and then cut, which for a body
	 * of hundreds of megabytes is a memory-limit fatal that ends the cron run
	 * and leaves the feed first in line for the next one.
	 */
	public function testAFeedLargerThanTheCeilingIsRefusedWithoutReadingItAll(): void {
		$this->answers(EndlessStream::open());
		$this->feedsRequest->expects($this->never())->method('addItem');
		$this->feedsRequest->expects($this->once())->method('recordRead')
			->with(7, '', '', '', '', 'too large to read');

		$this->assertSame(0, $this->service->refresh($this->feed()));
		$this->assertLessThan(5 * 1024 * 1024, EndlessStream::$read);
	}

	/**
	 * A new follow reads the feed once so the first page already has entries.
	 */
	public function testFollowingReadsTheFeedImmediatelyWithABoundedTimeout(): void {
		$this->discovery->method('discover')->willReturn('https://blog.example/feed');
		$this->feedsRequest->method('feedsOf')->willReturn([]);
		$this->feedsRequest->method('idOf')->willReturn(0);
		$this->feedsRequest->expects($this->once())->method('create')
			->with('alice', 'https://blog.example/feed', '', '')->willReturn(9);
		$this->answers(self::FEED);
		$this->feedsRequest->expects($this->once())->method('addItem')->with(9, $this->anything())->willReturn(true);
		$this->feedsRequest->expects($this->once())->method('recordRead')
			->with(9, 'Blog', 'https://blog.example/', '', '', '');

		$this->assertSame(['id' => 9, 'url' => 'https://blog.example/feed'], $this->service->follow('alice', 'https://blog.example/'));
		$this->assertSame(10, $this->requests[0]['timeout']);
	}

	/** A duplicate follow stays idempotent, even at the account's feed limit. */
	public function testFollowingAnExistingFeedAtTheLimitDoesNotFetchItAgain(): void {
		$this->discovery->method('discover')->willReturn('https://blog.example/feed');
		$this->feedsRequest->method('idOf')->willReturn(9);
		$this->feedsRequest->method('feedsOf')->willReturn(array_fill(0, SubscriptionService::MAX_FEEDS, ['id' => 1]));
		$this->feedsRequest->expects($this->never())->method('create');
		$this->client->expects($this->never())->method('get');

		$this->assertSame(['id' => 9, 'url' => 'https://blog.example/feed'], $this->service->follow('alice', 'https://blog.example/'));
	}

	/**
	 * A Takeout stores every channel and starts bounded parallel reads so the
	 * newest entries can show before the upload request returns.
	 */
	public function testATakeoutStoresEveryChannelAndReadsNewFeedsImmediately(): void {
		$csv = "Channel Id,Channel Url,Channel Title\n";
		for ($i = 0; $i < 3; $i++) {
			$csv .= 'UC' . str_repeat((string)$i, 22) . ",https://www.youtube.com/channel/x,Channel $i\n";
		}
		$this->discovery->method('discover')->willReturnCallback(
			fn (string $url): string => 'https://www.youtube.com/feeds/videos.xml?channel_id=' . substr($url, strrpos($url, '/') + 1)
		);
		$this->feedsRequest->method('feedsOf')->willReturn([]);
		$this->feedsRequest->method('idOf')->willReturn(0);
		$this->feedsRequest->expects($this->exactly(3))->method('create')->willReturnOnConsecutiveCalls(1, 2, 3);
		$this->feedsRequest->expects($this->exactly(3))->method('addItem')->willReturn(false);
		$this->feedsRequest->expects($this->exactly(3))->method('recordRead');
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(self::FEED);
		$response->method('getHeader')->willReturn('');
		$promise = $this->createMock(IPromise::class);
		$promise->method('wait')->willReturn($response);
		$options = [];
		$this->client->expects($this->exactly(3))->method('getAsync')->willReturnCallback(
			static function (string $url, array $requestOptions) use ($promise, &$options): IPromise {
				$options[] = $requestOptions;
				return $promise;
			}
		);

		$this->assertSame(3, $this->service->importTakeout('alice', $csv));
		$this->assertSame([10, 10, 10], array_column($options, 'timeout'));
	}

	public function testATakeoutStopsAtTheFeedAllowance(): void {
		$csv = '';
		for ($i = 0; $i < 5; $i++) {
			$csv .= 'UC' . str_repeat((string)$i, 22) . "\n";
		}
		$this->discovery->method('discover')->willReturnArgument(0);
		$this->feedsRequest->method('feedsOf')->willReturn(array_fill(0, SubscriptionService::MAX_FEEDS - 2, ['id' => 1]));
		$this->feedsRequest->method('idOf')->willReturn(0);
		$this->feedsRequest->expects($this->exactly(2))->method('create');

		$this->assertSame(2, $this->service->importTakeout('alice', $csv));
	}

	public function testAChannelAlreadyFollowedIsNotCountedAgain(): void {
		$this->discovery->method('discover')->willReturnArgument(0);
		$this->feedsRequest->method('feedsOf')->willReturn([]);
		$this->feedsRequest->method('idOf')->willReturn(4);
		$this->feedsRequest->expects($this->never())->method('create');

		$this->assertSame(0, $this->service->importTakeout('alice', 'UC' . str_repeat('a', 22) . "\n"));
	}

	/**
	 * Nothing in a feed means one thing before its first read and another
	 * after, and the page has no other way to tell them apart.
	 */
	public function testTheListSaysWhetherAFeedHasBeenReadYet(): void {
		$this->feedsRequest->method('feedsOf')->willReturn([
			['id' => 1, 'url' => 'https://blog.example/feed', 'title' => 'Blog', 'site_url' => 'https://blog.example/', 'error' => '', 'fetched_at' => '2026-09-01 08:00:00'],
			['id' => 2, 'url' => 'https://other.example/feed', 'title' => '', 'site_url' => 'javascript:alert(1)', 'error' => '', 'fetched_at' => null],
		]);
		$this->feedsRequest->method('countsFor')->willReturn([1 => 0]);

		$feeds = $this->service->feeds('alice');

		$this->assertTrue($feeds[0]['read']);
		$this->assertSame(0, $feeds[0]['items']);
		$this->assertSame('https://blog.example/', $feeds[0]['site_url']);
		$this->assertFalse($feeds[1]['read']);
		$this->assertSame('', $feeds[1]['site_url']);
	}

	public function testTimelineReturnsSafeYoutubeVideoIdsAndForwardsTheDateCursor(): void {
		$this->feedsRequest->expects($this->once())->method('timelineOf')
			->with('alice', 40, 0, '2026-09-24T16:25:01Z', 15)
			->willReturn([
				['id' => 16, 'link' => 'https://www.youtube.com/shorts/AbCdEfGhI_1', 'published' => '2026-09-24 17:00:00'],
				['id' => 15, 'link' => 'https://example.org/video/AbCdEfGhI_1', 'published' => '2026-09-24 16:25:01'],
				['id' => 14, 'link' => 'javascript:alert(1)', 'published' => '2026-09-24 16:00:00'],
			]);

		$items = $this->service->timeline('alice', 40, 0, '2026-09-24T16:25:01Z', 15);

		$this->assertSame('AbCdEfGhI_1', $items[0]['video_id']);
		$this->assertSame('', $items[1]['video_id']);
		$this->assertSame('', $items[2]['link']);
		$this->assertSame('', $items[2]['video_id']);
		$this->assertSame('2026-09-24T17:00:00Z', $items[0]['published']);
	}

	/** A read that added something prunes the feed to its allowance. */
	public function testAReadPrunesToTheAllowance(): void {
		$this->answers(self::FEED);
		$this->feedsRequest->method('addItem')->willReturn(true);
		$this->feedsRequest->expects($this->once())->method('prune')
			->with(7, SubscriptionService::KEEP_ITEMS, $this->callback(
				fn (int $before): bool => abs($before - (time() - SubscriptionService::KEEP_DAYS * 86400)) <= 2
			));

		$this->assertSame(1, $this->service->refresh($this->feed()));
	}

	/**
	 * Age decides nothing here. A blog whose last post is years old is still
	 * a blog somebody chose to follow: it used to read cleanly, parse, and
	 * store none of its entries, leaving a subscription that said "0 entries"
	 * with no error against it. The prune bounds a feed, not this.
	 */
	public function testAFeedThatStoppedPostingYearsAgoIsStillStored(): void {
		$old = gmdate(DATE_RSS, time() - (SubscriptionService::KEEP_DAYS + 10) * 86400);
		$this->answers('<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title>'
			. '<item><guid>old</guid><title>Old</title><link>https://blog.example/old</link><pubDate>' . $old . '</pubDate></item>'
			. '</channel></rss>');

		$this->feedsRequest->expects($this->once())->method('addItem')
			->with(7, $this->callback(fn (array $item): bool => $item['guid'] === 'old'))
			->willReturn(true);

		$this->assertSame(1, $this->service->refresh($this->feed()));
	}

	public function testAReadThatAddsNothingDoesNotPrune(): void {
		$this->answers(self::FEED);
		$this->feedsRequest->method('addItem')->willReturn(false);
		$this->feedsRequest->expects($this->never())->method('prune');

		$this->assertSame(0, $this->service->refresh($this->feed()));
	}

	/** @param array<array<string, mixed>> $feeds */
	private function feeds(int $from, int $count): array {
		$feeds = [];
		for ($i = $from; $i < $from + $count; $i++) {
			$feeds[] = ['id' => $i, 'url' => 'https://blog.example/feed' . $i, 'etag' => '', 'modified_at' => ''];
		}

		return $feeds;
	}

	/**
	 * A batch is sent before any of it is waited for: the feeds are read
	 * together, and one slow server holds up its batch rather than the pass.
	 */
	public function testADueBatchIsReadConcurrently(): void {
		$events = [];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(304);
		$this->client->expects($this->never())->method('get');
		$this->client->method('getAsync')->willReturnCallback(function (string $url, array $options) use (&$events, $response): IPromise {
			$events[] = 'send ' . $url;
			$this->assertTrue($options['stream']);
			$promise = $this->createMock(IPromise::class);
			$promise->method('wait')->willReturnCallback(function () use (&$events, $url, $response): IResponse {
				$events[] = 'wait ' . $url;

				return $response;
			});

			return $promise;
		});
		$this->feedsRequest->method('due')->willReturnOnConsecutiveCalls($this->feeds(1, 3), []);
		$this->feedsRequest->expects($this->exactly(3))->method('recordRead');

		$this->service->refreshDue(time() + 60);

		$this->assertSame([
			'send https://blog.example/feed1', 'send https://blog.example/feed2', 'send https://blog.example/feed3',
			'wait https://blog.example/feed1', 'wait https://blog.example/feed2', 'wait https://blog.example/feed3',
		], $events);
	}

	/** The pass goes on to the next batch while it has time, not after twenty. */
	public function testThePassKeepsTakingBatchesUntilNothingIsDue(): void {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn(304);
		$promise = $this->createStub(IPromise::class);
		$promise->method('wait')->willReturn($response);
		$this->client->method('getAsync')->willReturn($promise);

		$this->feedsRequest->expects($this->exactly(4))->method('due')
			->with(SubscriptionService::PARALLEL, $this->anything(), $this->anything())
			->willReturnOnConsecutiveCalls(
				$this->feeds(1, SubscriptionService::PARALLEL),
				$this->feeds(11, SubscriptionService::PARALLEL),
				$this->feeds(21, SubscriptionService::PARALLEL),
				[]
			);
		$this->feedsRequest->expects($this->exactly(3 * SubscriptionService::PARALLEL))->method('recordRead');

		$this->service->refreshDue(time() + 60);
	}

	public function testAPassWithNoTimeLeftReadsNothing(): void {
		$this->feedsRequest->expects($this->never())->method('due');
		$this->client->expects($this->never())->method('getAsync');

		$this->assertSame(0, $this->service->refreshDue(time() - 1));
	}

	/**
	 * A feed handed out again in the same pass — its read could not be
	 * recorded — is not read again: the pass ends instead of spinning.
	 */
	public function testAFeedIsReadOncePerPass(): void {
		$promise = $this->createStub(IPromise::class);
		$promise->method('wait')->willThrowException(new \RuntimeException('down'));
		$this->client->expects($this->once())->method('getAsync')->willReturn($promise);
		$this->feedsRequest->method('due')->willReturn($this->feeds(1, 1));
		$this->feedsRequest->expects($this->once())->method('recordRead')
			->with(1, '', '', '', '', 'could not be read');

		$this->assertSame(0, $this->service->refreshDue(time() + 60));
	}
}
