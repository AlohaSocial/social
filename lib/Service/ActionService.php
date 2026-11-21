<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Publisher\BlueskyBookmarks;
use OCA\Social\Atproto\Publisher\BlueskyThreadMutes;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;
use Psr\Container\ContainerInterface;

class ActionService {
	use TStringTools;

	private const FAVOURITE = 'favourite';
	private const UNFAVOURITE = 'unfavourite';
	private const REBLOG = 'reblog';
	private const UNREBLOG = 'unreblog';
	private const BOOKMARK = 'bookmark';
	private const UNBOOKMARK = 'unbookmark';
	private const MUTE = 'mute';
	private const UNMUTE = 'unmute';
	private const PIN = 'pin';
	private const UNPIN = 'unpin';
	/** PeerTube's other counter; only a video can carry one. */
	private const DISLIKE = 'dislike';
	private const UNDISLIKE = 'undislike';
	/** the author of a thread hiding a reply in it, or showing it again */
	private const HIDE_REPLY = 'hide_reply';
	private const SHOW_REPLY = 'unhide_reply';

	private static array $availableStatusAction = [
		self::FAVOURITE,
		self::UNFAVOURITE,
		self::REBLOG,
		self::UNREBLOG,
		self::BOOKMARK,
		self::UNBOOKMARK,
		self::HIDE_REPLY,
		self::SHOW_REPLY,
		self::MUTE,
		self::UNMUTE,
		self::PIN,
		self::UNPIN,
		self::DISLIKE,
		self::UNDISLIKE,
	];

