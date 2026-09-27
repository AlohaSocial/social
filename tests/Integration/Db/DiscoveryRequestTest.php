<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\ConfigService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The directory and the follow suggestions: who opted in, who posted last,
 * and who the people you follow follow.
 */
class DiscoveryRequestTest extends TestCase {
	private const BASE = 'https://cloud.example.org/discovery/users/';
	private const LOCALS = ['disc-quiet' => true, 'disc-active' => true, 'disc-hidden' => false];
	private const VIEWER = self::BASE . 'viewer';
	private const FRIEND = self::BASE . 'friend';
	private const OTHER_FRIEND = self::BASE . 'other-friend';
	private const POPULAR = 'https://remote.example/discovery/popular';
	private const NICHE = 'https://remote.example/discovery/niche';
	private const ASKED = 'https://remote.example/discovery/asked';

	private DiscoveryRequest $discovery;
	private ActorsRequest $actorsRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;
	private StreamRequest $streamRequest;
	private ActorRelationRequest $relations;

	protected function setUp(): void {
		parent::setUp();
		$this->discovery = Server::get(DiscoveryRequest::class);
		$this->actorsRequest = Server::get(ActorsRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->relations = Server::get(ActorRelationRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->streamRequest->deleteById(self::BASE . 'disc-active-note', Note::TYPE);
		foreach (array_keys(self::LOCALS) as $username) {
			$this->actorsRequest->delete($username);
			$this->cacheActorsRequest->deleteCacheById($this->localId($username));
		}
		foreach ([self::VIEWER, self::FRIEND, self::OTHER_FRIEND, self::POPULAR, self::NICHE, self::ASKED] as $id) {
			$this->followsRequest->deleteRelatedId($id);
			$this->relations->deleteRelatedId($id);
		}
	}

	/** What `ActorsRequest::create()` names a local account. */
	private function localId(string $username): string {
		return Server::get(ConfigService::class)->getSocialUrl() . '@' . $username;
	}

	private function local(string $username, bool $discoverable): Person {
		$actor = new Person();
		$actor->setPreferredUsername($username);
		$actor->setUserId($username);
		$actor->setDiscoverable($discoverable);
		$this->actorsRequest->create($actor);

		$actor->setAccount($username . '@cloud.example.org')
			->setInbox($actor->getId() . '/inbox')
			->setOutbox($actor->getId() . '/outbox')
			->setFollowers($actor->getId() . '/followers')
			->setFollowing($actor->getId() . '/following')
			->setLocal(true);
		$this->cacheActorsRequest->save($actor);

		return $actor;
	}

	private function follow(string $actor, string $object, bool $accepted = true): void {
		$follow = new Follow();
		$follow->setId($actor . '#follow/' . md5($object));
		$follow->setActorId($actor);
		$follow->setObjectId($object);
		$follow->setFollowId($object . '/followers');
		$follow->setAccepted($accepted);
		$this->followsRequest->save($follow);
	}

	/** @return string[] the usernames of ours among the prims, in order */
	private function ours(array $prims): array {
		$byPrim = [];
		foreach (array_keys(self::LOCALS) as $username) {
			$byPrim[md5($this->localId($username))] = $username;
		}

		return array_values(array_map(
			fn (string $prim) => $byPrim[$prim],
			array_filter($prims, fn (string $prim) => isset($byPrim[$prim]))
		));
	}

	public function testTheDirectoryListsWhoOptedInLastPosterFirst(): void {
		foreach (self::LOCALS as $username => $discoverable) {
			$this->local($username, $discoverable);
		}
		$note = new Note();
		$note->setId(self::BASE . 'disc-active-note');
		$note->setAttributedTo($this->localId('disc-active'));
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setVisibility('public');
		$note->setContent('<p>hello</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		$this->assertSame(['disc-active', 'disc-quiet'], $this->ours($this->discovery->directoryPrims(DiscoveryRequest::ORDER_ACTIVE, 500, 0)));
		$this->assertSame(['disc-active', 'disc-quiet'], $this->ours($this->discovery->activeLocalPrims(500)));
		$this->assertEqualsCanonicalizing(['disc-active', 'disc-quiet'], $this->ours($this->discovery->directoryPrims(DiscoveryRequest::ORDER_NEW, 500, 0)));
	}

	public function testProfilesComeBackInTheOrderAskedAndMissingOnesDropOut(): void {
		$this->local('disc-quiet', true);
		$this->local('disc-active', true);
		$prims = [md5($this->localId('disc-active')), md5('nobody'), md5($this->localId('disc-quiet'))];

		$actors = $this->discovery->actorsByPrims($prims);

		$this->assertSame(
			[$this->localId('disc-active'), $this->localId('disc-quiet')],
			array_map(fn (Person $a) => $a->getId(), $actors)
		);
		$this->assertSame([], $this->discovery->actorsByPrims([]));
	}

	public function testFriendsOfFriendsAreRankedByHowManyOfYourFollowsFollowThem(): void {
		$this->follow(self::VIEWER, self::FRIEND);
		$this->follow(self::VIEWER, self::OTHER_FRIEND);
		$this->follow(self::VIEWER, self::ASKED, false);
		$this->follow(self::FRIEND, self::POPULAR);
		$this->follow(self::OTHER_FRIEND, self::POPULAR);
		$this->follow(self::FRIEND, self::NICHE);
		$this->follow(self::FRIEND, self::ASKED);
		$this->follow(self::FRIEND, self::VIEWER);

		$this->assertEqualsCanonicalizing(
			[md5(self::FRIEND), md5(self::OTHER_FRIEND), md5(self::ASKED)],
			$this->discovery->followedPrims(self::VIEWER),
			'a pending request is a follow for the purpose of excluding it'
		);
		$this->assertSame(
			[md5(self::POPULAR), md5(self::NICHE)],
			$this->discovery->friendsOfFriendsPrims(self::VIEWER, 10)
		);
	}

	public function testRelationsAreReadByType(): void {
		$this->relations->save(self::VIEWER, self::POPULAR, ActorRelation::TYPE_BLOCK);
		$this->relations->save(self::VIEWER, self::NICHE, ActorRelation::TYPE_MUTE);

		$this->assertSame([md5(self::POPULAR)], $this->discovery->relatedPrims(self::VIEWER, [ActorRelation::TYPE_BLOCK]));
		$this->assertEqualsCanonicalizing(
			[md5(self::POPULAR), md5(self::NICHE)],
			$this->discovery->relatedPrims(self::VIEWER, [ActorRelation::TYPE_BLOCK, ActorRelation::TYPE_MUTE])
		);
		$this->assertSame([], $this->discovery->relatedPrims(self::VIEWER, []));
	}
}
