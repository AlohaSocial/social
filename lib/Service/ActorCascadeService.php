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
use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\DomainBlocksRequest;
use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\InterestsRequest;
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Everything one account leaves behind, in one list.
 *
 * There were two of these lists — one in `PersonInterface::delete()` for an
 * account that is gone, one in `ModerationService::purgeActor()` for one a
 * moderator has suspended — and they had drifted in both directions: a
 * suspended account went on holding likes and boosts that counted on other
 * people's posts, on filing reports and on being named in other people's
 * pictures, while a deleted one left its domain blocks, its notes, its albums,
 * its stories and half a dozen newer tables behind. The rule is the one the
 * older comment already stated and could not enforce: whichever list was
 * forgotten is the row that outlives the account.
 *
 * So there is one list, and a table added tomorrow has one place to be
 * registered. The posts themselves are not in it: a deletion rewrites what
 * addressed the account and may have to finish in a job (see
 * `PersonInterface::deleteStreamFromActor()`), which a suspension does not do,
 * and that is the only part of the two paths that legitimately differs.
 *
 * Every step is caught on its own and none depends on an earlier one, so
 * running it twice on the same account is a no-op rather than a failure, and
 * one table that cannot be written does not leave the rest behind.
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
		private AnnouncementsRequest $announcementsRequest,
		private ScheduledStatusesRequest $scheduledStatusesRequest,
		private CollectionsRequest $collectionsRequest,
		private StoriesRequest $storiesRequest,
		private StoryInteractionsRequest $storyInteractionsRequest,
		private ImportedPostsRequest $importedPostsRequest,
		private PostHoldsRequest $postHoldsRequest,
		private MediaTagsRequest $mediaTagsRequest,
		private PortfoliosRequest $portfoliosRequest,
		private WatchRequest $watchRequest,
		private RequestQueueRequest $requestQueueRequest,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private CacheDocumentService $cacheDocumentService,
		private AtprotoCascade $atproto,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $actorId the account
	 * @param bool $reversible a suspension rather than a deletion: the account
	 *                         can be let back in, so the rows other accounts
	 *                         own are left to them
	 */
	public function purge(string $actorId, bool $reversible = false): void {
		foreach ($this->steps($actorId, $reversible) as $what => $delete) {
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
	private function steps(string $actorId, bool $reversible): array {
		$steps = [
			// its Bluesky identity: the DID is tombstoned on a deletion and
			// left alone on a suspension, which can be lifted
			'atproto' => fn () => $this->atproto->purge($actorId, $reversible),
			// what it did to other people's posts: its likes and boosts, its
			// bookmarks and poll votes, its emoji reactions, and what it has
			// watched — all of which went on counting on posts that are not
			// its own
			'actions' => fn () => $this->actionsRequest->deleteByActor($actorId),
			'reactions' => fn () => $this->reactionsRequest->deleteByActor($actorId),
			'streamActions' => fn () => $this->streamActionsRequest->deleteByActor($actorId),
			'streamViews' => fn () => $this->streamViewsRequest->deleteByActor($actorId),
			'watch' => fn () => $this->watchRequest->deleteRelatedId($actorId),
			'storyInteractions' => fn () => $this->storyInteractionsRequest->deleteByActor($actorId),
			// both directions of the follow relationship: what it followed and
			// what followed it, which is what keeps an account in the delivery
			// fan-out and in other people's timelines
			'follows' => fn () => $this->followsRequest->deleteRelatedId($actorId),
			// the blocks and mutes it holds, the instances it blocked and the
			// notes it wrote
			'relations' => fn () => $this->actorRelationRequest->deleteRelatedId($actorId),
			'muteExpiry' => fn () => $this->muteExpiryRequest->deleteRelatedId($actorId),
			'domainBlocks' => fn () => $this->domainBlocksRequest->deleteRelatedId($actorId),
			'notes' => fn () => $this->accountNotesRequest->deleteRelatedId($actorId),
			// the reports about it and the ones it filed
			'reports' => fn () => $this->reportsRequest->deleteRelatedId($actorId),
			// what it had made for itself alone: its keyword filters, its
			// lists, how far it had read its conversations, the hashtags it
			// pinned, the announcements it had dismissed
			'filters' => fn () => $this->filtersRequest->deleteRelatedId($actorId),
			'lists' => fn () => $this->listsRequest->deleteRelatedId($actorId),
			'conversations' => fn () => $this->conversationsRequest->deleteRelatedId($actorId),
			'featuredTags' => fn () => $this->featuredTagsRequest->deleteRelatedId($actorId),
			// what For you learned about it, and the posts it hid there
			'interests' => fn () => $this->interestsRequest->deleteRelatedId($actorId),
			'announcements' => fn () => $this->announcementsRequest->deleteRelatedId($actorId),
			// the posts it had asked to have published later, which are the one
			// thing here that would otherwise go *out* under an account nothing
			// is serving any more
			'scheduled' => fn () => $this->scheduledStatusesRequest->deleteRelatedId($actorId),
			// the albums and the portfolio it curated, which are pages of its
			// own posts, and its live stories, which must not outlive it
			'collections' => fn () => $this->collectionsRequest->deleteRelatedId($actorId),
			'portfolios' => fn () => $this->portfoliosRequest->deleteByActor($actorId),
			'stories' => fn () => $this->storiesRequest->deleteRelatedId($actorId),
			// where it is named in somebody else's picture
			'mediaTags' => fn () => $this->mediaTagsRequest->deleteByActor($actorId),
			// the memory of what it brought over from another server, which
			// names posts that go with it
			'imported' => fn () => $this->importedPostsRequest->deleteByActor($actorId),
			// and anything of its own still waiting for a moderator: nobody is
			// going to approve the unpublished posts of an account that is not
			// there, and leaving them would leave a queue of decisions that
			// cannot be taken
			'held' => fn () => $this->postHoldsRequest->deleteByActor($actorId),
			// deliveries still queued towards it
			'queue' => fn () => $this->requestQueueRequest->deleteByAuthor($actorId),
			// its cached avatar and header: the files first, because the rows
			// are the only thing that remembers their names
			'documents' => fn () => $this->deleteDocuments($actorId),
			// a local account has no cached copy; deleting one that is not
			// there is not a failure
			'cachedActor' => fn () => $this->cacheActorsRequest->deleteCacheById($actorId),
		];

		if (!$reversible) {
			return $steps;
		}

		// A suspension can be lifted, and what it takes it never gives back.
		// So it takes only what belongs to the account: a block or a mute
		// somebody else holds over it is theirs, and so is a note they wrote
		// about it or a report they filed — none of which a decision about
		// this account may quietly undo.
		$steps['relations'] = fn () => $this->actorRelationRequest->deleteByActor($actorId);
		$steps['muteExpiry'] = fn () => $this->muteExpiryRequest->deleteByActor($actorId);
		unset($steps['notes'], $steps['reports']);

		return $steps;
	}

	/**
	 * The files of the cached documents hanging off an actor, then their rows.
	 *
	 * A file that cannot be removed is not a reason to keep the row: the row
	 * goes, and the file is what `occ social:media:usage` reports.
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