	public function __construct(
		private StreamService $streamService,
		private BoostService $boostService,
		private LikeService $likeService,
		private StreamActionService $streamActionService,
		private PinService $pinService,
		private ActionsRequest $actionsRequest,
		private ConversationsRequest $conversationsRequest,
		private DislikeService $dislikeService,
		private InterestService $interestService,
		private IRecommendationSignalRecorder $recommendationSignalService,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * The accounts that favourited or boosted a post, newest first: Mastodon's
	 * `favourited_by` and `reblogged_by`. The caller resolved the post through
	 * the visibility filter, so whoever may read it may see who reacted, as on
	 * Mastodon. Actors never cached are left out.
	 *
	 * @return Person[]
	 */
	public function reactedBy(Stream $post, string $type, int $limit): array {
		$accounts = [];
		foreach ($this->actionsRequest->getActionsOnObject($post->getId(), $type, $limit) as $action) {
			if (!$action->hasActor()) {
				continue;
			}

			$actor = $action->getActor();
			$actor->setExportFormat(ACore::FORMAT_LOCAL);
			$accounts[] = $actor;
		}

		return $accounts;
	}

	/**
	 * Stops, or restarts, the notifications a thread produces for one account;
	 * the posts stay on the timelines. Recorded against the thread's root, so it
	 * covers replies that arrive later.
	 */
	private function muteConversation(Person $actor, Stream $post, bool $muted): void {
		$rootId = $this->conversationsRequest->rootOf($post->getId());
		$this->conversationsRequest->setMuted(
			$actor->getId(),
			$rootId,
			$muted
		);
		$threadMutes = $this->container?->get(BlueskyThreadMutes::class);
		if ($threadMutes instanceof BlueskyThreadMutes) {
			$threadMutes->fromSocial($actor, $rootId, $muted);
		}
	}

	/**
	 * Refuses an interaction the post's author has said no to in its
	 * `interactionPolicy` (GoToSocial, Mastodon 4.5, Loops): no reply, boost or
	 * like. Only a peer's statement about its own post counts; local posts and
	 * posts without a policy are untouched.
	 *
	 * @throws InvalidActionException
	 */
	private function assertAllowedByAuthor(Stream $post, string $action, string $actorId): void {
		$interaction = match ($action) {
			self::FAVOURITE => Stream::INTERACTION_LIKE,
			self::REBLOG => Stream::INTERACTION_BOOST,
			default => '',
		};

		// taking one back is always allowed: the author's policy is about what
		// may be sent, and an Undo is how this instance stops having sent it
		if ($interaction === '' || $post->allowsInteraction($interaction, $actorId)) {
			return;
		}

		throw new InvalidActionException(
			'the author of this post does not allow it to be '
			. ($interaction === Stream::INTERACTION_LIKE ? 'favourited' : 'boosted')
		);
	}

	/**
	 * Applies a status action to a post as $actor.
	 *
	 * @param int|string $nid
	 * @param string $action
	 *
	 * @return Stream|null the post for a pin or unpin, null otherwise
	 * @throws InvalidActionException
	 */
	public function action(Person $actor, int|string $nid, string $action): ?Stream {
		if (!in_array($action, self::$availableStatusAction, true)) {
			throw new InvalidActionException();
		}

		$post = $this->streamService->getStreamByNid($nid);
		$this->assertAllowedByAuthor($post, $action, $actor->getId());
		$previousState = match ($action) {
			self::FAVOURITE, self::UNFAVOURITE => $this->streamActionService->getActionBool($actor->getId(), $post->getId(), StreamAction::LIKED),
			self::REBLOG, self::UNREBLOG => $this->streamActionService->getActionBool($actor->getId(), $post->getId(), StreamAction::BOOSTED),
			self::BOOKMARK, self::UNBOOKMARK => $this->streamActionService->getActionBool($actor->getId(), $post->getId(), StreamAction::BOOKMARKED),
			default => false,
		};

		switch ($action) {
			case self::FAVOURITE:
				$this->favourite($actor, $post->getId());
				$this->interestService->recordAction($actor, $post, InterestService::ACTION_FAVOURITE);
				if (!$previousState) {
					$this->recommendationSignalService->recordAction($actor, $post, 'like');
				}
				break;

			case self::UNFAVOURITE:
				$this->favourite($actor, $post->getId(), false);
				if ($previousState) {
					$this->recommendationSignalService->recordAction($actor, $post, 'unlike');
				}
				break;

			case self::REBLOG:
				$this->reblog($actor, $post->getId());
				$this->interestService->recordAction($actor, $post, InterestService::ACTION_BOOST);
				if (!$previousState) {
					$this->recommendationSignalService->recordAction($actor, $post, 'boost');
				}
				break;

			case self::UNREBLOG:
				$this->reblog($actor, $post->getId(), false);
				if ($previousState) {
					$this->recommendationSignalService->recordAction($actor, $post, 'unboost');
				}
				break;

			case self::BOOKMARK:
				$this->bookmark($actor, $post->getId());
				$this->interestService->recordAction($actor, $post, InterestService::ACTION_BOOKMARK);
				if (!$previousState) {
					$this->recommendationSignalService->recordAction($actor, $post, 'save');
				}
				break;

			case self::UNBOOKMARK:
				$this->bookmark($actor, $post->getId(), false);
				if ($previousState) {
					$this->recommendationSignalService->recordAction($actor, $post, 'unsave');
				}
				break;

			case self::DISLIKE:
				$this->dislikeService->create($actor, $post->getId());
				break;

			case self::HIDE_REPLY:
			case self::SHOW_REPLY:
				$hidden = $this->container?->get(HiddenReplyService::class);
				if ($hidden instanceof HiddenReplyService) {
					$hidden->setHidden($actor, $post, $action === self::HIDE_REPLY);
				}
				break;

			case self::UNDISLIKE:
				$this->dislikeService->delete($actor, $post->getId());
				break;

			case self::PIN:
				return $this->pinService->pin($actor, $nid);
			case self::UNPIN:
				return $this->pinService->unpin($actor, $nid);
			case self::MUTE:
			case self::UNMUTE:
				$this->muteConversation($actor, $post, $action === self::MUTE);
				break;
		}

		return null;
	}

	/**
	 * `translate` is `StatusApiController::statusTranslate()`, which answers with
	 * a Translation entity and needs a translation provider.
	 */

	private function favourite(Person $actor, string $postId, bool $enabled = true): void {
		if ($enabled) {
			$this->likeService->create($actor, $postId);
		} else {
			$this->likeService->delete($actor, $postId);
		}
	}

	private function reblog(Person $actor, string $postId, bool $enabled = true): void {
		if ($enabled) {
			$this->boostService->create($actor, $postId);
		} else {
			$this->boostService->delete($actor, $postId);
		}
	}

	/**
	 * Bookmarks are a per-viewer flag (as on Mastodon) — nothing is federated.
	 * A post that is on Bluesky is bookmarked there too, privately, so a
	 * Bluesky app signed in here shows the same bookmark (`BlueskyBookmarks`).
	 */
	private function bookmark(Person $actor, string $postId, bool $enabled = true): void {
		$this->streamActionService->setActionBool(
			$actor->getId(), $postId, StreamAction::BOOKMARKED, $enabled
		);
		$bookmarks = $this->container?->get(BlueskyBookmarks::class);
		if ($bookmarks instanceof BlueskyBookmarks) {
			$bookmarks->bookmarked($actor, $postId, $enabled);
		}
	}
}
