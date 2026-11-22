<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskySearch;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

#[AllowMockObjectsWithoutExpectations]
class SearchServiceTest extends TestCase {
	use TActivityPubMocks;

	private CacheActorService|MockObject $cacheActorService;
	private HashtagService|MockObject $hashtagService;
	private StreamRequest|Stub $streamRequest;
	private CurlService|MockObject $curlService;
	private SignatureService|Stub $signatureService;
	private SearchService $service;

	// resolveStatus()

	private function note(string $id): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo('https://remote.example/users/bob');

		return $note;
	}

	/**
	 * The read the resolver does after a save. Returning a post here means a
	 * refusal further up is the only thing that can make the answer empty --
	 * without it these tests would pass for the wrong reason.
	 */
	private function readBackIsASavedPost(Note $stored): void {
		$calls = 0;
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function () use (&$calls, $stored) {
				if ($calls++ === 0) {
					throw new StreamNotFoundException();
				}

				return $stored;
			});
	}

	public function testAPostAlreadyHeldIsNotFetchedAgain(): void {
		$held = $this->note('https://remote.example/notes/1');
		$this->streamRequest->method('getStreamById')->willReturn($held);
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->assertSame($held, $this->service->resolveStatus('https://remote.example/notes/1'));
	}

	public function testSomethingThatIsNotAnAddressIsNeverFetched(): void {
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->assertNull($this->service->resolveStatus('just some words'));
	}

	/**
	 * The positive case, which is what makes the guard below load-bearing: a
	 * well-formed document *is* fetched, stored and returned.
	 */
	public function testAPostNamedByItsAddressIsFetchedAndStored(): void {
		$stored = $this->note('https://remote.example/notes/1');
		$this->readBackIsASavedPost($stored);
		$this->curlService->method('retrieveObject')->willReturn([
			'id' => 'https://remote.example/notes/1',
			'type' => 'Note',
			'attributedTo' => 'https://remote.example/users/bob',
			'content' => '<p>hello</p>',
		]);

		$this->assertSame($stored, $this->service->resolveStatus('https://remote.example/notes/1'));
	}

	/**
	 * A document is only evidence about itself. Without this, anybody could
	 * host a document claiming to be somebody else's post and have this
	 * instance store it under that id.
	 */
	public function testADocumentThatClaimsAnotherAddressIsRefused(): void {
		$this->readBackIsASavedPost($this->note('https://remote.example/notes/1'));
		$this->curlService->method('retrieveObject')->willReturn([
			'id' => 'https://remote.example/notes/SOMEBODY-ELSE',
			'type' => 'Note',
			'attributedTo' => 'https://remote.example/users/bob',
		]);

		$this->assertNull($this->service->resolveStatus('https://remote.example/notes/1'));
	}

	/**
	 * A PeerTube watch page is not the video's id, and is still a link people
	 * paste: the video it serves names the page among its `url` links, on the
	 * same server.
	 */
	public function testAPeerTubeWatchPageResolvesToItsVideo(): void {
		$stored = $this->note('https://peertube.example/videos/watch/9c9de5e8');
		$this->readBackIsASavedPost($stored);
		$this->curlService->method('retrieveObject')->willReturn([
			'id' => 'https://peertube.example/videos/watch/9c9de5e8',
			'type' => 'Video',
			'attributedTo' => 'https://peertube.example/accounts/bob',
			'url' => [
				['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://peertube.example/w/6f4c1e1a'],
			],
		]);

		$this->assertSame($stored, $this->service->resolveStatus('https://peertube.example/w/6f4c1e1a'));
	}

	/**
	 * Listing the fetched address among its `url` links is all a document
	 * says about itself, so the id it claims has to be on the server that
	 * served it: otherwise any server could serve somebody else's post.
	 */
	public function testADocumentNamingTheAddressButClaimingAnotherServersIdIsRefused(): void {
		$this->readBackIsASavedPost($this->note('https://victim.example/users/bob/statuses/999'));
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->curlService->method('retrieveObject')->willReturn([
			'id' => 'https://victim.example/users/bob/statuses/999',
			'type' => 'Note',
			'attributedTo' => 'https://victim.example/users/bob',
			'url' => 'https://evil.example/w/x',
		]);

		$this->assertNull($this->service->resolveStatus('https://evil.example/w/x'));
	}

	/** This instance's own posts are held here; a fetched one under a local id is a forgery. */
	public function testAFetchedDocumentWithALocalIdIsRefused(): void {
		$this->readBackIsASavedPost($this->note('https://cloud.example/apps/social/@alice/1'));
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->curlService->method('retrieveObject')->willReturn([
			'id' => 'https://cloud.example/apps/social/@alice/1',
			'type' => 'Note',
			'attributedTo' => 'https://cloud.example/apps/social/users/alice',
			'url' => 'https://cloud.example/s/share/download',
		]);

		$this->assertNull($this->service->resolveStatus('https://cloud.example/s/share/download'));
	}

	public function testSomethingThatIsNotAPostIsRefused(): void {
		$this->readBackIsASavedPost($this->note('https://remote.example/users/bob'));
		$this->curlService->method('retrieveObject')->willReturn([
			'id' => 'https://remote.example/users/bob',
			'type' => 'Person',
		]);

		$this->assertNull($this->service->resolveStatus('https://remote.example/users/bob'));
	}

	public function testAServerThatCannotBeReachedIsAnEmptyAnswerAndNotAFailure(): void {
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->curlService->method('retrieveObject')
			->willThrowException(new \Exception('unreachable'));

		$this->assertNull($this->service->resolveStatus('https://remote.example/notes/1'));
	}

	protected function setUp(): void {
		$this->installActivityPub();
		// importing a Note reaches the container for the hashtag links it builds
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
		$this->curlService = $this->createMock(CurlService::class);
		$this->signatureService = $this->createStub(SignatureService::class);
		$this->signatureService->method('isLocalActor')
			->willReturnCallback(static fn (string $id): bool => str_starts_with($id, 'https://cloud.example/'));
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->service = new SearchService(
			$this->cacheActorService,
			$this->hashtagService,
			$this->streamRequest,
			new NullLogger(),
			$this->curlService,
			$this->signatureService
		);
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	public function testSearchUriResolvesAnActorUrl(): void {
		$bob = new Person();
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with('https://remote.example/users/bob')
			->willReturn($bob);

		$this->assertSame([$bob], $this->service->searchUri('https://remote.example/users/bob'));
	}

	/**
	 * The search bar of Nextcloud asks once per debounced prefix of what is
	 * typed, and every prefix of a URL is a URL: a lookup that may fetch
	 * would be one connect attempt per keystroke inside the request. So it
	 * answers from the cache, and hands what it does not know to the
	 * background fetch queue, which a later search then finds.
	 */
	public function testACacheOnlySearchNeverFetchesAndQueuesWhatItDoesNotKnow(): void {
		$queue = $this->createMock(RemoteFetchQueue::class);
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $this->streamRequest, new NullLogger(), $this->curlService, $this->signatureService,
			null, $queue
		);
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->cacheActorService->method('getCachedFromId')->willThrowException(new CacheActorDoesNotExistException());
		$queue->expects($this->once())->method('resolveActors')->with(['https://remote.example/users/bob']);

		$this->assertSame([], $service->searchUri('https://remote.example/users/bob', false));
	}

	/**
	 * Nextcloud's own search bar is asked about every search in every app:
	 * an address typed there is not sent anywhere, not even later.
	 */
	public function testASearchThatMayNotQueueAsksNoOtherServer(): void {
		$queue = $this->createMock(RemoteFetchQueue::class);
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $this->streamRequest, new NullLogger(), $this->curlService, $this->signatureService,
			null, $queue
		);
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->cacheActorService->method('getCachedFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->cacheActorService->method('searchCachedAccounts')->willReturn([]);
		$queue->expects($this->never())->method('resolveActors');
		$queue->expects($this->never())->method('resolveAccounts');

		$this->assertSame([], $service->searchUri('https://remote.example/users/bob', false, false));
		$this->assertSame([], $service->searchAccounts('alice@customer.example', 5, '', false, false));
	}

	public function testACacheOnlySearchAnswersACachedActorWithoutQueueing(): void {
		$queue = $this->createMock(RemoteFetchQueue::class);
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $this->streamRequest, new NullLogger(), $this->curlService, $this->signatureService,
			null, $queue
		);
		$bob = new Person();
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->cacheActorService->method('getCachedFromId')
			->with('https://remote.example/users/bob')->willReturn($bob);
		$queue->expects($this->never())->method('resolveActors');

		$this->assertSame([$bob], $service->searchUri('https://remote.example/users/bob', false));
	}

	public function testACacheOnlySearchDoesNotOfferALimitedBlueskyAccount(): void {
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $this->streamRequest, new NullLogger(), $this->curlService, $this->signatureService,
			null, $this->createMock(RemoteFetchQueue::class)
		);
		$limited = new Person();
		$limited->setDetailArray(ActorMapper::DETAIL, ['limited' => true]);
		$this->cacheActorService->method('getCachedFromId')->willReturn($limited);

		$this->assertSame([], $service->searchUri('https://remote.example/users/hidden', false));
	}

	public function testSearchUriSwallowsResolutionFailures(): void {
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertSame([], $this->service->searchUri('https://remote.example/users/nobody'));
	}

	/** @return array<string, array{string}> */
	public static function nonUriSearchProvider(): array {
		return [
			'empty' => [''],
			'account' => ['@bob@remote.example'],
			'hashtag' => ['#nextcloud'],
		];
	}

	#[DataProvider('nonUriSearchProvider')]
	public function testSearchUriIgnoresNonUriSearches(string $search): void {
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertSame([], $this->service->searchUri($search));
	}

	public function testSearchAccountsCachesTheExactMatchThenSearchesTheCache(): void {
		$bob = new Person();
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')
			->with('bob@remote.example');
		$this->cacheActorService->expects($this->once())
			->method('searchCachedAccounts')
			->with('bob@remote.example')
			->willReturn([$bob]);

		$this->assertSame([$bob], $this->service->searchAccounts('@bob@remote.example', null, '', true));
	}

	public function testSearchAccountsStillSearchesTheCacheWhenTheExactLookupFails(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->cacheActorService->expects($this->once())
			->method('searchCachedAccounts')
			->with('bob')
			->willReturn([]);

		$this->assertSame([], $this->service->searchAccounts('bob', null, '', true));
	}

	/**
	 * Mastodon's `resolve=false`, which a client's autocomplete sends once
	 * per keystroke: no other server is asked, neither by WebFinger nor by
	 * Bluesky's typeahead. A whole handle not held here is queued, so a
	 * search a little later finds it.
	 */
	public function testWithoutResolveNoOtherServerIsAskedAndAWholeHandleIsQueued(): void {
		$queue = $this->createMock(RemoteFetchQueue::class);
		$bluesky = $this->createMock(BlueskySearch::class);
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $this->streamRequest, new NullLogger(), $this->curlService, $this->signatureService,
			$bluesky, $queue
		);
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->curlService->expects($this->never())->method($this->anything());
		$bluesky->expects($this->never())->method('typeahead');
		$bluesky->expects($this->never())->method('keep');
		$this->cacheActorService->method('searchCachedAccounts')->willReturn([]);
		$queue->expects($this->once())->method('resolveAccounts')->with(['bob@remote.example']);

		$this->assertSame([], $service->searchAccounts('@bob@remote.example'));
	}

	/** @return array<string, array{string}> */
	public static function partialHandleProvider(): array {
		return [
			'a name' => ['bob'],
			'no host yet' => ['bob@'],
			'a host without a dot' => ['bob@mastodon'],
			'a host still being typed' => ['bob@mastodon.s'],
		];
	}

	/** Every keystroke of a handle is a search; only a whole one is worth a job. */
	#[DataProvider('partialHandleProvider')]
	public function testWithoutResolveTheStartOfAHandleIsNotQueued(string $search): void {
		$queue = $this->createMock(RemoteFetchQueue::class);
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $this->streamRequest, new NullLogger(), $this->curlService, $this->signatureService,
			null, $queue
		);
		$this->cacheActorService->method('searchCachedAccounts')->willReturn([]);
		$queue->expects($this->never())->method('resolveAccounts');

		$service->searchAccounts($search);
	}

	public function testWithoutResolveAHandleHeldHereIsNotQueued(): void {
		$queue = $this->createMock(RemoteFetchQueue::class);
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $this->streamRequest, new NullLogger(), $this->curlService, $this->signatureService,
			null, $queue
		);
		$bob = new Person();
		$bob->setAccount('Bob@Remote.example');
		$this->cacheActorService->method('searchCachedAccounts')->willReturn([$bob]);
		$queue->expects($this->never())->method('resolveAccounts');

		$this->assertSame([$bob], $service->searchAccounts('bob@remote.example'));
	}

	/**
	 * With `resolve` the typeahead's accounts are stored, since the answer
	 * hands a client an id for each.
	 */
	public function testWithResolveTheTypeaheadsAccountsAreKept(): void {
		$bluesky = $this->createMock(BlueskySearch::class);
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $this->streamRequest, new NullLogger(), $this->curlService, $this->signatureService,
			$bluesky
		);
		$found = (new Person())->setId('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz');
		$kept = (new Person())->setId($found->getId())->setNid(42);
		$this->cacheActorService->method('searchCachedAccounts')->willReturn([]);
		$bluesky->expects($this->once())->method('typeahead')->with('alice.b')->willReturn([$found]);
		$bluesky->expects($this->once())->method('keep')->with([$found])->willReturn([$kept]);

		$this->assertSame([$kept], $service->searchAccounts('alice.b', 8, '', true));
	}

	/** Nobody here follows an account this instance has never seen. */
	public function testSearchingFollowedAccountsNarrowsTheCacheSearchAndFetchesNothing(): void {
		$bob = new Person();
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->cacheActorService->expects($this->once())
			->method('searchCachedAccounts')
			->with('bob', 8, 'https://cloud.example/users/alice')
			->willReturn([$bob]);

		$this->assertSame([$bob], $this->service->searchAccounts('@bob', 8, 'https://cloud.example/users/alice'));
	}

	public function testSearchAccountsWithEmptySearchDoesNothing(): void {
		$this->cacheActorService->expects($this->never())->method($this->anything());

		$this->assertSame([], $this->service->searchAccounts(''));
	}

	public function testSearchHashtagsStripsTheHashSign(): void {
		$this->hashtagService->expects($this->once())
			->method('searchHashtags')
			->with('nextcloud', true)
			->willReturn([['hashtag' => '#nextcloud']]);

		$this->assertSame([['hashtag' => '#nextcloud']], $this->service->searchHashtags('#nextcloud'));
	}

	public function testSearchHashtagsAcceptsFreeText(): void {
		$this->hashtagService->expects($this->once())
			->method('searchHashtags')
			->with('next', true)
			->willReturn([]);

		$this->assertSame([], $this->service->searchHashtags('next'));
	}

	public function testSearchHashtagsWithEmptySearchDoesNothing(): void {
		$this->hashtagService->expects($this->never())->method('searchHashtags');

		$this->assertSame([], $this->service->searchHashtags(''));
	}

	public function testSearchStreamContentIsNotImplemented(): void {
		$this->assertSame([], $this->service->searchStreamContent('anything'));
		$this->assertSame([], $this->service->searchStreamContent(''));
	}

	public function testAContentSearchHandsTheNarrowingAndThePageToTheQuery(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->expects($this->once())->method('searchContent')
			->with('fox', 10, 20, 'https://cloud.example/users/alice', '1791284215592836068', 5)
			->willReturn([]);
		$service = new SearchService(
			$this->cacheActorService, $this->hashtagService, $streamRequest, new NullLogger(), $this->curlService, $this->signatureService
		);

		$service->searchStreamContent('fox', 10, 20, 'https://cloud.example/users/alice', '1791284215592836068', 5);
	}

	public function testSearchAccountsIgnoresAHashtagSearch(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->cacheActorService->expects($this->never())->method('searchCachedAccounts');

		$this->assertSame([], $this->service->searchAccounts('#nextcloud'));
	}

	public function testSearchHashtagsIgnoresAnAccountSearch(): void {
		$this->hashtagService->expects($this->never())->method('searchHashtags');

		$this->assertSame([], $this->service->searchHashtags('@bob@remote.example'));
	}
}
