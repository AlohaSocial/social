<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PostMapperTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const OTHER = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	public function testAThreadNobodyMayReplyToIsMarkedSo(): void {
		$view = $this->postView();
		$view['threadgate'] = ['uri' => 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.threadgate/3k', 'record' => ['$type' => 'app.bsky.feed.threadgate', 'post' => $view['uri'], 'allow' => [], 'createdAt' => '2026-01-01T00:00:00.000Z']];

		$this->assertSame(['canReply' => ['automaticApproval' => []]], (new PostMapper($this->resolver()))->note($view)['interactionPolicy'] ?? null);
		$view['threadgate']['record']['allow'] = [['$type' => 'app.bsky.feed.threadgate#mentionRule']];
		$this->assertArrayNotHasKey('interactionPolicy', (new PostMapper($this->resolver()))->note($view), 'a narrower gate is checked on replying');
	}

	public function testAPostItsAuthorKeepsTheViewerFromQuotingIsMarkedSo(): void {
		$view = $this->postView();
		$this->assertArrayNotHasKey('interactionPolicy', (new PostMapper($this->resolver()))->note($view));

		$view['viewer'] = ['embeddingDisabled' => true];
		$view['threadgate'] = ['record' => ['allow' => []]];
		$this->assertSame(['canReply' => ['automaticApproval' => []], 'canQuote' => ['automaticApproval' => []]], (new PostMapper($this->resolver()))->note($view)['interactionPolicy'] ?? null);
	}

	public function testAnEmbeddedFeedListOrStarterPackIsACardAndALink(): void {
		$creator = ['did' => self::OTHER, 'handle' => 'bob.test', 'avatar' => 'https://cdn.bsky.app/img/avatar/plain/bob@jpeg'];
		$record = static fn (array $view): array => ['$type' => 'app.bsky.embed.record#view', 'record' => $view];
		$list = ['$type' => 'app.bsky.graph.defs#listView', 'uri' => 'at://' . self::OTHER . '/app.bsky.graph.list/3kl', 'name' => 'Friends', 'purpose' => 'app.bsky.graph.defs#curatelist', 'creator' => $creator];
		$pack = ['$type' => 'app.bsky.graph.defs#starterPackViewBasic', 'uri' => 'at://' . self::OTHER . '/app.bsky.graph.starterpack/3ks', 'record' => ['name' => 'Start here', 'description' => 'Good people'], 'creator' => $creator];

		$this->assertSame(['url' => 'https://bsky.app/profile/bob.test/lists/3kl', 'title' => 'Friends', 'description' => '', 'image' => '', 'provider' => 'Bluesky list by @bob.test'], PostMapper::cardOf($record($list)));
		$this->assertSame(['url' => 'https://bsky.app/starter-pack/bob.test/3ks', 'title' => 'Start here', 'description' => 'Good people', 'image' => 'https://cdn.bsky.app/img/avatar/plain/bob@jpeg', 'provider' => 'Bluesky starter pack by @bob.test'],
			PostMapper::cardOf(['$type' => 'app.bsky.embed.recordWithMedia#view', 'record' => $record($pack), 'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => []]]), 'beside pictures');
		$this->assertNull(PostMapper::cardOf($record(['$type' => 'app.bsky.embed.record#viewRecord', 'uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kp'])), 'a quoted post is a quote');

		$note = (new PostMapper($this->resolver()))->note($this->postView(['embed' => $record($list)]));
		$this->assertStringContainsString('<a href="https://bsky.app/profile/bob.test/lists/3kl"', $note['content']);
		$this->assertSame('Friends', $note['_atproto']['card']['title']);
	}

	public function testAPostViewBecomesACreateOfAPublicNote(): void {
		$create = (new PostMapper($this->resolver()))->create($this->postView());
		$this->assertNotNull($create);
		$this->assertSame('Create', $create['type']);
		$this->assertSame('https://bsky.app/profile/' . self::DID, $create['actor']);
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22/activity', $create['id']);
		$note = $create['object'];
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22', $note['id']);
		$this->assertSame('Note', $note['type']);
		$this->assertSame('https://bsky.app/profile/alice.bsky.social/post/3kznmn7xqxl22', $note['url'], 'the page is by handle');
		$this->assertSame('2026-10-08T10:00:00Z', $note['published']);
		$this->assertSame([ACore::CONTEXT_PUBLIC], $note['to']);
		$this->assertSame(['https://bsky.app/profile/' . self::DID . '/followers'], $note['cc'], 'addressed to the followers collection the home timeline joins on');
		$this->assertSame('<p>Hello <a href="https://bsky.app/hashtag/interop" class="mention hashtag" rel="tag">#interop</a></p>', $note['content']);
		$this->assertSame([['type' => 'Hashtag', 'href' => 'https://bsky.app/hashtag/interop', 'name' => '#interop']], $note['tag']);
		$this->assertSame('', $note['summary']);
		$this->assertFalse($note['sensitive']);
		$this->assertSame(['en' => $note['content']], $note['contentMap']);
		$this->assertCount(2, $note['attachment']);
		$this->assertSame(['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/' . self::DID . '/bafkreipic1@jpeg', 'name' => 'a cat', 'width' => 1000, 'height' => 750], $note['attachment'][0]);
		$this->assertSame('https://bsky.app/profile/' . self::OTHER . '/post/3kparent', $note['inReplyTo']);
		$this->assertSame(['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', 'cid' => 'bafyreipost', 'likes' => 3, 'reposts' => 1, 'replies' => 2, 'quotes' => 0, 'labels' => [], 'label_sources' => [], 'indexed_at' => '2026-10-08T10:00:01.000Z', 'reply_root' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kparent', 'cid' => 'bafyr']], $note['_atproto']);
		$this->assertArrayNotHasKey('quote', $note);
	}

	public function testEachLabelKeepsTheLabelerThatAppliedIt(): void {
		$note = (new PostMapper($this->resolver()))->note($this->postView(['labels' => [
			['src' => 'did:plc:newitj5jo3uel7o4mnf3vj2o', 'uri' => 'at://x', 'val' => 'twitter-screenshot'],
			['src' => 'not a did', 'uri' => 'at://x', 'val' => 'x'],
			['src' => self::DID, 'uri' => 'at://x', 'val' => ''],
		]]));
		$this->assertSame([['src' => 'did:plc:newitj5jo3uel7o4mnf3vj2o', 'val' => 'twitter-screenshot']], $note['_atproto']['label_sources']);
	}

	public function testLabelsWarnAndHide(): void {
		$mapper = new PostMapper($this->resolver());
		$adult = $this->postView(['labels' => [['src' => self::DID, 'uri' => 'at://x', 'val' => 'porn']]]);
		$note = $mapper->note($adult);
		$this->assertTrue($note['sensitive']);
		$this->assertSame('Porn', $note['summary'], 'a warning naming the label when the post had none');

		$spam = $mapper->note($this->postView(['labels' => [['src' => 'did:plc:ar7c4by46qjdydhdevvrndac', 'uri' => 'at://x', 'val' => 'spam'], ['src' => self::DID, 'uri' => 'at://x', 'val' => '!warn']]]));
		$this->assertSame('Spam, Content warning', $spam['summary']);
		$this->assertTrue($spam['sensitive']);

		$this->assertNull($mapper->note($this->postView(['labels' => [['src' => 'did:plc:ar7c4by46qjdydhdevvrndac', 'uri' => 'at://x', 'val' => '!hide']]])), 'a hidden post is not stored');
		$this->assertNull($mapper->create($this->postView(['uri' => 'at://alice.bsky.social/app.bsky.feed.post/3k'])), 'a handle authority is not an id');
	}

	public function testQuotesExternalLinksAndVideoAreAppendedOrLinked(): void {
		$mapper = new PostMapper($this->resolver());
		$quote = $mapper->note($this->postView(['embed' => ['$type' => 'app.bsky.embed.record#view', 'record' => ['$type' => 'app.bsky.embed.record#viewRecord', 'uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kquoted', 'cid' => 'bafyq']]]));
		$this->assertSame('https://bsky.app/profile/' . self::OTHER . '/post/3kquoted', $quote['quote']);
		$this->assertSame([], $quote['attachment']);

		$withMedia = $mapper->note($this->postView(['embed' => [
			'$type' => 'app.bsky.embed.recordWithMedia#view',
			'record' => ['record' => ['$type' => 'app.bsky.embed.record#viewRecord', 'uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kquoted']],
			'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => [['fullsize' => 'https://cdn.bsky.app/x@png', 'alt' => '']]],
		]]));
		$this->assertSame('https://bsky.app/profile/' . self::OTHER . '/post/3kquoted', $withMedia['quote']);
		$this->assertSame('image/png', $withMedia['attachment'][0]['mediaType']);

		$card = $mapper->note($this->postView(['embed' => ['$type' => 'app.bsky.embed.external#view', 'external' => ['uri' => 'https://nextcloud.com/blog', 'title' => 'A <post>']]]));
		$this->assertStringEndsWith('<p><a href="https://nextcloud.com/blog" rel="nofollow noopener noreferrer" target="_blank">A &lt;post&gt;</a></p>', $card['content']);

		$pending = $mapper->note($this->postView(['embed' => ['$type' => 'app.bsky.embed.video#view', 'cid' => 'bafkrei']]));
		$this->assertSame('Note', $pending['type'], 'no playlist yet: a link to the post');
		$this->assertStringContainsString('<a href="https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22"', $pending['content']);
		$this->assertStringContainsString('Video on Bluesky', $pending['content']);
	}

	public function testAVideoPostIsAFederatedVideoStreamedFromItsPlaylist(): void {
		$mapper = new PostMapper($this->createMock(LocalRecordResolver::class));
		$playlist = 'https://video.bsky.app/watch/did%3Aplc%3Ax/bafkrei/playlist.m3u8';
		$view = ['$type' => 'app.bsky.embed.video#view', 'cid' => 'bafkrei', 'playlist' => $playlist,
			'thumbnail' => 'https://video.bsky.app/watch/did%3Aplc%3Ax/bafkrei/thumbnail.jpg', 'aspectRatio' => ['width' => 720, 'height' => 1280]];

		$video = $mapper->note($this->postView(['embed' => $view]));

		$this->assertSame('Video', $video['type']);
		$this->assertStringNotContainsString('Video on Bluesky', $video['content']);
		$this->assertSame([
			['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://bsky.app/profile/alice.bsky.social/post/3kznmn7xqxl22'],
			['type' => 'Link', 'mediaType' => 'application/x-mpegURL', 'href' => $playlist, 'width' => 720, 'height' => 1280],
		], $video['url']);
		$this->assertSame('https://video.bsky.app/watch/did%3Aplc%3Ax/bafkrei/thumbnail.jpg', $video['icon']['url']);
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', $video['_atproto']['uri'], 'still a Bluesky post');

		$quoting = $mapper->note($this->postView(['embed' => ['$type' => 'app.bsky.embed.recordWithMedia#view', 'record' => ['record' => []], 'media' => $view]]));
		$this->assertSame('Video', $quoting['type'], 'a video beside a quote');

		$this->assertNull(PostMapper::videoOf(['$type' => 'app.bsky.embed.video#view', 'playlist' => 'http://plain.example/x.m3u8']), 'only https');
	}

	public function testARepostInAFeedIsAnAnnounceByTheReposter(): void {
		$mapper = new PostMapper($this->resolver());
		$item = ['post' => $this->postView(), 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => self::OTHER, 'handle' => 'bob.bsky.social'], 'indexedAt' => '2026-10-08T11:00:00.000Z', 'uri' => 'at://' . self::OTHER . '/app.bsky.feed.repost/3krepost']];
		$announce = $mapper->announce($item);
		$this->assertSame([
			'id' => 'https://bsky.app/profile/' . self::OTHER . '/repost/3krepost',
			'type' => 'Announce',
			'actor' => 'https://bsky.app/profile/' . self::OTHER,
			'object' => 'https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22',
			'published' => '2026-10-08T11:00:00Z',
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => ['https://bsky.app/profile/' . self::OTHER . '/followers'],
		], $announce);
		$this->assertNull($mapper->announce(['post' => $this->postView()]), 'no reason, no repost');
		$this->assertSame('https://bsky.app/profile/' . self::OTHER . '/repost/3kznmn7xqxl22', $mapper->announce(['post' => $this->postView(), 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => self::OTHER]]])['id'], 'without the repost URI the post rkey stands in');
	}

	public function testAReplyToALocalPostMentionsItsAuthor(): void {
		$identities = $this->createMock(AtprotoIdentityRequest::class);
		$identities->method('getByDid')->willReturnCallback(static fn (string $did): Identity => $did === self::OTHER
			? new Identity(1, 'https://social.test/@alice', self::OTHER, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0)
			: throw new AtprotoIdentityNotFoundException());
		$records = $this->createMock(AtprotoRepoRequest::class);
		$records->method('getRecord')->willReturn(new StoredRecord(self::OTHER, 'app.bsky.feed.post', '3kparent', Cid::forRaw('p'), '', 'https://social.test/@alice/7', 0));
		$note = (new PostMapper(new LocalRecordResolver($identities, $records)))->note($this->postView());

		$this->assertSame('https://social.test/@alice/7', $note['inReplyTo'], 'the parent is the local post');
		$this->assertContains(['type' => 'Mention', 'href' => 'https://social.test/@alice', 'name' => '@' . self::OTHER], $note['tag']);
	}

	private function postView(array $overrides = []): array {
		return array_merge([
			'uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22',
			'cid' => 'bafyreipost',
			'author' => ['did' => self::DID, 'handle' => 'Alice.bsky.social', 'displayName' => 'Alice', 'avatar' => 'https://cdn.bsky.app/img/avatar/plain/x@jpeg', 'labels' => []],
			'record' => [
				'$type' => 'app.bsky.feed.post',
				'text' => 'Hello #interop',
				'facets' => [['index' => ['byteStart' => 6, 'byteEnd' => 14], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'interop']]]],
				'langs' => ['en'],
				'createdAt' => '2026-10-08T10:00:00.000Z',
				'reply' => ['root' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kparent', 'cid' => 'bafyr'], 'parent' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kparent', 'cid' => 'bafyr']],
			],
			'embed' => ['$type' => 'app.bsky.embed.images#view', 'images' => [
				['thumb' => 'https://cdn.bsky.app/img/feed_thumbnail/plain/' . self::DID . '/bafkreipic1@jpeg', 'fullsize' => 'https://cdn.bsky.app/img/feed_fullsize/plain/' . self::DID . '/bafkreipic1@jpeg', 'alt' => 'a cat', 'aspectRatio' => ['width' => 1000, 'height' => 750]],
				['thumb' => 'https://cdn.bsky.app/img/feed_thumbnail/plain/' . self::DID . '/bafkreipic2@jpeg', 'fullsize' => 'https://cdn.bsky.app/img/feed_fullsize/plain/' . self::DID . '/bafkreipic2@jpeg', 'alt' => ''],
			]],
			'replyCount' => 2,
			'repostCount' => 1,
			'likeCount' => 3,
			'quoteCount' => 0,
			'indexedAt' => '2026-10-08T10:00:01.000Z',
			'labels' => [],
		], $overrides);
	}

	private function resolver(): LocalRecordResolver {
		$identities = $this->createMock(AtprotoIdentityRequest::class);
		$identities->method('getByDid')->willThrowException(new AtprotoIdentityNotFoundException());

		return new LocalRecordResolver($identities, $this->createMock(AtprotoRepoRequest::class));
	}
}
