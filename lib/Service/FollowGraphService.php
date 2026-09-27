<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\GraphSuggestion;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who the people you follow follow — the "Followgraph" question.
 *
 * `SuggestionService` asks the same question of this instance's own database,
 * and on a small server the answer is nearly empty: the follows it holds are
 * the ones that happened here. This asks the *other servers* instead. For each
 * account the viewer follows, their `following` collection is fetched from
 * wherever they live, and an account named by several of them is a better
 * suggestion than an account named by one. That is the whole model: a count of
 * who vouched, and the names of the ones who did.
 *
 * It is worth being clear about what this reads. A `following` collection is
 * published by the server that owns the account, to whoever asks, and many
 * servers do not publish it at all — Mastodon has a "hide your social graph"
 * setting and honours it here by answering with an empty collection or a 403.
 * A viewer's own follows are never sent anywhere: what leaves this instance is
 * a request for a public collection, one per account followed, which is the
 * same request their server answers for anyone.
 *
 * **It needs a starting handful of follows and says so.** Following nobody
 * means there is no graph to walk, and a page that answers that with "no
 * suggestions" has told the reader they are uninteresting rather than that
 * they have not started. `MINIMUM_FOLLOWS` is where it starts trying, and
 * below it the answer carries `needs` so the page can say what to do instead.
 */
class FollowGraphService {
	/** Below this the graph has nothing to say, and says that instead. */
	public const MINIMUM_FOLLOWS = 2;

	/**
	 * How many of the viewer's follows are asked who *they* follow.
	 *
	 * The cost of this is one HTTP request per account, so it is a ceiling on
	 * politeness as much as on time: twenty servers being asked one public
	 * question each is a reasonable thing to do on somebody pressing a
	 * button, and two hundred is not.
	 */
	public const SAMPLE = 20;

	/** How many names one collection contributes. A Mastodon page holds 80. */
	public const PAGE = 80;

	/**
	 * How long one account has to answer.
	 *
	 * There is no separate budget for the whole walk any more: the twenty
	 * requests go together, so the walk takes as long as the slowest single
	 * answer rather than as long as all of them added up, and a ceiling on one
	 * request is a ceiling on the set.
	 */
	public const TIMEOUT = 4;

	/**
	 * How long an answer is kept. Long, because it is expensive to make and
	 * because a follow graph does not change between two glances at a page —
	 * and it is dropped the moment the viewer follows somebody, which is the
	 * one thing that really changes it.
	 */
	public const CACHE_TTL = 1800;

	/** How many of the vouching accounts are remembered for the reason line. */
	private const VIA = 3;

	private ICache $cache;

	public function __construct(
		private FollowsRequest $followsRequest,
		private CacheActorService $cacheActorService,
		private CurlService $curlService,
		private FediverseService $fediverseService,
		private SuggestionService $suggestionService,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('social.followgraph');
	}

	/**
	 * Accounts followed by the accounts the viewer follows, most-vouched
	 * first.
	 *
	 * @return array{suggestions: GraphSuggestion[], asked: int, needs: int}
	 *                                                                       `needs` is how many more follows it would take to be worth
	 *                                                                       asking, and is 0 once it is
	 */
	public function suggestions(Person $viewer, int $limit = 20): array {
		$limit = max(1, min(40, $limit));
		$follows = $this->followedActors($viewer);

		if (count($follows) < self::MINIMUM_FOLLOWS) {
			return [
				'suggestions' => [],
				'asked' => 0,
				'needs' => self::MINIMUM_FOLLOWS - count($follows),
			];
		}

		$cached = $this->cache->get($this->cacheKey($viewer, $limit));
		if (is_string($cached)) {
			$decoded = json_decode($cached, true);
			if (is_array($decoded)) {
				return $this->decode($decoded);
			}
		}

		$tally = $this->walk(array_slice($follows, 0, self::SAMPLE));
		$answer = [
			'suggestions' => $this->resolve($viewer, $tally['counts'], $tally['via'], $limit),
			'asked' => $tally['asked'],
			'needs' => 0,
		];

		$this->cache->set($this->cacheKey($viewer, $limit), json_encode([
			'suggestions' => array_map(
				static fn (GraphSuggestion $suggestion): array => $suggestion->jsonSerialize(),
				$answer['suggestions']
			),
			'asked' => $answer['asked'],
		]), self::CACHE_TTL);

		return $answer;
	}

	/**
	 * Whether there is a graph to walk at all, without walking it.
	 *
	 * The page asks this when it opens, and the walk itself only when somebody
	 * presses the button: twenty outgoing requests is a thing to do because a
	 * reader asked for suggestions, not because they opened a tab.
	 *
	 * @return array{suggestions: array<empty>, asked: int, needs: int}
	 */
	public function probe(Person $viewer): array {
		$follows = count($this->followedActors($viewer));

		return [
			'suggestions' => [],
			'asked' => 0,
			'needs' => max(0, self::MINIMUM_FOLLOWS - $follows),
		];
	}

	/** Throws the walk away, because the thing it was walking has changed. */
	public function forget(Person $viewer): void {
		$this->cache->remove($this->cacheKey($viewer, 0));
		for ($limit = 1; $limit <= 40; $limit++) {
			$this->cache->remove($this->cacheKey($viewer, $limit));
		}
	}

