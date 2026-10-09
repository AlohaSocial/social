<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Nid;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who may reply to one of this instance's own posts (`Stream::normalizeReplyRule()`):
 * the author's choice, held to wherever a reply comes from — a reply from
 * here is refused with the reason, one from another server or from Bluesky
 * is not kept — and said to peers as `interactionPolicy.canReply` and to
 * Bluesky as the post's threadgate. The author always may.
 */
class ReplyRuleService {
	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private ListsRequest $lists,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * Why the account may not reply to the post, or '' when it may — also
	 * when the post is not one of this instance's own.
	 */
	public function refusal(Stream $parent, string $replierId): string {
		$parts = $parent->getReplyRuleParts();
		if (!$parent->isLocal() || $parts === [] || $parent->getAttributedTo() === $replierId) {
			return '';
		}
		$author = $parent->getAttributedTo();
		foreach ($parts as $part) {
			$allowed = match (true) {
				$part === Stream::REPLY_RULE_FOLLOWERS => $this->follows($replierId, $author),
				$part === Stream::REPLY_RULE_FOLLOWING => $this->follows($author, $replierId),
				$part === Stream::REPLY_RULE_MENTIONED => in_array($replierId, $parent->addressed(), true),
				str_starts_with($part, Stream::REPLY_RULE_LIST) => $this->onList($author, (int)substr($part, strlen(Stream::REPLY_RULE_LIST)), $replierId),
				default => false,
			};
			if ($allowed) {
				return '';
			}
		}

		return self::describe($parent->getReplyRule());
	}

	/**
	 * A rule as the author may set it: the lists it names their own, the
	 * others left out — and nobody, rather than everybody, when nothing of
	 * it is left.
	 */
	public function sanitize(string $authorId, string $rule): string {
		$rule = Stream::normalizeReplyRule($rule);
		$parts = array_filter(explode(',', $rule), function (string $part) use ($authorId): bool {
			if (!str_starts_with($part, Stream::REPLY_RULE_LIST)) {
				return true;
			}
			try {
				$this->lists->getOwnedById($authorId, (int)substr($part, strlen(Stream::REPLY_RULE_LIST)));

				return true;
			} catch (Throwable) {
				return false;
			}
		});

		$kept = Stream::normalizeReplyRule(implode(',', $parts));

		return ($kept === Stream::REPLY_RULE_EVERYONE && $rule !== Stream::REPLY_RULE_EVERYONE) ? Stream::REPLY_RULE_NOBODY : $kept;
	}

	/**
	 * The members of the lists a rule names, as they are now: what the post
	 * tells other servers they may reply as (`Stream::setReplyListMembers()`).
	 */
	public function snapshotListMembers(Stream $post): void {
		$members = [];
		foreach ($post->getReplyRuleParts() as $part) {
			if (!str_starts_with($part, Stream::REPLY_RULE_LIST)) {
				continue;
			}
			try {
				$list = $this->lists->getOwnedById($post->getAttributedTo(), (int)substr($part, strlen(Stream::REPLY_RULE_LIST)));
				$members = array_merge($members, $this->lists->getMemberIds($list));
			} catch (Throwable) {
			}
		}
		$post->setReplyListMembers($members);
	}

	/**
	 * Sets who may reply to one of the author's own posts, from now on; a
	 * reply already made stays. The post's Bluesky threadgate follows.
	 *
	 * @throws InvalidResourceException the post is not this account's own
	 */
	public function setRule(int|string $nid, Person $actor, string $rule): Stream {
		try {
			$post = ctype_digit((string)$nid)
				? $this->streamRequest->getStreamByNid(Nid::fromStorage((string)$nid))
				: $this->streamRequest->getStreamById((string)$nid);
		} catch (Throwable) {
			throw new InvalidResourceException('no such post');
		}
		if (!$post->isLocal() || $post->getAttributedTo() !== $actor->getId()) {
			throw new InvalidResourceException('not your post');
		}
		$post->setReplyRule($this->sanitize($actor->getId(), $rule));
		$this->snapshotListMembers($post);
		// the wire object carries `interactionPolicy`, so the stored source
		// says what this server now holds peers to
		$post->setSource(json_encode($post, JSON_UNESCAPED_SLASHES));
		$this->streamRequest->update($post);
		try {
			$this->container?->get(Publisher::class)->updateGates($post);
		} catch (Throwable $e) {
			$this->logger->warning('Reply rule not written to Bluesky', ['post' => $post->getId(), 'exception' => $e]);
		}

		return $post;
	}

	/**
	 * What a rule lets through, as the refusal of a reply says it.
	 */
	public static function describe(string $rule): string {
		$rule = Stream::normalizeReplyRule($rule);
		if ($rule === Stream::REPLY_RULE_NOBODY || $rule === Stream::REPLY_RULE_EVERYONE) {
			return 'The author of this post allows no replies';
		}
		$who = [];
		foreach (explode(',', $rule) as $part) {
			$who[] = match (true) {
				$part === Stream::REPLY_RULE_FOLLOWERS => 'their followers',
				$part === Stream::REPLY_RULE_FOLLOWING => 'the accounts they follow',
				$part === Stream::REPLY_RULE_MENTIONED => 'the accounts it mentions',
				default => 'the members of one of their lists',
			};
		}
		$who = array_values(array_unique($who));
		$last = array_pop($who);

		return 'The author of this post allows replies only from ' . ($who === [] ? $last : implode(', ', $who) . ' and ' . $last);
	}

	/**
	 * Whether the account is on one of the author's lists.
	 */
	private function onList(string $authorId, int $listId, string $memberId): bool {
		try {
			return $this->lists->isMember($this->lists->getOwnedById($authorId, $listId), $memberId);
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Whether `$from` follows `$to`, the follow accepted.
	 */
	private function follows(string $from, string $to): bool {
		try {
			return $this->followsRequest->getByPersons($from, $to)->isAccepted();
		} catch (FollowNotFoundException) {
			return false;
		}
	}
}
