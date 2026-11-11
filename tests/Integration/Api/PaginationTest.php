<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\ConversationController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Tools\Nid;

/**
 * Mastodon's paging, the same questions asked of every paged list, against
 * the real database with more rows than one page holds.
 *
 * What a client relies on (docs.joinmastodon.org/api/guidelines/#pagination):
 *
 * - a page is newest first, and `max_id` is exclusive: the page strictly
 *   below the cursor;
 * - `since_id` answers the *newest* page above the cursor, which may leave a
 *   gap between it and the cursor — it is how a client asks "what is new";
 * - `min_id` answers the page *adjacent* to the cursor — the oldest rows
 *   above it, still handed back newest first — which is how a client fills
 *   a gap, and what the `prev` link pages with;
 * - `Link` carries `next` (max_id of the oldest row on the page, only while a
 *   page is full) and `prev` (min_id of the newest), with every other
 *   parameter of the request kept.
 *
 * A list where `since_id` and `min_id` agree passes every test that has fewer
 * rows than `limit`, which is why each list here is seeded with three pages
 * of rows and read `LIMIT` at a time.
 */
class PaginationTest extends ApiTestCase {
	private const LIMIT = 3;
	/** rows per list: three full pages, so every cursor has rows on both sides */
	private const ROWS = 9;

	#[\Override]
	protected function accountNames(): array {
		return ['alice', 'bob', 'carol', 'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9'];
	}

	/**
	 * A paged route, as a function of its query string.
	 *
	 * @param class-string $controller
	 * @return callable(array<string, mixed>): ApiResponse
	 */
	private function route(string $controller, string $method, array $routeParams, string $as): callable {
		return fn (array $query): ApiResponse => $this->call($controller, $method, $routeParams, $query, $as);
	}

	/** @return string[] the ids on a page, in the order the page has them */
	private function ids(ApiResponse $response): array {
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertIsArray($response->body);

		return array_map(static fn (\stdClass $item): string => (string)$item->id, $response->body);
	}

	/**
	 * Ids in the order a list holds them: descending. A status id is a
	 * snowflake whose time part has a resolution of a second, so posts
	 * written within one second are not in the order they were written.
	 *
	 * @param string[] $ids
	 * @return string[]
	 */
	private function newestFirst(array $ids): array {
		usort($ids, static fn (string $a, string $b): int => Nid::compare($b, $a));

		return $ids;
	}

	/** bob posts ROWS public statuses; their ids, newest first */
	private function bobPosts(string $extra = ''): array {
		$ids = [];
		for ($i = 1; $i <= self::ROWS; $i++) {
			$ids[] = $this->publish('bob', ['status' => 'page post ' . $i . $extra, 'visibility' => 'public'])->id;
		}

		return $this->newestFirst($ids);
	}

