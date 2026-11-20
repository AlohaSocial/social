<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Service\AtprotoCascade;
use OCA\Social\Db\AccountNotesRequest;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\AnnouncementsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\DeviceAuthorizationRequest;
use OCA\Social\Db\DomainBlocksRequest;
use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Db\FileCommentsRequest;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\ImportsRequest;
use OCA\Social\Db\InterestsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Db\PostHoldsRequest;
use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\RecommendationPreferenceRequest;
use OCA\Social\Db\ReportsRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\ScheduledStatusesRequest;
use OCA\Social\Db\StoriesRequest;
use OCA\Social\Db\StoryInteractionsRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamViewsRequest;
use OCA\Social\Db\TeamsRequest;
use OCA\Social\Db\WatchRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Everything one account leaves behind, in one list shared by deletion
 * (`PersonInterface::delete()`) and suspension
 * (`ModerationService::purgeActor()`), so a new table has one place to be
 * registered. The posts are not in it: a deletion handles them separately
 * (`PersonInterface::deleteStreamFromActor()`).
 *
 * Every step is caught on its own and independent, so running it twice is a
 * no-op and one failing table does not leave the rest behind.
 */
class ActorCascadeService {
	public function __construct(
		private ActionsRequest $actionsRequest,
		private ReactionsRequest $reactionsRequest,
		private StreamActionsRequest $streamActionsRequest,
		private StreamViewsRequest $streamViewsRequest,
		private FollowsRequest $followsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private MuteExpiryRequest $muteExpiryRequest,
		private DomainBlocksRequest $domainBlocksRequest,
		private AccountNotesRequest $accountNotesRequest,
		private ReportsRequest $reportsRequest,
		private FiltersRequest $filtersRequest,
		private ListsRequest $listsRequest,
		private ConversationsRequest $conversationsRequest,
		private FeaturedTagsRequest $featuredTagsRequest,
		private InterestsRequest $interestsRequest,
		private RecommendationPreferenceRequest $recommendationPreferences,
		private AnnouncementsRequest $announcementsRequest,
		private ScheduledStatusesRequest $scheduledStatusesRequest,
		private CollectionsRequest $collectionsRequest,
		private StoriesRequest $storiesRequest,
		private StoryInteractionsRequest $storyInteractionsRequest,
		private ImportedPostsRequest $importedPostsRequest,
		private PostHoldsRequest $postHoldsRequest,
		private MediaTagsRequest $mediaTagsRequest,
		private FollowedTagsRequest $followedTagsRequest,
		private QuoteGrantRequest $quoteGrantRequest,
		private TeamsRequest $teamsRequest,
		private FileCommentsRequest $fileCommentsRequest,
		private ImportsRequest $importsRequest,
		private DeviceAuthorizationRequest $deviceAuthorizations,
		private ChannelsRequest $channelsRequest,
		private WatchRequest $watchRequest,
		private RequestQueueRequest $requestQueueRequest,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private CacheDocumentService $cacheDocumentService,
		private AtprotoCascade $atproto,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * @param string $actorId the account
	 * @param bool $reversible a suspension rather than a deletion: the account
	 *                         can be let back in, so the rows other accounts
	 *                         own are left to them
	 * @param string $userId the Nextcloud user of a local account, for what
	 *                       is kept by user rather than by account
	 */
	public function purge(string $actorId, bool $reversible = false, string $userId = ''): void {
		foreach ($this->steps($actorId, $reversible, $userId) as $what => $delete) {
			try {
				$delete();
			} catch (Throwable $e) {
				$this->logger->error('could not detach an account', [
					'actor' => $actorId, 'what' => $what, 'exception' => $e,
				]);
			}
		}
	}

	/**
	 * @return array<string, callable():void>
	 */
	private function steps(string $actorId, bool $reversible, string $userId): array {
		$steps = [
			// Its Bluesky identity: the DID is tombstoned on a deletion and left alone
			// on a suspension, which can be lifted.
			'atproto' => fn () => $this->atproto->purge($actorId, $reversible),
			// What it did to other people's posts: likes, boosts, bookmarks, poll
			// votes, emoji reactions and watch progress.
			'actions' => fn () => $this->actionsRequest->deleteByActor($actorId),
			'reactions' => fn () => $this->reactionsRequest->deleteByActor($actorId),
			'streamActions' => fn () => $this->streamActionsRequest->deleteByActor($actorId),
			'streamViews' => fn () => $this->streamViewsRequest->deleteByActor($actorId),
			'watch' => fn () => $this->watchRequest->deleteRelatedId($actorId),
			'storyInteractions' => fn () => $this->storyInteractionsRequest->deleteByActor($actorId),
			// Both directions of follows, which keep it in fan-outs and timelines.
			'follows' => fn () => $this->followsRequest->deleteRelatedId($actorId),
			// Its blocks and mutes, the instances it blocked and the notes it wrote.
			'relations' => fn () => $this->actorRelationRequest->deleteRelatedId($actorId),
			'muteExpiry' => fn () => $this->muteExpiryRequest->deleteRelatedId($actorId),
			'domainBlocks' => fn () => $this->domainBlocksRequest->deleteRelatedId($actorId),
			'notes' => fn () => $this->accountNotesRequest->deleteRelatedId($actorId),
			// the reports about it and the ones it filed
			'reports' => fn () => $this->reportsRequest->deleteRelatedId($actorId),
			// What it made for itself: filters, lists, conversation read markers,
			// featured hashtags, dismissed announcements.
			'filters' => fn () => $this->filtersRequest->deleteRelatedId($actorId),
			'lists' => fn () => $this->listsRequest->deleteRelatedId($actorId),
			'conversations' => fn () => $this->conversationsRequest->deleteRelatedId($actorId),
			'featuredTags' => fn () => $this->featuredTagsRequest->deleteRelatedId($actorId),
			// what For you learned about it, and the posts it hid there
			'interests' => fn () => $this->interestsRequest->deleteRelatedId($actorId),
			'recommendationPreferences' => function () use ($reversible, $userId): void {
				if (!$reversible && $userId !== '') {
					$this->recommendationPreferences->purge($userId);
				}
			},
			'announcements' => fn () => $this->announcementsRequest->deleteRelatedId($actorId),
			// Scheduled posts, which would otherwise go out under a dead account.
			'scheduled' => fn () => $this->scheduledStatusesRequest->deleteRelatedId($actorId),
			// The albums it curated and its live shorts.
			'collections' => fn () => $this->collectionsRequest->deleteRelatedId($actorId),
			'stories' => fn () => $this->storiesRequest->deleteRelatedId($actorId),
			// Where it is tagged in somebody else's photo.
			'mediaTags' => fn () => $this->mediaTagsRequest->deleteByActor($actorId),
			// The tags it put on photos, whoever they name.
			'mediaTagsMade' => fn () => $this->mediaTagsRequest->deleteByTagger($actorId),
			// the hashtags it followed, as the ones it featured
			'followedTags' => fn () => $this->followedTagsRequest->deleteByActor($actorId),
			// The quote approvals it was given; those about its posts go with the posts
			// (`StreamDeletion::deleteRelatedTo()`).
			'quoteGrants' => fn () => $this->quoteGrantRequest->deleteByActor($actorId),
			// Its channels and its own channel row; the channel accounts are deleted
			// with it (`AccountService::deleteActor()`).
			'channels' => fn () => $this->channelsRequest->deleteRelatedId($actorId),
			// which of a team's posts it wrote; the posts stay the team's
			'teamPosts' => fn () => $this->teamsRequest->deleteByAuthor($actorId),
			// What it posted from Files and its imports, kept by Nextcloud user.
			'filesPosts' => function () use ($userId): void {
				if ($userId !== '') {
					$this->fileCommentsRequest->deleteByUser($userId);
				}
			},
			'imports' => function () use ($userId): void {
				if ($userId !== '') {
					$this->importsRequest->deleteByUser($userId);
				}
			},
			// The record of what it imported from another server.
			'imported' => fn () => $this->importedPostsRequest->deleteByActor($actorId),
			'deviceAuthorizations' => function () use ($userId): void {
				if ($userId !== '') {
					$this->deviceAuthorizations->deleteByUser($userId);
				}
			},
			// Its posts still waiting for a moderator.
			'held' => fn () => $this->postHoldsRequest->deleteByActor($actorId),
			// deliveries still queued towards it
			'queue' => fn () => $this->requestQueueRequest->deleteByAuthor($actorId),
			// Its cached avatar and header: files first, as only the rows know their names.
			'documents' => fn () => $this->deleteDocuments($actorId),
			// A local account has no cached copy; that is not a failure.
			'cachedActor' => fn () => $this->cacheActorsRequest->deleteCacheById($actorId),
			// This instance's verification of it and the Bluesky record of it; resolved
			// lazily because of a service cycle.
			'verification' => fn () => $this->container?->get(VerificationService::class)->unverify($actorId),
		];

		if (!$reversible) {
			return $steps;
		}

		// A suspension can be lifted, so it takes only what belongs to the account:
		// blocks, mutes, notes and reports others hold about it stay theirs.
		$steps['relations'] = fn () => $this->actorRelationRequest->deleteByActor($actorId);
		$steps['muteExpiry'] = fn () => $this->muteExpiryRequest->deleteByActor($actorId);
		// Quote approvals and team post attributions are other accounts' records too.
		unset($steps['notes'], $steps['reports'], $steps['verification'], $steps['quoteGrants'], $steps['teamPosts'], $steps['channels']);

		return $steps;
	}

	/**
	 * The files of the cached documents hanging off an actor, then their rows;
	 * a file that cannot be removed does not keep its row.
	 */
	private function deleteDocuments(string $actorId): void {
		foreach ($this->cacheDocumentsRequest->getByParent($actorId) as $document) {
			try {
				$this->cacheDocumentService->removeFromCache($document->getLocalCopy());
				$this->cacheDocumentService->removeFromCache($document->getResizedCopy());
			} catch (Throwable $e) {
				$this->logger->warning('could not remove the cached copy of ' . $document->getId(), [
					'exception' => $e,
				]);
			}
		}

		$this->cacheDocumentsRequest->deleteByParent($actorId);
	}
}
