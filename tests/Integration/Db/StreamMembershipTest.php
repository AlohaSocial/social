<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Moderation;
use OCA\Social\Tools\Nid;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The questions the Mastodon streaming daemon asks of the real database: the
 * newest stored nid that gates its probes, and whether an edited post is on a
 * reader's home timeline or on the public timeline as that reader sees it.
 */
class StreamMembershipTest extends TestCase {
	private const BASE = 'https://cloud.example.org/strmem';
	private const VIEWER = self::BASE . '/users/viewer';
	private const AUTHOR = 'https://remote.example/strmem/users/author';
	private const BLOCKED = 'https://remote.example/strmem/users/blocked';
	private const SILENCED = 'https://remote.example/strmem/users/silenced';
	private const STRANGER = 'https://remote.example/strmem/users/stranger';
	private const ACTORS = [self::VIEWER, self::AUTHOR, self::BLOCKED, self::SILENCED, self::STRANGER];

	private const SUFFIXES = ['followed', 'own', 'stranger', 'blocked', 'silenced', 'unlisted', 'followers'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;
	private ActorRelationRequest $actorRelationRequest;
	private ModerationRequest $moderationRequest;

	/** @var array<string, string> suffix => nid */
	private array $nids = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->actorRelationRequest = Server::get(ActorRelationRequest::class);
		$this->moderationRequest = Server::get(ModerationRequest::class);
		$this->cleanup();

		foreach (self::ACTORS as $id) {
			$this->cacheActorsRequest->save($this->person($id));
		}
		foreach ([self::AUTHOR, self::BLOCKED] as $followed) {
			$follow = new Follow();
			$follow->setId(self::VIEWER . '/follows/' . basename($followed));
			$follow->setActorId(self::VIEWER);
			$follow->setObjectId($followed);
			$follow->setFollowId($followed . '/followers');
			$follow->setAccepted(true);
			$this->followsRequest->save($follow);
		}
		$this->actorRelationRequest->save(self::VIEWER, self::BLOCKED, ActorRelation::TYPE_BLOCK);
		$this->moderationRequest->save(new Moderation(self::SILENCED, Moderation::SILENCE));

		$this->post('followed', self::AUTHOR, ACore::CONTEXT_PUBLIC, [self::AUTHOR . '/followers'], 50);
		$this->post('own', self::VIEWER, ACore::CONTEXT_PUBLIC, [self::VIEWER . '/followers'], 45);
		$this->post('stranger', self::STRANGER, ACore::CONTEXT_PUBLIC, [self::STRANGER . '/followers'], 40);
		$this->post('blocked', self::BLOCKED, ACore::CONTEXT_PUBLIC, [self::BLOCKED . '/followers'], 35);
		$this->post('silenced', self::SILENCED, ACore::CONTEXT_PUBLIC, [self::SILENCED . '/followers'], 30);
		$this->post('unlisted', self::STRANGER, self::STRANGER . '/followers', [ACore::CONTEXT_PUBLIC], 25);
		$this->post('followers', self::AUTHOR, self::AUTHOR . '/followers', [], 20);
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix));
		}
		foreach (self::ACTORS as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
		$this->actorRelationRequest->deleteRelatedId(self::VIEWER);
		$this->moderationRequest->delete(self::SILENCED);
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername(basename($id));
		$person->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal(str_starts_with($id, self::BASE));

		return $person;
	}

	/** @param string[] $cc */
	private function post(string $suffix, string $author, string $to, array $cc, int $age): void {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo($author);
		$note->setTo($to);
		$note->setCcArray($cc);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time() - $age);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $age));
		$this->streamRequest->save($note);
		$this->nids[$suffix] = (string)$this->streamRequest->getStreamById($note->getId(), false, ACore::FORMAT_LOCAL)->getNid();
	}

	/** @param string[] $suffixes */
	private function nidsOf(array $suffixes): array {
		$nids = array_map(fn (string $suffix): string => $this->nids[$suffix], $suffixes);
		sort($nids);

		return $nids;
	}

	/** @param string[] $nids */
	private function sorted(array $nids): array {
		sort($nids);

		return $nids;
	}

	public function testTheNewestNidIsAtLeastTheNewestPostStored(): void {
		$newest = $this->streamRequest->newestNid();

		foreach ($this->nids as $nid) {
			$this->assertGreaterThanOrEqual(0, Nid::compare($newest, $nid));
		}
	}

	/**
	 * Followed and own posts, a followers-only one among them; not a
	 * stranger's, and not a followed account the viewer has blocked.
	 */
	public function testHomeMembershipIsTheHomeTimelineForTheseIds(): void {
		$this->streamRequest->setViewer($this->person(self::VIEWER));

		$this->assertSame(
			$this->nidsOf(['followed', 'own', 'followers']),
			$this->sorted($this->streamRequest->homeNidsAmong(array_values($this->nids)))
		);
		$this->assertSame([], $this->streamRequest->homeNidsAmong([]));
	}

	/**
	 * Addressed to the public collection, by an author the viewer has not
	 * blocked and the instance has not silenced; an unlisted or followers-only
	 * post is not on it.
	 */
	public function testPublicMembershipAppliesThePublicTimelineFilters(): void {
		$this->streamRequest->setViewer($this->person(self::VIEWER));

		$this->assertSame(
			$this->nidsOf(['followed', 'own', 'stranger']),
			$this->sorted($this->streamRequest->publicNidsAmong(array_values($this->nids)))
		);
		$this->assertSame([], $this->streamRequest->publicNidsAmong([]));
	}
}