	/**
	 * The questions, asked of one list whose rows are `$rows`, newest first.
	 *
	 * @param callable(array<string, mixed>): ApiResponse $page
	 * @param string[] $rows every row of the list, newest first
	 * @param bool $closed whether the list holds only these rows, so that the
	 *                     end of the list can be asserted too; the public
	 *                     timeline may hold older posts of other tests
	 */
	private function assertMastodonPaging(
		callable $page, array $rows, string $list, bool $closed = true,
	): void {
		$this->assertCount(self::ROWS, $rows, $list . ': the fixture');
		$limit = ['limit' => self::LIMIT];

		// the head of the list, and its links
		$head = $page($limit);
		$this->assertSame(array_slice($rows, 0, 3), $this->ids($head), $list . ': the first page is the newest rows, newest first');
		$links = $head->links();
		$this->assertArrayHasKey('next', $links, $list . ': a full page links the next one');
		$this->assertArrayHasKey('prev', $links, $list . ': a page with rows links the previous one');
		$this->assertSame($rows[2], $links['next']['query']['max_id'] ?? null, $list . ': next continues below the oldest row');
		$this->assertSame($rows[0], $links['prev']['query']['min_id'] ?? null, $list . ': prev continues above the newest row');
		$this->assertSame((string)self::LIMIT, $links['next']['query']['limit'] ?? null, $list . ': the limit survives into next');
		$this->assertSame((string)self::LIMIT, $links['prev']['query']['limit'] ?? null, $list . ': the limit survives into prev');

		// following `next`, exactly as a client does
		$second = $page($links['next']['query']);
		$this->assertSame(array_slice($rows, 3, 3), $this->ids($second), $list . ': next is the page below, max_id exclusive');

		// and `prev` from there leads back to the head
		$back = $page($second->links()['prev']['query'] ?? []);
		$this->assertSame(array_slice($rows, 0, 3), $this->ids($back), $list . ': prev from the second page is the first page');

		$this->assertSame(
			array_slice($rows, 4, 3),
			$this->ids($page($limit + ['max_id' => $rows[3]])),
			$list . ': max_id is exclusive'
		);

		// six rows above the cursor and room for three: the two differ
		$this->assertSame(
			array_slice($rows, 0, 3),
			$this->ids($page($limit + ['since_id' => $rows[6]])),
			$list . ': since_id is the newest page above the cursor'
		);
		$this->assertSame(
			array_slice($rows, 3, 3),
			$this->ids($page($limit + ['min_id' => $rows[6]])),
			$list . ': min_id is the page next to the cursor, newest first'
		);

		$this->assertSame(
			array_slice($rows, 0, 2),
			$this->ids($page($limit + ['since_id' => $rows[2]])),
			$list . ': since_id answers only what is above the cursor'
		);
		$this->assertSame(
			array_slice($rows, 2, 2),
			$this->ids($page($limit + ['max_id' => $rows[1], 'since_id' => $rows[4]])),
			$list . ': max_id and since_id bound one window'
		);

		$short = $page($limit + ['min_id' => $rows[1]]);
		$this->assertSame([$rows[0]], $this->ids($short), $list . ': a min_id near the top answers what is above it');
		$this->assertArrayNotHasKey('next', $short->links(), $list . ': a short page links no next page');

		if (!$closed) {
			return;
		}

		$last = $page($limit + ['max_id' => $rows[6]]);
		$this->assertSame(array_slice($rows, 7), $this->ids($last), $list . ': the last page is what is left');
		$this->assertArrayNotHasKey('next', $last->links(), $list . ': the last page links no next page');
		$this->assertArrayHasKey('prev', $last->links());

		$beyond = $page($limit + ['max_id' => $rows[8]]);
		$this->assertSame([], $this->ids($beyond), $list . ': below the oldest row there is nothing');
		$this->assertArrayNotHasKey('Link', $beyond->headers, $list . ': an empty page links nowhere');
	}

	public function testAnAccountsStatuses(): void {
		$rows = $this->bobPosts();
		$bob = (string)$this->account('bob')->getNid();

		$this->assertMastodonPaging(
			$this->route(AccountApiController::class, 'accountStatuses', ['account' => $bob], 'carol'), $rows, 'account statuses'
		);
	}

	public function testTheHomeTimeline(): void {
		$this->follow('alice', 'bob');
		$rows = $this->bobPosts();

		$this->assertMastodonPaging(
			$this->route(TimelineApiController::class, 'timelines', ['timeline' => 'home'], 'alice'), $rows, 'home'
		);
	}

	public function testTheLocalPublicTimeline(): void {
		$rows = $this->bobPosts();

		$this->assertMastodonPaging(
			fn (array $query): ApiResponse => $this->call(
				TimelineApiController::class, 'timelines', ['timeline' => 'public'], $query + ['local' => 'true'], 'carol'
			),
			$rows, 'public', false
		);
	}

	public function testAHashtagTimeline(): void {
		$rows = $this->bobPosts(' #apictrpaging');

		$this->assertMastodonPaging(
			$this->route(TimelineApiController::class, 'tag', ['hashtag' => 'apictrpaging'], 'carol'), $rows, 'hashtag'
		);
	}

