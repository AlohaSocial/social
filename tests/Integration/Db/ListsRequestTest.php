<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Lists: their members, the group lists Nextcloud keeps in step with a group,
 * and the list timeline, which is the home timeline narrowed to the members.
 */
class ListsRequestTest extends TestCase {
	private const BASE = 'https://cloud.example.org/lists';
	private const OWNER = self::BASE . '/users/owner';
	private const STRANGER = self::BASE . '/users/stranger';
	private const MEMBER = 'https://remote.example/lists/users/member';
	private const OTHER = 'https://remote.example/lists/users/other';
	private const GROUP = 'itest-lists-group';

	private ListsRequest $lists;
	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->lists = Server::get(ListsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->lists->deleteByGroup(self::GROUP);
		foreach (['member', 'other'] as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach ([self::OWNER, self::STRANGER, self::MEMBER, self::OTHER] as $id) {
			$this->lists->deleteRelatedId($id);
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function list(string $owner, string $title, string $group = ''): MastodonList {
		return $this->lists->create((new MastodonList())
			->setOwnerId($owner)
			->setTitle($title)
			->setGroupId($group));
	}

	public function testAListIsOnlyItsOwners(): void {
		$list = $this->list(self::OWNER, 'Friends');

		$this->assertGreaterThan(0, $list->getId());
		$this->assertSame('Friends', $this->lists->getOwnedById(self::OWNER, $list->getId())->getTitle());
		$this->assertFalse($this->lists->getOwnedById(self::OWNER, $list->getId())->isPublic(), 'private unless made public');
		$this->assertCount(1, $this->lists->getByActor(self::OWNER));
		$this->assertSame([], $this->lists->getByActor(self::STRANGER));

		$this->expectException(ItemNotFoundException::class);
		$this->lists->getOwnedById(self::STRANGER, $list->getId());
	}

	public function testOnlyTheOwnerCanRenameOrDeleteIt(): void {
		$list = $this->list(self::OWNER, 'Friends');

		$this->lists->update((clone $list)->setOwnerId(self::STRANGER)->setTitle('Taken'));
		$this->lists->delete((clone $list)->setOwnerId(self::STRANGER));
		$this->assertSame('Friends', $this->lists->getOwnedById(self::OWNER, $list->getId())->getTitle());

		$this->lists->update($list->setTitle('Close friends')->setExclusive(true)->setPublic(true));
		$renamed = $this->lists->getOwnedById(self::OWNER, $list->getId());
		$this->assertSame('Close friends', $renamed->getTitle());
		$this->assertTrue($renamed->isExclusive());
		$this->assertTrue($renamed->isPublic());

		$this->lists->delete($list);
		$this->assertSame([], $this->lists->getByActor(self::OWNER));
	}

	public function testMembersAreAddedOnceAndPagedNewestFirst(): void {
		$list = $this->list(self::OWNER, 'Friends');
		$this->lists->addMember($list, self::MEMBER);
		$this->lists->addMember($list, self::MEMBER);
		$this->lists->addMember($list, self::OTHER);

		$this->assertEqualsCanonicalizing([self::MEMBER, self::OTHER], $this->lists->getMemberIds($list));
		$this->assertTrue($this->lists->isMember($list, self::MEMBER));

		$first = $this->lists->getMembers($list, 1);
		$this->assertSame(self::OTHER, $first[0]['actorId']);
		$next = $this->lists->getMembers($list, 5, $first[0]['id']);
		$this->assertSame([self::MEMBER], array_column($next, 'actorId'));
		$this->assertSame([self::OTHER], array_column($this->lists->getMembers($list, 5, 0, $next[0]['id']), 'actorId'));

		$this->lists->removeMember($list, self::MEMBER);
		$this->assertFalse($this->lists->isMember($list, self::MEMBER));
	}

	public function testTheListsSomebodyIsInAreReadOnlyFromTheAskersOwn(): void {
		$mine = $this->list(self::OWNER, 'Friends');
		$theirs = $this->list(self::STRANGER, 'Watching');
		$this->lists->addMember($mine, self::MEMBER);
		$this->lists->addMember($theirs, self::MEMBER);

		$this->assertSame([$mine->getId()], array_map(
			fn (MastodonList $l) => $l->getId(),
			$this->lists->getByMember(self::OWNER, self::MEMBER)
		));
	}

	public function testAGoneAccountLeavesEveryList(): void {
		$list = $this->list(self::OWNER, 'Friends');
		$this->lists->addMember($list, self::MEMBER);

		$this->lists->deleteRelatedId(self::MEMBER);

		$this->assertSame([], $this->lists->getMemberIds($list));
	}

	public function testGroupListsAreFoundRenamedAndRemovedByTheirGroup(): void {
		$this->list(self::OWNER, 'Staff', self::GROUP);
		$this->list(self::STRANGER, 'Staff', self::GROUP);
		$this->list(self::OWNER, 'Friends');

		$this->assertCount(2, $this->lists->getByGroup(self::GROUP));
		$this->assertContains(self::GROUP, $this->lists->getGroupIds());
		$this->assertSame(2, $this->lists->updateTitleByGroup(self::GROUP, 'Everyone'));
		$this->assertSame(['Everyone', 'Everyone'], array_map(
			fn (MastodonList $l) => $l->getTitle(),
			$this->lists->getByGroup(self::GROUP)
		));

		$this->lists->deleteByGroup(self::GROUP);
		$this->assertSame([], $this->lists->getByGroup(self::GROUP));
		$this->assertCount(1, $this->lists->getByActor(self::OWNER));
	}

	public function testATitleIsTrimmedAndCut(): void {
		$this->assertSame('Friends', ListsRequest::normaliseTitle('  Friends  '));
		$this->assertSame(255, mb_strlen(ListsRequest::normaliseTitle(str_repeat('ä', 300))));
	}

	public function testTheTimelineShowsOnlyWhatTheMembersPosted(): void {
		$owner = $this->person(self::OWNER, 'lists-owner', true);
		$this->followsRequest->generateLoopbackAccount($owner);
		foreach ([self::MEMBER => 'lists-member', self::OTHER => 'lists-other'] as $id => $name) {
			$this->person($id, $name, false);
			$this->follow($id);
		}
		$this->note('member', self::MEMBER);
		$this->note('other', self::OTHER);

		$list = $this->list(self::OWNER, 'Friends');
		$this->lists->addMember($list, self::MEMBER);

		$this->streamRequest->setViewer($owner);
		$this->lists->setViewer($owner);
		$options = (new ProbeOptions())->setProbe(ProbeOptions::HOME)->setLimit(40);

		$home = array_map(fn ($s) => $s->getId(), $this->streamRequest->getTimeline($options));
		$this->assertContains(self::BASE . '/notes/other', $home, 'the other author is on home');

		$this->assertSame(
			[self::BASE . '/notes/member'],
			array_map(fn ($s) => $s->getId(), $this->lists->getTimeline($list, $options))
		);
	}

	private function person(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function follow(string $objectId): void {
		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/' . md5($objectId));
		$follow->setActorId(self::OWNER);
		$follow->setObjectId($objectId);
		$follow->setFollowId($objectId . '/followers');
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);
	}

	private function note(string $suffix, string $author): void {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo($author);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$author . '/followers']);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);
	}
}
