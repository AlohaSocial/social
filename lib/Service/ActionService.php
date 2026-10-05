<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;
use OCA\Social\Service\Atproto\AtprotoEngagementService;

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

	private static array $availableStatusAction = [
		self::FAVOURITE,
		self::UNFAVOURITE,
		self::REBLOG,
		self::UNREBLOG,
		self::BOOKMARK,
		self::UNBOOKMARK,
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
		private ?AtprotoEngagementService $atprotoEngagementService = null,
	) {
	}

	/**
	 * The accounts that favourited or boosted a post, newest first.
	 *
	 * Mastodon's `favourited_by` and `reblogged_by`, and the reason a tap on a
	 * favourite or boost count is not a dead end. The caller has already
	 * resolved the post through the visibility filter, so whoever may read the
	 * post may see who reacted to it — which is Mastodon's rule too.
	 *
	 * An action whose actor this instance never cached is left out rather than
	 * sent half-filled: a page of accounts with no handle and no avatar is one
	 * a client can do nothing with.
	 *
	 * @return Person[]
	 */
	public function reactedBy(Stream $post, string $type, int $limit, int $offset = 0): array {
		$accounts = [];
		foreach ($this->actionsRequest->getActionsOnObject($post->getId(), $type, $limit, $offset) as $action) {
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
	 * should return null
	 * will return Stream only with translate action
	 *
	 * @param int|string $nid
	 * @param string $action
	 *
	 * @return Stream|null
	 * @throws InvalidActionException
	 */
	/**
	 * Stops, or restarts, the notifications a thread produces for one account.
	 *
	 * Mastodon's conversation mute is about being *told*, not about seeing:
	 * the posts stay on the timelines and the notifications stop. The mute is
	 * recorded against the thread's root, so a reply that arrives tomorrow is
	 * covered by a mute taken today — which is the whole point of muting a
	 * conversation rather than a post.
	 */
	private function muteConversation(Person $actor, Stream $post, bool $muted): void {
		$this->conversationsRequest->setMuted(
			$actor->getId(),
			$this->conversationsRequest->rootOf($post->getId()),
			$muted
		);
	}

	/**
	 * Refuses an interaction the post's own author has said no to.
	 *
	 * GoToSocial defined `interactionPolicy`, Mastodon 4.5 reads it and Loops
	 * publishes it: an author can state that their post may not be replied to,
	 * boosted or liked. This app read only the quote clause, so the other
	 * three were offered here and refused by the author's server afterwards —
	 * the reader was told their boost went out, and it did, and nothing ever
	 * showed it.
	 *
	 * Only what a peer stated about their own post is refused. A post from
	 * here is this instance's to decide about when the interaction arrives,
	 * and a post whose server publishes no policy — which is most of them — is
	 * untouched.
	 *
	 * @throws InvalidActionException
	 */
	private function assertAllowedByAuthor(Stream $post, string $action): void {
		$interaction = match ($action) {
			self::FAVOURITE => Stream::INTERACTION_LIKE,
			self::REBLOG => Stream::INTERACTION_BOOST,
			default => '',
		};

		// taking one back is always allowed: the author's policy is about what
		// may be sent, and an Undo is how this instance stops having sent it
		if ($interaction === '' || $post->allowsInteraction($interaction)) {
			return;
		}

		throw new InvalidActionException(
			'the author of this post does not allow it to be '
			. ($interaction === Stream::INTERACTION_LIKE ? 'favourited' : 'boosted')
		);
	}

	public function action(Person $actor, int|string $nid, string $action): ?Stream {
		if (!in_array($action, self::$availableStatusAction, true)) {
			throw new InvalidActionException();
		}

		$post = $this->streamService->getStreamByNid($nid);
		$this->assertAllowedByAuthor($post, $action);
		if ($this->atprotoEngagementService !== null
			&& str_contains($post->getId(), '/ap/bluesky/')
			&& in_array($action, [self::FAVOURITE, self::UNFAVOURITE, self::REBLOG, self::UNREBLOG], true)) {
			$isLike = in_array($action, [self::FAVOURITE, self::UNFAVOURITE], true);
			$enabled = in_array($action, [self::FAVOURITE, self::REBLOG], true);
			if ($isLike) {
				$this->atprotoEngagementService->setLiked(
					$actor->getUserId(), $post, $enabled
				);
			} else {
				$this->atprotoEngagementService->setReposted(
					$actor->getUserId(), $post, $enabled
				);
			}
			$this->streamActionService->setActionBool(
				$actor->getId(), $post->getId(), $isLike ? StreamAction::LIKED : StreamAction::BOOSTED, $enabled
			);
			return null;
		}

		switch ($action) {
			case self::FAVOURITE:
				$this->favourite($actor, $post->getId());
				$this->interestService->recordAction($actor, $post, InterestService::ACTION_FAVOURITE);
				break;

			case self::UNFAVOURITE:
				$this->favourite($actor, $post->getId(), false);
				break;

			case self::REBLOG:
				$this->reblog($actor, $post->getId());
				$this->interestService->recordAction($actor, $post, InterestService::ACTION_BOOST);
				break;

			case self::UNREBLOG:
				$this->reblog($actor, $post->getId(), false);
				break;

			case self::BOOKMARK:
				$this->bookmark($actor, $post->getId());
				$this->interestService->recordAction($actor, $post, InterestService::ACTION_BOOKMARK);
				break;

			case self::UNBOOKMARK:
				$this->bookmark($actor, $post->getId(), false);
				break;

			case self::DISLIKE:
				$this->dislikeService->create($actor, $post->getId());
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
	 * `translate` is not here.
	 *
	 * It used to be, and it returned the post unchanged — which a client
	 * cannot tell from a translation, so the button worked and did nothing.
	 * It is `StatusApiController::statusTranslate()` now, because what it answers
	 * with is a Translation entity rather than a Status, and because it needs
	 * a translation provider that this service has no business holding.
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
	 * Bookmarks are a purely local, per-viewer flag (as on Mastodon) — nothing
	 * is federated.
	 */
	private function bookmark(Person $actor, string $postId, bool $enabled = true): void {
		$this->streamActionService->setActionBool(
			$actor->getId(), $postId, StreamAction::BOOKMARKED, $enabled
		);
	}
}
