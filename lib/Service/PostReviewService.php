<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\PostHoldsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\HeldPost;
use OCA\Social\Model\Post;
use OCA\Social\Model\Strike;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Holds posts for a moderator: an account's first post (`review_first_post`,
 * off by default), posts tripping a very small set of spam rules
 * (`autospam`: a wall of links, mentions scattered at strangers), and posts
 * with a video (`review_videos`). Rules, not scores, so a moderator can judge
 * the reason at a glance.
 *
 * Never held: a direct message (it would show private correspondence to a
 * moderator who was not addressed; it also does not count as having posted,
 * see `StreamRequest::countPostsBy()`), and a post by an account a moderator
 * already decided about.
 *
 * What is held is the request, never a `social_stream` row, so a held post
 * cannot leak into a timeline through a forgotten predicate.
 */
class PostReviewService {
	/**
	 * How many posts one account may have waiting; past it a post is refused,
	 * so a spammer cannot flood the queue.
	 */
	public const MAX_PENDING_PER_ACTOR = 20;

	/**
	 * The most posts an account can be asked to have approved before it is
	 * trusted.
	 */
	public const MAX_POSTS_BEFORE_TRUSTED = 20;

	/** Links in one post, past which it goes to a person. */
	public const MAX_LINKS = 5;

	/** Mentions in one post, past which it goes to a person. */
	public const MAX_MENTIONS = 5;

	/** Shorter than this, a post is text with a link in it, not a link post. */
	private const SHORT_POST = 240;

	public function __construct(
		private PostHoldsRequest $postHoldsRequest,
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private ModerationService $moderationService,
		private PostService $postService,
		private StatusAssemblyService $statusAssemblyService,
		private ScheduledStatusService $scheduledStatusService,
		private StrikeService $strikeService,
		private AccountService $accountService,
		private ConfigService $configService,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}

