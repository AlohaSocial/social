<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * How the activities of the implementations the older fixtures did not
 * cover are read: Misskey/Sharkey, GoToSocial, Akkoma, Friendica, Lemmy,
 * Mastodon 4.5's quotes and polls, the Delete/Block/Flag/Move family,
 * PeerTube's Dislike/View/CacheFile, Threads, the WordPress ActivityPub
 * plugin, Ghost and Bridgy Fed.
 *
 * The fixtures are shaped after each project's documented format and
 * published source as known when they were written; none was captured from
 * a live server. `{{LOCAL_POST}}` and `{{ALICE}}` stand for a post and an
 * account on this instance and are filled in by the test.
 *
 * This is the parse step, through AP::getItemFromData(), pinned field by
 * field as it is today. Integration/Federation/InboundCorpusTest takes the
 * same fixtures through the inbox handling into the database.
 */
class InboundCorpusTest extends TestCase {
	use TActivityPubMocks;

	private const ALICE = 'https://cloud.example.org/apps/social/@alice';
	private const LOCAL_POST = 'https://cloud.example.org/apps/social/@alice/17000000001111111111';

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
		$this->apInterface(PersonInterface::class)
			->method('getItemById')
			->willThrowException(new ItemNotFoundException());
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	public static function fixture(string $name, string $alice = self::ALICE, string $localPost = self::LOCAL_POST): array {
		$json = file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');

		return json_decode(
			str_replace(['{{LOCAL_POST}}', '{{ALICE}}'], [$localPost, $alice], (string)$json),
			true, 128, JSON_THROW_ON_ERROR
		);
	}

	private function read(string $name): ACore {
		return AP::instance()->getItemFromData(self::fixture($name));
	}

	/**
	 * What every activity resolves to: its model, its actor, the id of its
	 * object, and for an embedded object its model and subtype.
	 *
	 * @return iterable<string, array{string, string, string, string, string, string}>
	 */
	public static function activities(): iterable {
		$ms = 'https://mastodon.example/users/carol';
		yield 'Misskey Note' => ['misskey-create-note', 'Create', 'https://misskey.example/users/9fq0a1b2c3', 'https://misskey.example/notes/9fq0z1y2x3', 'Note', ''];
		yield 'Misskey reaction' => ['misskey-like-reaction', 'Like', 'https://misskey.example/users/9fq0a1b2c3', self::LOCAL_POST, '', ''];
		yield 'GoToSocial reply' => ['gotosocial-create-note', 'Create', 'https://gts.example/users/erin', 'https://gts.example/users/erin/statuses/01J0GTS0000000000000000001', 'Note', ''];
		yield 'Akkoma reaction' => ['akkoma-emoji-react', 'EmojiReact', 'https://akkoma.example/users/frank', self::LOCAL_POST, '', ''];
		yield 'Friendica Note' => ['friendica-create-note', 'Create', 'https://friendica.example/profile/gina', 'https://friendica.example/objects/a1b2c3d4-0001-4567-89ab-cdef01234567', 'Note', ''];
		yield 'Lemmy Page' => ['lemmy-create-page', 'Create', 'https://lemmy.example/u/dana', 'https://lemmy.example/post/4711', 'Note', 'Page'];
		yield 'Lemmy community boost' => ['lemmy-announce-create', 'Announce', 'https://lemmy.example/c/gardening', 'https://lemmy.example/activities/create/0f1e2d3c-4b5a-4968-8776-655443322110', 'Create', ''];
		yield 'Mastodon quote' => ['mastodon-create-quote-note', 'Create', $ms, $ms . '/statuses/115000000000000001', 'Note', ''];
		yield 'Mastodon QuoteRequest' => ['mastodon-quote-request', 'QuoteRequest', $ms, self::LOCAL_POST, '', ''];
		yield 'Mastodon poll' => ['mastodon-create-question', 'Create', $ms, $ms . '/statuses/115000000000000002', 'Question', ''];
		yield 'Mastodon poll update' => ['mastodon-update-question', 'Update', $ms, $ms . '/statuses/115000000000000002', 'Question', ''];
		yield 'Mastodon account deletion' => ['mastodon-delete-actor', 'Delete', $ms, $ms, '', ''];
		yield 'Mastodon block' => ['mastodon-block', 'Block', $ms, self::ALICE, '', ''];
		yield 'Mastodon report' => ['mastodon-flag', 'Flag', 'https://mastodon.example/actor', self::ALICE, '', ''];
		yield 'Mastodon move' => ['mastodon-move', 'Move', $ms, $ms, '', ''];
		yield 'PeerTube dislike' => ['peertube-dislike', 'Dislike', 'https://peertube.example/accounts/hank', self::LOCAL_POST, '', ''];
		yield 'PeerTube view' => ['peertube-view', 'View', 'https://peertube.example/accounts/peertube', 'https://peertube.example/videos/watch/9c9de5e8-0a1b-4c2d-8e3f-123456789abc', '', ''];
		// a type with no model: the Create keeps the id and nothing else
		yield 'PeerTube CacheFile' => ['peertube-cachefile', 'Create', 'https://peertube.example/accounts/peertube', 'https://peertube.example/redundancy/videos/9c9de5e8-0a1b-4c2d-8e3f-123456789abc/720', '', ''];
		yield 'Threads Note' => ['threads-create-note', 'Create', 'https://threads.net/ap/users/17841400000000001/', 'https://threads.net/ap/users/17841400000000001/post/17900000000000001/', 'Note', ''];
		yield 'WordPress Article' => ['wordpress-create-article', 'Create', 'https://blog.example/author/jules/', 'https://blog.example/?p=123', 'Note', 'Article'];
		yield 'Ghost Article' => ['ghost-create-article', 'Create', 'https://ghost.example/.ghost/activitypub/users/index', 'https://ghost.example/.ghost/activitypub/article/0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', 'Note', 'Article'];
		yield 'Bridgy Fed Note' => ['bridgyfed-create-note', 'Create', 'https://bsky.brid.gy/ap/did:plc:3jpt2mvvsumj2r7eqk4gzzjz', 'https://bsky.brid.gy/convert/ap/at://did:plc:3jpt2mvvsumj2r7eqk4gzzjz/app.bsky.feed.post/3kxyzabc2s2c', 'Note', ''];
	}