	/**
	 * Paged on the status id and ordered by it, where Mastodon orders by when
	 * the status was favourited — so here they are favourited oldest status
	 * first, the one order in which the two agree.
	 */
	/**
	 * The same questions of a list whose cursors are opaque: favourites and
	 * bookmarks are ordered by when they were marked and paged on the mark's
	 * own id, as Mastodon pages them, so a client never names a cursor itself
	 * and only ever follows the `Link` header.
	 *
	 * @param callable(array<string, mixed>): ApiResponse $page
	 * @param string[] $rows every row of the list, newest first
	 */
	private function assertLinkPaging(callable $page, array $rows, string $list): void {
		$this->assertCount(self::ROWS, $rows, $list . ': the fixture');
		$limit = ['limit' => self::LIMIT];

		$head = $page($limit);
		$this->assertSame(array_slice($rows, 0, 3), $this->ids($head), $list . ': the first page is the newest rows, newest first');
		$links = $head->links();
		$this->assertArrayHasKey('next', $links, $list . ': a full page links the next one');
		$this->assertArrayHasKey('prev', $links, $list . ': a page with rows links the previous one');
		$this->assertNotContains($links['next']['query']['max_id'] ?? '', $rows, $list . ': the cursor names the mark, not the post');
		$this->assertSame((string)self::LIMIT, $links['next']['query']['limit'] ?? null, $list . ': the limit survives into next');

		$second = $page($links['next']['query']);
		$this->assertSame(array_slice($rows, 3, 3), $this->ids($second), $list . ': next is the page below');
		$back = $page($second->links()['prev']['query'] ?? []);
		$this->assertSame(array_slice($rows, 0, 3), $this->ids($back), $list . ': prev from the second page is the first page');

		$last = $page($second->links()['next']['query'] ?? []);
		$this->assertSame(array_slice($rows, 6, 3), $this->ids($last), $list . ': the last page is what is left');
		$this->assertArrayHasKey('prev', $last->links());

		// a full last page still links a next one, which is empty and links nowhere
		$beyond = $page($last->links()['next']['query'] ?? []);
		$this->assertSame([], $this->ids($beyond), $list . ': below the oldest mark there is nothing');
		$this->assertArrayNotHasKey('Link', $beyond->headers, $list . ': an empty page links nowhere');

		// `since_id` takes the same cursor and answers the newest page above it
		$this->assertSame(
			array_slice($rows, 0, 3),
			$this->ids($page($limit + ['since_id' => $last->links()['prev']['query']['min_id'] ?? ''])),
			$list . ': since_id is the newest page above the cursor'
		);
	}

	public function testFavouritesAndBookmarks(): void {
		$rows = $this->bobPosts();
		foreach (array_reverse($rows) as $id) {
			$this->act('alice', $id, 'favourite');
			$this->act('alice', $id, 'bookmark');
			// the marks are dated to the millisecond; one each, in order
			usleep(2000);
		}

		$this->assertLinkPaging($this->route(TimelineApiController::class, 'favourites', [], 'alice'), $rows, 'favourites');
		$this->assertLinkPaging($this->route(TimelineApiController::class, 'bookmarks', [], 'alice'), $rows, 'bookmarks');
	}

	public function testNotifications(): void {
		$this->acceptEveryNotification('alice');
		for ($i = 1; $i <= self::ROWS; $i++) {
			$this->publish('bob', ['status' => '@' . self::PREFIX . 'alice number ' . $i]);
		}
		$rows = $this->ids($this->call(TimelineApiController::class, 'notifications', [], ['limit' => 40], 'alice'));

		$this->assertMastodonPaging($this->route(TimelineApiController::class, 'notifications', [], 'alice'), $rows, 'notifications');
	}

