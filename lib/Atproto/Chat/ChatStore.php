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
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Service\DurableCache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A Bluesky direct message as a direct message here: a `Note` from its
 * sender to the one local account, naming it, so it is in that account's
 * conversations and notifies it like any other. Each message answers the
 * one before it in the Bluesky conversation, received or sent from here,
 * which is how the conversation stays one conversation here.
 *
 * A message the account itself wrote in a Bluesky app is stored as its own
 * direct message to the others in the conversation, saved as it is and
 * never delivered: it reached them already. One sent from here is
 * remembered by its Bluesky id, so it is not stored a second time when the
 * log shows it, and by its conversation, so reading it here is reading it
 * on Bluesky.
 */
class ChatStore {
	/** how long the newest message of a conversation is remembered */
	private const KEPT = 90 * 86400;
	private const CACHE = 'social.chat';
	/** a message sent from here: its Bluesky conversation */
	private const CONVO = 'social.chat.convo';
	/** a message sent from here: the message here, by its Bluesky id */
	private const SENT = 'social.chat.sent';

	public function __construct(
		private PostStore $posts,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private DurableCache $durableCache,
		private LoggerInterface $logger,
		private StreamRequest $streams,
		private CacheActorService $cacheActors,
		private ConversationService $conversations,
	) {
	}

	/**
	 * The id a Bluesky message is stored under: its sender's, never a post's.
	 */
	public static function messageId(string $senderDid, string $convoId, string $messageId): string {
		return BlueskyIds::actorId($senderDid) . '/convo/' . rawurlencode($convoId) . '/' . rawurlencode($messageId);
	}