	#[DataProvider('activities')]
	public function testEachActivityResolvesToItsModel(
		string $fixture, string $type, string $actor, string $objectId, string $objectType, string $subType,
	): void {
		$item = $this->read($fixture);

		$this->assertSame($type, $item->getType());
		$this->assertSame($actor, $item->getActorId());
		$this->assertSame($objectId, $item->getObjectId());
		$this->assertSame($objectType, $item->hasObject() ? $item->getObject()->getType() : '');
		$this->assertSame($subType, $item->hasObject() ? $item->getObject()->getSubType() : '');
	}

	/** @return iterable<string, array{string, string, bool, string, string}> */
	public static function posts(): iterable {
		yield 'Misskey' => ['misskey-create-note', 'https://misskey.example/users/9fq0a1b2c3', true, '', self::LOCAL_POST];
		yield 'GoToSocial' => ['gotosocial-create-note', 'https://gts.example/users/erin', true, 'en', ''];
		yield 'Friendica' => ['friendica-create-note', 'https://friendica.example/profile/gina', true, 'de', ''];
		yield 'Lemmy' => ['lemmy-create-page', 'https://lemmy.example/u/dana', true, '', ''];
		yield 'Mastodon quote' => ['mastodon-create-quote-note', 'https://mastodon.example/users/carol', true, 'en', self::LOCAL_POST];
		yield 'Threads' => ['threads-create-note', 'https://threads.net/ap/users/17841400000000001/', true, '', ''];
		yield 'WordPress' => ['wordpress-create-article', 'https://blog.example/author/jules/', true, 'en', ''];
		yield 'Ghost' => ['ghost-create-article', 'https://ghost.example/.ghost/activitypub/users/index', true, '', ''];
		yield 'Bridgy Fed' => ['bridgyfed-create-note', 'https://bsky.brid.gy/ap/did:plc:3jpt2mvvsumj2r7eqk4gzzjz', true, 'en', ''];
	}

	/**
	 * The post inside each Create: who wrote it, whether it is public, its
	 * language (read from `contentMap` only, so Lemmy's `language` object
	 * and Misskey's absent one give none) and what it quotes.
	 */
	#[DataProvider('posts')]
	public function testEachPostIsReadWithItsAuthorAudienceLanguageAndQuote(
		string $fixture, string $author, bool $public, string $language, string $quote,
	): void {
		$note = $this->read($fixture)->getObject();

		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame($author, $note->getAttributedTo());
		$this->assertSame($public, $note->isPublic());
		$this->assertSame($language, $note->getLanguage());
		$this->assertSame($quote, $note->getQuote());
	}

	/**
	 * Misskey's quote arrives three ways (`_misskey_quote`, `quoteUrl`,
	 * `quoteUri`) and its custom emoji as a tag; the MFM source in
	 * `_misskey_content` is not read, the rendered `content` is.
	 */
	public function testAMisskeyNoteKeepsItsQuoteAndEmojiAndShowsTheRenderedContent(): void {
		/** @var Note $note */
		$note = $this->read('misskey-create-note')->getObject();

		$this->assertSame(self::LOCAL_POST, $note->getQuote());
		$this->assertStringStartsWith('<p><span>look at this </span>', $note->getContent());
		$this->assertSame(['garden'], $note->getHashtags());
		$this->assertSame('blobcat', $note->getEmojis()[0]['shortcode'] ?? '');
	}

	/**
	 * Misskey sends its emoji as an ordinary `Like` plus `_misskey_reaction`;
	 * the parser maps it into the shared reaction model.
	 */
	public function testAMisskeyReactionKeepsItsEmoji(): void {
		$reaction = $this->read('misskey-like-reaction');

		$this->assertInstanceOf(EmojiReact::class, $reaction);
		$this->assertSame('🍅', $reaction->getContent());
	}

