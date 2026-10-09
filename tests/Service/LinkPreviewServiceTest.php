<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Exceptions\CardNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class LinkPreviewServiceTest extends TestCase {
	private const POST_ID = 'https://cloud.example/@alice/1';

	private StreamCardsRequest|MockObject $streamCardsRequest;
	private CurlService|MockObject $curlService;
	private LinkPreviewService $service;

	protected function setUp(): void {
		$this->streamCardsRequest = $this->createMock(StreamCardsRequest::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->service = new LinkPreviewService(
			$this->streamCardsRequest, $this->curlService, new NullLogger()
		);
	}

	private function post(string $content): Note {
		$note = new Note();
		$note->setId(self::POST_ID);
		$note->setContent($content);

		return $note;
	}

	/** Serves one page body and captures the request that asked for it. */
	private function serves(string $html): callable {
		$captured = null;
		$this->curlService->method('doRequest')->willReturnCallback(
			function (string $method, string $url, array $options) use ($html, &$captured): string {
				$captured = ['method' => $method, 'url' => $url, 'options' => $options];

				return $html;
			}
		);

		// by reference: the request only exists once doRequest has been called
		return static function () use (&$captured): ?array {
			return $captured;
		};
	}

	private function stored(): callable {
		$captured = null;
		$this->streamCardsRequest->method('save')->willReturnCallback(
			function (StreamCard $card) use (&$captured): void {
				$captured = $card;
			}
		);

		return static function () use (&$captured): ?StreamCard {
			return $captured;
		};
	}

	// --- which link gets previewed

	public function testTheFirstPlainLinkOfAPostIsPreviewed(): void {
		$this->assertSame(
			'https://example.org/one',
			$this->service->extractUrl('<p>see <a href="https://example.org/one">one</a> and <a href="https://example.org/two">two</a></p>')
		);
	}

	public function testMentionsAndHashtagsAreNotLinksToPreview(): void {
		$content = '<p><span class="mention"><a href="https://remote.example/@bob">@bob</a></span>'
			. ' <a href="https://cloud.example/timeline/tags/nextcloud" class="hashtag">#nextcloud</a>'
			. ' <a href="https://example.org/article">the article</a></p>';

		$this->assertSame('https://example.org/article', $this->service->extractUrl($content));
	}

	public function testAPostOfNothingButMentionsHasNoPreview(): void {
		$content = '<p><span class="mention"><a href="https://remote.example/@bob">@bob</a></span> hello</p>';

		$this->assertSame('', $this->service->extractUrl($content));
	}

	public function testALinkWrittenAsPlainTextIsFound(): void {
		$this->assertSame(
			'https://example.org/article',
			$this->service->extractUrl('read https://example.org/article, it is good')
		);
	}

	public function testOnlyHttpLinksArePreviewed(): void {
		$this->assertSame('', $this->service->extractUrl('<p><a href="javascript:alert(1)">click</a></p>'));
		$this->assertSame('', $this->service->extractUrl('<p><a href="file:///etc/passwd">file</a></p>'));
		$this->assertSame('', $this->service->extractUrl('<p><a href="/local/page">relative</a></p>'));
		$this->assertSame('', $this->service->extractUrl('gopher://example.org/thing'));
	}

	public function testAPostWithoutContentHasNoPreview(): void {
		$this->assertSame('', $this->service->extractUrl(''));
	}

	// --- what the page says about itself

	public function testACardTheLinkHasAlreadyIsNotReadAgain(): void {
		$this->streamCardsRequest->method('getByStreamId')->willReturn(new StreamCard(self::POST_ID, 'https://bsky.app/profile/bob.test/feed/cats'));
		$this->curlService->expects($this->never())->method('doRequest');
		$this->streamCardsRequest->expects($this->never())->method('save');

		$this->assertTrue($this->service->generate($this->post('<a href="https://bsky.app/profile/bob.test/feed/cats">Cats</a>')));
	}

	public function testAnEditedLinkIsReadAgain(): void {
		$this->streamCardsRequest->method('getByStreamId')->willReturn(new StreamCard(self::POST_ID, 'https://example.org/old'));
		$this->serves('<html><head><title>New</title></head></html>');
		$stored = $this->stored();

		$this->assertTrue($this->service->generate($this->post('<a href="https://example.org/new">new</a>')));
		$this->assertSame('https://example.org/new', $stored()?->getUrl());
	}

	public function testOpenGraphBecomesTheCard(): void {
		$this->serves(<<<'HTML'
			<html><head>
			<meta property="og:title" content="The headline">
			<meta property="og:description" content="What it is about">
			<meta property="og:image" content="https://example.org/img/hero.png">
			<meta property="og:site_name" content="Example News">
			<title>ignored when og:title is there</title>
			</head><body>…</body></html>
			HTML);
		$stored = $this->stored();

		$this->assertTrue($this->service->generate($this->post('<a href="https://example.org/a">a</a>')));

		$card = $stored();
		$this->assertSame(self::POST_ID, $card->getStreamId());
		$this->assertSame('https://example.org/a', $card->getUrl());
		$this->assertSame('The headline', $card->getTitle());
		$this->assertSame('What it is about', $card->getDescription());
		$this->assertSame('https://example.org/img/hero.png', $card->getImage());
		$this->assertSame('Example News', $card->getProviderName());
	}

	/**
	 * A value ends at the quote it opened with. Accepting either ended a
	 * double-quoted value at the first apostrophe inside it, so every headline
	 * with one in it was truncated there — `Don't miss this` became `Don`.
	 */
	public function testAnApostropheInsideAQuotedValueIsPartOfIt(): void {
		$this->serves(
			'<html><head>'
			. '<meta property="og:title" content="Don\'t miss this">'
			. '<meta property="og:description" content="Apple\'s new thing">'
			. "<meta property='og:site_name' content='The \"Daily\" News'>"
			. '</head></html>'
		);
		$stored = $this->stored();

		$this->service->generate($this->post('<a href="https://example.org/a">a</a>'));

		$card = $stored();
		$this->assertSame("Don't miss this", $card->getTitle());
		$this->assertSame("Apple's new thing", $card->getDescription());
		$this->assertSame('The "Daily" News', $card->getProviderName());
	}

	/** A `>` inside a quoted value does not end the tag. */
	public function testAGreaterThanInsideAValueDoesNotEndTheTag(): void {
		$this->serves(
			'<html><head><meta property="og:title" content="1 > 2 says the headline">'
			. '<meta property="og:description" content="plain"></head></html>'
		);
		$stored = $this->stored();

		$this->service->generate($this->post('<a href="https://example.org/a">a</a>'));

		$this->assertSame('1 > 2 says the headline', $stored()->getTitle());
	}

	public function testTwitterTagsAndThePlainTitleFillIn(): void {
		$this->serves(
			'<html><head><title>  The   plain &amp; only title </title>'
			. '<meta name="twitter:description" content="from twitter">'
			. '<meta name="twitter:image" content="https://example.org/t.png">'
			. '</head></html>'
		);
		$stored = $this->stored();

		$this->service->generate($this->post('<a href="https://example.org/a">a</a>'));

		$card = $stored();
		$this->assertSame('The plain & only title', $card->getTitle(), 'entities decoded, whitespace collapsed');
		$this->assertSame('from twitter', $card->getDescription());
		$this->assertSame('https://example.org/t.png', $card->getImage());
		$this->assertSame('example.org', $card->getProviderName(), 'the host stands in for a missing site name');
	}

	public function testARelativeImageIsResolvedAgainstThePage(): void {
		$this->serves(
			'<html><head><title>t</title><meta property="og:image" content="/img/hero.png"></head></html>'
		);
		$stored = $this->stored();

		$this->service->generate($this->post('<a href="https://example.org/news/today">a</a>'));

		$this->assertSame('https://example.org/img/hero.png', $stored()->getImage());
	}

	public function testAnImageThatIsNotAnHttpUrlIsDropped(): void {
		$this->serves(
			'<html><head><title>t</title><meta property="og:image" content="data:image/png;base64,AAAA"></head></html>'
		);
		$stored = $this->stored();

		$this->service->generate($this->post('<a href="https://example.org/a">a</a>'));

		$this->assertSame('', $stored()->getImage(), 'the client would load this straight from the origin');
	}

	public function testAPageThatSaysNothingAboutItselfStoresNoCard(): void {
		$this->serves('<html><head></head><body>just words</body></html>');
		$this->streamCardsRequest->expects($this->never())->method('save');

		$this->assertFalse($this->service->generate($this->post('<a href="https://example.org/a">a</a>')));
	}

	public function testAPostWithoutALinkIsNeverFetched(): void {
		$this->curlService->expects($this->never())->method('doRequest');
		$this->streamCardsRequest->expects($this->never())->method('save');

		$this->assertFalse($this->service->generate($this->post('<p>no links here</p>')));
	}

	public function testAnUnreachablePageIsNotAnError(): void {
		$this->curlService->method('doRequest')->willThrowException(new RequestNetworkException('timeout'));
		$this->streamCardsRequest->expects($this->never())->method('save');

		$this->assertFalse($this->service->generate($this->post('<a href="https://example.org/a">a</a>')));
	}

	public function testTheFetchAsksForHtmlAndKeepsTheLinkAsItIsWritten(): void {
		$request = $this->serves('<html><head><title>t</title></head></html>');

		$this->service->generate($this->post('<a href="https://example.org/news/today?ref=social">a</a>'));

		$this->assertSame('get', $request()['method']);
		$this->assertSame(
			'https://example.org/news/today?ref=social',
			$request()['url'],
			'the link as written, scheme included and never downgraded'
		);
		$this->assertSame('text/html,application/xhtml+xml', $request()['options']['headers']['Accept']);
		$this->assertSame(5, $request()['options']['timeout'], 'a preview must not hold up the inbox');
	}

	public function testALongTitleAndDescriptionAreCapped(): void {
		$this->serves(
			'<html><head><meta property="og:title" content="' . str_repeat('a', 400) . '">'
			. '<meta property="og:description" content="' . str_repeat('b', 900) . '"></head></html>'
		);
		$stored = $this->stored();

		$this->service->generate($this->post('<a href="https://example.org/a">a</a>'));

		$this->assertSame(StreamCard::MAX_TITLE, mb_strlen($stored()->getTitle()));
		$this->assertSame(StreamCard::MAX_DESCRIPTION, mb_strlen($stored()->getDescription()));
	}

	// --- reading cards back

	public function testAttachCardsFetchesAWholePageInOneQuery(): void {
		$first = $this->post('<a href="https://example.org/a">a</a>');
		$second = $this->post('<p>nothing</p>');
		$second->setId('https://cloud.example/@alice/2');
		$card = new StreamCard(self::POST_ID, 'https://example.org/a');
		$card->setTitle('The headline');

		$this->streamCardsRequest->expects($this->once())->method('getByStreamIds')
			->with([self::POST_ID, 'https://cloud.example/@alice/2'])
			->willReturn([md5(self::POST_ID) => $card]);

		$this->service->attachCards([$first, $second]);

		$this->assertSame($card, $first->getCard());
		$this->assertNull($second->getCard());
	}

	public function testAttachCardsDoesNotQueryForAnEmptyPage(): void {
		$this->streamCardsRequest->expects($this->never())->method('getByStreamIds');

		$this->service->attachCards([]);
	}

	public function testAttachCardLeavesAPostWithoutACardAlone(): void {
		$this->streamCardsRequest->method('getByStreamId')
			->willThrowException(new CardNotFoundException());
		$post = $this->post('<p>x</p>');

		$this->service->attachCard($post);

		$this->assertNull($post->getCard());
	}

	public function testTheCardReachesTheStatusEntity(): void {
		$card = new StreamCard(self::POST_ID, 'https://example.org/a');
		$card->setTitle('The headline')->setDescription('About')->setProviderName('Example');
		$post = $this->post('<a href="https://example.org/a">a</a>');
		$post->setCard($card);

		$exported = $post->exportAsLocal()['card'];

		$this->assertSame('https://example.org/a', $exported['url']);
		$this->assertSame('The headline', $exported['title']);
		$this->assertSame('link', $exported['type']);
		$this->assertNull($exported['image'], 'a card without an image says so');
		$this->assertNull($this->post('<p>x</p>')->exportAsLocal()['card']);
	}
}
