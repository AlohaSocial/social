<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FollowGraphService;
use OCA\Social\Service\SuggestionService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Who the people you follow follow.
 *
 * What is asserted here is the part that only shows up once other servers are
 * answering: a collection that hands back a `first` page instead of its items,
 * an account whose server will not publish the list at all, somebody who is
 * suggested by four of the viewer's follows and somebody suggested by one, and
 * the accounts that must never be offered however many people vouch for them.
 */
class FollowGraphServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	private FollowsRequest|Stub $followsRequest;
	private CacheActorService|Stub $cacheActorService;
	private CurlService|Stub $curlService;
	private FediverseService|Stub $fediverseService;
	private SuggestionService|Stub $suggestionService;
	private FollowGraphService $service;

	/** actor id => the actor ids they follow, as their server would publish them */
	private array $graph = [];
	/** collections that answer with a `first` page rather than their items */
	private array $paged = [];
	/** actor ids whose server refuses to say */
	private array $private = [];
	/** prims the suggestion rules exclude */
	private array $excluded = [];
	/** hosts this instance will not deal with */
	private array $blocked = [];
	/** every URL that was fetched */
	private array $fetched = [];

	protected function setUp(): void {
		parent::setUp();

		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->followsRequest->method('getFollowingByActorId')
			->willReturnCallback(function (string $actorId): array {
				$follows = [];
				foreach (array_keys($this->graph) as $followed) {
					$follow = new Follow();
					$follow->setObjectId((string)$followed);
					$follows[] = $follow;
				}

				return $follows;
			});

		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(function (string $id): Person {
				$host = (string)parse_url($id, PHP_URL_HOST);
				$name = basename($id);

				$person = new Person();
				$person->setId($id);
				$person->setPreferredUsername($name);
				$person->setAccount($name . '@' . $host);
				$person->setFollowing($id . '/following');

				return $person;
			});

		$this->curlService = $this->createStub(CurlService::class);
		// the walk asks every server at once now; a peer that refuses is a
		// null in its place rather than an exception, which is what the
		// batch's callers cope with
		$this->curlService->method('retrieveObjectsMany')
			->willReturnCallback(function (array $urls): array {
				$answers = [];
				foreach ($urls as $url) {
					try {
						$answers[$url] = $this->curlService->retrieveObject($url);
					} catch (\Throwable $e) {
						$answers[$url] = null;
					}
				}

				return $answers;
			});
		$this->curlService->method('retrieveObject')
			->willReturnCallback(function (string $url): array {
				$this->fetched[] = $url;
				$actor = preg_replace('#/following(/page)?$#', '', $url);

				if (in_array($actor, $this->private, true)) {
					throw new RuntimeException('that account does not publish it');
				}

				$items = array_map(
					static fn (string $id): string => $id,
					$this->graph[$actor] ?? []
				);

				if (in_array($actor, $this->paged, true)) {
					return str_ends_with($url, '/page')
						? ['orderedItems' => $items]
						: ['first' => $actor . '/following/page'];
				}

				return ['orderedItems' => $items];
			});

		$this->fediverseService = $this->createStub(FediverseService::class);
		$this->fediverseService->method('authorized')
			->willReturnCallback(function (string $handle): bool {
				foreach ($this->blocked as $host) {
					if (str_ends_with($handle, '@' . $host)) {
						throw new RuntimeException('not federating with ' . $host);
					}
				}

				return true;
			});

		$this->suggestionService = $this->createStub(SuggestionService::class);
		$this->suggestionService->method('excludedPrims')
			->willReturnCallback(fn (): array => array_fill_keys(
				array_map(static fn (string $id): string => md5($id), $this->excluded), true
			));

		$this->service = $this->build($this->createStub(ICache::class));
	}

	private function build(ICache|MockObject $cache): FollowGraphService {
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new FollowGraphService(
			$this->followsRequest,
			$this->cacheActorService,
			$this->curlService,
			$this->fediverseService,
			$this->suggestionService,
			new NullLogger(),
			$factory
		);
	}

	private function viewer(): Person {
		$person = new Person();
		$person->setId(self::VIEWER);
		$person->setAccount('alice@cloud.example');

		return $person;
	}

	/** @return array<string, int> handle => how many of the viewer's follows follow them */
	private function counted(array $answer): array {
		$counted = [];
		foreach ($answer['suggestions'] as $suggestion) {
			$counted[$suggestion->getAccount()->getAccount()] = $suggestion->getFollowedBy();
		}

		return $counted;
	}

	public function testAnAccountSeveralOfYourFollowsFollowComesFirst(): void {
		$this->graph = [
			'https://a.example/users/one' => ['https://x.example/users/popular', 'https://x.example/users/quiet'],
			'https://b.example/users/two' => ['https://x.example/users/popular'],
			'https://c.example/users/three' => ['https://x.example/users/popular'],
		];

		$counted = $this->counted($this->service->suggestions($this->viewer()));

		$this->assertSame(
			['popular@x.example' => 3, 'quiet@x.example' => 1],
			$counted
		);
	}

	public function testTheAccountsThatVouchedAreNamed(): void {
		$this->graph = [
			'https://a.example/users/one' => ['https://x.example/users/popular'],
			'https://b.example/users/two' => ['https://x.example/users/popular'],
		];

		$answer = $this->service->suggestions($this->viewer());

		// the count alone is a number to trust; the handles are a reason to
		// believe it
		$this->assertSame(['one@a.example', 'two@b.example'], $answer['suggestions'][0]->getVia());
	}

	public function testACollectionThatPagesIsFollowedOnce(): void {
		$this->graph = [
			'https://a.example/users/one' => ['https://x.example/users/popular'],
			'https://b.example/users/two' => [],
		];
		$this->paged = ['https://a.example/users/one'];

		$this->assertSame(['popular@x.example' => 1], $this->counted($this->service->suggestions($this->viewer())));
		$this->assertContains('https://a.example/users/one/following/page', $this->fetched);
	}

	public function testAnAccountWhoseServerWillNotSayIsNotAFailure(): void {
		// Mastodon's "hide your social graph" is this, and it is common
		$this->graph = [
			'https://a.example/users/one' => ['https://x.example/users/popular'],
			'https://b.example/users/two' => ['https://x.example/users/popular'],
		];
		$this->private = ['https://b.example/users/two'];

		$answer = $this->service->suggestions($this->viewer());

		$this->assertSame(['popular@x.example' => 1], $this->counted($answer));
		// one of the two answered, and the page is told so rather than left to
		// wonder why the list is short
		$this->assertSame(1, $answer['asked']);
	}

	public function testSomebodyYouAlreadyFollowIsNotSuggested(): void {
		$this->graph = [
			'https://a.example/users/one' => ['https://b.example/users/two', 'https://x.example/users/new'],
			'https://b.example/users/two' => ['https://a.example/users/one'],
		];
		// the exclusions are the suggestion rules', and they hold the viewer's
		// follows among other things
		$this->excluded = ['https://a.example/users/one', 'https://b.example/users/two'];

		$this->assertSame(['new@x.example' => 1], $this->counted($this->service->suggestions($this->viewer())));
	}

	public function testTheViewerIsNeverSuggestedToThemselves(): void {
		$this->graph = [
			'https://a.example/users/one' => [self::VIEWER],
			'https://b.example/users/two' => [self::VIEWER],
		];

		$this->assertSame([], $this->counted($this->service->suggestions($this->viewer())));
	}

	public function testNobodyOnABlockedHostIsSuggested(): void {
		$this->graph = [
			'https://a.example/users/one' => ['https://spam.example/users/bot'],
			'https://b.example/users/two' => ['https://spam.example/users/bot'],
		];
		$this->blocked = ['spam.example'];

		$this->assertSame([], $this->counted($this->service->suggestions($this->viewer())));
	}

	/**
	 * The whole point of the feature is that it does not work yet for somebody
	 * who has just arrived, and the answer has to say so: a page that shows
	 * "no suggestions" to a new account has told them they are uninteresting
	 * rather than that they have not started.
	 */
	public function testFollowingNobodyIsAnsweredWithWhatIsMissing(): void {
		$this->graph = [];

		$answer = $this->service->suggestions($this->viewer());

		$this->assertSame([], $answer['suggestions']);
		$this->assertSame(FollowGraphService::MINIMUM_FOLLOWS, $answer['needs']);
		// and nothing was asked of anybody
		$this->assertSame([], $this->fetched);
	}

	public function testOneFollowIsStillTooFewToWalk(): void {
		$this->graph = ['https://a.example/users/one' => ['https://x.example/users/popular']];

		$answer = $this->service->suggestions($this->viewer());

		$this->assertSame(1, $answer['needs']);
		$this->assertSame([], $this->fetched);
	}

	/**
	 * What the page asks when it opens. Twenty outgoing requests is a thing to
	 * do because a reader asked for suggestions, not because they opened a
	 * tab, so this one answers from this server alone.
	 */
	public function testTheStatusProbeAsksNobodyAnything(): void {
		$this->graph = [
			'https://a.example/users/one' => ['https://x.example/users/popular'],
			'https://b.example/users/two' => ['https://x.example/users/popular'],
		];

		$answer = $this->service->probe($this->viewer());

		$this->assertSame(0, $answer['needs']);
		$this->assertSame([], $answer['suggestions']);
		$this->assertSame([], $this->fetched, 'opening the page went out on the network');
	}

	public function testTheStatusProbeSaysHowManyFollowsAreMissing(): void {
		$this->graph = ['https://a.example/users/one' => []];

		$this->assertSame(1, $this->service->probe($this->viewer())['needs']);
	}

	public function testTheWalkIsKeptSoTheNextGlanceIsFree(): void {
		$this->graph = [
			'https://a.example/users/one' => ['https://x.example/users/popular'],
			'https://b.example/users/two' => ['https://x.example/users/popular'],
		];

		$kept = [];
		$cache = $this->createStub(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$kept): bool {
			$kept[$key] = $value;

			return true;
		});
		// a closure, not an arrow function: an arrow function captures by
		// value, so it would read the empty array this started as
		$cache->method('get')->willReturnCallback(function (string $key) use (&$kept) {
			return is_string($kept[$key] ?? null) ? $kept[$key] : null;
		});
		$this->service = $this->build($cache);

		$first = $this->counted($this->service->suggestions($this->viewer()));
		fwrite(STDERR, 'KEPT KEYS: ' . json_encode(array_keys($kept)) . ' values are strings: ' . json_encode(array_map('is_string', $kept)) . '
');
		$fetchedOnce = count($this->fetched);
		$second = $this->counted($this->service->suggestions($this->viewer()));

		$this->assertSame($first, $second);
		$this->assertCount($fetchedOnce, $this->fetched, 'the second answer went back out on the network');
	}
}
