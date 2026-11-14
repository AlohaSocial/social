<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\ApiController;
use OCA\Social\Controller\MediaApiController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * What one page of the client API costs in queries, end to end: the
 * controller a request reaches, the services behind it, and the rendering of
 * the answer, where each status is turned into JSON.
 *
 * Every endpoint is asked twice: with five posts seeded, and again after
 * thirty-five more. A page of twenty must cost what a page of five costs — a
 * statement that grows with the page is a query per post (an N+1), the thing
 * that took the home timeline from 50 ms to seconds before — and both must
 * stay within a budget: today's count plus two, so that a new query on a hot
 * path is a decision somebody takes in this file rather than an accident.
 *
 * The first count is taken on a second request and the second on a first
 * request for the new posts, so a lookup memoised per post or per author
 * still shows up as growth. Each new post has its own author for the same
 * reason.
 *
 * The counts are the same on every database: what differs between SQLite,
 * MySQL and PostgreSQL is the plan of each query, which `QueryPlanTest` pins.
 */
class QueryBudgetTest extends ReaderTestCase {
	private const PAGE = 20;

	private string $fastPathBefore = '';

	protected function setUp(): void {
		parent::setUp();
		$this->fastPathBefore = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_DEST_NID_FILLED);
	}

	protected function tearDown(): void {
		$this->useHomeFastPath($this->fastPathBefore === '1');
		parent::tearDown();
	}

	/**
	 * Seeds five posts and measures a repeated request; seeds thirty-five more
	 * and measures the first request after; then holds both counts to the
	 * budget.
	 *
	 * @param callable(int): void $seed seeds that many more posts
	 * @param callable(): Response $request one request to the endpoint
	 * @param callable(array): int $itemsOf how many entries the answer carries
	 * @param bool $shortPageReadsOn a page short of the limit reads further
	 *                               windows by design, so five posts may cost
	 *                               more than forty, never less
	 */
	private function assertFlatCostWithin(
		string $what, int $budget, callable $seed, callable $request, callable $itemsOf,
		bool $shortPageReadsOn = false,
	): void {
		$seed(5);
		// rendered like the counted requests, so what a service reads once and
		// keeps for the rest of the process is read here whatever ran before
		$warm = $request();
		$this->assertSame(Http::STATUS_OK, $warm->getStatus(), $what);
		self::render($warm);
		$small = QueryCounter::record(static fn (): string => self::render($request()));

		$seed(35);
		$large = QueryCounter::record(static fn (): string => self::render($request()));

		$this->assertSame(
			self::PAGE, $itemsOf(self::decode($request())),
			$what . ': the larger seed fills a whole page, or the comparison proves nothing'
		);
		$growth = $what . ': a page of ' . self::PAGE . ' costs more queries than a page of 5 — a query per post?'
			. "\nwith 5 posts, " . $small->summary() . "\nwith 40 posts, " . $large->summary();
		if ($shortPageReadsOn) {
			$this->assertLessThanOrEqual($small->count(), $large->count(), $growth);
		} else {
			$this->assertSame($small->count(), $large->count(), $growth);
		}
		foreach ([$small, $large] as $run) {
			$this->assertLessThanOrEqual(
				$budget, $run->count(),
				$what . ' is over its query budget; if the new query is meant, raise the budget here'
				. "\n" . $run->summary()
			);
		}
	}

	private function useHomeFastPath(bool $fast): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_DEST_NID_FILLED, $fast ? '1' : '0');
		// the request memoises the flag for the life of the instance
		(new ReflectionProperty(StreamRequest::class, 'recipientNidsFilled'))
			->setValue(Server::get(StreamRequest::class), null);
	}

	/**
	 * Both include the three queries of the poll tag (`newestHomeNid()`),
	 * which a request with no cursor is given and a poll that still holds it
	 * is answered with, and nothing else.
	 *
	 * @return array<string, array{bool, int}>
	 */
	public static function homePaths(): array {
		return [
			'fast path, read off the recipient rows' => [true, 27],
			'join path, the fallback' => [false, 14],
		];
	}

	#[DataProvider('homePaths')]
	public function testTheHomeTimelineCostsNoMoreForAPageOfTwentyThanForAPageOfFive(bool $fast, int $budget): void {
		$this->useHomeFastPath($fast);
		$authors = 0;

		$this->assertFlatCostWithin(
			'home', $budget,
			function (int $count) use (&$authors): void {
				for ($i = 0; $i < $count; $i++, $authors++) {
					$author = $this->remoteActor('home' . $authors);
					$this->follow($author);
					$this->post($author, 'home post ' . $authors);
				}
			},
			fn (): Response => $this->controller(TimelineApiController::class)->timelines('home'),
			static fn (array $page): int => count($page),
			$fast,
		);
	}

	/**
	 * Off the recipient rows, as home: a page short of the limit reads one
	 * window further to find out there is nothing more, so five posts cost
	 * one query more than forty.
	 */
	public function testThePublicTimelineCostsTheSameForAPageOfFiveAndAPageOfTwenty(): void {
		$this->useHomeFastPath(true);
		$authors = 0;

		$this->assertFlatCostWithin(
			'public', 11,
			function (int $count) use (&$authors): void {
				for ($i = 0; $i < $count; $i++, $authors++) {
					$this->post($this->remoteActor('public' . $authors), 'public post ' . $authors);
				}
			},
			fn (): Response => $this->controller(TimelineApiController::class)->timelines('public'),
			static fn (array $page): int => count($page),
			true,
		);
	}

	/**
	 * The senders are followed, so the reader's calm notification policy lets
	 * them through and the page is rendered rather than held — and the policy's
	 * own facts about the senders are still read, once per page.
	 */
	public function testNotificationsCostTheSameForAPageOfFiveAndAPageOfTwenty(): void {
		$authors = 0;

		$this->assertFlatCostWithin(
			'notifications', 12,
			function (int $count) use (&$authors): void {
				for ($i = 0; $i < $count; $i++, $authors++) {
					$author = $this->remoteActor('mention' . $authors);
					$this->follow($author);
					$this->mention($author);
				}
			},
			fn (): Response => $this->controller(TimelineApiController::class)->notifications(),
			static fn (array $page): int => count($page),
		);
	}

	/**
	 * An account's statuses that are all boosts, each of a post by an author
	 * of its own: the boosted post and its author are read with the page,
	 * not once per boost.
	 */
	public function testAnAccountsBoostsCostTheSameForAPageOfFiveAndAPageOfTwenty(): void {
		$booster = $this->remoteActor('booster');
		$boosts = 0;

		$this->assertFlatCostWithin(
			'account boosts', 13,
			function (int $count) use (&$boosts, $booster): void {
				for ($i = 0; $i < $count; $i++, $boosts++) {
					$this->boost($booster, $this->post($this->remoteActor('boosted' . $boosts), 'boosted post ' . $boosts));
				}
			},
			fn (): Response => $this->controller(AccountApiController::class)->accountStatuses((string)$booster->getNid()),
			static fn (array $page): int => count(array_filter($page, static fn (array $status): bool => ($status['reblog'] ?? null) !== null)),
		);
	}

	public function testAnAccountsStatusesCostTheSameForAPageOfFiveAndAPageOfTwenty(): void {
		$author = $this->remoteActor('profile');
		$posts = 0;

		$this->assertFlatCostWithin(
			'account statuses', 13,
			function (int $count) use (&$posts, $author): void {
				for ($i = 0; $i < $count; $i++, $posts++) {
					$this->post($author, 'profile post ' . $posts);
				}
			},
			fn (): Response => $this->controller(AccountApiController::class)->accountStatuses((string)$author->getNid()),
			static fn (array $page): int => count($page),
		);
	}

	/**
	 * Opening a post is two requests — the status, then its thread — and the
	 * thread's replies each have their own author. Each request reads the
	 * cards, reactions and people in the pictures of what it serves once.
	 */
	public function testAStatusAndItsContextCostTheSameWithFiveRepliesAndWithForty(): void {
		$root = $this->post($this->remoteActor('thread'), 'the post everyone answers');
		$nid = (string)Server::get(StreamRequest::class)->getStreamById($root->getId())->getNid();
		$replies = 0;

		$this->assertFlatCostWithin(
			// Opening a post reads its live-count refresh marker and records that
			// the viewer saw it before loading the conversation context. The
			// replies a viewer may see are paged by nid first, over the key
			// alone, and then read whole, which keeps the large columns out of
			// the DISTINCT the recipient join needs.
			'status and context', 20,
			function (int $count) use (&$replies, $root): void {
				for ($i = 0; $i < $count; $i++, $replies++) {
					$this->post(
						$this->remoteActor('replier' . $replies), 'a reply ' . $replies,
						static function (Note $note) use ($root): void {
							$note->setInReplyTo($root->getId());
						}
					);
				}
			},
			function () use ($nid): Response {
				self::render($this->controller(StatusApiController::class)->statusGet($nid));

				return $this->controller(StatusApiController::class)->statusContext($nid);
			},
			// the thread is not paged: all forty replies, of which twenty is the
			// part the comparison needs
			static fn (array $context): int => min(self::PAGE, count($context['descendants'] ?? [])),
		);
	}

	public function testSearchCostsTheSameForAPageOfFiveAndAPageOfTwenty(): void {
		$authors = 0;
		$word = 'budgetword' . bin2hex(random_bytes(3));

		$this->assertFlatCostWithin(
			'search', 10,
			function (int $count) use (&$authors, $word): void {
				for ($i = 0; $i < $count; $i++, $authors++) {
					$this->post($this->remoteActor('found' . $authors), $word . ' number ' . $authors);
				}
			},
			fn (): Response => $this->controller(ApiController::class)->searchV2($word),
			static fn (array $results): int => count($results['statuses'] ?? []),
		);
	}

	/**
	 * The route every picture and every video seek goes through — forty to
	 * sixty of them a screen. It was nineteen queries in September; reading
	 * the document row and the file it names is the whole of the job.
	 */
	public function testAByteRangeOfAMediaFileStaysWithinThreeQueries(): void {
		$document = new Document();
		$document->setLocal(true);
		$document->setAccount('querybudget');
		$document->setUrlCloud('https://cloud.example.org');
		$document->generateUniqueId('/documents/local');
		$document->setPublic(true);

		$png = (string)tempnam(sys_get_temp_dir(), 'social-query-budget');
		$image = imagecreatetruecolor(40, 20);
		imagefilledrectangle($image, 0, 0, 39, 19, (int)imagecolorallocate($image, 30, 60, 200));
		imagepng($image, $png);
		try {
			Server::get(CacheDocumentService::class)->saveFromTempToCache($document, $png);
		} finally {
			unlink($png);
		}
		$documents = Server::get(CacheDocumentsRequest::class);
		$documents->save($document);

		try {
			$request = fn (): Response => $this->controller(MediaApiController::class, [], ['Range' => 'bytes=0-9'])
				->mediaOpen($document->getLocalCopy());
			$request();

			$run = QueryCounter::record($request);
			/** @var Response $response */
			$response = $run->result();

			$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
			$this->assertStringStartsWith('bytes 0-9/', $response->getHeaders()['Content-Range'] ?? '');
			$this->assertLessThanOrEqual(3, $run->count(), $run->summary());
		} finally {
			$documents->deleteById($document->getId());
		}
	}
}
