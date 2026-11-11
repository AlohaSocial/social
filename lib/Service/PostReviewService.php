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
 * The first post an account writes here, and the posts that read like spam.
 *
 * Two switches, both on by default, both an administrator's to turn off:
 *
 *  - **first-post review** holds the first post of an account that has
 *    published nothing here yet. It is the cheapest spam measure there is on a
 *    server whose accounts are Nextcloud users: a spammer has to get an
 *    account on this instance first, and the first thing they do with it is
 *    seen by a person before it reaches anybody else.
 *  - **autospam** holds a post that trips one of a very small set of rules —
 *    a wall of links, or mentions scattered at accounts with no connection to
 *    the author. No wordlist and no score: a queue that says "0.82" tells a
 *    moderator nothing they can act on, while "seven links" is something a
 *    person can agree or disagree with in a second.
 *
 * Two things are never held, and both matter more than the rules do:
 *
 *  - A **direct message**. Holding one would put private correspondence in
 *    front of a moderator who was not addressed, for a machine's reason. A
 *    spammer sending DMs is dealt with by the people who receive them, through
 *    a report — by a person who was actually written to. A direct message does
 *    not count as having posted here either (`StreamRequest::countPostsBy()`):
 *    otherwise an account could send one message to itself and be past
 *    first-post review a second later.
 *  - A post by an account with a **moderation decision** already against it.
 *    That account's posts are being dealt with by whatever the moderator
 *    decided; the queue is for accounts nobody has looked at yet.
 *
 * What is held is the *request*, never a row in `social_stream`. See the
 * migration for why: a held post that existed as a row with a flag on it would
 * be one forgotten predicate away from a timeline, and this app has shipped
 * that leak before.
 */
class PostReviewService {
	/**
	 * How many posts one account may have waiting.
	 *
	 * The cap is what stops a determined spammer turning the queue into the
	 * thing that has to be moderated. Past it the post is refused outright,
	 * which is the honest answer: nobody is going to read the fortieth.
	 */
	public const MAX_PENDING_PER_ACTOR = 20;

	/**
	 * The most posts an account can be asked to have approved before it is
	 * trusted. Past this it is not a review queue, it is a permission.
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
	 * How many posts an account publishes before it stops being held.
	 *
	 * An account graduates by having that many posts approved — which is a
	 * person having looked at it that many times, and is the only measure of
	 * trust this app has that is not a guess. One by default, which is what
	 * "first-post review" has always meant; bounded so that a mistyped setting
	 * cannot hold an account's posts for ever.
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
	 *
	 * Deliberately cheap: one count of the account's posts, and string work on
	 * the text. It runs inside every post anybody writes.
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

		// Somebody who can empty the queue is not somebody to put in it, and
		// that holds for the video rule as much as for the other three — the
		// case that made it obvious was the first post on a brand-new
		// instance; see `mayModerate()`.
		if ($this->mayModerate($actor)) {
			return '';
		}

		// Before the "already decided" gate, and deliberately: the other rules
		// are about an account nobody has vouched for yet and stop applying
		// once somebody has, while this one is about the video. An instance
		// that has asked to see videos has asked to see all of them.
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
	 * Whether this account is one of the people the queue is drained by.
	 *
	 * Asked of Nextcloud rather than stored: an administrator today is an
	 * administrator for as long as the group says so, and a copy would drift.
	 * A team account and any other actor with no Nextcloud user behind it is
	 * not an administrator, which is what the null check says.
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

		// 0 means the same post was already waiting: the unique index on the
		// digest settled it, and the row that is there is the one to answer
		// with — see PostHoldsRequest::save()
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
	 * A page of the queue, for a moderator.
	 *
	 * The handle is resolved per row rather than left as the actor id: a
	 * moderator reading a queue of URLs is reading the same forty characters
	 * over and over with the name buried at the end. One indexed lookup per
	 * row, on a page somebody opens by hand.
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
	 * The row is deleted first and the post published after, so a failure
	 * leaves nothing behind that a second approval could publish twice. The
	 * cost is that a post whose publishing fails is lost rather than left in
	 * the queue — the same trade `ScheduledStatusService::publishDue()` takes,
	 * and for the same reason: a duplicate post cannot be taken back.
	 *
	 * The published post is dated now, not when it was written. It became a
	 * post when it was approved; a post that appeared in a timeline already a
	 * day old would be one nobody saw. A post held when it was scheduled is
	 * scheduled for its time instead, while that is still ahead, and the
	 * answer is null.
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

		// held when it was scheduled: it goes out at its time, unless that
		// has passed while it waited
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
	 * Refuses one, and tells its author.
	 *
	 * A refusal is recorded as a strike rather than being a deletion nobody
	 * can see the reason for: the account is told through the same
	 * notification a takedown uses, the moderators can see it was told, and
	 * the next moderator to look at this account can see this was not the
	 * first time. Nothing else about the account changes — a refused post is
	 * not a silence.
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
			// the post is refused either way; a strike that could not be
			// recorded must not put the row back
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
	 * Whether anybody here has anything to do with this account.
	 *
	 * An account with followers scattering mentions is having a bad day in
	 * public and its followers can say so; an account nobody follows and that
	 * follows nobody, doing the same thing, is a machine. The second rule is
	 * the one worth a moderator's time, so the mention rule asks this before
	 * it holds anything.
	 */
	private function knowsAnyone(Person $actor): bool {
		try {
			return $this->followsRequest->countFollowers($actor->getId()) > 0
				|| $this->followsRequest->countFollowing($actor->getId()) > 0;
		} catch (Throwable $e) {
			// an account whose relations cannot be counted is not thereby a
			// spammer
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
	 * The handles a post mentions.
	 *
	 * Counted off the text rather than off the resolved recipients, because
	 * this runs before the post is built: a mention of an account that does
	 * not exist is still a mention, and a hundred of them is still spam.
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
