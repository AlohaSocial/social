<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Followed hashtags against the real database.
 *
 * Two things here cannot be tested anywhere else. The first is the unique
 * index on (actor, tag): `save()` makes following twice a no-op by letting the
 * constraint refuse the second row and swallowing that one reason, so a mocked
 * query builder proves nothing about it — only a real index does.
 *
 * The second is the case folding in the home-timeline join. Tags are stored on
 * a post exactly as they were written (`Note::fillHashtags()` strips the '#'
 * and nothing else) while a followed tag is stored lowercased, so the join
 * lowers the post's copy to compare them. Whether that is even necessary
 * depends on the database — MySQL's default collation compares without regard
 * to case and PostgreSQL's does not — which is precisely why it is asserted
 * here, where every supported database runs it.
 */
class FollowedTagsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/ftag';
	private const VIEWER = self::BASE . '/users/viewer';
	private const STRANGER = 'https://remote.example/ftag/users/stranger';
	private const BLUESKY_DID = 'did:plc:ftagblueskyauthor000000';

	private const TAG = 'ftagfollowed';
	private const OTHER_TAG = 'ftagsecond';
	private const UNFOLLOWED_TAG = 'ftagignored';

	/** every note id the tests may create, so cleanup also catches an aborted run */
	private const SUFFIXES = ['tagged', 'tagged-mixed-case', 'tagged-private', 'two-tags', 'untagged'];

	private FollowedTagsRequest $followedTags;
	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->followedTags = Server::get(FollowedTagsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach ([self::TAG, self::OTHER_TAG, self::UNFOLLOWED_TAG] as $tag) {
			$this->followedTags->delete(self::VIEWER, $tag);
			$this->followedTags->delete(self::STRANGER, $tag);
		}
		$this->streamRequest->deleteById(BlueskyIds::postId(self::BLUESKY_DID, '3ftagpost'), Note::TYPE);
		Server::get(ActorRelationRequest::class)->deleteRelatedId(self::VIEWER);
		foreach ([self::VIEWER, self::STRANGER, BlueskyIds::actorId(self::BLUESKY_DID)] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	private function cachedPerson(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername($username);
		$person->setAccount($username . ($local ? '@cloud.example.org' : '@remote.example'))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	/** @param string[] $hashtags */
	private function note(string $suffix, string $to, array $hashtags): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::STRANGER);
		$note->setTo($to);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setHashtags($hashtags);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/** @return string[] the ids the viewer's home timeline returns */
	private function homeTimeline(Person $viewer): array {
		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::HOME)
			->setLimit(50);

		return array_map(
			static fn ($stream): string => $stream->getId(),
			$this->streamRequest->getTimeline($options)
		);
	}

	// the table itself

	public function testFollowingTheSameTagTwiceLeavesOneRow(): void {
		$this->followedTags->save(self::VIEWER, self::TAG);
		$this->followedTags->save(self::VIEWER, self::TAG);

		$this->assertTrue($this->followedTags->isFollowing(self::VIEWER, self::TAG));
		$this->assertSame(1, $this->followedTags->countByActor(self::VIEWER));
	}

	public function testTheSameTagFollowedByTwoAccountsIsTwoRows(): void {
		$this->followedTags->save(self::VIEWER, self::TAG);
		$this->followedTags->save(self::STRANGER, self::TAG);

		$this->assertSame(1, $this->followedTags->countByActor(self::VIEWER));
		$this->assertSame(1, $this->followedTags->countByActor(self::STRANGER));
	}

	public function testUnfollowingWhatWasNeverFollowedIsNotAnError(): void {
		$this->followedTags->delete(self::VIEWER, self::TAG);

		$this->assertFalse($this->followedTags->isFollowing(self::VIEWER, self::TAG));
	}

	public function testUnfollowingRemovesOnlyThatTagOfThatAccount(): void {
		$this->followedTags->save(self::VIEWER, self::TAG);
		$this->followedTags->save(self::VIEWER, self::OTHER_TAG);
		$this->followedTags->save(self::STRANGER, self::TAG);

		$this->followedTags->delete(self::VIEWER, self::TAG);

		$this->assertFalse($this->followedTags->isFollowing(self::VIEWER, self::TAG));
		$this->assertTrue($this->followedTags->isFollowing(self::VIEWER, self::OTHER_TAG));
		$this->assertTrue($this->followedTags->isFollowing(self::STRANGER, self::TAG));
	}

	public function testAFollowedTagIsReadBackWhole(): void {
		$this->followedTags->save(self::VIEWER, self::TAG);

		$rows = $this->followedTags->getByActor(self::VIEWER, 10);

		$this->assertCount(1, $rows);
		$this->assertSame(self::TAG, $rows[0]['hashtag']);
		$this->assertGreaterThan(0, $rows[0]['id']);
		$this->assertGreaterThan(0, $rows[0]['creation'], 'the follow has no date the API can page or show');
	}

	/**
	 * The column is 127 *characters*, and `normalise()` cuts with mb_substr for
	 * that reason. A database that counted bytes would refuse this row.
	 */
	public function testALongMultiByteTagFitsTheColumn(): void {
		$tag = FollowedTagsRequest::normalise(str_repeat('ä', 200));
		$this->assertSame(127, mb_strlen($tag, 'UTF-8'));

		try {
			$this->followedTags->save(self::VIEWER, $tag);
			$this->assertTrue($this->followedTags->isFollowing(self::VIEWER, $tag));
			$this->assertSame($tag, $this->followedTags->getByActor(self::VIEWER, 10)[0]['hashtag']);
		} finally {
			$this->followedTags->delete(self::VIEWER, $tag);
		}
	}

	public function testPagingWalksBackwardsFromTheNewestFollow(): void {
		$this->followedTags->save(self::VIEWER, self::TAG);
		$this->followedTags->save(self::VIEWER, self::OTHER_TAG);
		$this->followedTags->save(self::VIEWER, self::UNFOLLOWED_TAG);

		$first = $this->followedTags->getByActor(self::VIEWER, 2);
		$this->assertCount(2, $first);
		$this->assertSame(
			[self::UNFOLLOWED_TAG, self::OTHER_TAG],
			array_column($first, 'hashtag'),
			'the newest follow is not first'
		);

		$second = $this->followedTags->getByActor(self::VIEWER, 2, $first[1]['id']);
		$this->assertSame([self::TAG], array_column($second, 'hashtag'));
		$this->assertSame([], $this->followedTags->getByActor(self::VIEWER, 2, $second[0]['id']));
	}

	// the home timeline join

	public function testAPublicPostCarryingAFollowedTagReachesTheHomeTimeline(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'ftagviewer', true);
		$this->cachedPerson(self::STRANGER, 'ftagstranger', false);
		$this->followedTags->save(self::VIEWER, self::TAG);

		$tagged = $this->note('tagged', ACore::CONTEXT_PUBLIC, [self::TAG]);
		$this->note('untagged', ACore::CONTEXT_PUBLIC, [self::UNFOLLOWED_TAG]);

		$home = $this->homeTimeline($viewer);

		$this->assertContains($tagged->getId(), $home);
		$this->assertNotContains(self::BASE . '/notes/untagged', $home);
	}

	/**
	 * A post is tagged in the case its author typed; a follow is stored
	 * lowercased. `#FtagFollowed` and `ftagfollowed` are the same tag, and the
	 * reader who followed it has to see the post.
	 */
	public function testTheTagOnAPostMatchesTheFollowWhateverItsCase(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'ftagviewer', true);
		$this->cachedPerson(self::STRANGER, 'ftagstranger', false);
		$this->followedTags->save(self::VIEWER, self::TAG);

		$mixed = $this->note('tagged-mixed-case', ACore::CONTEXT_PUBLIC, ['FtagFollowed']);

		$this->assertContains($mixed->getId(), $this->homeTimeline($viewer));
	}

	/**
	 * Following a tag is not a relationship with the author, so it may not
	 * reach past what any stranger could already read.
	 */
	public function testAFollowersOnlyPostCarryingAFollowedTagStaysOut(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'ftagviewer', true);
		$this->cachedPerson(self::STRANGER, 'ftagstranger', false);
		$this->followedTags->save(self::VIEWER, self::TAG);

		$private = $this->note('tagged-private', self::STRANGER . '/followers', [self::TAG]);

		$this->assertNotContains($private->getId(), $this->homeTimeline($viewer));
	}

	/** The join matches once per tag; the reader must still see one post. */
	public function testAPostCarryingTwoFollowedTagsAppearsOnce(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'ftagviewer', true);
		$this->cachedPerson(self::STRANGER, 'ftagstranger', false);
		$this->followedTags->save(self::VIEWER, self::TAG);
		$this->followedTags->save(self::VIEWER, self::OTHER_TAG);

		$both = $this->note('two-tags', ACore::CONTEXT_PUBLIC, [self::TAG, self::OTHER_TAG]);

		$home = $this->homeTimeline($viewer);

		$this->assertSame(
			1,
			count(array_keys($home, $both->getId(), true)),
			'a post carrying two followed tags was listed more than once'
		);
	}

	public function testUnfollowingTakesThePostBackOutOfTheHomeTimeline(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'ftagviewer', true);
		$this->cachedPerson(self::STRANGER, 'ftagstranger', false);
		$this->followedTags->save(self::VIEWER, self::TAG);
		$tagged = $this->note('tagged', ACore::CONTEXT_PUBLIC, [self::TAG]);
		$this->assertContains($tagged->getId(), $this->homeTimeline($viewer));

		$this->followedTags->delete(self::VIEWER, self::TAG);

		$this->assertNotContains($tagged->getId(), $this->homeTimeline($viewer));
	}

	/**
	 * A post read from Bluesky — by a page, or by the background read of the
	 * hashtags people here follow — is stored as `PostStore` stores one:
	 * addressed to the public collection, its hashtags as tags. The home
	 * timeline's join reads it like any other, and a muted author stays out.
	 */
	public function testAPostReadFromBlueskyWithAFollowedTagReachesHomeUnlessItsAuthorIsMuted(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'ftagviewer', true);
		$author = new Person();
		$author->setId(BlueskyIds::actorId(self::BLUESKY_DID))
			->setPreferredUsername('ftagsky');
		$author->setAccount('ftagsky.bsky.social@bsky.app')
			->setFollowers(BlueskyIds::followersId(self::BLUESKY_DID))
			->setFollowing($author->getId() . '/following')
			->setInbox($author->getId() . '/inbox')
			->setOutbox($author->getId() . '/outbox')
			->setLocal(false);
		$this->cacheActorsRequest->save($author);
		$this->followedTags->save(self::VIEWER, self::TAG);

		$note = new Note();
		$note->setId(BlueskyIds::postId(self::BLUESKY_DID, '3ftagpost'));
		$note->setAttributedTo($author->getId());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->addCc(BlueskyIds::followersId(self::BLUESKY_DID));
		$note->setContent('<p>from elsewhere</p>');
		$note->setHashtags(['FtagFollowed']);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		$this->assertContains($note->getId(), $this->homeTimeline($viewer));

		Server::get(ActorRelationRequest::class)->save(self::VIEWER, $author->getId(), ActorRelation::TYPE_MUTE);

		$this->assertNotContains($note->getId(), $this->homeTimeline($viewer));
	}

	// what the background read of followed tags picks

	public function testEachFollowedTagIsDueOnceNeverReadFirstThenReadLongestAgo(): void {
		$this->followedTags->save(self::VIEWER, self::TAG);
		$this->followedTags->save(self::STRANGER, self::TAG);
		$this->followedTags->save(self::VIEWER, self::OTHER_TAG);
		$this->followedTags->save(self::VIEWER, self::UNFOLLOWED_TAG);
		$this->followedTags->markFilled(self::TAG, 2000);
		$this->followedTags->markFilled(self::UNFOLLOWED_TAG, 1000);

		$ours = array_values(array_filter(
			$this->followedTags->dueForFill(1000),
			static fn (array $due): bool => in_array($due['hashtag'], [self::TAG, self::OTHER_TAG, self::UNFOLLOWED_TAG], true)
		));

		$this->assertSame([
			['hashtag' => self::OTHER_TAG, 'filled' => 0],
			['hashtag' => self::UNFOLLOWED_TAG, 'filled' => 1000],
			['hashtag' => self::TAG, 'filled' => 2000],
		], $ours, 'a tag two accounts follow is read once, as both rows were stamped');
	}

	public function testATagFollowedAgainAfterItWasReadIsNotReadAsNew(): void {
		$this->followedTags->save(self::VIEWER, self::TAG);
		$this->followedTags->markFilled(self::TAG, 2000);
		$this->followedTags->save(self::STRANGER, self::TAG);

		$due = array_values(array_filter(
			$this->followedTags->dueForFill(1000),
			static fn (array $due): bool => $due['hashtag'] === self::TAG
		));

		$this->assertSame([['hashtag' => self::TAG, 'filled' => 2000]], $due);
	}
}
