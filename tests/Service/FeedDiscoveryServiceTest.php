<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Service\FeedDiscoveryService;
use OCA\Social\Tests\Helper\EndlessStream;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class FeedDiscoveryServiceTest extends TestCase {
	private IClient|MockObject $client;
	private FeedDiscoveryService $service;

	protected function setUp(): void {
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$this->service = new FeedDiscoveryService($clientService);
	}

	private function answers(string $body, string $contentType = 'text/html'): void {
		$response = $this->createStub(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturn($contentType);
		$this->client->method('get')->willReturn($response);
	}

	/**
	 * The one address a channel feed can be built from without asking anybody.
	 */
	public function testAChannelIdIsAllItNeeds(): void {
		$this->client->expects($this->never())->method('get');

		$this->assertSame(
			'https://www.youtube.com/feeds/videos.xml?channel_id=UCXuqSBlHAE6Xw-yeJA0Tunw',
			$this->service->discover('https://www.youtube.com/channel/UCXuqSBlHAE6Xw-yeJA0Tunw')
		);
	}

	/**
	 * Every other YouTube address names a channel without saying which one.
	 *
	 * A handle, a `/c/` name, a `/user/` name and a video all do it, and the
	 * id is only in the page — which is why pasting one of these is the case
	 * that did not work, and it is the case everybody has.
	 *
	 * @param string $url what somebody would paste
	 */
	#[DataProvider('youtubePagesThatNameAChannel')]
	public function testAYouTubePageIsReadForItsChannel(string $url): void {
		$this->answers(
			'<html><head><link rel="alternate" type="application/rss+xml" title="RSS"'
			. ' href="https://www.youtube.com/feeds/videos.xml?channel_id=UCXuqSBlHAE6Xw-yeJA0Tunw">'
			. '</head></html>'
		);

		$this->assertSame(
			'https://www.youtube.com/feeds/videos.xml?channel_id=UCXuqSBlHAE6Xw-yeJA0Tunw',
			$this->service->discover($url)
		);
	}

	public static function youtubePagesThatNameAChannel(): array {
		return [
			'a handle' => ['https://www.youtube.com/@LinusTechTips'],
			'a legacy name' => ['https://www.youtube.com/c/LinusTechTips'],
			'a user name' => ['https://www.youtube.com/user/LinusTechTips'],
			'a video' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
			'a short link' => ['https://youtu.be/dQw4w9WgXcQ'],
			'no scheme at all' => ['youtube.com/@LinusTechTips'],
		];
	}

	/** A page that carries the id without declaring a feed still works. */
	public function testAYouTubePageWithNoLinkButAChannelIdWorks(): void {
		$this->answers('<html><body><script>{"channelId":"UCXuqSBlHAE6Xw-yeJA0Tunw"}</script></body></html>');

		$this->assertSame(
			'https://www.youtube.com/feeds/videos.xml?channel_id=UCXuqSBlHAE6Xw-yeJA0Tunw',
			$this->service->discover('https://www.youtube.com/@somebody')
		);
	}

	/** A playlist is a feed of its own, and its id is in the address. */
	public function testAPlaylistIsItsOwnFeed(): void {
		$this->client->expects($this->never())->method('get');

		$this->assertSame(
			'https://www.youtube.com/feeds/videos.xml?playlist_id=PLabc123',
			$this->service->discover('https://www.youtube.com/playlist?list=PLabc123')
		);
	}

	/**
	 * The other half: a blog. Nobody knows their own feed's address, and the
	 * page has said where it is since 2005.
	 */
	public function testAPageIsReadForTheFeedItDeclares(): void {
		$this->answers(
			'<!doctype html><html><head>'
			. '<link rel="icon" href="/favicon.ico">'
			. "<link rel='alternate' type='application/rss+xml' title='Feed' href='/blog/feed.xml'>"
			. '</head><body>a blog</body></html>'
		);

		$this->assertSame(
			'https://example.org/blog/feed.xml',
			$this->service->discover('https://example.org/blog/')
		);
	}

	/**
	 * @param string $href as the page writes it
	 * @param string $expected where it points
	 */
	#[DataProvider('hrefShapes')]
	public function testADeclaredFeedIsResolvedAgainstThePage(string $href, string $expected): void {
		$html = '<html><head><link rel="alternate" type="application/atom+xml" href="' . $href . '"></head></html>';

		$this->assertSame($expected, $this->service->declaredFeed($html, 'https://example.org/blog/index.html'));
	}

	public static function hrefShapes(): array {
		return [
			'whole address' => ['https://feeds.example.net/x.xml', 'https://feeds.example.net/x.xml'],
			'from the root' => ['/atom.xml', 'https://example.org/atom.xml'],
			'beside the page' => ['feed.xml', 'https://example.org/blog/feed.xml'],
			'scheme relative' => ['//cdn.example.net/f.xml', 'https://cdn.example.net/f.xml'],
			'escaped' => ['/feed?format=rss&amp;lang=en', 'https://example.org/feed?format=rss&lang=en'],
		];
	}

	/** Something that is already a feed is not looked into any further. */
	public function testAFeedIsTakenAsItIs(): void {
		$this->answers('<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>', 'application/rss+xml');

		$this->assertSame('https://example.org/feed.xml', $this->service->discover('https://example.org/feed.xml'));
	}

	/** Plenty of servers hand a feed over as text/plain. */
	public function testAFeedServedAsTheWrongTypeIsStillAFeed(): void {
		$this->answers('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>', 'text/plain');

		$this->assertSame('https://example.org/atom', $this->service->discover('https://example.org/atom'));
	}

	public function testAPageWithNoFeedSaysSo(): void {
		$this->answers('<html><head><title>nothing here</title></head></html>');

		$this->expectException(InvalidArgumentException::class);

		$this->service->discover('https://example.org/');
	}

	public function testSomethingUnreachableSaysSo(): void {
		$this->client->method('get')->willThrowException(new RuntimeException('down'));

		$this->expectException(InvalidArgumentException::class);

		$this->service->discover('https://example.org/');
	}

	/**
	 * This fetches an address a reader typed. The two shapes that name
	 * something only this server can reach are refused before anything is
	 * fetched at all.
	 *
	 * @param string $url what was typed
	 */
	#[DataProvider('addressesThatAreNotOnTheInternet')]
	public function testAnAddressInsideThisNetworkIsRefused(string $url): void {
		$this->client->expects($this->never())->method('get');

		$this->expectException(InvalidArgumentException::class);

		$this->service->discover($url);
	}

	public static function addressesThatAreNotOnTheInternet(): array {
		return [
			'a bare name' => ['http://localhost/feed'],
			'an address' => ['http://10.0.0.5/feed'],
			'a loopback address' => ['http://127.0.0.1:8080/feed'],
			'another scheme' => ['file:///etc/passwd'],
			'nothing' => ['   '],
		];
	}

	/**
	 * A page is read up to the ceiling as it arrives, not buffered whole and
	 * cut afterwards: a server that never stops sending must not be able to
	 * fill this process's memory.
	 */
	public function testAPageIsReadNoFurtherThanTheCeiling(): void {
		$options = [];
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(EndlessStream::open());
		$response->method('getHeader')->willReturn('text/html');
		$this->client->method('get')->willReturnCallback(function (string $url, array $sent) use ($response, &$options): IResponse {
			$options = $sent;

			return $response;
		});

		try {
			$this->service->discover('https://blog.example/');
		} catch (InvalidArgumentException) {
			// nothing to find in a page of 'a's; what matters is that it returned
		}

		$this->assertTrue($options['stream'] ?? false);
		$this->assertLessThan(3 * 1024 * 1024, EndlessStream::$read);
	}
}
