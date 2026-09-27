<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FeedService;
use PHPUnit\Framework\TestCase;

/**
 * An account's public posts as RSS.
 *
 * PeerTube publishes one per channel and its users live in feed readers: a
 * channel with no feed is one they cannot subscribe to from outside. What is
 * held hardest here is that nothing but a public post can ever reach one — a
 * feed is the easiest possible way to leak a followers-only post, because one
 * wrong predicate puts it in somebody's reader and out of reach for ever.
 */
class FeedServiceTest extends TestCase {
	private FeedService $service;

	protected function setUp(): void {
		$config = $this->createStub(ConfigService::class);
		$config->method('getCloudHost')->willReturn('cloud.example');
		$this->service = new FeedService($config);
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId('https://cloud.example/apps/social/@alice')
			->setPreferredUsername('alice')
			->setName('Alice');

		return $alice;
	}

	private function post(string $content, bool $public = true): Note {
		$note = new Note();
		$note->setId('https://cloud.example/apps/social/@alice/1');
		$note->setContent($content);
		$note->setPublishedTime(1757000000);
		if ($public) {
			$note->setToArray([Stream::CONTEXT_PUBLIC]);
		}

		return $note;
	}

	public function testTheFeedIsRssTheWayPeerTubeAndMastodonPublishIt(): void {
		$feed = $this->service->forAccount($this->alice(), [$this->post('<p>Hello</p>')]);

		$this->assertStringContainsString('<rss version="2.0"', $feed);
		$this->assertStringContainsString('<title>Alice</title>', $feed);
		$this->assertStringContainsString('<item>', $feed);
	}

	/**
	 * The second refusal. The caller reads with no viewer at all, which already
	 * answers with public posts and nothing else; this is what makes a mistake
	 * upstream cost nothing.
	 */
	public function testAPostThatIsNotPublicNeverReachesTheFeed(): void {
		$feed = $this->service->forAccount($this->alice(), [$this->post('<p>Secret</p>', public: false)]);

		$this->assertStringNotContainsString('Secret', $feed);
		$this->assertStringNotContainsString('<item>', $feed);
	}

	/** A reader shows a list of titles, and a post has none of its own. */
	public function testAPostIsTitledByItsFirstLine(): void {
		$feed = $this->service->forAccount(
			$this->alice(),
			[$this->post('<p>The cat and the glass</p><p>Again.</p>')]
		);

		$this->assertStringContainsString('<title>The cat and the glass</title>', $feed);
	}

	/** A video has a name of its own, which is what it is listed under. */
	public function testAVideoIsTitledByItsName(): void {
		$post = $this->post('<p>whatever the body says</p>');
		$post->setVideoMeta(['title' => 'The state of the Fediverse']);

		$feed = $this->service->forAccount($this->alice(), [$post]);

		$this->assertStringContainsString('<title>The state of the Fediverse</title>', $feed);
	}

	/**
	 * The enclosure is the whole reason PeerTube publishes a feed: without one
	 * a podcast client has a list of links and nothing to play.
	 */
	public function testAVideoCarriesAnEnclosure(): void {
		$video = new MediaAttachment();
		$video->setType('video')
			->setMediaType('video/mp4')
			->setUrl('https://cloud.example/media/movie.mp4')
			->setSizeBytes(4_194_304);

		$post = $this->post('<p>A film</p>');
		$post->setAttachments([$video]);

		$feed = $this->service->forAccount($this->alice(), [$post]);

		$this->assertStringContainsString('<enclosure url="https://cloud.example/media/movie.mp4"', $feed);
		$this->assertStringContainsString('type="video/mp4"', $feed);
		$this->assertStringContainsString('length="4194304"', $feed);
	}

	/** A feed that is not well-formed XML is one no reader opens. */
	public function testWhatAPostSaysCannotBreakTheXml(): void {
		$post = $this->post('<p>Cats &amp; dogs ]]&gt; <script>alert(1)</script></p>');
		$alice = $this->alice();
		$alice->setName('Alice & "Bob"');

		$feed = $this->service->forAccount($alice, [$post]);

		$this->assertNotFalse(simplexml_load_string($feed), 'the feed is not well-formed XML');
		$this->assertStringNotContainsString('<script>', $feed);
	}
}
