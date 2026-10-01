<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\AccountNotesRequest;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\AnnouncementsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\DomainBlocksRequest;
use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Db\PortfoliosRequest;
use OCA\Social\Db\PostHoldsRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\ReportsRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\ScheduledStatusesRequest;
use OCA\Social\Db\StoriesRequest;
use OCA\Social\Db\StoryInteractionsRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamViewsRequest;
use OCA\Social\Db\WatchRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\ActorCascadeService;
use OCA\Social\Service\CacheDocumentService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The one list of what an account leaves behind.
 *
 * It is one list because it used to be two — the deletion path and the
 * suspension path — and they had drifted: a suspended account kept its likes
 * and boosts on other people's posts, a deleted one kept its domain blocks,
 * albums and stories. The table below is what both entry points now work from,
 * and what stops the next table added from being registered in one place only.
 */
#[AllowMockObjectsWithoutExpectations]
class ActorCascadeServiceTest extends TestCase {
	private const BOB = 'https://spam.example/users/bob';

	/** @var array<class-string, MockObject> */
	private array $mocks = [];
	private CacheDocumentService|Stub $cacheDocumentService;

	/**
	 * Every table the cascade clears, as `class => [method, argument]`.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	private static function tables(): array {
		return [
			'likes and boosts' => [ActionsRequest::class, 'deleteByActor'],
			'emoji reactions' => [ReactionsRequest::class, 'deleteByActor'],
			'bookmarks, favourites and votes' => [StreamActionsRequest::class, 'deleteByActor'],
			'what it opened' => [StreamViewsRequest::class, 'deleteByActor'],
			'where it stopped watching' => [WatchRequest::class, 'deleteRelatedId'],
			'what it said about a story' => [StoryInteractionsRequest::class, 'deleteByActor'],
			'follows either way' => [FollowsRequest::class, 'deleteRelatedId'],
			'blocks and mutes' => [ActorRelationRequest::class, 'deleteRelatedId'],
			'mute expiries' => [MuteExpiryRequest::class, 'deleteRelatedId'],
			'domain blocks' => [DomainBlocksRequest::class, 'deleteRelatedId'],
			'account notes' => [AccountNotesRequest::class, 'deleteRelatedId'],
			'reports' => [ReportsRequest::class, 'deleteRelatedId'],
			'keyword filters' => [FiltersRequest::class, 'deleteRelatedId'],
			'lists' => [ListsRequest::class, 'deleteRelatedId'],
			'conversation state' => [ConversationsRequest::class, 'deleteRelatedId'],
			'featured tags' => [FeaturedTagsRequest::class, 'deleteRelatedId'],
			'what For you learned, and the posts it hid' => [\OCA\Social\Db\InterestsRequest::class, 'deleteRelatedId'],
			'dismissed announcements' => [AnnouncementsRequest::class, 'deleteRelatedId'],
			'scheduled statuses' => [ScheduledStatusesRequest::class, 'deleteRelatedId'],
			'collections' => [CollectionsRequest::class, 'deleteRelatedId'],
			'portfolio' => [PortfoliosRequest::class, 'deleteByActor'],
			'stories' => [StoriesRequest::class, 'deleteRelatedId'],
			'media tags' => [MediaTagsRequest::class, 'deleteByActor'],
			'imported posts' => [ImportedPostsRequest::class, 'deleteByActor'],
			'posts held for a moderator' => [PostHoldsRequest::class, 'deleteByActor'],
			'queued deliveries' => [RequestQueueRequest::class, 'deleteByAuthor'],
			'cached documents' => [CacheDocumentsRequest::class, 'deleteByParent'],
			'the cached actor' => [CacheActorsRequest::class, 'deleteCacheById'],
		];
	}

	protected function setUp(): void {
		$this->cacheDocumentService = $this->createStub(CacheDocumentService::class);
		foreach (self::tables() as [$class, $method]) {
			$this->mocks[$class] ??= $this->createMock($class);
		}
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 *
	 * @return T&MockObject
	 */
	private function request(string $class): MockObject {
		return $this->mocks[$class];
	}

	private function service(?LoggerInterface $logger = null): ActorCascadeService {
		return new ActorCascadeService(
			$this->request(ActionsRequest::class),
			$this->request(ReactionsRequest::class),
			$this->request(StreamActionsRequest::class),
			$this->request(StreamViewsRequest::class),
			$this->request(FollowsRequest::class),
			$this->request(ActorRelationRequest::class),
			$this->request(MuteExpiryRequest::class),
			$this->request(DomainBlocksRequest::class),
			$this->request(AccountNotesRequest::class),
			$this->request(ReportsRequest::class),
			$this->request(FiltersRequest::class),
			$this->request(ListsRequest::class),
			$this->request(ConversationsRequest::class),
			$this->request(FeaturedTagsRequest::class),
			$this->request(\OCA\Social\Db\InterestsRequest::class),
			$this->request(AnnouncementsRequest::class),
			$this->request(ScheduledStatusesRequest::class),
			$this->request(CollectionsRequest::class),
			$this->request(StoriesRequest::class),
			$this->request(StoryInteractionsRequest::class),
			$this->request(ImportedPostsRequest::class),
			$this->request(PostHoldsRequest::class),
			$this->request(MediaTagsRequest::class),
			$this->request(PortfoliosRequest::class),
			$this->request(WatchRequest::class),
			$this->request(RequestQueueRequest::class),
			$this->request(CacheDocumentsRequest::class),
			$this->request(CacheActorsRequest::class),
			$this->cacheDocumentService,
			$logger ?? new NullLogger(),
		);
	}