	/** Whether an administrator has asked for a first post to be looked at. */
	public function reviewsFirstPost(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_REVIEW_FIRST_POST);
	}

	/**
	 * How many approved posts an account needs before it stops being held; one
	 * by default, bounded so a mistyped setting cannot hold an account for ever.
	 */
	public function postsBeforeTrusted(): int {
		return max(1, min(
			self::MAX_POSTS_BEFORE_TRUSTED,
			$this->configService->getAppValueInt(ConfigService::SOCIAL_REVIEW_POSTS)
		));
	}

	/** Whether an administrator has asked to see videos before they are published. */
	public function reviewsVideos(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_REVIEW_VIDEOS);
	}

	/** Whether an administrator has asked for the spam rules to be applied. */
	public function autospam(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_AUTOSPAM);
	}

	/**
	 * Which rule says a person should see this post first, or `''` for none.
	 * Cheap (one count and string work), as it runs on every post.
	 *
	 * @param string $visibility as the post will be published, not as the
	 *                           client sent it — an absent visibility has
	 *                           already become the account's default by here
	 * @param bool $hasVideo whether a video is going out with it
	 */
	public function assess(Person $actor, string $text, string $visibility, bool $hasVideo = false): string {
		if ($visibility === Stream::TYPE_DIRECT) {
			return '';
		}

		if (!$this->reviewsFirstPost() && !$this->autospam() && !$this->reviewsVideos()) {
			return '';
		}

		// Moderators are never held, for the video rule as for the others.
		if ($this->mayModerate($actor)) {
			return '';
		}

		// Before the "already decided" gate: this rule is about the video, not about
		// an unvouched account.
		if ($hasVideo && $this->reviewsVideos()) {
			return HeldPost::REASON_VIDEO;
		}

		if ($this->alreadyDecided($actor->getId())) {
			return '';
		}

		if ($this->autospam()) {
			$spam = $this->spamRule($actor, $text);
			if ($spam !== '') {
				return $spam;
			}
		}

		if ($this->reviewsFirstPost()
			&& $this->streamRequest->countPostsBy($actor->getId()) < $this->postsBeforeTrusted()) {
			return HeldPost::REASON_FIRST_POST;
		}

		return '';
	}

	/**
	 * Whether this account is a Nextcloud admin who drains the queue, asked of
	 * Nextcloud each time. An actor without a Nextcloud user (a team) is not.
	 */
	private function mayModerate(Person $actor): bool {
		$userId = $actor->getUserId();

		return $userId !== '' && $this->groupManager->isAdmin($userId);
	}

	/**
	 * Puts one post in the queue.
	 *
	 * @param array<string, mixed> $params the post request, in the shape
	 *                                     `ScheduledStatus` stores it
	 *
	 * @throws InvalidActionException the account has too many waiting already
	 */
	public function hold(Person $actor, array $params, string $reason): HeldPost {
		if ($this->postHoldsRequest->countForActor($actor->getId()) >= self::MAX_PENDING_PER_ACTOR) {
			throw new InvalidActionException(
				'there are already ' . self::MAX_PENDING_PER_ACTOR
				. ' posts of this account waiting to be looked at'
			);
		}

		$held = new HeldPost();
		$held->setActorId($actor->getId())
			->setParams($params)
			->setReason($reason);

		// 0: the same post was already waiting (unique digest index); answer with
		// that row. See PostHoldsRequest::save().
		if ($this->postHoldsRequest->save($held) === 0) {
			foreach ($this->postHoldsRequest->getByActor($actor->getId()) as $waiting) {
				if ($waiting->digest() === $held->digest()) {
					return $waiting;
				}
			}
		}

		return $held;
	}

	/**
	 * A page of the queue, for a moderator, with each handle resolved (one
	 * indexed lookup per row).
	 *
	 * @return HeldPost[]
	 */
	public function pending(int $limit = 50, int $offset = 0): array {
		$held = $this->postHoldsRequest->page($limit, $offset);
		foreach ($held as $one) {
			$one->setHandle($this->handleOf($one->getActorId()));
		}

		return $held;
	}

	public function countPending(): int {
		return $this->postHoldsRequest->countAll();
	}

	/**
	 * One account's own waiting posts.
	 *
	 * @return HeldPost[]
	 */
	public function forActor(Person $actor): array {
		return $this->postHoldsRequest->getByActor($actor->getId());
	}

	/**
	 * One held post, whoever wrote it. For a moderator.
	 *
	 * @throws ItemNotFoundException
	 */
	public function heldPost(int $id): HeldPost {
		return $this->postHoldsRequest->getById($id);
	}

	/**
	 * Publishes a held post, down the path it would have taken.
	 *
	 * The row is deleted before publishing so a second approval cannot publish
	 * twice; a failed publish loses the post, as in
	 * `ScheduledStatusService::publishDue()`. The post is dated now. A post
	 * held when it was scheduled is scheduled for its time instead while that is
	 * still ahead, and the answer is null.
	 *
	 * @throws ItemNotFoundException no such held post
	 * @throws Throwable whatever `createPost()` refuses it for
	 */
	public function approve(int $id, Person $author): ?ACore {
		$held = $this->postHoldsRequest->getById($id);
		if ($held->getActorId() !== $author->getId()) {
			throw new ItemNotFoundException('that held post belongs to somebody else');
		}

		$this->postHoldsRequest->delete($id);

		// Held when scheduled: it goes out at its time unless that has passed.
		$when = (int)($held->getParams()['scheduled_at'] ?? 0);
		if ($this->scheduledStatusService->scheduleApproved($author, $held->getParams(), $when)) {
			return null;
		}

		// reviewed once, by the moderator approving it
		return $this->postService->createPost(
			$this->statusAssemblyService->fromParams($author, $held)->setReview(Post::REVIEW_EXEMPT)
		);
	}

	/**
	 * Refuses one, recorded as a strike so its author is notified as for a
	 * takedown and later moderators see it. Nothing else about the account
	 * changes.
	 *
	 * @throws ItemNotFoundException
	 */
	public function reject(int $id, string $comment = ''): HeldPost {
		$held = $this->postHoldsRequest->getById($id);
		$this->postHoldsRequest->delete($id);

		try {
			$this->strikeService->record(
				$held->getActorId(),
				Strike::TAKEDOWN,
				($comment !== '') ? $comment : 'A post was not published: ' . $this->reasonText($held->getReason())
			);
		} catch (Throwable $e) {
			// The post is refused either way; a failed strike must not restore the row.
			$this->logger->warning('could not record the refusal of a held post', [
				'held' => $id, 'exception' => $e,
			]);
		}

		return $held;
	}

	/**
	 * An author taking back their own waiting post.
	 *
	 * @throws ItemNotFoundException no such post *of this account*, which is
	 *                               also the answer for somebody else's
	 */
	public function withdraw(Person $actor, int $id): void {
		$held = $this->postHoldsRequest->getByIdForActor($id, $actor->getId());
		$this->postHoldsRequest->delete($held->getId());
	}

	/** The handle behind an actor id, or the id when it cannot be resolved. */
	private function handleOf(string $actorId): string {
		try {
			return $this->accountService->getFromId($actorId)->getAccount();
		} catch (Throwable $e) {
			return $actorId;
		}
	}

	/** Why it is waiting, in a sentence a person reads. */
	public function reasonText(string $reason): string {
		return match ($reason) {
			HeldPost::REASON_FIRST_POST => 'the first post of a new account',
			HeldPost::REASON_LINKS => 'more links than a post usually carries',
			HeldPost::REASON_MENTIONS => 'mentions of accounts with no connection to this one',
			HeldPost::REASON_REPEAT => 'the same text as a post already waiting',
			default => $reason,
		};
	}

	/**
	 * The spam rules, in the order they are cheapest to apply.
	 */
	private function spamRule(Person $actor, string $text): string {
		if ($this->links($text) > self::MAX_LINKS && mb_strlen($text) < self::SHORT_POST) {
			return HeldPost::REASON_LINKS;
		}

		$mentions = $this->mentions($text);
		if (count($mentions) > self::MAX_MENTIONS && !$this->knowsAnyone($actor)) {
			return HeldPost::REASON_MENTIONS;
		}

		return '';
	}

	/**
	 * Whether anybody here has anything to do with this account; the mention
	 * rule only holds posts of accounts that follow and are followed by nobody.
	 */
	private function knowsAnyone(Person $actor): bool {
		try {
			return $this->followsRequest->countFollowers($actor->getId()) > 0
				|| $this->followsRequest->countFollowing($actor->getId()) > 0;
		} catch (Throwable $e) {
			// Unknown relations do not make a spammer.
			return true;
		}
	}

	/** Whether a moderator has already decided something about this account. */
	private function alreadyDecided(string $actorId): bool {
		try {
			return $this->moderationService->levelOf($actorId) !== '';
		} catch (Throwable $e) {
			return false;
		}
	}

	private function links(string $text): int {
		return preg_match_all('#https?://#i', $text) ?: 0;
	}

	/**
	 * The handles a post mentions, counted off the text since this runs before
	 * the post is built.
	 *
	 * @return string[]
	 */
	private function mentions(string $text): array {
		$found = [];
		if (preg_match_all('/(?:^|[^\w\/])@([\w.-]+(?:@[\w.-]+)?)/', $text, $matches) > 0) {
			$found = array_unique($matches[1]);
		}

		return array_values($found);
	}
}
