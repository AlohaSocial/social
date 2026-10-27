<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Federation;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ActorService;
use OCP\Server;

/**
 * The inbound fixture corpus (tests/Federation/fixtures, shaped after each
 * project's documented format; see the unit InboundCorpusTest) delivered
 * through the inbox handling into the real database, and read back as the
 * row that was stored and the Mastodon entity a client is served.
 *
 * What is pinned is today's behaviour, including what is dropped: a Misskey
 * reaction is stored as an EmojiReact, an Akkoma custom
 * emoji reaction is not stored at all, a WordPress excerpt becomes a content
 * warning, and a Lemmy community's boost of a post is queued for a fetch
 * rather than taken in. A Move is not here: its target is always fetched
 * from its server, which a test runner cannot reach.
 */
class InboundCorpusTest extends InboxTestCase {
	protected function hosts(): array {
		return [
			'misskey.example', 'gts.example', 'akkoma.example', 'friendica.example', 'lemmy.example',
			'mastodon.example', 'peertube.example', 'video.example', 'threads.net', 'blog.example',
			'ghost.example', 'bsky.brid.gy',
		];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->localPost();
	}

	/** Delivers a fixture, its actor cached first under the name given. */
	private function receive(string $fixture, string $username): void {
		$actor = (string)(json_decode($this->fixture($fixture), true)['actor'] ?? '');
		$this->remoteActor($actor, $username);

		$this->assertTrue($this->deliver($this->fixture($fixture)), $fixture . ' is handled');
	}

	private function queued(string $streamId): array {
		return array_column($this->rows('social_stream_queue', 'stream_id', $streamId), 'type');
	}

	public function testAMisskeyNoteIsStoredWithItsQuotePendingApproval(): void {
		$this->receive('misskey-create-note', 'yuki');
		$id = 'https://misskey.example/notes/9fq0z1y2x3';

		$status = $this->statusEntity($id);
		$this->assertSame('public', $status['visibility']);
		$this->assertSame('yuki@misskey.example', $status['account']['acct']);
		$this->assertStringContainsString('look at this', $status['content']);
		$this->assertNull($status['language'], 'no contentMap, no language');
		$this->assertSame('pending', $status['quote']['state'] ?? null, 'a quote nobody here approved');
		$this->assertSame(['garden'], array_column($status['tags'], 'name'));
		$this->assertSame(['LinkPreview'], $this->queued($id));
	}

	/** Misskey's reaction is dispatched to the EmojiReact handler and stored with its emoji. */
	public function testAMisskeyReactionIsStoredAsAnEmojiReaction(): void {
		$this->receive('misskey-like-reaction', 'yuki');

		$reactions = $this->rows(CoreRequestBuilder::TABLE_REACTIONS, 'actor_id', 'https://misskey.example/');
		$this->assertSame(['🍅'], array_column($reactions, 'emoji'));
		$this->assertSame([], $this->rows(CoreRequestBuilder::TABLE_ACTIONS, 'actor_id', 'https://misskey.example/'));
		$this->assertSame(0, $this->statusEntity($this->localPostId())['favourites_count']);
	}

	public function testAGoToSocialReplyIsStoredUnderThePostItAnswers(): void {
		$this->receive('gotosocial-create-note', 'erin');

		$status = $this->statusEntity('https://gts.example/users/erin/statuses/01J0GTS0000000000000000001');
		$this->assertSame($this->statusEntity($this->localPostId())['id'], $status['in_reply_to_id']);
		$this->assertSame('en', $status['language']);
		$this->assertSame(1, $this->statusEntity($this->localPostId())['replies_count']);
	}

	/** A custom emoji is nothing this app draws as a reaction, so it is not stored; a Unicode one is. */
	public function testAnAkkomaReactionIsStoredOnlyWhenItIsAUnicodeEmoji(): void {
		$this->receive('akkoma-emoji-react', 'frank');
		$this->assertSame([], $this->rows(CoreRequestBuilder::TABLE_REACTIONS, 'actor_id', 'https://akkoma.example/'));

		$unicode = json_decode($this->fixture('akkoma-emoji-react'), true);
		$unicode['id'] .= '-unicode';
		$unicode['content'] = '🍅';
		unset($unicode['tag']);
		$this->assertTrue($this->deliver((string)json_encode($unicode)));

		$this->assertSame(['🍅'], array_column(
			$this->rows(CoreRequestBuilder::TABLE_REACTIONS, 'actor_id', 'https://akkoma.example/'), 'emoji'
		));
	}