	/**
	 * The actor ids the viewer follows, newest first.
	 *
	 * @return string[]
	 */
	private function followedActors(Person $viewer): array {
		$ids = [];
		foreach ($this->followsRequest->getFollowingByActorId($viewer->getId(), self::SAMPLE * 2) as $follow) {
			$id = $follow->getObjectId();
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Asks each of them who they follow.
	 *
	 * A server that will not say is not an error and not retried: "I do not
	 * publish that" is a real answer, given by every Mastodon account with the
	 * social graph hidden.
	 *
	 * @param string[] $follows
	 *
	 * @return array{counts: array<string, int>, via: array<string, string[]>, asked: int}
	 */
	private function walk(array $follows): array {
		$counts = [];
		$via = [];
		$asked = 0;

		// the collections first, then all of them at once: these are twenty
		// servers that have nothing to do with each other, and asking them one
		// after another let the slowest of them set the pace for the whole
		// page. The budget is what it was; it now covers the batch rather than
		// being spent a request at a time.
		$collections = [];
		foreach ($follows as $followedId) {
			try {
				$collection = $this->cacheActorService->getFromId($followedId)->getFollowing();
			} catch (Throwable $e) {
				$this->logger->debug('[FollowGraphService] no actor to ask', [
					'actor' => $followedId, 'exception' => $e,
				]);
				continue;
			}

			if ($collection !== '') {
				$collections[$followedId] = $collection;
			}
		}

		$documents = $this->curlService->retrieveObjectsMany(
			array_values($collections), ['timeout' => self::TIMEOUT]
		);

		foreach ($collections as $followedId => $collection) {
			$document = $documents[$collection] ?? null;
			if (!is_array($document)) {
				continue;
			}

			$asked++;
			foreach ($this->itemsIn($document) as $candidate) {
				$counts[$candidate] = ($counts[$candidate] ?? 0) + 1;
				if (count($via[$candidate] ?? []) < self::VIA) {
					$via[$candidate][] = $followedId;
				}
			}
		}

		arsort($counts);

		return ['counts' => $counts, 'via' => $via, 'asked' => $asked];
	}

	/**
	 * The actor ids in one fetched `following` collection, one page of it.
	 *
	 * A collection answers either with its items or with a `first` page that
	 * has them; both shapes are in the wild and the difference is not
	 * interesting here. Nothing pages further: a walk of somebody's entire
	 * following list is a different act from reading the front of it, and it
	 * is not one this instance should perform on a button press.
	 *
	 * @param array<string, mixed> $document
	 *
	 * @return string[]
	 */
	private function itemsIn(array $document): array {
		$items = $document['orderedItems'] ?? $document['items'] ?? [];

		if ($items === [] && is_string($document['first'] ?? null)) {
			// one more request, and only for the collections that need it: a
			// server that answers with a `first` page rather than with items
			try {
				$page = $this->curlService->retrieveObject($document['first']);
				$items = $page['orderedItems'] ?? $page['items'] ?? [];
			} catch (Throwable $e) {
				$items = [];
			}
		}

		$ids = [];
		foreach (is_array($items) ? $items : [] as $item) {
			if (count($ids) >= self::PAGE) {
				break;
			}

			// an entry is an id, or an object that has one
			/** @var mixed $item */
			$id = match (true) {
				is_string($item) => $item,
				is_array($item) => (string)($item['id'] ?? ''),
				default => '',
			};
			if ($id !== '' && str_starts_with($id, 'http')) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Turns the counted ids into accounts, dropping the ones nobody should be
	 * offered.
	 *
	 * The exclusions are `SuggestionService`'s, because "who may be suggested"
	 * is one question with one answer, and a second copy of it here would be
	 * the place where a blocked account eventually gets recommended.
	 *
	 * @param array<string, int> $counts
	 * @param array<string, string[]> $via
	 *
	 * @return GraphSuggestion[]
	 */
	private function resolve(Person $viewer, array $counts, array $via, int $limit): array {
		$excluded = $this->suggestionService->excludedPrims($viewer->getId());
		$suggestions = [];

		foreach ($counts as $id => $count) {
			if (count($suggestions) >= $limit) {
				break;
			}

			$id = (string)$id;
			// the exclusions are keyed by prim, which is how every other
			// list of actors in this app is keyed
			if ($id === $viewer->getId() || isset($excluded[md5($id)])) {
				continue;
			}

			try {
				$account = $this->cacheActorService->getFromId($id);
				$this->fediverseService->authorized($account->getAccount());
			} catch (Throwable $e) {
				// an account this instance cannot reach, or will not deal
				// with, is not a suggestion
				continue;
			}

			$suggestions[] = new GraphSuggestion(
				$account,
				$count,
				$this->handlesOf($via[$id] ?? [])
			);
		}

		return $suggestions;
	}

	/**
	 * The handles of the accounts that vouched, for the line that says why.
	 *
	 * Only ones already held here are named — this is a reason shown to the
	 * viewer, not a reason to make more requests.
	 *
	 * @param string[] $ids
	 *
	 * @return string[]
	 */
	private function handlesOf(array $ids): array {
		$handles = [];
		foreach ($ids as $id) {
			try {
				$handles[] = $this->cacheActorService->getFromId($id)->getAccount();
			} catch (Throwable $e) {
				continue;
			}
		}

		return array_values(array_filter($handles));
	}

	/** @param array<string, mixed> $decoded */
	private function decode(array $decoded): array {
		$suggestions = [];
		foreach ($decoded['suggestions'] ?? [] as $row) {
			$suggestion = GraphSuggestion::fromArray(is_array($row) ? $row : []);
			if ($suggestion !== null) {
				$suggestions[] = $suggestion;
			}
		}

		return [
			'suggestions' => $suggestions,
			'asked' => (int)($decoded['asked'] ?? 0),
			'needs' => 0,
		];
	}

	private function cacheKey(Person $viewer, int $limit): string {
		return md5($viewer->getId()) . '/' . $limit;
	}
}