	public function testADeletedAccountLeavesNothingBehindInAnyOfThem(): void {
		foreach (self::tables() as [$class, $method]) {
			$this->request($class)->expects($this->once())->method($method)->with(self::BOB);
		}

		$this->service()->purge(self::BOB);
	}

	/**
	 * A suspension is not a deletion: it can be lifted, and what it takes it
	 * never gives back. So the relations other accounts hold *over* the
	 * suspended one stay — a block silently undone by a moderator's decision
	 * about somebody else is a block the person who made it is never told
	 * about.
	 */
	public function testASuspensionTakesOnlyTheRelationsTheAccountItselfHolds(): void {
		$this->request(ActorRelationRequest::class)
			->expects($this->once())->method('deleteByActor')->with(self::BOB);
		$this->request(ActorRelationRequest::class)
			->expects($this->never())->method('deleteRelatedId');
		$this->request(MuteExpiryRequest::class)
			->expects($this->once())->method('deleteByActor')->with(self::BOB);
		$this->request(MuteExpiryRequest::class)
			->expects($this->never())->method('deleteRelatedId');

		$this->service()->purge(self::BOB, reversible: true);
	}

	/**
	 * The record of why an account was suspended, and what people had written
	 * about it, are not the account's to take with it.
	 */
	public function testASuspensionKeepsTheReportsAndTheNotesOtherPeopleWrote(): void {
		$this->request(ReportsRequest::class)->expects($this->never())->method('deleteRelatedId');
		$this->request(AccountNotesRequest::class)->expects($this->never())->method('deleteRelatedId');

		$this->service()->purge(self::BOB, reversible: true);
	}

	/** Everything else a suspension does take is what a deletion takes. */
	public function testASuspensionClearsEveryOtherTableADeletionDoes(): void {
		$spared = [ReportsRequest::class, AccountNotesRequest::class];
		$rewritten = [ActorRelationRequest::class, MuteExpiryRequest::class];

		foreach (self::tables() as [$class, $method]) {
			if (in_array($class, $spared, true) || in_array($class, $rewritten, true)) {
				continue;
			}

			$this->request($class)->expects($this->once())->method($method)->with(self::BOB);
		}

		$this->service()->purge(self::BOB, reversible: true);
	}

	public function testOneTableThatCannotBeWrittenDoesNotStopTheRest(): void {
		$this->request(ActionsRequest::class)->method('deleteByActor')
			->willThrowException(new \RuntimeException('database busy'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'could not detach an account',
			$this->callback(static fn (array $context): bool => $context['what'] === 'actions')
		);
		// the account still loses its cached copy, which is the last step
		$this->request(CacheActorsRequest::class)
			->expects($this->once())->method('deleteCacheById')->with(self::BOB);

		$this->service($logger)->purge(self::BOB);
	}

	/**
	 * The files go before the rows: the row is the only thing that remembers
	 * the file's name, so a delete that does not read first leaves the bytes
	 * in appdata for good.
	 */
	public function testTheCachedAvatarFilesGoWithTheirRows(): void {
		$document = new Document();
		$document->setId(self::BOB . '#avatar');
		$document->setLocalCopy('local.jpg');
		$document->setResizedCopy('resized.jpg');
		$this->request(CacheDocumentsRequest::class)->method('getByParent')
			->with(self::BOB)->willReturn([$document]);

		$removed = [];
		$this->cacheDocumentService->method('removeFromCache')
			->willReturnCallback(static function (string $name) use (&$removed): void {
				$removed[] = $name;
			});
		$this->request(CacheDocumentsRequest::class)
			->expects($this->once())->method('deleteByParent')->with(self::BOB);

		$this->service()->purge(self::BOB);

		$this->assertSame(['local.jpg', 'resized.jpg'], $removed);
	}

	/** A file that cannot be removed is not a reason to keep the row. */
	public function testAFileThatCannotBeRemovedStillLosesItsRow(): void {
		$document = new Document();
		$document->setId(self::BOB . '#avatar');
		$document->setLocalCopy('local.jpg');
		$this->request(CacheDocumentsRequest::class)->method('getByParent')->willReturn([$document]);
		$this->cacheDocumentService->method('removeFromCache')
			->willThrowException(new \RuntimeException('appdata is read-only'));

		$this->request(CacheDocumentsRequest::class)
			->expects($this->once())->method('deleteByParent')->with(self::BOB);

		$this->service()->purge(self::BOB);
	}
}
