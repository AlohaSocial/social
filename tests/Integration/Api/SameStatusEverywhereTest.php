<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\CollectionController;
use OCA\Social\Controller\DiscoveryController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\PixelfedController;
use OCA\Social\Controller\PlaceController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Db\MediaTagsRequest;
use OCP\Server;

/**
 * A status carries the same reactions, place and tagged people wherever a
 * client is served it: a timeline, the status on its own, its thread, a list,
 * a place, a collection, the trends, the photos somebody is in, the archive
 * and the videos to continue.
 *
 * Only the timelines dressed their posts with all of them; `GET
 * /api/v1/statuses/{id}` answered `"reactions": []` for a post with reactions,
 * and the other routes left out some of the four. A client that opens a post
 * from a link or a notification then drew it differently from the timeline it
 * was opened from.
 */
class SameStatusEverywhereTest extends ApiTestCase {
	/** What bob, who reacted 🎉 along with carol, sees on alice's post. */
	private const AS_BOB = [
		['name' => '🎉', 'count' => 2, 'me' => true],
		['name' => '👍', 'count' => 1, 'me' => false],
	];

	/** @var list<int|string> posts whose media tags are removed afterwards */
	private array $tagged = [];

	protected function tearDown(): void {
		foreach ($this->tagged as $nid) {
			Server::get(MediaTagsRequest::class)->deleteByStream($nid);
		}
		parent::tearDown();
	}

	/** Names carol in the pictures of one of alice's posts. */
	private function tagCarol(\stdClass $post): void {
		$this->tagged[] = $post->id;
		Server::get(MediaTagsRequest::class)->tag(
			$post->id, md5($post->uri), $this->account('carol')->getId(), $this->account('alice')->getId()
		);
	}

	/** A collection of alice's holding the post. */
	private function collectionOf(\stdClass $post): \stdClass {
		$collection = $this->call(CollectionController::class, 'create', [], ['title' => 'best of'], 'alice', 'POST');
		$this->assertSame(200, $collection->status, json_encode($collection->body) ?: '');
		$added = $this->call(
			CollectionController::class, 'addItem', ['id' => $collection->body->id], ['status_id' => $post->id], 'alice', 'POST'
		);
		$this->assertSame(200, $added->status, json_encode($added->body) ?: '');

		return $collection->body;
	}

	/** A public post of alice's with reactions by alice, bob and carol. */
	private function reactedPost(array $params = []): \stdClass {
		$post = $this->publish('alice', array_merge(['status' => 'a post people reacted to'], $params));
		$this->react($post->id);

		return $post;
	}

	private function react(string $statusId): void {
		foreach (['alice' => '👍', 'bob' => '🎉', 'carol' => '🎉'] as $as => $emoji) {
			$response = $this->call(StatusApiController::class, 'statusReact', ['nid' => $statusId], ['emoji' => $emoji], $as, 'POST');
			$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		}
	}

	/**
	 * The reactions of one status out of a list of them, as plain arrays.
	 *
	 * @param mixed $statuses the decoded list
	 * @return list<array{name: string, count: int, me: bool}>
	 */
	private function reactionsIn(mixed $statuses, string $statusId, string $where): array {
		$this->assertIsArray($statuses, $where);
		foreach ($statuses as $status) {
			if ($status->id === $statusId) {
				return $this->reactionsOf($status);
			}
		}

		$this->fail($where . ' does not hold status ' . $statusId);
	}

	/** @return list<array{name: string, count: int, me: bool}> */
	private function reactionsOf(\stdClass $status): array {
		return json_decode(json_encode($status->reactions) ?: '[]', true);
	}

