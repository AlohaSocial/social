<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../TActivityPubMocks.php';

/**
 * Quote posts (FEP-044f, Mastodon 4.5) as the Stream model carries them:
 * parsed off the wire, re-read from the stored wire object, and exported both
 * ways.
 */
class StreamQuoteTest extends TestCase {
	use TActivityPubMocks;

	private const QUOTED = 'https://mastodon.social/users/bob/statuses/111';
	private const QUOTING = 'https://mastodon.social/users/alice/statuses/222';

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
	}

	protected function tearDown(): void {
		Stream::resetQuoteCache();
		AP::set(null);
		\OC::$server->reset();
	}

	/** The quoting note as Mastodon 4.5 sends it, with the key under test. */
	private function incoming(array $extra): Stream {
		$stream = new Stream();
		$stream->import(array_merge([
			'id' => self::QUOTING,
			'type' => 'Note',
			'attributedTo' => 'https://mastodon.social/users/alice',
			'content' => '<p>look at this</p>',
			'to' => [ACore::CONTEXT_PUBLIC],
		], $extra));

		return $stream;
	}

	/** The quoted post as it comes back from storage in client format. */
	private function storedQuoted(): Note {
		$author = new Person();
		$author->setId('https://mastodon.social/users/bob');
		$author->setNid(3);
		$author->setAccount('bob@mastodon.social');

		$quoted = new Note();
		$quoted->setId(self::QUOTED);
		$quoted->setNid(11);
		$quoted->setActor($author);
		$quoted->setContent('<p>the original</p>');
		$quoted->setExportFormat(ACore::FORMAT_LOCAL);

		return $quoted;
	}

	/** A StreamRequest that hands back the quoted post, and nothing else. */
	private function holdingTheQuoted(): void {
		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id, bool $asViewer = false, int $format = ACore::FORMAT_ACTIVITYPUB): Stream {
				if ($id === self::QUOTED && $asViewer && $format === ACore::FORMAT_LOCAL) {
					return $this->storedQuoted();
				}

				throw new StreamNotFoundException();
			});
		\OC::$server->register(StreamRequest::class, $streamRequest);
	}

	private function holdingNothing(): void {
		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		\OC::$server->register(StreamRequest::class, $streamRequest);
	}

	// --- parsing the wire object

	public function testTheFep044fQuoteIsReadOffAnIncomingNote(): void {
		$this->assertSame(self::QUOTED, $this->incoming(['quote' => self::QUOTED])->getQuote());
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function quoteAliasProvider(): array {
		return [
			// what Mastodon 4.5 emits alongside `quote`, for older readers
			'quoteUrl' => [['quoteUrl' => self::QUOTED]],
			'_misskey_quote' => [['_misskey_quote' => self::QUOTED]],
			'quoteUri' => [['quoteUri' => self::QUOTED]],
			'an embedded object' => [['quote' => ['id' => self::QUOTED, 'type' => 'Note']]],
			'a link' => [['quote' => ['href' => self::QUOTED, 'type' => 'Link']]],
		];
	}

	#[DataProvider('quoteAliasProvider')]
	public function testTheLegacyQuoteAliasesAreReadToo(array $wire): void {
		$this->assertSame(self::QUOTED, $this->incoming($wire)->getQuote());
	}

	// --- what the author said about being quoted

	/**
	 * Loops publishes this on every video, GoToSocial defined it and Mastodon
	 * 4.5 reads it. It used to be ignored here, so a post whose author said
	 * "nobody" was quoted anyway — and their server then refused the request,
	 * after this instance had already shown the quote to the person who wrote
	 * it.
	 */
	public function testAPostOpenToTheWorldIsQuotable(): void {
		$stream = $this->incoming([
			'interactionPolicy' => ['canQuote' => ['automaticApproval' => [ACore::CONTEXT_PUBLIC]]],
		]);

		$this->assertSame(Stream::QUOTE_POLICY_PUBLIC, $stream->getQuotePolicy());
		$this->assertTrue($stream->isQuotable());
	}

	public function testAPostWhoseAuthorAllowsNobodyIsNotQuotable(): void {
		$stream = $this->incoming([
			'interactionPolicy' => ['canQuote' => ['automaticApproval' => []]],
		]);

		$this->assertSame(Stream::QUOTE_POLICY_NOBODY, $stream->getQuotePolicy());
		$this->assertFalse($stream->isQuotable());
	}

	/**
	 * Narrower than public — the author's followers, say — is not something
	 * this app can check without pretending to know a remote server's follower
	 * list, so it is treated as "ask the author", which means not here.
	 */
	public function testAPolicyNarrowerThanPublicIsNotTakenAsPermission(): void {
		$stream = $this->incoming([
			'interactionPolicy' => ['canQuote' => [
				'automaticApproval' => ['https://mastodon.social/users/alice/followers'],
			]],
		]);

		$this->assertFalse($stream->isQuotable());
	}

	/**
	 * `manualApproval` means their server decides case by case, and this app
	 * cannot wait for that answer before showing somebody the quote they just
	 * wrote.
	 */
	public function testManualApprovalIsNotAutomaticPermission(): void {
		$stream = $this->incoming([
			'interactionPolicy' => ['canQuote' => ['manualApproval' => [ACore::CONTEXT_PUBLIC]]],
		]);

		$this->assertFalse($stream->isQuotable());
	}

	public function testAPostThatSaysNothingIsDecidedByItsVisibilityAsBefore(): void {
		$stream = $this->incoming([]);

		$this->assertSame('', $stream->getQuotePolicy());
		$this->assertTrue($stream->isQuotable(), 'a public post with no policy stays quotable');
	}

	public function testANoteThatQuotesNothingHasNoQuote(): void {
		$this->assertSame('', $this->incoming([])->getQuote());
	}

	public function testTheQuoteAuthorizationIsReadOffAnIncomingNote(): void {
		$stream = $this->incoming([
			'quote' => self::QUOTED,
			'quoteAuthorization' => 'https://mastodon.social/users/bob/approvals/1',
		]);

		$this->assertSame('https://mastodon.social/users/bob/approvals/1', $stream->getQuoteAuthorization());
	}

	/**
	 * The quote also rides in the stored wire object, and is read back out of it
	 * for a row written before `Version1000Date20260912000007` gave it a column
	 * — the fallback `Stream::importFromDatabase()` keeps for exactly that, and
	 * the only path a row the backfill has not reached is read through.
	 */
	public function testTheQuoteIsReadBackOutOfTheStoredWireObject(): void {
		$stream = new Stream();
		$stream->importFromDatabase([
			'id' => self::QUOTING,
			'nid' => 22,
			'source' => json_encode([
				'id' => self::QUOTING,
				'type' => 'Note',
				'quote' => self::QUOTED,
				'quoteAuthorization' => 'https://mastodon.social/users/bob/approvals/1',
			]),
		]);

		$this->assertSame(self::QUOTED, $stream->getQuote());
		$this->assertSame('https://mastodon.social/users/bob/approvals/1', $stream->getQuoteAuthorization());
	}

	/** A quoting post whose quote the quoted author has approved. */
	private function approved(): Stream {
		$stream = $this->incoming([
			'quote' => self::QUOTED,
			'quoteAuthorization' => 'https://mastodon.social/users/bob/approvals/1',
		]);
		$stream->setNid(22);

		return $stream;
	}

	// --- the client entity

	public function testAnApprovedQuoteOfAPostWeHoldIsAcceptedAndCarriesIt(): void {
		$this->holdingTheQuoted();
		$stream = $this->approved();

		$quote = $stream->exportAsLocal()['quote'];

		$this->assertSame(Stream::QUOTE_ACCEPTED, $quote['state']);
		$this->assertSame('11', $quote['quoted_status']['id']);
		$this->assertSame('<p>the original</p>', $quote['quoted_status']['content']);
	}

	/**
	 * Holding the quoted post says we *could* show it, which is not the same
	 * question as whether its author said we may. Reading the state off the
	 * lookup reported every quote accepted the moment it was written — before
	 * the author had answered, and whatever they answered.
	 */
	public function testAQuoteNobodyHasApprovedIsPendingEvenWhenWeHoldThePost(): void {
		$this->holdingTheQuoted();
		$stream = $this->incoming(['quote' => self::QUOTED]);
		$stream->setNid(22);

		$this->assertSame(
			['state' => Stream::QUOTE_PENDING, 'quoted_status' => null],
			$stream->exportAsLocal()['quote']
		);
	}

	/** Approved and queued for fetching: not here yet, so nothing to carry. */
	public function testAnApprovedQuoteOfAPostWeHaveNotFetchedYetCarriesNothing(): void {
		$this->holdingNothing();
		$stream = $this->approved();

		$this->assertSame(
			['state' => Stream::QUOTE_ACCEPTED, 'quoted_status' => null],
			$stream->exportAsLocal()['quote']
		);
	}

	public function testAStatusThatQuotesNothingHasANullQuote(): void {
		$stream = $this->incoming([]);
		$stream->setNid(22);

		$this->assertNull($stream->exportAsLocal()['quote']);
	}

	/**
	 * A quote of a post the reader may not see must not hand it over: the
	 * lookup is made as the viewer, which is the rule the boosted-object join
	 * applies to an Announce of a followers-only post.
	 */
	public function testAQuotedPostTheViewerMayNotSeeIsNotLeaked(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->expects($this->once())
			->method('getStreamById')
			->with(self::QUOTED, true, ACore::FORMAT_LOCAL)
			->willThrowException(new StreamNotFoundException());
		\OC::$server->register(StreamRequest::class, $streamRequest);

		$quote = $this->approved()->exportAsLocal()['quote'];

		// accepted, because the author did approve it; the post is simply not
		// this reader's to see. Saying `pending` here would report the author
		// as not having answered when they have
		$this->assertSame(Stream::QUOTE_ACCEPTED, $quote['state']);
		$this->assertNull($quote['quoted_status']);
	}

	/** A refusal that was recorded is what the client is told, post or no post. */
	public function testARejectedQuoteIsExportedAsRejectedWithoutThePost(): void {
		$this->holdingTheQuoted();
		$stream = $this->incoming(['quote' => self::QUOTED]);
		$stream->setNid(22);
		$stream->setQuoteState(Stream::QUOTE_REJECTED);

		$this->assertSame(
			['state' => Stream::QUOTE_REJECTED, 'quoted_status' => null],
			$stream->exportAsLocal()['quote']
		);
	}

	/**
	 * A quote of a quote of a quote is a chain of database lookups and of
	 * nested entities; Mastodon stops at the first level and so does this.
	 */
	public function testANestedQuoteIsNotResolvedAgain(): void {
		$quoted = $this->storedQuoted();
		$quoted->setQuote('https://mastodon.social/users/carol/statuses/1');

		$quoted->setQuoteAuthorization('https://mastodon.social/users/carol/approvals/9');

		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->expects($this->once())->method('getStreamById')->willReturn($quoted);
		\OC::$server->register(StreamRequest::class, $streamRequest);

		$quote = $this->approved()->exportAsLocal()['quote'];

		$this->assertSame(Stream::QUOTE_ACCEPTED, $quote['state']);
		// approved at the next level down too, and still not resolved: the
		// chain stops here whatever the answer was
		$this->assertSame(
			['state' => Stream::QUOTE_ACCEPTED, 'quoted_status' => null],
			$quote['quoted_status']['quote']
		);
	}

	// --- the wire object we emit

	public function testAQuoteFederatesWithTheLegacyAliasesBeside(): void {
		$stream = new Note();
		$stream->setId(self::QUOTING);
		$stream->setQuote(self::QUOTED);
		$stream->setQuoteAuthorization('https://mastodon.social/users/bob/approvals/1');

		$wire = $stream->exportAsActivityPub();

		// Mastodon reads `quote`; anything older reads one of the two aliases
		$this->assertSame(self::QUOTED, $wire['quote']);
		$this->assertSame(self::QUOTED, $wire['quoteUrl']);
		$this->assertSame(self::QUOTED, $wire['_misskey_quote']);
		$this->assertSame('https://mastodon.social/users/bob/approvals/1', $wire['quoteAuthorization']);
	}

	public function testAPostThatQuotesNothingFederatesNoQuoteKeys(): void {
		$wire = (new Note())->setId(self::QUOTING)->exportAsActivityPub();

		$this->assertArrayNotHasKey('quote', $wire);
		$this->assertArrayNotHasKey('quoteUrl', $wire);
		$this->assertArrayNotHasKey('_misskey_quote', $wire);
		$this->assertArrayNotHasKey('quoteAuthorization', $wire);
	}

	/**
	 * Without an `interactionPolicy.canQuote`, Mastodon 4.5 treats a post as
	 * unquotable and offers no quote button at all.
	 */
	public function testALocalPublicPostSaysAnybodyMayQuoteIt(): void {
		$note = new Note();
		$note->setId('https://cloud.example.org/apps/social/@alice/1');
		$note->setLocal(true);
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setAttributedTo('https://cloud.example.org/apps/social/@alice');

		$this->assertSame(
			// the author beside the collection: they are always allowed, and
			// naming them is what lets a peer see that without special-casing
			['canQuote' => ['automaticApproval' => [
				ACore::CONTEXT_PUBLIC, 'https://cloud.example.org/apps/social/@alice',
			]]],
			$note->exportAsActivityPub()['interactionPolicy']
		);
	}

	public function testALocalFollowersOnlyPostSaysNobodyButTheAuthorMayQuoteIt(): void {
		$note = new Note();
		$note->setId('https://cloud.example.org/apps/social/@alice/1');
		$note->setLocal(true);
		$note->setVisibility(Stream::TYPE_FOLLOWERS);
		$note->setAttributedTo('https://cloud.example.org/apps/social/@alice');

		$this->assertSame(
			['canQuote' => ['automaticApproval' => ['https://cloud.example.org/apps/social/@alice']]],
			$note->exportAsActivityPub()['interactionPolicy']
		);
	}

	/**
	 * The whole inbound path over a document shaped the way Mastodon 4.5 sends
	 * one: through the registry, into a Note, with the quote on it.
	 */
	public function testAMastodonQuotePostArrivesAsAQuote(): void {
		$create = AP::instance()->getItemFromData([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => self::QUOTING . '/activity',
			'type' => 'Create',
			'actor' => 'https://mastodon.social/users/alice',
			'to' => [ACore::CONTEXT_PUBLIC],
			'object' => [
				'id' => self::QUOTING,
				'type' => 'Note',
				'attributedTo' => 'https://mastodon.social/users/alice',
				'content' => '<p>this is worth reading</p>',
				'to' => [ACore::CONTEXT_PUBLIC],
				'quote' => self::QUOTED,
				'quoteUrl' => self::QUOTED,
				'_misskey_quote' => self::QUOTED,
				'quoteAuthorization' => 'https://mastodon.social/users/bob/approvals/1',
				'tag' => [],
			],
		]);

		$note = $create->getObject();

		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame(self::QUOTED, $note->getQuote());
		$this->assertSame('https://mastodon.social/users/bob/approvals/1', $note->getQuoteAuthorization());
		// and the document it was built from is what a reload re-reads it from
		$this->assertSame(self::QUOTED, json_decode($note->getSource(), true)['quote']);
	}

	/** Somebody else's post: their server says who may quote it, not ours. */
	public function testARemotePostCarriesNoPolicyOfOurs(): void {
		$note = new Note();
		$note->setId(self::QUOTED);
		$note->setVisibility(Stream::TYPE_PUBLIC);

		$this->assertArrayNotHasKey('interactionPolicy', $note->exportAsActivityPub());
	}

	// --- who may quote it -------------------------------------------------

	private function localPost(string $policy = '', string $visibility = Stream::TYPE_PUBLIC): Note {
		$note = new Note();
		$note->setId('https://cloud.example.org/apps/social/@alice/1');
		$note->setLocal(true);
		$note->setVisibility($visibility);
		$note->setAttributedTo('https://cloud.example.org/apps/social/@alice');
		$note->setQuotePolicy($policy);

		return $note;
	}

	/**
	 * Almost every stored post is in this state: nobody was ever asked, so the
	 * answer is the one this app gave before the question existed.
	 */
	public function testAPostNobodyWasAskedAboutKeepsTheVisibilityRule(): void {
		$this->assertSame(
			Stream::QUOTE_POLICY_PUBLIC,
			$this->localPost()->effectiveQuotePolicy()
		);
		$this->assertSame(
			Stream::QUOTE_POLICY_NOBODY,
			$this->localPost('', Stream::TYPE_FOLLOWERS)->effectiveQuotePolicy()
		);
	}

	public function testAPolicyThatIsNotOneOfTheThreeIsNoPolicyAtAll(): void {
		$this->assertSame('', $this->localPost()->setQuotePolicy('whenever')->getQuotePolicy());
	}

	public function testTheFollowersPolicyAdvertisesTheFollowersCollection(): void {
		$policy = $this->localPost(Stream::QUOTE_POLICY_FOLLOWERS)
			->exportAsActivityPub()['interactionPolicy'];

		$this->assertSame(
			['canQuote' => ['automaticApproval' => [
				'https://cloud.example.org/apps/social/@alice/followers',
				'https://cloud.example.org/apps/social/@alice',
			]]],
			$policy
		);
	}

	public function testNobodyMeansTheAuthorAlone(): void {
		$this->assertSame(
			['canQuote' => ['automaticApproval' => ['https://cloud.example.org/apps/social/@alice']]],
			$this->localPost(Stream::QUOTE_POLICY_NOBODY)->exportAsActivityPub()['interactionPolicy']
		);
	}

	/**
	 * Quoting your own post is how a thread is picked up later, and it is the
	 * one case no policy refuses.
	 */
	public function testTheAuthorMayAlwaysQuoteTheirOwnPost(): void {
		$post = $this->localPost(Stream::QUOTE_POLICY_NOBODY);

		$this->assertTrue($post->mayBeQuotedBy('https://cloud.example.org/apps/social/@alice'));
		$this->assertFalse($post->mayBeQuotedBy('https://remote.example/users/carol'));
	}

	public function testTheFollowersPolicyTurnsOnWhetherTheAskerFollows(): void {
		$post = $this->localPost(Stream::QUOTE_POLICY_FOLLOWERS);

		$this->assertTrue($post->mayBeQuotedBy('https://remote.example/users/carol', true));
		$this->assertFalse($post->mayBeQuotedBy('https://remote.example/users/carol', false));
	}

	/**
	 * `manual` is empty and honestly so: this app answers a QuoteRequest the
	 * moment it arrives and has no queue for an author to work through, so a
	 * "requested" state would be one nothing here would ever resolve.
	 */
	public function testQuoteApprovalSaysWhatIsAutomaticAndNothingIsManual(): void {
		$approval = $this->localPost(Stream::QUOTE_POLICY_PUBLIC)->exportQuoteApproval('', false);

		$this->assertSame(['public'], $approval['automatic']);
		$this->assertSame([], $approval['manual']);
		$this->assertSame('automatic', $approval['current_user']);
	}

	public function testQuoteApprovalSaysDeniedWhereNobodyMayQuote(): void {
		$approval = $this->localPost(Stream::QUOTE_POLICY_NOBODY)
			->exportQuoteApproval('https://remote.example/users/carol', false);

		$this->assertSame([], $approval['automatic']);
		$this->assertSame('denied', $approval['current_user']);
	}

	/**
	 * GoToSocial defined `interactionPolicy`, Mastodon 4.5 reads it and Loops
	 * publishes it. This app read only the quote clause, so the other three
	 * were offered to every reader here and refused by the author's server
	 * afterwards — the reader was told their reply went out, and it did.
	 */
	public function testWhatTheAuthorAllowsIsReadForEveryInteractionNotJustQuoting(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://gts.example/@bob/1',
			'interactionPolicy' => [
				'canReply' => ['automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public']],
				'canAnnounce' => ['automaticApproval' => ['https://gts.example/@bob/followers']],
				'canLike' => ['automaticApproval' => []],
			],
		]);

		$this->assertSame('public', $stream->getInteractionPolicy(Stream::INTERACTION_REPLY));
		$this->assertSame('nobody', $stream->getInteractionPolicy(Stream::INTERACTION_BOOST));
		$this->assertSame('nobody', $stream->getInteractionPolicy(Stream::INTERACTION_LIKE));
	}

	/**
	 * Most servers publish no policy at all, and a post with none is a post
	 * anybody may answer: an absent clause must not read as "nobody".
	 */
	public function testAPostWhoseServerSaysNothingAllowsEverything(): void {
		$stream = new Stream();
		$stream->import(['id' => 'https://remote.example/@bob/1']);

		$this->assertSame('', $stream->getInteractionPolicy(Stream::INTERACTION_REPLY));
		$this->assertTrue($stream->allowsInteraction(Stream::INTERACTION_REPLY));
		$this->assertTrue($stream->allowsInteraction(Stream::INTERACTION_BOOST));
		$this->assertTrue($stream->allowsInteraction(Stream::INTERACTION_LIKE));
	}

	public function testAnInteractionTheAuthorRefusedIsNotAllowed(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://gts.example/@bob/1',
			'interactionPolicy' => ['canLike' => ['automaticApproval' => []]],
		]);

		$this->assertFalse($stream->allowsInteraction(Stream::INTERACTION_LIKE));
		$this->assertTrue($stream->allowsInteraction(Stream::INTERACTION_BOOST));
	}

	/**
	 * A post of ours is this instance's to decide about when the interaction
	 * arrives, not something to refuse in advance.
	 */
	public function testALocalPostIsNeverRefusedInAdvance(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://cloud.example/@alice/1',
			'interactionPolicy' => ['canLike' => ['automaticApproval' => []]],
		]);
		$stream->setLocal(true);

		$this->assertTrue($stream->allowsInteraction(Stream::INTERACTION_LIKE));
	}

	/** A row larger for no reason on every post from every ordinary server. */
	public function testNoPolicyBlobIsStoredForAPostThatCarriesNone(): void {
		$stream = new Stream();
		$stream->import(['id' => 'https://remote.example/@bob/1']);

		$this->assertArrayNotHasKey(Details::POLICIES, $stream->getDetailsAll());
	}

	/** A client can only leave a button out if it is told to. */
	public function testTheClientIsToldWhatTheAuthorAllows(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://gts.example/@bob/1',
			'interactionPolicy' => [
				'canReply' => ['automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public']],
				'canLike' => ['automaticApproval' => []],
			],
		]);
		$stream->setExportFormat(Stream::FORMAT_LOCAL);

		$exported = $stream->jsonSerialize()['interaction_policy'];

		$this->assertSame(['reply' => true, 'like' => false], $exported);
	}

	public function testAPostWithNoPolicyTellsTheClientNothingRatherThanTrue(): void {
		$stream = new Stream();
		$stream->import(['id' => 'https://remote.example/@bob/1']);
		$stream->setExportFormat(Stream::FORMAT_LOCAL);

		$this->assertNull($stream->jsonSerialize()['interaction_policy']);
	}
}
