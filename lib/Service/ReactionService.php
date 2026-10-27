<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Traits\TStringTools;
use Psr\Log\LoggerInterface;

/**
 * Reacting to a post with an emoji, and taking it back.
 *
 * Built on the same parts as `LikeService` — an activity is made, signed,
 * stored and requested — because a reaction *is* a like with a picture chosen
 * for it. What is different is that the emoji has to survive the round trip,
 * and that not every emoji a peer might send is one this app will show.
 *
 * Unicode emoji and bounded custom shortcode labels are accepted on input.
 * A custom reaction's remote image is deliberately never fetched or rendered:
 * it could track each reader. Outbound custom reactions are refused until
 * this instance can attach a verified Emoji tag for its own shortcode.
 */
class ReactionService {
	use TStringTools;

	/**
	 * How long an emoji may be, in bytes.
	 *
	 * One emoji, not a sentence. A family with skin tones and zero-width
	 * joiners is around 30 bytes in UTF-8, so this leaves room for the longest
	 * real sequences and nothing like room for text. The column is 63.
	 */
	public const MAX_LENGTH = 63;

	public function __construct(
		private ReactionsRequest $reactionsRequest,
		// the DB layer rather than StreamService, which attaches reaction bars
		// to the pages it serves and would otherwise depend on this in turn
		private StreamRequest $streamRequest,
		private SignatureService $signatureService,
		private ActivityService $activityService,
		private CacheActorService $cacheActorService,
		private ModerationService $moderationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether an inbound reaction is something the app will store and draw.
	 *
	 * Unicode emoji are accepted without a fixed list, so new Unicode releases
	 * do not need code changes. A custom shortcode is accepted only in a bounded
	 * ASCII label form, optionally qualified by its origin domain as Misskey
	 * does. The label stays plain text; its remote image is never fetched.
	 */
	public static function isUsableEmoji(string $emoji): bool {
		$emoji = trim($emoji);

		if ($emoji === '' || strlen($emoji) > self::MAX_LENGTH) {
			return false;
		}
		if (preg_match('/^:[A-Za-z0-9_+-]{1,48}(?:@([A-Za-z0-9.-]+))?:$/D', $emoji, $match) === 1) {
			if (isset($match[1]) && $match[1] !== ''
				&& filter_var($match[1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
				return false;
			}

			return true;
		}

		// no letters, digits, punctuation or whitespace anywhere in it
		if (preg_match('/[\p{L}\p{N}\p{P}\s]/u', $emoji) === 1) {
			return false;
		}

		// and at least one character that is actually an emoji, rather than a
		// run of symbols that merely is not text.
		//
		// Regional indicators are the second half of this and not a detail: a
		// flag is two of them and they are *not* Extended_Pictographic, so
		// that property alone refuses every flag there is.
		return self::isUnicodeEmoji($emoji);
	}

	/** Custom shortcode metadata is not available here, so never emit a bare shortcode. */
	public static function isUsableOutboundEmoji(string $emoji): bool {
		$emoji = trim($emoji);
		if ($emoji === '' || strlen($emoji) > self::MAX_LENGTH
			|| preg_match('/[\p{L}\p{N}\p{P}\s]/u', $emoji) === 1) {
			return false;
		}

		return self::isUnicodeEmoji($emoji);
	}

	private static function isUnicodeEmoji(string $emoji): bool {
		return preg_match('/[\p{Extended_Pictographic}\p{Regional_Indicator}]/u', $emoji) === 1;
	}

	/**
	 * Reacts to a post.
	 *
	 * @throws StreamNotFoundException when there is no such post
	 * @throws ItemUnknownException when the emoji is not one this app will draw
	 */
	public function create(Person $actor, string $postId, string $emoji, string &$token = ''): ACore {
		$this->moderationService->assertNotSuspended($actor->getId());

		$emoji = trim($emoji);
		if (!self::isUsableOutboundEmoji($emoji)) {
			throw new ItemUnknownException('not an emoji this app can draw');
		}

		$post = $this->streamRequest->getStreamById($postId, true);

		/** @var EmojiReact $reaction */
		$reaction = AP::instance()->getItemFromType(EmojiReact::TYPE);
		$reaction->setId($actor->getId() . '#emojireact/' . $this->uuid(8));
		$reaction->setActor($actor);
		$reaction->setObjectId($post->getId());
		$reaction->setContent($emoji);
		$reaction->setTo($post->getAttributedTo());
		$this->assignInstance($reaction, $post);

		$reaction->setPublished(date('c'));
		$this->signatureService->signObject($actor, $reaction);

		// stored before it is sent, so the reader sees their own reaction at
		// once whatever the peer does with it
		$this->reactionsRequest->save($reaction);
		$token = $this->activityService->request($reaction);

		return $reaction;
	}

	/**
	 * Takes a reaction back.
	 *
	 * The row goes whether or not the `Undo` can be built and sent: a reader
	 * who presses their own reaction again has taken it off, and leaving it in
	 * place because a peer is unreachable would be the app disagreeing with
	 * what they just did.
	 *
	 * @throws StreamNotFoundException when there is no such post
	 */
	public function delete(Person $actor, string $postId, string $emoji, string &$token = ''): ACore {
		$emoji = trim($emoji);
		$post = $this->streamRequest->getStreamById($postId, true);

		$undo = new Undo();
		$undo->setActor($actor);
		$this->assignInstance($undo, $post);

		try {
			$reaction = $this->reactionsRequest->getReaction($actor->getId(), $post->getId(), $emoji);

			$undo->setId($reaction->getId() . '/undo');
			$undo->setObject($reaction);
			$undo->setPublished(date('c'));
			$this->signatureService->signObject($actor, $undo);

			$this->reactionsRequest->delete($reaction);
			$token = $this->activityService->request($undo);
		} catch (ItemNotFoundException $e) {
		} catch (Exception $e) {
			$this->logger->warning('could not undo a reaction', [
				'postId' => $postId, 'exception' => $e,
			]);
			$this->reactionsRequest->deleteReaction($actor->getId(), $post->getId(), $emoji);
		}

		return $undo;
	}

	private function assignInstance(ACore $item, Stream $post): void {
		try {
			$target = $this->cacheActorService->getFromId($post->getAttributedTo());
			$item->addInstancePath(
				new InstancePath(
					$target->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
				)
			);
		} catch (Exception $e) {
			$item->addInstancePath(
				new InstancePath(
					$post->getAttributedTo(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
				)
			);
		}
	}
}
