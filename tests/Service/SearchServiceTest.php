<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\SearchService;
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
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->service = new SearchService(
			$this->cacheActorService,
			$this->hashtagService,
			$this->streamRequest,
			new NullLogger(),
			$this->curlService
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

		$this->assertSame([$bob], $this->service->searchAccounts('@bob@remote.example'));
	}

	public function testSearchAccountsStillSearchesTheCacheWhenTheExactLookupFails(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->cacheActorService->expects($this->once())
			->method('searchCachedAccounts')
			->with('bob')
			->willReturn([]);

		$this->assertSame([], $this->service->searchAccounts('bob'));
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
