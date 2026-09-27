<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Search;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\Search\UnifiedSearchResult;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UnifiedSearchProviderTest extends TestCase {
	/** @var IL10N&MockObject */
	private $l10n;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var SearchService&MockObject */
	private $searchService;
	private UnifiedSearchProvider $provider;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->l10n->method('n')->willReturnCallback(
			fn (string $singular, string $plural, int $count, array $params = []): string
				=> vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $params)
		);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->searchService = $this->createMock(SearchService::class);

		$this->provider = new UnifiedSearchProvider(
			$this->l10n,
			$this->urlGenerator,
			$this->createMock(StreamService::class),
			$this->createMock(StreamRequest::class),
			$this->createMock(FollowService::class),
			$this->createMock(CacheActorService::class),
			$this->createMock(AccountService::class),
			$this->searchService
		);
	}

	public function testStatusesJoinTheResultsWithExcerptAndLink(): void {
		$alice = new Person();
		$alice->setId('https://cloud.example/@alice');
		$alice->setPreferredUsername('alice');
		$alice->setAccount('alice@cloud.example');

		$local = new Note();
		$local->setId('https://cloud.example/@alice/1');
		$local->setNid(7);
		$local->setContent('<p>The   quick brown fox jumps over the lazy dog, ' . str_repeat('again and ', 20) . 'again</p>');
		$local->setLocal(true);
		$local->setActor($alice);

		$remote = new Note();
		$remote->setId('https://remote.example/notes/9');
		$remote->setContent('<p>remote hit</p>');
		$remote->setLocal(false);

		$this->searchService->method('searchStreamContent')->with('fox')->willReturn([$local, $remote]);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('social.ActivityPub.displayPost', ['username' => 'alice', 'token' => '7'])
			->willReturn('https://cloud.example/apps/social/@alice/7');

		$entries = $this->provider->search($this->user(), $this->query('fox'))->jsonSerialize()['entries'];

		$this->assertCount(2, $entries);
		$first = $entries[0]->jsonSerialize();
		$this->assertStringStartsWith('The quick brown fox', $first['title'], 'whitespace collapsed, tags stripped');
		$this->assertLessThanOrEqual(120, mb_strlen($first['title']));
		$this->assertStringEndsWith('…', $first['title']);
		$this->assertSame('@alice@cloud.example', $first['subline']);
		$this->assertSame('https://cloud.example/apps/social/@alice/7', $first['resourceUrl']);

		$second = $entries[1]->jsonSerialize();
		$this->assertSame('https://remote.example/notes/9', $second['resourceUrl'], 'remote statuses link to their origin');
	}

	/** @return IUser&MockObject */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}

	/** @return ISearchQuery&MockObject */
	private function query(string $term, int $limit = 5, ?int $cursor = null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);

		return $query;
	}

	/** @return Person&MockObject */
	private function account(string $username, string $account, ?string $iconUrl = null): Person {
		$person = $this->createMock(Person::class);
		$person->method('getPreferredUsername')->willReturn($username);
		$person->method('getAccount')->willReturn($account);
		$person->method('hasIcon')->willReturn($iconUrl !== null);
		if ($iconUrl !== null) {
			$icon = $this->createMock(Document::class);
			$icon->method('getUrl')->willReturn($iconUrl);
			$person->method('getIcon')->willReturn($icon);
		}

		return $person;
	}

	public function testIdentity(): void {
		$this->assertSame('social', $this->provider->getId());
		$this->assertSame('Social', $this->provider->getName());
		$this->assertSame(12, $this->provider->getOrder('files.View.index', []));
		$this->assertSame(12, $this->provider->getOrder('social.Navigation.navigate', ['path' => 'home']));
	}

	public function testSearchTrimsTheTermAndQueriesAccountsUrisAndHashtags(): void {
		$this->searchService->expects($this->once())->method('searchUri')->with('alice')->willReturn([]);
		$this->searchService->expects($this->once())->method('searchAccounts')->with('alice')->willReturn([]);
		$this->searchService->expects($this->once())->method('searchHashtags')->with('alice')->willReturn([]);

		$result = $this->provider->search($this->user(), $this->query('  alice '));

		$this->assertSame('Social', $result->jsonSerialize()['name']);
		$this->assertSame([], $result->jsonSerialize()['entries']);
	}

	public function testAccountsBecomeEntriesLinkingToTheActorPage(): void {
		$this->searchService->method('searchUri')->willReturn([$this->account('bob', 'bob@remote.example', 'https://remote.example/bob.png')]);
		$this->searchService->method('searchAccounts')->willReturn([$this->account('alice', 'alice@cloud.example')]);
		$this->searchService->method('searchHashtags')->willReturn([]);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(
			fn (string $route, array $args): string => $route . '?username=' . $args['username']
		);

		$entries = $this->provider->search($this->user(), $this->query('x'))->jsonSerialize()['entries'];

		$this->assertCount(2, $entries);
		$this->assertContainsOnlyInstancesOf(UnifiedSearchResult::class, $entries);
		[$bob, $alice] = $entries;
		$this->assertSame('bob', $bob->getTitle());
		$this->assertSame('@bob@remote.example', $bob->getSubline());
		$this->assertSame('social.ActivityPub.actorAlias?username=bob@remote.example', $bob->getResourceUrl());
		$this->assertSame('https://remote.example/bob.png', $bob->getThumbnailUrl());
		$this->assertSame('https://remote.example/bob.png', $bob->getIcon());
		$this->assertSame('alice', $alice->getTitle());
		$this->assertSame('', $alice->getThumbnailUrl(), 'accounts without icon have no thumbnail');
	}

	public function testHashtagsBecomeEntriesLinkingToTheTagTimeline(): void {
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);
		$this->searchService->method('searchHashtags')->willReturn([
			['hashtag' => 'nextcloud', 'trend' => ['10d' => 42]],
			['hashtag' => 'solo', 'trend' => ['10d' => 1]],
			['hashtag' => 'untracked'],
		]);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $args): string => 'https://cloud.example/apps/social/timeline/' . $args['path']
		);

		$entries = $this->provider->search($this->user(), $this->query('nextcloud'))->jsonSerialize()['entries'];

		$this->assertCount(3, $entries);
		$this->assertSame("42 posts related to 'nextcloud'", $entries[0]->getTitle());
		$this->assertSame('#nextcloud', $entries[0]->getSubline());
		$this->assertSame('https://cloud.example/apps/social/timeline/tags/nextcloud', $entries[0]->getResourceUrl());
		$this->assertSame('', $entries[0]->getThumbnailUrl());
		// the count goes through the plural forms of the language, like every other
		// string this provider emits
		$this->assertSame("1 post related to 'solo'", $entries[1]->getTitle());
		$this->assertSame("0 posts related to 'untracked'", $entries[2]->getTitle());
	}

	public function testLoadMoreServesTheNextResultsAndTheLastPageAdvertisesNoOther(): void {
		// The cursor used to be handed out without ever being read, so every
		// "load more" answered with the first page again, for ever.
		$accounts = [];
		for ($i = 0; $i < 12; $i++) {
			$accounts[] = $this->account('user' . $i, 'user' . $i . '@cloud.example');
		}
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchHashtags')->willReturn([]);
		$this->searchService->method('searchStreamContent')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturnCallback(
			fn (string $search, ?int $limit = null): array
				=> ($limit === null) ? $accounts : array_slice($accounts, 0, $limit)
		);
		$this->urlGenerator->method('linkToRoute')->willReturn('/social');

		$first = $this->provider->search($this->user(), $this->query('user', 5))->jsonSerialize();
		$second = $this->provider->search($this->user(), $this->query('user', 5, $first['cursor']))->jsonSerialize();
		$last = $this->provider->search($this->user(), $this->query('user', 5, $second['cursor']))->jsonSerialize();

		$this->assertSame(['user0', 'user1', 'user2', 'user3', 'user4'], $this->titles($first));
		$this->assertTrue($first['isPaginated']);
		$this->assertSame(5, $first['cursor']);

		$this->assertSame(['user5', 'user6', 'user7', 'user8', 'user9'], $this->titles($second));
		$this->assertTrue($second['isPaginated']);
		$this->assertSame(10, $second['cursor']);

		$this->assertSame(['user10', 'user11'], $this->titles($last));
		$this->assertFalse($last['isPaginated'], 'a page that ends the results must not advertise another');
	}

	public function testAPageThatFitsIsNotAdvertisedAsPaginated(): void {
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);
		$this->searchService->method('searchHashtags')->willReturn([]);
		$this->searchService->method('searchStreamContent')->willReturn([]);

		$result = $this->provider->search($this->user(), $this->query('x', 5))->jsonSerialize();

		$this->assertSame([], $result['entries']);
		$this->assertFalse($result['isPaginated'], 'no results is not a page to load more of');
	}

	public function testEverySourceIsAskedForNoMoreThanThePageNeeds(): void {
		$this->searchService->expects($this->once())->method('searchAccounts')->with('x', 8)->willReturn([]);
		$this->searchService->expects($this->once())->method('searchHashtags')->with('x', 8)->willReturn([]);
		$this->searchService->expects($this->once())->method('searchStreamContent')->with('x', 8)->willReturn([]);
		$this->searchService->method('searchUri')->willReturn([]);

		$this->provider->search($this->user(), $this->query('x', 5, 2));
	}

	private function titles(array $result): array {
		return array_map(fn (UnifiedSearchResult $entry): string => $entry->getTitle(), $result['entries']);
	}
}