	/** Akkoma's custom-emoji reaction keeps the shortcode as its content. */
	public function testAnAkkomaReactionKeepsItsShortcode(): void {
		$reaction = $this->read('akkoma-emoji-react');

		$this->assertInstanceOf(EmojiReact::class, $reaction);
		$this->assertSame(':blobcat:', $reaction->getContent());
	}

	/**
	 * GoToSocial's interaction policy is read for quotes (the only clause this
	 * app acts on): only the author may quote automatically, which is
	 * "nobody else" here. `approvedBy` is not read.
	 */
	public function testAGoToSocialPolicyIsReadForQuotes(): void {
		/** @var Note $note */
		$note = $this->read('gotosocial-create-note')->getObject();

		$this->assertSame(self::LOCAL_POST, $note->getInReplyTo());
		$this->assertNotSame(Stream::QUOTE_POLICY_PUBLIC, $note->getQuotePolicy());
		$this->assertStringNotContainsString('approvedBy', (string)json_encode($note->exportAsActivityPub()));
	}

	/**
	 * A Lemmy link post keeps its title, body and `Link` attachment.
	 */
	public function testALemmyPageKeepsItsLinkAndBody(): void {
		/** @var Note $note */
		$note = $this->read('lemmy-create-page')->getObject();

		$this->assertStringStartsWith('<h2>Best tomato varieties for a cold summer?</h2><p>Looking for suggestions.</p>', $note->getContent());
		$this->assertStringContainsString('<a href="https://seeds.example/tomatoes">', $note->getContent());
	}

	/** An Article with a body keeps its title above the body. */
	public function testAnArticleWithABodyKeepsItsTitle(): void {
		/** @var Note $note */
		$note = $this->read('wordpress-create-article')->getObject();

		$this->assertSame('<h2>How I grow tomatoes on a balcony</h2><h2>Pots</h2><p>Use big ones.</p>', $note->getContent());
		$this->assertSame('https://blog.example/2026/04/05/tomatoes/', $note->getUrl());
		$this->assertCount(1, $note->getAttachments());
		$this->assertSame(['gardening'], $note->getHashtags());
	}

	public function testAnArticleTitleAlreadyAtTheTopIsNotDuplicated(): void {
		$data = self::fixture('wordpress-create-article');
		$data['object']['name'] = 'Tomatoes';
		$data['object']['content'] = '<h2>Tomatoes</h2><p>Use big ones.</p>';
		/** @var Note $note */
		$note = AP::instance()->getItemFromData($data)->getObject();

		$this->assertSame('<h2>Tomatoes</h2><p>Use big ones.</p>', $note->getContent());
	}

	public function testAMastodonPollAndItsUpdateCarryTheCounts(): void {
		/** @var Question $poll */
		$poll = $this->read('mastodon-create-question')->getObject();
		/** @var Question $updated */
		$updated = $this->read('mastodon-update-question')->getObject();

		$this->assertSame([['title' => 'beans', 'votes_count' => 1], ['title' => 'peas', 'votes_count' => 0]], $poll->getOptions());
		$this->assertSame(1, $poll->getVotersCount());
		$this->assertSame([['title' => 'beans', 'votes_count' => 3], ['title' => 'peas', 'votes_count' => 2]], $updated->getOptions());
		$this->assertSame(5, $updated->getVotersCount());
		$this->assertSame('2026-04-04T12:00:00Z', $updated->getUpdated());
	}

	/** The quoting post rides inline in Mastodon's QuoteRequest; only its id is kept. */
	public function testAMastodonQuoteRequestNamesTheQuotingPost(): void {
		$request = $this->read('mastodon-quote-request');

		$this->assertInstanceOf(\OCA\Social\Model\ActivityPub\Activity\QuoteRequest::class, $request);
		$this->assertSame('https://mastodon.example/users/carol/statuses/115000000000000001', $request->getInstrument());
	}

	public function testAMoveNamesItsTarget(): void {
		$this->assertSame('https://new.example/users/carol', $this->read('mastodon-move')->getTarget());
	}

	public function testAReportNamesTheAccountAndThePost(): void {
		$flag = $this->read('mastodon-flag');

		$this->assertInstanceOf(\OCA\Social\Model\ActivityPub\Object\Flag::class, $flag);
		$this->assertSame([self::ALICE, self::LOCAL_POST], $flag->getObjectIds());
		$this->assertSame('spam', $flag->getContent());
	}

	/** A Bridgy Fed account keeps its Bluesky handle and its at:// alias. */
	public function testABridgyFedActorKeepsItsHandleAndAliases(): void {
		/** @var Person $actor */
		$actor = $this->read('bridgyfed-actor');

		$this->assertInstanceOf(Person::class, $actor);
		$this->assertSame('kim.bsky.social', $actor->getPreferredUsername());
		$this->assertSame('https://bsky.brid.gy/ap/sharedInbox', $actor->getSharedInbox());
		$this->assertContains('at://did:plc:3jpt2mvvsumj2r7eqk4gzzjz', $actor->getAlsoKnownAs());
	}
}
