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
use OCA\Social\Model\Details;
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

		$this->assertSame(['url' => 'https://bsky.app/profile/bob.test/lists/3kl', 'title' => 'Friends', 'description' => '', 'image' => '', 'provider' => 'List by @bob.test'], PostMapper::cardOf($record($list)));
		$this->assertSame(['url' => 'https://bsky.app/starter-pack/bob.test/3ks', 'title' => 'Start here', 'description' => 'Good people', 'image' => 'https://cdn.bsky.app/img/avatar/plain/bob@jpeg', 'provider' => 'Starter pack by @bob.test'],
			PostMapper::cardOf(['$type' => 'app.bsky.embed.recordWithMedia#view', 'record' => $record($pack), 'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => []]]), 'beside pictures');
		$external = ['$type' => 'app.bsky.embed.external', 'external' => ['uri' => 'https://example.com/story', 'title' => 'A story', 'description' => 'Details']];
		$this->assertSame(['url' => 'https://example.com/story', 'title' => 'A story', 'description' => 'Details', 'image' => '', 'provider' => 'example.com'],
			PostMapper::cardOf(['$type' => 'app.bsky.embed.recordWithMedia', 'record' => ['record' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kp']], 'media' => $external]), 'an external card beside a quoted record');
		$this->assertNull(PostMapper::cardOf($record(['$type' => 'app.bsky.embed.record#viewRecord', 'uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kp'])), 'a quoted post is a quote');

		$note = (new PostMapper($this->resolver()))->note($this->postView(['embed' => $record($list)]));
		$this->assertStringContainsString('<a href="https://bsky.app/profile/bob.test/lists/3kl"', $note['content']);
		$this->assertSame('Friends', $note['_atproto']['card']['title']);
	}

	public function testARecordWithExternalMediaKeepsItsNonPostStrongRefForRepublishing(): void {
		$uri = 'at://' . self::OTHER . '/app.bsky.feed.generator/3kgenerator';
		$cid = Cid::forRaw('generator record')->toString();
		$view = $this->postView([
			'embed' => [
				'$type' => 'app.bsky.embed.recordWithMedia#view',
				'record' => ['record' => ['$type' => 'app.bsky.embed.record#viewRecord', 'uri' => $uri, 'cid' => $cid]],
				'media' => ['$type' => 'app.bsky.embed.external#view', 'external' => [
					'uri' => 'https://example.com/story',
					'title' => 'A story',
					'description' => 'Details',
				]],
			],
		]);

		$note = (new PostMapper($this->resolver()))->note($view);

		$this->assertSame(['uri' => $uri, 'cid' => $cid], $note['_atproto'][Details::ATPROTO_RECORD_EMBED]);
		$this->assertSame('https://example.com/story', $note['_atproto']['card']['url']);
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

	/**
	 * The self-label asks apps not to show the account, or the post, to
	 * people who are not logged in: the post is addressed to the author's
	 * followers alone, never to the public.
	 */
	public function testAPostNotForLoggedOutPeopleIsAddressedToTheFollowersAlone(): void {
		$mapper = new PostMapper($this->resolver());
		$label = ['src' => self::DID, 'uri' => 'at://' . self::DID . '/app.bsky.actor.profile/self', 'val' => PostMapper::NO_UNAUTHENTICATED];
		$followers = 'https://bsky.app/profile/' . self::DID . '/followers';

		$onThePost = $mapper->create($this->postView(['labels' => [$label]]));
		$this->assertSame([$followers], $onThePost['object']['to']);
		$this->assertSame([], $onThePost['object']['cc']);
		$this->assertSame([$followers], $onThePost['to'], 'the Create carries the same audience');
		$this->assertNotContains(ACore::CONTEXT_PUBLIC, array_merge($onThePost['object']['to'], $onThePost['object']['cc']));

		$author = $this->postView();
		$author['author']['labels'] = [$label];
		$onTheProfile = $mapper->note($author);
		$this->assertSame([$followers], $onTheProfile['to'], 'the label on the profile covers every post');
		$this->assertSame([], $onTheProfile['cc']);
		$this->assertSame([PostMapper::NO_UNAUTHENTICATED], $onThePost['object']['_atproto']['labels'], 'the post label is kept with the post');

		$public = $mapper->note($this->postView());
		$this->assertSame([ACore::CONTEXT_PUBLIC], $public['to'], 'without the label a post is public');

		$announce = $mapper->announce(['post' => $author, 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => self::OTHER], 'indexedAt' => '2026-10-08T11:00:00.000Z']]);
		$this->assertSame(['https://bsky.app/profile/' . self::OTHER . '/followers'], $announce['to'], 'a boost of it is for the booster\'s followers alone');
		$this->assertSame([], $announce['cc']);
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

	public function testSelfWarningTextBecomesTheSummaryAndItsFacetsKeepTheirOffsets(): void {
		$view = $this->postView(['labels' => [['src' => self::DID, 'uri' => 'at://x', 'val' => '!warn']]]);
		$view['record']['text'] = "Content warning: family emergency\n\nHello @bob.bsky.social";
		$mention = '@bob.bsky.social';
		$start = strlen("Content warning: family emergency\n\nHello ");
		$view['record']['facets'] = [[
			'index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($mention)],
			'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => self::OTHER]],
		]];

		$note = (new PostMapper($this->resolver()))->note($view);

		$this->assertSame('family emergency', $note['summary']);
		$this->assertTrue($note['sensitive']);
		$this->assertSame('<p>Hello <span class="h-card"><a href="https://bsky.app/profile/' . self::OTHER . '" class="u-url mention">@bob.bsky.social</a></span></p>', $note['content']);
		$this->assertContains(['type' => 'Mention', 'href' => 'https://bsky.app/profile/' . self::OTHER, 'name' => '@bob.bsky.social'], $note['tag']);
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
		$this->assertArrayNotHasKey('quote_detached', $quote['_atproto']);

		$detached = $mapper->note($this->postView(['embed' => [
			'$type' => 'app.bsky.embed.recordWithMedia#view',
			'record' => ['record' => ['$type' => 'app.bsky.embed.record#viewDetached', 'uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kquoted', 'detached' => true]],
			'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => [['fullsize' => 'https://cdn.bsky.app/x@png', 'alt' => '']]],
		]]));
		$this->assertSame('https://bsky.app/profile/' . self::OTHER . '/post/3kquoted', $detached['quote'], 'a detached quote is still a quote');
		$this->assertTrue($detached['_atproto']['quote_detached']);

		$card = $mapper->note($this->postView(['embed' => ['$type' => 'app.bsky.embed.external#view', 'external' => ['uri' => 'https://nextcloud.com/blog', 'title' => 'A <post>', 'description' => 'A post description', 'thumb' => 'https://cdn.bsky.app/card.jpg']]]));
		$this->assertStringEndsWith('<p><a href="https://nextcloud.com/blog" rel="nofollow noopener noreferrer" target="_blank">A &lt;post&gt;</a></p>', $card['content']);
		$this->assertSame([
			'url' => 'https://nextcloud.com/blog',
			'title' => 'A <post>',
			'description' => 'A post description',
			'image' => 'https://cdn.bsky.app/card.jpg',
			'provider' => 'nextcloud.com',
		], $card['_atproto']['card'], 'the external embed is retained as a link preview with its image');

		$pending = $mapper->note($this->postView(['embed' => ['$type' => 'app.bsky.embed.video#view', 'cid' => 'bafkrei']]));
		$this->assertSame('Note', $pending['type'], 'no playlist yet: a link to the post');
		$this->assertStringContainsString('<a href="https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22"', $pending['content']);
		$this->assertStringContainsString('target="_blank">Video</a>', $pending['content'], 'named for what it is, not where it is');
	}

	public function testGalleryAndImageViewsBecomeUsableImageAttachments(): void {
		$items = [];
		for ($i = 0; $i < 5; $i++) {
			$items[] = [
				'thumbnail' => 'https://cdn.bsky.app/thumb-' . $i . '.jpg',
				'fullsize' => $i === 0 ? '' : 'https://cdn.bsky.app/full-' . $i . '.jpg',
				'alt' => 'photo ' . $i,
				'aspectRatio' => ['width' => 1200, 'height' => 800],
			];
		}
		$gallery = (new PostMapper($this->resolver()))->note($this->postView([
			'embed' => ['$type' => 'app.bsky.embed.gallery#view', 'items' => $items],
		]));

		$this->assertCount(5, $gallery['attachment'], 'all supported gallery images must reach the carousel');
		$this->assertSame('https://cdn.bsky.app/thumb-0.jpg', $gallery['attachment'][0]['url'], 'the thumbnail remains usable when the full image is absent');
		$this->assertSame('photo 0', $gallery['attachment'][0]['name']);
		$this->assertSame(1200, $gallery['attachment'][0]['width']);
		$this->assertSame('https://cdn.bsky.app/full-1.jpg', $gallery['attachment'][1]['url']);

		$image = (new PostMapper($this->resolver()))->note($this->postView([
			'embed' => ['$type' => 'app.bsky.embed.images#view', 'images' => [['thumb' => 'https://cdn.bsky.app/thumb.png', 'fullsize' => '', 'alt' => 'preview']]],
		]));
		$this->assertSame('https://cdn.bsky.app/thumb.png', $image['attachment'][0]['url'], 'legacy image views also fall back to their thumbnail');
	}

	public function testRawImageBlobsAreUsedWhenAppViewOmitsTheEmbedView(): void {
		$cid = Cid::forRaw('original bluesky image bytes')->toString();
		$view = $this->postView();
		$view['embed'] = ['$type' => 'app.bsky.embed.images#view', 'images' => []];
		$view['record']['embed'] = [
			'$type' => 'app.bsky.embed.images',
			'images' => [[
				'image' => [
					'$type' => 'blob',
					'ref' => ['$link' => $cid],
					'mimeType' => 'image/png',
					'size' => 1234,
				],
				'alt' => 'the original record image',
				'aspectRatio' => ['width' => 1200, 'height' => 800],
			]],
		];

		$note = (new PostMapper($this->resolver()))->note($view);

		$this->assertSame([[
			'type' => 'Image',
			'mediaType' => 'image/png',
			'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/' . self::DID . '/' . $cid . '@png',
			'name' => 'the original record image',
			'width' => 1200,
			'height' => 800,
		]], $note['attachment'], 'the post remains viewable when its CDN-enriched embed is missing');
	}

	public function testRawImageBlobsOutsideTheCdnFormatsStreamFromThePds(): void {
		$cid = Cid::forRaw('original HEIC image bytes')->toString();
		$view = $this->postView();
		$view['embed'] = ['$type' => 'app.bsky.embed.images#view', 'images' => []];
		$view['record']['embed'] = [
			'$type' => 'app.bsky.embed.images',
			'images' => [[
				'image' => ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'image/heic', 'size' => 4096],
				'alt' => 'Original camera photo',
				'aspectRatio' => ['width' => 1600, 'height' => 1200],
			]],
		];

		$mapper = new PostMapper($this->resolver());
		$this->assertTrue(PostMapper::hasGenericBlobs($view['record']['embed']), 'the unsupported standard image needs a later PDS recovery');
		$this->assertSame([], $mapper->note($view)['attachment'], 'do not invent a CDN URL when the PDS has not been resolved');

		$note = $mapper->note($view, 'https://pds.example');
		$this->assertSame([[
			'type' => 'Document',
			'mediaType' => 'image/heic',
			'url' => 'https://pds.example/xrpc/com.atproto.sync.getBlob?did=' . rawurlencode(self::DID) . '&cid=' . $cid,
			'name' => 'Original camera photo.heic',
			'width' => 1600,
			'height' => 1200,
		]], $note['attachment']);
		$this->assertSame([$note['attachment'][0]['url']], $note['_atproto']['streamed_blobs']);
	}

	public function testUnsupportedStandardImageIsStreamedAsInlinePreviewableAvif(): void {
		$cid = Cid::forRaw('original AVIF image bytes')->toString();
		$view = $this->postView();
		$view['embed'] = ['$type' => 'app.bsky.embed.images#view', 'images' => []];
		$view['record']['embed'] = [
			'$type' => 'app.bsky.embed.images',
			'images' => [['image' => ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'image/avif'], 'alt' => 'Alpine lake']],
		];

		$note = (new PostMapper($this->resolver()))->note($view, 'https://pds.example');

		$this->assertSame('Image', $note['attachment'][0]['type']);
		$this->assertSame('image/avif', $note['attachment'][0]['mediaType']);
		$this->assertSame('Alpine lake', $note['attachment'][0]['name']);
	}

	public function testRawImageBlobReferencesMustHaveAValidCidAndImageMimeType(): void {
		$view = $this->postView();
		$view['embed'] = null;
		$view['record']['embed'] = [
			'$type' => 'app.bsky.embed.images',
			'images' => [
				['image' => ['ref' => ['$link' => 'not-a-cid'], 'mimeType' => 'image/png']],
				['image' => ['ref' => ['$link' => Cid::forRaw('opaque file bytes')->toString()], 'mimeType' => 'application/pdf']],
			],
		];

		$note = (new PostMapper($this->resolver()))->note($view);

		$this->assertSame([], $note['attachment'], 'an opaque or non-image blob is never presented as a broken image');
	}

	public function testThirdPartyEmbedFilesBecomeNamedStreamedAttachments(): void {
		$cid = Cid::forRaw('a PDF from a custom embed')->toString();
		$embed = [
			'$type' => 'org.example.embed.files',
			'files' => [[
				'name' => 'meeting-notes.pdf',
				'blob' => ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'application/pdf', 'size' => 512],
			]],
		];
		$view = $this->postView([
			'embed' => ['$type' => 'org.example.embed.files#view'],
			'record' => array_merge($this->postView()['record'], ['embed' => $embed]),
		]);

		$note = (new PostMapper($this->resolver()))->note($view, 'https://pds.example');

		$this->assertSame([[
			'type' => 'Document',
			'mediaType' => 'application/pdf',
			'url' => 'https://pds.example/xrpc/com.atproto.sync.getBlob?did=' . rawurlencode(self::DID) . '&cid=' . $cid,
			'name' => 'meeting-notes.pdf',
			'size' => 512,
		]], $note['attachment']);
		$this->assertSame([$note['attachment'][0]['url']], $note['_atproto']['streamed_blobs']);
		$this->assertTrue(PostMapper::hasGenericBlobs($embed));
	}

	public function testGenericBlobsNeedAValidCidPdsAndPublicPost(): void {
		$embed = ['$type' => 'org.example.embed.file', 'file' => [
			'name' => 'report.pdf', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => Cid::forRaw('report')->toString()], 'mimeType' => 'application/pdf'],
		]];
		$view = $this->postView([
			'embed' => ['$type' => 'org.example.embed.file#view'],
			'record' => array_merge($this->postView()['record'], ['embed' => $embed]),
		]);
		$mapper = new PostMapper($this->resolver());
		$this->assertSame([], $mapper->note($view)['attachment'], 'without a verified PDS there is no external file URL');
		$this->assertSame([], $mapper->note($view, 'http://pds.example')['attachment'], 'insecure PDS endpoints are refused');
		foreach ([
			'https://user@pds.example',
			'https://user:password@pds.example',
			'https://pds.example?mode=unsafe',
			'https://pds.example#fragment',
		] as $unsafePds) {
			$this->assertSame([], $mapper->note($view, $unsafePds)['attachment'], 'PDS endpoints with credentials, query strings, or fragments are refused');
		}

		$view['labels'] = [['val' => PostMapper::NO_UNAUTHENTICATED]];
		$this->assertSame([], $mapper->note($view, 'https://pds.example')['attachment'], 'logged-in-only Bluesky posts do not leak through the public media route');
	}

	public function testGenericFileBlobsNestedInRecordWithMediaAreEligibleForPdsRecovery(): void {
		$blob = [
			'$type' => 'blob',
			'ref' => ['$link' => Cid::forRaw('nested custom file')->toString()],
			'mimeType' => 'application/pdf',
		];
		foreach (['app.bsky.embed.recordWithMedia', 'app.bsky.embed.recordWithMedia#view'] as $wrapperType) {
			$embed = [
				'$type' => $wrapperType,
				'media' => ['$type' => 'org.example.embed.file', 'file' => ['name' => 'notes.pdf', 'blob' => $blob]],
				'record' => [],
			];
			$this->assertTrue(PostMapper::hasGenericBlobs($embed), $wrapperType . ' must recurse into its media union');
		}
	}

	public function testImagesInThirdPartyEmbedsUseTheValidatedBlueskyCdn(): void {
		$cid = Cid::forRaw('custom image')->toString();
		$embed = ['$type' => 'org.example.embed.file', 'file' => [
			'name' => 'cover.png', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'image/png'],
		]];
		$view = $this->postView([
			'embed' => ['$type' => 'org.example.embed.file#view'],
			'record' => array_merge($this->postView()['record'], ['embed' => $embed]),
		]);

		$note = (new PostMapper($this->resolver()))->note($view);

		$this->assertSame([[
			'type' => 'Image',
			'mediaType' => 'image/png',
			'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/' . self::DID . '/' . $cid . '@png',
			'name' => 'cover.png',
		]], $note['attachment']);
	}

	public function testUnsupportedCdnImageFormatsRemainAvailableAsPdsFiles(): void {
		$cid = Cid::forRaw('a third-party AVIF image')->toString();
		$embed = ['$type' => 'org.example.embed.file', 'file' => [
			'name' => 'portrait.avif', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'image/avif', 'size' => 2048],
		]];
		$view = $this->postView([
			'embed' => ['$type' => 'org.example.embed.file#view'],
			'record' => array_merge($this->postView()['record'], ['embed' => $embed]),
		]);

		$this->assertSame([], (new PostMapper($this->resolver()))->note($view)['attachment'], 'without a verified PDS, an unknown blob URL is not invented');
		$note = (new PostMapper($this->resolver()))->note($view, 'https://pds.example');

		$this->assertSame([[
			'type' => 'Document',
			'mediaType' => 'image/avif',
			'url' => 'https://pds.example/xrpc/com.atproto.sync.getBlob?did=' . rawurlencode(self::DID) . '&cid=' . $cid,
			'name' => 'portrait.avif',
			'size' => 2048,
		]], $note['attachment']);
		$this->assertTrue(PostMapper::hasGenericBlobs($embed), 'a later view may add the PDS endpoint and recover this file');
	}

	public function testUnnamedBlobsUseReadableMimetypeNames(): void {
		$pdfCid = Cid::forRaw('unnamed PDF')->toString();
		$imageCid = Cid::forRaw('unnamed AVIF image')->toString();
		$embed = ['$type' => 'org.example.embed.files', 'files' => [
			['blob' => ['$type' => 'blob', 'ref' => ['$link' => $pdfCid], 'mimeType' => 'application/pdf']],
			['blob' => ['$type' => 'blob', 'ref' => ['$link' => $imageCid], 'mimeType' => 'image/avif']],
		]];
		$view = $this->postView([
			'embed' => ['$type' => 'org.example.embed.files#view'],
			'record' => array_merge($this->postView()['record'], ['embed' => $embed]),
		]);

		$note = (new PostMapper($this->resolver()))->note($view, 'https://pds.example');

		$this->assertSame(['PDF document.pdf', 'AVIF image.avif'], array_column($note['attachment'], 'name'));
	}

	public function testOpaqueBlobKeysAreNotShownAsFilenames(): void {
		$pdfCid = Cid::forRaw('opaque named PDF')->toString();
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$opaqueCid = Cid::forRaw('opaque filename CID')->toString();
		$embed = ['$type' => 'org.example.embed.files', 'files' => [
			['name' => '123456789', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => $pdfCid], 'mimeType' => 'application/pdf']],
			['filename' => $uuid . '.pdf', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => Cid::forRaw('uuid named PDF')->toString()], 'mimeType' => 'application/pdf']],
			['fileName' => $opaqueCid, 'blob' => ['$type' => 'blob', 'ref' => ['$link' => Cid::forRaw('cid named PDF')->toString()], 'mimeType' => 'application/pdf']],
		]];
		$view = $this->postView([
			'embed' => ['$type' => 'org.example.embed.files#view'],
			'record' => array_merge($this->postView()['record'], ['embed' => $embed]),
		]);

		$note = (new PostMapper($this->resolver()))->note($view, 'https://pds.example');

		$this->assertSame(
			['PDF document.pdf', 'PDF document.pdf', 'PDF document.pdf'],
			array_column($note['attachment'], 'name'),
			'opaque identifiers must be replaced by readable labels derived from each blob MIME type'
		);
	}

	public function testRawExternalEmbedKeepsItsLinkAndBlobThumbnail(): void {
		$cid = Cid::forRaw('external card image bytes')->toString();
		$view = $this->postView();
		$view['embed'] = ['$type' => 'app.bsky.embed.future#view'];
		$view['record']['embed'] = [
			'$type' => 'app.bsky.embed.external',
			'external' => [
				'uri' => 'https://example.org/report.pdf',
				'title' => 'The report',
				'description' => 'A linked file',
				'thumb' => [
					'$type' => 'blob',
					'ref' => ['$link' => $cid],
					'mimeType' => 'image/jpeg',
					'size' => 1234,
				],
			],
		];

		$note = (new PostMapper($this->resolver()))->note($view);

		$this->assertSame([
			'url' => 'https://example.org/report.pdf',
			'title' => 'The report',
			'description' => 'A linked file',
			'image' => 'https://cdn.bsky.app/img/feed_fullsize/plain/' . self::DID . '/' . $cid . '@jpeg',
			'provider' => 'example.org',
		], $note['_atproto']['card']);
		$this->assertStringContainsString('https://example.org/report.pdf', $note['content']);
	}

	public function testRawVideoAndRecordWithMediaEmbedsRemainVisible(): void {
		$video = $this->postView();
		$video['embed'] = null;
		$video['record']['embed'] = [
			'$type' => 'app.bsky.embed.video',
			'video' => ['ref' => ['$link' => Cid::forRaw('original video bytes')->toString()], 'mimeType' => 'video/mp4', 'size' => 1234],
			'aspectRatio' => ['width' => 640, 'height' => 360],
			'alt' => 'A video before the AppView built its playlist',
		];
		$videoNote = (new PostMapper($this->resolver()))->note($video);
		$playlist = 'https://video.bsky.app/watch/' . rawurlencode(self::DID) . '/' . Cid::forRaw('original video bytes') . '/playlist.m3u8';
		$this->assertSame('Video', $videoNote['type'], 'a raw video blob uses the Bluesky video service while its AppView view is missing');
		$this->assertSame($playlist, $videoNote['url'][1]['href']);
		$this->assertSame(640, $videoNote['url'][1]['width']);
		$this->assertSame(360, $videoNote['url'][1]['height']);
		$this->assertSame('https://video.bsky.app/watch/' . rawurlencode(self::DID) . '/' . Cid::forRaw('original video bytes') . '/thumbnail.jpg', $videoNote['icon']['url']);

		$invalidVideo = $video;
		$invalidVideo['record']['embed']['video']['mimeType'] = 'application/octet-stream';
		$invalidNote = (new PostMapper($this->resolver()))->note($invalidVideo);
		$this->assertSame('Note', $invalidNote['type'], 'unknown raw blob types are not sent to the video service');
		$this->assertStringContainsString('target="_blank">Video</a>', $invalidNote['content']);

		$cid = Cid::forRaw('quoted post image bytes')->toString();
		$quoted = $this->postView();
		$quoted['embed'] = [
			'$type' => 'app.bsky.embed.recordWithMedia#view',
			'record' => [],
			'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => []],
		];
		$quoted['record']['embed'] = [
			'$type' => 'app.bsky.embed.recordWithMedia',
			'record' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kquoted', 'cid' => 'bafyreibjifzpqj6o6wcq3hejh7y4z4z2vmiklkvykc57tw3pcbx3kxifpm'],
			'media' => [
				'$type' => 'app.bsky.embed.images',
				'images' => [[
					'image' => ['ref' => ['$link' => $cid], 'mimeType' => 'image/png'],
					'alt' => 'A quoted image',
				]],
			],
		];
		$quotedNote = (new PostMapper($this->resolver()))->note($quoted);
		$this->assertSame('https://bsky.app/profile/' . self::OTHER . '/post/3kquoted', $quotedNote['quote']);
		$this->assertSame('https://cdn.bsky.app/img/feed_fullsize/plain/' . self::DID . '/' . $cid . '@png', $quotedNote['attachment'][0]['url']);
	}

	public function testAVideoPostIsAFederatedVideoStreamedFromItsPlaylist(): void {
		$mapper = new PostMapper($this->createMock(LocalRecordResolver::class));
		$playlist = 'https://video.bsky.app/watch/did%3Aplc%3Ax/bafkrei/playlist.m3u8';
		$view = ['$type' => 'app.bsky.embed.video#view', 'cid' => 'bafkrei', 'playlist' => $playlist,
			'thumbnail' => 'https://video.bsky.app/watch/did%3Aplc%3Ax/bafkrei/thumbnail.jpg', 'aspectRatio' => ['width' => 720, 'height' => 1280]];

		$video = $mapper->note($this->postView(['embed' => $view]));

		$this->assertSame('Video', $video['type']);
		$this->assertStringNotContainsString('>Video</a>', $video['content']);
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
		$this->assertContains(['type' => 'Mention', 'href' => 'https://social.test/@alice', 'name' => '@alice.social.test'], $note['tag'], 'named by the handle, not the DID');
	}

	/** The record carries a mention's DID alone; the handle is what the text shows at the facet. */
	public function testAMentionIsNamedByTheHandleTheTextShowsAndTheDidStaysInTheTarget(): void {
		$view = $this->postView();
		$view['record']['text'] = 'hi @bob.bsky.social and @did:plc:z72i7hdynmk6r22z27h6tvur too';
		$view['record']['facets'] = [
			['index' => ['byteStart' => 3, 'byteEnd' => 19], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => self::OTHER]]],
			['index' => ['byteStart' => 100, 'byteEnd' => 120], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:elsewhere']]],
		];

		$tags = (new PostMapper($this->resolver()))->note($view)['tag'];

		$this->assertContains(['type' => 'Mention', 'href' => 'https://bsky.app/profile/' . self::OTHER, 'name' => '@bob.bsky.social'], $tags);
		$this->assertContains(['type' => 'Mention', 'href' => 'https://bsky.app/profile/did:plc:elsewhere', 'name' => '@did:plc:elsewhere'], $tags, 'a facet past the text falls back to the DID');
		$this->assertStringNotContainsString(self::OTHER, implode(' ', array_column($tags, 'name')));
	}

	public function testTheHashtagsBesideTheTextAreThePostsTooOnce(): void {
		$view = $this->postView();
		$view['record']['tags'] = ['Interop', 'sometag', '#other', '', 7];

		$note = (new PostMapper($this->resolver()))->note($view);

		$this->assertSame(
			['#interop', '#sometag', '#other'],
			array_column(array_filter($note['tag'], static fn (array $tag): bool => $tag['type'] === 'Hashtag'), 'name'),
			'one in the text is not named twice, whatever its case'
		);
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
