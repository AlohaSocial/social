<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Db\FollowsRequest;
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
 * Who may reply to one of this instance's own posts (`Stream::REPLY_RULES`):
 * the author's choice, held to wherever a reply comes from — a reply from
 * here is refused with the reason, one from another server or from Bluesky
 * is not kept — and said to peers as `interactionPolicy.canReply` and to
 * Bluesky as the post's threadgate. The author always may.
 */
class ReplyRuleService {
	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * Why the account may not reply to the post, or '' when it may — also
	 * when the post is not one of this instance's own.
	 */
	public function refusal(Stream $parent, string $replierId): string {
		if (!$parent->isLocal() || $parent->getAttributedTo() === $replierId) {
			return '';
		}
		$author = $parent->getAttributedTo();
		$allowed = match ($parent->getReplyRule()) {
			Stream::REPLY_RULE_FOLLOWERS => $this->follows($replierId, $author),
			Stream::REPLY_RULE_FOLLOWING => $this->follows($author, $replierId),
			Stream::REPLY_RULE_MENTIONED => in_array($replierId, $parent->addressed(), true),
			Stream::REPLY_RULE_NOBODY => false,
			default => true,
		};

		return $allowed ? '' : self::describe($parent->getReplyRule());
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
		$post->setReplyRule($rule);
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
		return match ($rule) {
			Stream::REPLY_RULE_FOLLOWERS => 'The author of this post allows replies only from their followers',
			Stream::REPLY_RULE_FOLLOWING => 'The author of this post allows replies only from the accounts they follow',
			Stream::REPLY_RULE_MENTIONED => 'The author of this post allows replies only from the accounts it mentions',
			default => 'The author of this post allows no replies',
		};
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