	/**
	 * A message of one of the account's conversations: somebody else's, or
	 * one it wrote itself in a Bluesky app.
	 *
	 * @param array $message a `chat.bsky.convo.defs#messageView`
	 * @param list<string> $members the conversation's members by DID, which
	 *                              the account's own message is addressed to
	 * @return bool whether it was stored
	 */
	public function received(Identity $identity, string $convoId, array $message, array $members = []): bool {
		$sender = (string)($message['sender']['did'] ?? '');
		$id = (string)($message['id'] ?? '');
		if ($sender === $identity->did) {
			return $this->own($identity, $convoId, $message, $members);
		}
		if (!Syntax::isDid($sender) || $id === '' || $this->blocklist->isBlockedDid($sender)) {
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
		$handle = $identity->handle !== '' ? $identity->handle : $identity->did;
		$note = $this->note($noteId, $actor->getId(), [$identity->actorId], $message, $this->last($identity->did, $convoId));
		$note['tag'] = [['type' => 'Mention', 'href' => $identity->actorId, 'name' => '@' . $handle]];
		$stored = $this->posts->storeMessage([
			'id' => $noteId . '/activity',
			'type' => 'Create',
			'actor' => $actor->getId(),
			'published' => $note['published'],
			'to' => [$identity->actorId],
			'cc' => [],
			'object' => $note,
		]);
		if ($stored) {
			$this->remember($identity->did, $convoId, $noteId);
		}

		return $stored;
	}

	/**
	 * A message the account wrote in a Bluesky app: its own direct message
	 * to the others in the conversation, saved with its recipients and
	 * nothing else — no delivery, no event, as it reached them already. One
	 * sent from here is here already.
	 *
	 * @param list<string> $members
	 */
	private function own(Identity $identity, string $convoId, array $message, array $members): bool {
		$id = (string)($message['id'] ?? '');
		if ($id === '' || $this->sentHere($identity->did, $id) !== '') {
			return false;
		}
		$noteId = self::messageId($identity->did, $convoId, $id);
		if ($this->posts->isKnown($noteId)) {
			return false;
		}
		$to = [];
		foreach ($members as $did) {
			if (!is_string($did) || $did === $identity->did || !Syntax::isDid($did) || $this->blocklist->isBlockedDid($did)) {
				continue;
			}
			try {
				$to[] = $this->actors->resolve($did)->getId();
			} catch (Throwable $e) {
				$this->logger->notice('Member of a Bluesky conversation not resolved', ['did' => $did, 'exception' => $e]);
			}
		}
		if ($to === []) {
			return false;
		}
		try {
			$data = $this->note($noteId, $identity->actorId, $to, $message, $this->last($identity->did, $convoId));
			$note = new Note();
			$note->setId($noteId);
			$note->setAttributedTo($identity->actorId);
			$note->setToArray($to);
			$note->setContent($data['content']);
			$note->setInReplyTo($data['inReplyTo']);
			$note->setPublished($data['published']);
			$note->convertPublished();
			$note->setVisibility(Stream::TYPE_DIRECT);
			$this->streams->save($note);
		} catch (Throwable $e) {
			$this->logger->warning('Own Bluesky direct message not stored', ['id' => $noteId, 'exception' => $e]);

			return false;
		}
		$this->remember($identity->did, $convoId, $noteId);

		return true;
	}

	/**
	 * A message its sender deleted: gone here too. The account's own, written
	 * in a Bluesky app, goes the same way; one sent from here stays, as it
	 * went to the Fediverse as well.
	 *
	 * @param array $message a `chat.bsky.convo.defs#deletedMessageView`
	 */
	public function deleted(Identity $identity, string $convoId, array $message): bool {
		$sender = (string)($message['sender']['did'] ?? '');
		$id = (string)($message['id'] ?? '');
		if (!Syntax::isDid($sender) || $id === '') {
			return false;
		}
		$noteId = self::messageId($sender, $convoId, $id);
		if ($sender !== $identity->did) {
			return $this->posts->delete($noteId);
		}
		try {
			if ($this->streams->getStreamById($noteId)->getAttributedTo() !== $identity->actorId) {
				return false;
			}
		} catch (StreamNotFoundException) {
			return false;
		}
		$this->streams->deleteById($noteId, Note::TYPE);

		return true;
	}

	/**
	 * The account read a conversation on Bluesky up to a message: read here
	 * up to the same one.
	 *
	 * @param array $message a `chat.bsky.convo.defs#messageView`, or a deleted one
	 * @return bool whether a conversation here was marked
	 */
	public function read(Identity $identity, string $convoId, array $message): bool {
		$sender = (string)($message['sender']['did'] ?? '');
		$id = (string)($message['id'] ?? '');
		if (!Syntax::isDid($sender) || $id === '') {
			return false;
		}
		$noteId = $sender === $identity->did ? $this->sentHere($identity->did, $id) : '';
		$noteId = $noteId !== '' ? $noteId : self::messageId($sender, $convoId, $id);
		try {
			return $this->conversations->markReadUpTo($this->cacheActors->getFromId($identity->actorId), $noteId);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky read state not taken over', ['did' => $identity->did, 'exception' => $e]);

			return false;
		}
	}

	/**
	 * A message sent from here went to Bluesky as `$messageId` in
	 * `$convoId`: the next one there answers it, the log's copy of it is not
	 * stored again, and reading it here is reading that conversation.
	 */
	public function sent(string $did, string $convoId, string $messageId, string $noteId): void {
		$this->remember($did, $convoId, $noteId);
		$this->durableCache->setShared(self::CONVO, $noteId, $convoId, self::KEPT);
		if ($messageId !== '') {
			$this->durableCache->setShared(self::SENT, $did . "\0" . $messageId, $noteId, self::KEPT);
		}
	}

	/**
	 * The message here an account sent to Bluesky as `$messageId`; '' for
	 * one not sent from here.
	 */
	public function sentHere(string $did, string $messageId): string {
		$noteId = $this->durableCache->getShared(self::SENT, $did . "\0" . $messageId);

		return is_string($noteId) ? $noteId : '';
	}

	/**
	 * The Bluesky conversation a message here is in: named in the id of one
	 * that came from Bluesky, remembered for one sent from here; '' for any
	 * other.
	 */
	public function convoOf(string $noteId): string {
		if (preg_match('#^https://bsky\.app/profile/did:[a-z]+:[A-Za-z0-9._:%-]+/convo/([^/]+)/[^/]+$#', $noteId, $m) === 1) {
			return rawurldecode($m[1]);
		}
		$convo = $this->durableCache->getShared(self::CONVO, $noteId);

		return is_string($convo) ? $convo : '';
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
	 * A message as a direct `Note` from `$from` to `$to`, answering `$inReplyTo`.
	 *
	 * @param list<string> $to
	 */
	private function note(string $noteId, string $from, array $to, array $message, string $inReplyTo): array {
		$text = (string)($message['text'] ?? '');
		// a message without facets still has its links and hashtags linked
		$facets = is_array($message['facets'] ?? null) && $message['facets'] !== [] ? $message['facets'] : TextMapper::facetsOf($text);

		return [
			'id' => $noteId,
			'type' => 'Note',
			'attributedTo' => $from,
			'published' => PostMapper::datetime((string)($message['sentAt'] ?? '')),
			'to' => $to,
			'cc' => [],
			'content' => FacetRenderer::html($text, $facets) . self::embedded($message),
			'tag' => [],
			'inReplyTo' => $inReplyTo,
		];
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