	public function testAFriendicaNoteKeepsItsHtmlAndLanguage(): void {
		$this->receive('friendica-create-note', 'gina');

		$status = $this->statusEntity('https://friendica.example/objects/a1b2c3d4-0001-4567-89ab-cdef01234567');
		$this->assertSame('Seedlings are <b>finally</b> up.', $status['content']);
		$this->assertSame('de', $status['language']);
		$this->assertSame('https://friendica.example/display/a1b2c3d4-0001-4567-89ab-cdef01234567', $status['url']);
	}

	public function testALemmyLinkPostIsStoredWithItsLink(): void {
		$this->receive('lemmy-create-page', 'dana');
		$id = 'https://lemmy.example/post/4711';

		$status = $this->statusEntity($id);
		$this->assertStringContainsString('https://seeds.example/tomatoes', $status['content']);
		$this->assertSame('public', $status['visibility']);
		$this->assertSame(['LinkPreview'], $this->queued($id));
	}

	/** A community's Announce is never taken on its word: nothing is stored, a fetch is queued. */
	public function testALemmyCommunityBoostIsQueuedNotStored(): void {
		$this->receive('lemmy-announce-create', 'gardening');

		$this->assertNull($this->stored('https://lemmy.example/post/4711'));
		$this->assertSame(['Cache'], $this->queued('https://lemmy.example/activities/announce/create/1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d'));
	}

	/**
	 * Mastodon 4.5's quote of a local post, and the QuoteRequest it sends for
	 * it: the quote is pending until approved, and the request is approved
	 * with a grant and an Accept queued back to Mastodon carrying the
	 * QuoteAuthorization as its `result`.
	 */
	public function testAMastodonQuoteAndItsRequestAreApproved(): void {
		$this->receive('mastodon-create-quote-note', 'carol');
		$this->assertSame('pending', $this->statusEntity('https://mastodon.example/users/carol/statuses/115000000000000001')['quote']['state'] ?? null);

		$this->assertTrue($this->deliver($this->fixture('mastodon-quote-request')));

		$grants = $this->rows(CoreRequestBuilder::TABLE_QUOTE_GRANTS, 'actor_id', 'https://mastodon.example/');
		$this->assertSame(['https://mastodon.example/users/carol/statuses/115000000000000001'], array_column($grants, 'quoting_id'));
		$queued = $this->rows(CoreRequestBuilder::TABLE_REQUEST_QUEUE, 'author', $this->alice());
		$this->assertCount(1, $queued);
		$accept = json_decode($queued[0]['activity'], true);
		$this->assertSame('Accept', $accept['type']);
		$this->assertSame($grants[0]['authorization'], $accept['result']);
		$this->assertSame('https://mastodon.example/users/carol/quote_requests/7', $accept['object']['id'] ?? $accept['object']);
	}

	public function testAPollAndAnUpdateOfItsCounts(): void {
		$this->receive('mastodon-create-question', 'carol');
		$id = 'https://mastodon.example/users/carol/statuses/115000000000000002';
		$this->assertSame(1, $this->statusEntity($id)['poll']['votes_count']);

		$this->assertTrue($this->deliver($this->fixture('mastodon-update-question')));

		$poll = $this->statusEntity($id)['poll'];
		$this->assertSame([['title' => 'beans', 'votes_count' => 3], ['title' => 'peas', 'votes_count' => 2]], $poll['options']);
		$this->assertSame(5, $poll['voters_count']);
	}

	public function testABlockIsRecordedAgainstTheLocalAccount(): void {
		$this->receive('mastodon-block', 'carol');

		$relations = $this->rows(CoreRequestBuilder::TABLE_ACTOR_RELATION, 'object_id', 'https://mastodon.example/users/carol');
		$this->assertSame(['blocked_by'], array_column($relations, 'type'));
		$this->assertSame(md5($this->alice()), $relations[0]['actor_id_prim']);
	}

	public function testAReportBecomesARemoteReportAboutTheLocalAccount(): void {
		$this->receive('mastodon-flag', 'mastodon.example');

		$reports = $this->rows(CoreRequestBuilder::TABLE_REPORTS, 'actor_id', 'https://mastodon.example/');
		$this->assertCount(1, $reports);
		$this->assertSame($this->alice(), $reports[0]['account_id']);
		$this->assertSame('spam', $reports[0]['comment']);
		$this->assertSame([$this->localPostId()], json_decode($reports[0]['status_ids'], true));
		$this->assertSame(0, (int)$reports[0]['local']);
	}

	public function testAPeerTubeDislikeIsStoredAsADislike(): void {
		$this->receive('peertube-dislike', 'hank');

		$this->assertSame(['Dislike'], array_column(
			$this->rows(CoreRequestBuilder::TABLE_ACTIONS, 'actor_id', 'https://peertube.example/'), 'type'
		));
	}