	public function testAListTimeline(): void {
		$this->follow('alice', 'bob');
		$list = $this->call(ListController::class, 'create', [], ['title' => 'paging'], 'alice', 'POST');
		$this->call(ListController::class, 'addAccounts', ['id' => (int)$list->body->id], [
			'account_ids' => [(string)$this->account('bob')->getNid()],
		], 'alice', 'POST');
		$rows = $this->bobPosts();

		$this->assertMastodonPaging(
			$this->route(ListController::class, 'timeline', ['id' => (int)$list->body->id], 'alice'), $rows, 'list'
		);
	}

	/**
	 * One conversation per message here, as each message starts a thread of
	 * its own. A page is built from a window of messages and then cut to
	 * `limit` conversations, so `min_id` has to keep the oldest of them —
	 * the ones next to the cursor — or a client filling a gap with `prev`
	 * skips the conversations between.
	 */
	public function testConversations(): void {
		$this->account('alice');
		$rows = [];
		for ($i = 1; $i <= self::ROWS; $i++) {
			$rows[] = $this->publish('bob', ['status' => '@' . self::PREFIX . 'alice a word, ' . $i, 'visibility' => 'direct'])->id;
		}

		$this->assertMastodonPaging(
			$this->route(ConversationController::class, 'index', [], 'alice'), $this->newestFirst($rows), 'conversations'
		);
	}

	/**
	 * Ordered and paged on the account's id, where Mastodon orders by when
	 * the follow was made; the accounts here follow in the order they were
	 * created, in which the two agree.
	 */
	public function testFollowersAndFollowing(): void {
		$followers = [];
		$following = [];
		for ($i = 1; $i <= self::ROWS; $i++) {
			$this->follow('f' . $i, 'alice');
			$this->follow('alice', 'f' . $i);
			$followers[] = (string)$this->account('f' . $i)->getNid();
			$following[] = (string)$this->account('f' . $i)->getNid();
		}
		$alice = (string)$this->account('alice')->getNid();

		$this->assertMastodonPaging(
			$this->route(AccountApiController::class, 'accountFollowers', ['account' => $alice], 'bob'), array_reverse($followers), 'followers'
		);
		$this->assertMastodonPaging(
			$this->route(AccountApiController::class, 'accountFollowing', ['account' => $alice], 'bob'), array_reverse($following), 'following'
		);
	}

	/**
	 * A deliberate gap, documented in docs/API.md: the block and mute lists
	 * take no cursor, so they answer one page of at most `limit` accounts and
	 * send no `Link` — a header pointing at the page just sent made a client
	 * page through the same accounts for ever.
	 */
	public function testBlocksAndMutesAnswerOnePageWithoutLinks(): void {
		foreach (['f1', 'f2', 'f3', 'f4'] as $name) {
			$this->call(AccountApiController::class, 'accountBlock', ['id' => (string)$this->account($name)->getNid()], [], 'alice', 'POST');
			$this->call(AccountApiController::class, 'accountMute', ['id' => (string)$this->account($name)->getNid()], [], 'alice', 'POST');
		}

		foreach (['blocks', 'mutes'] as $method) {
			$page = $this->call(AccountApiController::class, $method, [], ['limit' => self::LIMIT], 'alice');
			$this->assertCount(self::LIMIT, $this->ids($page), $method . ' honours limit');
			$this->assertArrayNotHasKey('Link', $page->headers, $method . ' sends no Link');
			$this->assertCount(4, $this->ids($this->call(AccountApiController::class, $method, [], [], 'alice')), $method . ' defaults to a page of 40');
		}
	}

	/** Twenty rows unless the client asks otherwise, as Mastodon pages a timeline. */
	public function testTheDefaultPageIsTwenty(): void {
		for ($i = 1; $i <= 21; $i++) {
			$this->publish('bob', ['status' => 'one of twenty-one, ' . $i]);
		}
		$bob = (string)$this->account('bob')->getNid();

		$page = $this->call(AccountApiController::class, 'accountStatuses', ['account' => $bob], [], 'carol');

		$this->assertCount(20, $this->ids($page));
		$this->assertArrayHasKey('next', $page->links());
	}
}