	public function testTheStatusOnItsOwnHasTheReactionsOfTheHomeTimeline(): void {
		$this->follow('bob', 'alice');
		$post = $this->reactedPost();

		$home = $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'home'], [], 'bob');
		$single = $this->call(StatusApiController::class, 'statusGet', ['nid' => $post->id], [], 'bob');
		$anonymous = $this->call(StatusApiController::class, 'statusGet', ['nid' => $post->id], [], null);

		$this->assertSame(self::AS_BOB, $this->reactionsIn($home->body, $post->id, 'home'));
		$this->assertSame(200, $single->status);
		$this->assertSame(self::AS_BOB, $this->reactionsOf($single->body));
		$this->assertSame([
			['name' => '🎉', 'count' => 2, 'me' => false],
			['name' => '👍', 'count' => 1, 'me' => false],
		], $this->reactionsOf($anonymous->body), 'nobody is anybody in a bar read anonymously');
	}

	public function testEveryPostOfAThreadHasItsReactions(): void {
		$root = $this->reactedPost();
		$post = $this->reactedPost(['in_reply_to_id' => $root->id]);
		$reply = $this->reactedPost(['in_reply_to_id' => $post->id]);

		$context = $this->call(StatusApiController::class, 'statusContext', ['nid' => $post->id], [], 'bob');

		$this->assertSame(200, $context->status);
		$this->assertSame(self::AS_BOB, $this->reactionsIn($context->body->ancestors, $root->id, 'ancestors'));
		$this->assertSame(self::AS_BOB, $this->reactionsIn($context->body->descendants, $reply->id, 'descendants'));
	}

	public function testAListTimelineHasTheReactions(): void {
		$this->follow('bob', 'alice');
		$post = $this->reactedPost();
		$list = $this->call(ListController::class, 'create', [], ['title' => 'friends'], 'bob', 'POST');
		$this->assertSame(200, $list->status, json_encode($list->body) ?: '');
		$added = $this->call(
			ListController::class, 'addAccounts', ['id' => $list->body->id],
			['account_ids' => [(string)$this->account('alice')->getNid()]], 'bob', 'POST'
		);
		$this->assertSame(200, $added->status, json_encode($added->body) ?: '');

		$timeline = $this->call(ListController::class, 'timeline', ['id' => $list->body->id], [], 'bob');

		$this->assertSame(self::AS_BOB, $this->reactionsIn($timeline->body, $post->id, 'the list'));
	}

	public function testAPlaceTimelineHasTheReactions(): void {
		$post = $this->reactedPost(['place_name' => 'Apictr Harbour ' . bin2hex(random_bytes(4))]);
		$this->assertNotNull($post->place, 'the post was published with its place');

		$timeline = $this->call(PlaceController::class, 'statuses', ['id' => $post->place->id], [], 'bob');

		$this->assertSame(self::AS_BOB, $this->reactionsIn($timeline->body, $post->id, 'the place'));
		$this->assertSame($post->place->id, $timeline->body[0]->place?->id, 'the place on the place page');
	}

	public function testACollectionHasTheReactions(): void {
		$post = $this->reactedPost();
		$collection = $this->collectionOf($post);

		$items = $this->call(CollectionController::class, 'items', ['id' => $collection->id], [], 'bob');

		$this->assertSame(self::AS_BOB, $this->reactionsIn($items->body, $post->id, 'the collection'));
	}

	public function testTheTrendingStatusesHaveTheReactions(): void {
		$post = $this->reactedPost();
		$this->act('carol', $post->id, 'favourite');

		$trends = $this->call(DiscoveryController::class, 'trendStatuses', [], ['limit' => 40], 'bob');

		$this->assertSame(self::AS_BOB, $this->reactionsIn($trends->body, $post->id, 'the trends'));
	}

	/** Favouriting answers with the status, which a client draws in place of the one it had. */
	public function testAStatusActedOnIsAnsweredWithItsReactions(): void {
		$post = $this->reactedPost();

		$this->assertSame(self::AS_BOB, $this->reactionsOf($this->act('bob', $post->id, 'favourite')));
	}

	public function testTheStatusOnItsOwnHasItsPlaceAndTaggedPeople(): void {
		$carol = $this->account('carol');
		$post = $this->publish('alice', ['status' => 'at the harbour', 'place_name' => 'Apictr Quay ' . bin2hex(random_bytes(4))]);
		$this->tagCarol($post);

		$single = $this->call(StatusApiController::class, 'statusGet', ['nid' => $post->id], [], 'bob');

		$this->assertSame(200, $single->status);
		$this->assertSame($post->place->id, $single->body->place?->id, 'the place');
		$this->assertSame(
			[(string)$carol->getNid()],
			array_map(static fn (\stdClass $person): string => $person->id, $single->body->tagged_people),
			'the people named in the picture'
		);
	}

	public function testThePhotosSomebodyIsInHaveTheReactions(): void {
		$post = $this->reactedPost();
		$this->tagCarol($post);

		$photos = $this->call(
			PixelfedController::class, 'accountTagged', ['account_id' => (string)$this->account('carol')->getNid()], [], 'bob'
		);

		$this->assertSame(200, $photos->status, json_encode($photos->body) ?: '');
		$this->assertSame(self::AS_BOB, $this->reactionsIn($photos->body, $post->id, 'the photos of carol'));
	}

	public function testTheArchiveHasTheReactions(): void {
		$post = $this->reactedPost();
		$archived = $this->call(PixelfedController::class, 'archiveAdd', ['id' => (int)$post->id], [], 'alice', 'POST');
		$this->assertSame(200, $archived->status, json_encode($archived->body) ?: '');

		$archive = $this->call(PixelfedController::class, 'archiveList', [], [], 'alice');

		$this->assertSame([
			['name' => '🎉', 'count' => 2, 'me' => false],
			['name' => '👍', 'count' => 1, 'me' => true],
		], $this->reactionsIn($archive->body, $post->id, 'the archive'));
	}

	public function testTheVideosToContinueHaveTheReactions(): void {
		$post = $this->reactedPost();
		$watched = $this->call(
			StatusApiController::class, 'statusWatched', ['nid' => $post->id], ['position' => 60, 'duration' => 600], 'bob', 'POST'
		);
		$this->assertSame(200, $watched->status, json_encode($watched->body) ?: '');

		$continue = $this->call(TimelineApiController::class, 'videosContinue', [], [], 'bob');

		$this->assertSame(self::AS_BOB, $this->reactionsIn($continue->body, $post->id, 'continue watching'));
	}
}