	/** A View of a video this instance does not hold is handled and leaves nothing behind. */
	public function testAPeerTubeViewOfAnUnknownVideoLeavesNothing(): void {
		$this->receive('peertube-view', 'peertube');

		$this->assertSame([], $this->rows(CoreRequestBuilder::TABLE_STREAM, 'id', 'https://peertube.example/'));
		$this->assertSame([], $this->rows(CoreRequestBuilder::TABLE_ACTIONS, 'actor_id', 'https://peertube.example/'));
	}

	/**
	 * A redundancy announcement has no model; the Create keeps only the
	 * object's id, which is on the sender's own host, so it is queued for a
	 * fetch like any Create of a bare id.
	 */
	public function testAPeerTubeCacheFileIsQueuedForAFetch(): void {
		$this->receive('peertube-cachefile', 'peertube');

		$this->assertSame(['Fetch'], $this->queued('https://peertube.example/redundancy/videos/9c9de5e8-0a1b-4c2d-8e3f-123456789abc/720'));
	}

	public function testAThreadsNoteKeepsItsPictureAsARemoteAttachment(): void {
		$this->receive('threads-create-note', 'ivy');

		$status = $this->statusEntity('https://threads.net/ap/users/17841400000000001/post/17900000000000001/');
		$this->assertSame('https://www.threads.net/@ivy/post/C5aBcDeFgHi', $status['url']);
		$this->assertCount(1, $status['media_attachments']);
		$this->assertSame('https://scontent.cdninstagram.com/v/greenhouse.jpg', $status['media_attachments'][0]['remote_url']);
		$this->assertSame('rows of trays in a greenhouse', $status['media_attachments'][0]['description']);
	}

	/** The WordPress plugin's excerpt arrives as `summary`, which is read as a content warning. */
	public function testAWordPressArticleShowsItsBodyBehindItsExcerpt(): void {
		$this->receive('wordpress-create-article', 'jules');

		$status = $this->statusEntity('https://blog.example/?p=123');
		$this->assertSame('<h2>Pots</h2><p>Use big ones.</p>', $status['content']);
		$this->assertSame('A short guide to […]', $status['spoiler_text']);
		$this->assertSame('https://blog.example/2026/04/05/tomatoes/', $status['url']);
		$this->assertCount(1, $status['media_attachments']);
	}

	public function testAGhostArticleIsStoredWithItsPage(): void {
		$this->receive('ghost-create-article', 'index');

		$status = $this->statusEntity('https://ghost.example/.ghost/activitypub/article/0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c');
		$this->assertSame('<p>It is finally warm enough.</p>', $status['content']);
		$this->assertSame('https://ghost.example/spring-planting-notes/', $status['url']);
		$this->assertSame('', $status['spoiler_text']);
	}

	/**
	 * A Bridgy Fed account, cached the way a fetched actor is, and a post of
	 * it: the account is addressed by its Bluesky handle on the bridge.
	 */
	public function testABridgyFedAccountAndItsPost(): void {
		$actor = $this->importService->importFromJson($this->fixture('bridgyfed-actor'));
		$this->assertInstanceOf(Person::class, $actor);
		$actor->setAccount($actor->getPreferredUsername() . '@bsky.brid.gy');
		Server::get(ActorService::class)->save($actor);

		$this->assertTrue($this->deliver($this->fixture('bridgyfed-create-note')));

		$status = $this->statusEntity('https://bsky.brid.gy/convert/ap/at://did:plc:3jpt2mvvsumj2r7eqk4gzzjz/app.bsky.feed.post/3kxyzabc2s2c');
		$this->assertSame('kim.bsky.social@bsky.brid.gy', $status['account']['acct']);
		$this->assertSame('https://bsky.app/profile/did:plc:3jpt2mvvsumj2r7eqk4gzzjz/post/3kxyzabc2s2c', $status['url']);
	}

	/** An account deleting itself takes its cached copy and its posts with it. */
	public function testAnAccountDeletingItselfTakesItsPostsWithIt(): void {
		$this->receive('mastodon-create-question', 'carol');

		$this->assertTrue($this->deliver($this->fixture('mastodon-delete-actor')));

		$this->assertSame([], $this->rows(CoreRequestBuilder::TABLE_CACHE_ACTORS, 'id', 'https://mastodon.example/users/carol'));
		$this->assertNull($this->stored('https://mastodon.example/users/carol/statuses/115000000000000002'));
	}
}
