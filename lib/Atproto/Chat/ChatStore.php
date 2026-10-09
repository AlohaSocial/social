<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\TextMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\FacetRenderer;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Service\DurableCache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A Bluesky direct message as a direct message here: a `Note` from its
 * sender to the one local account, naming it, so it is in that account's
 * conversations and notifies it like any other. Each message answers the
 * one before it in the Bluesky conversation, received or sent from here,
 * which is how the conversation stays one conversation here.
 */
class ChatStore {
	/** how long the newest message of a conversation is remembered */
	private const KEPT = 90 * 86400;
	private const CACHE = 'social.chat';

	public function __construct(
		private PostStore $posts,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private DurableCache $durableCache,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The id a Bluesky message is stored under: its sender's, never a post's.
	 */
	public static function messageId(string $senderDid, string $convoId, string $messageId): string {
		return BlueskyIds::actorId($senderDid) . '/convo/' . rawurlencode($convoId) . '/' . rawurlencode($messageId);
	}

	/**
	 * A message the account received; its own are here already, or were
	 * written in a Bluesky app and stay there.
	 *
	 * @param array $message a `chat.bsky.convo.defs#messageView`
	 * @return bool whether it was stored
	 */
	public function received(Identity $identity, string $convoId, array $message): bool {
		$sender = (string)($message['sender']['did'] ?? '');
		$id = (string)($message['id'] ?? '');
		if (!Syntax::isDid($sender) || $sender === $identity->did || $id === '' || $this->blocklist->isBlockedDid($sender)) {
			return false;
		}
		try {
			$actor = $this->actors->resolve($sender);
		} catch (Throwable $e) {
			$this->logger->notice('Sender of a Bluesky direct message not resolved', ['did' => $sender, 'exception' => $e]);

			return false;
		}
		if (BlueskyActorService::isLimited($actor) || $this->blocklist->isBlockedActor($actor)) {
			return false;
		}
		$noteId = self::messageId($sender, $convoId, $id);
		$published = PostMapper::datetime((string)($message['sentAt'] ?? ''));
		$text = (string)($message['text'] ?? '');
		// a message without facets still has its links and hashtags linked
		$facets = is_array($message['facets'] ?? null) && $message['facets'] !== [] ? $message['facets'] : TextMapper::facetsOf($text);
		$content = FacetRenderer::html($text, $facets) . self::embedded($message);
		$handle = $identity->handle !== '' ? $identity->handle : $identity->did;
		$stored = $this->posts->storeMessage([
			'id' => $noteId . '/activity',
			'type' => 'Create',
			'actor' => $actor->getId(),
			'published' => $published,
			'to' => [$identity->actorId],
			'cc' => [],
			'object' => [
				'id' => $noteId,
				'type' => 'Note',
				'attributedTo' => $actor->getId(),
				'published' => $published,
				'to' => [$identity->actorId],
				'cc' => [],
				'content' => $content,
				'tag' => [['type' => 'Mention', 'href' => $identity->actorId, 'name' => '@' . $handle]],
				'inReplyTo' => $this->last($identity->did, $convoId),
			],
		]);
		if ($stored) {
			$this->remember($identity->did, $convoId, $noteId);
		}

		return $stored;
	}

	/**
	 * A message its sender deleted: gone here too.
	 *
	 * @param array $message a `chat.bsky.convo.defs#deletedMessageView`
	 */
	public function deleted(string $convoId, array $message): bool {
		$sender = (string)($message['sender']['did'] ?? '');
		$id = (string)($message['id'] ?? '');

		return Syntax::isDid($sender) && $id !== '' && $this->posts->delete(self::messageId($sender, $convoId, $id));
	}

	/**
	 * The newest message of an account's Bluesky conversation, which the
	 * next one answers; '' when none is known.
	 */
	public function last(string $did, string $convoId): string {
		$last = $this->durableCache->getShared(self::CACHE, $did . "\0" . $convoId);

		return is_string($last) ? $last : '';
	}

	/**
	 * Remembers the newest message of an account's Bluesky conversation,
	 * received or sent from here.
	 */
	public function remember(string $did, string $convoId, string $noteId): void {
		$this->durableCache->setShared(self::CACHE, $did . "\0" . $convoId, $noteId, self::KEPT);
	}

	/**
	 * A post a message shares, as a link to it.
	 */
	private static function embedded(array $message): string {
		$parsed = Syntax::parseAtUri((string)($message['embed']['record']['uri'] ?? ''));
		if ($parsed === null || $parsed['collection'] !== BlueskyIds::POST) {
			return '';
		}
		$url = BlueskyIds::postUrl($parsed['authority'], $parsed['rkey']);

		return '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" rel="nofollow noopener noreferrer" target="_blank">' . htmlspecialchars($url, ENT_QUOTES) . '</a></p>';
	}
}
