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
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\ReactionService;
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
	/** a message on its way out: when it was first attempted */
	private const ATTEMPT = 'social.chat.attempt';
	/** how long an attempt is remembered, past the last retry of `Cron\AtprotoPublish` */
	private const ATTEMPT_KEPT = 86400;

	public function __construct(
		private PostStore $posts,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private DurableCache $durableCache,
		private LoggerInterface $logger,
		private StreamRequest $streams,
		private CacheActorService $cacheActors,
		private ConversationService $conversations,
		private ReactionsRequest $reactions,
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
			$stored = $this->own($identity, $convoId, $message, $members);
			$this->storeMessageReactions($identity, $convoId, $message);

			return $stored;
		}
		if (!Syntax::isDid($sender) || $id === '' || $this->blocklist->isBlockedDid($sender)) {
			return false;
		}
		try {
			$actor = $this->actors->resolve($sender);
		} catch (Throwable $e) {
			$this->logger->notice('Sender of a Bluesky direct message not resolved', ['did' => $sender, 'exception' => $e]);
			throw $e;
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
		$known = $stored || $this->posts->isKnown($noteId);
		if (!$known) {
			throw new \RuntimeException('Bluesky direct message was not durably stored');
		}
		$this->storeMessageReactions($identity, $convoId, $message);
		$this->remember($identity->did, $convoId, $noteId);

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
			$this->remember($identity->did, $convoId, $noteId);

			return false;
		}
		if ($members === []) {
			throw new \RuntimeException('Bluesky conversation members are not available for an own message');
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
				throw $e;
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
			if (!$this->streams->save($note)) {
				throw new \RuntimeException('Own Bluesky direct message was not durably stored');
			}
		} catch (Throwable $e) {
			$this->logger->warning('Own Bluesky direct message not stored', ['id' => $noteId, 'exception' => $e]);
			throw $e;
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
			$deleted = $this->posts->delete($noteId);
			if (!$deleted && $this->posts->isKnown($noteId)) {
				throw new \RuntimeException('Bluesky direct message deletion was not durably stored');
			}

			return $deleted;
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
	 * A reaction in a Bluesky chat log, or one attached to a backfilled message.
	 * Reactions use the existing local EmojiReact table so direct conversations
	 * expose the same summaries as other messages.
	 *
	 * An old message may not be present here (the initial import is deliberately
	 * limited to unread messages). Such a reaction cannot be displayed and is
	 * intentionally ignored; database failures still propagate so
	 * the chat cursor can retry them.
	 *
	 * @param array $message a `messageView` or `deletedMessageView`
	 * @param array $reaction a `reactionView`
	 */
	public function reaction(Identity $identity, string $convoId, array $message, array $reaction, bool $added, string $revision = ''): bool {
		$sender = (string)($message['sender']['did'] ?? '');
		$messageId = (string)($message['id'] ?? '');
		$actorDid = (string)($reaction['sender']['did'] ?? '');
		$emoji = trim((string)($reaction['value'] ?? ''));
		if (!Syntax::isDid($sender) || $messageId === '' || !Syntax::isDid($actorDid)
			|| !ReactionService::isUsableEmoji($emoji) || preg_match('/^\\X$/u', $emoji) !== 1) {
			return false;
		}
		if ($added && $this->blocklist->isBlockedDid($actorDid)) {
			return false;
		}

		$objectId = $this->sentHere($sender, $messageId);
		$objectId = $objectId !== '' ? $objectId : self::messageId($sender, $convoId, $messageId);
		$actorId = $actorDid === $identity->did ? $identity->actorId : BlueskyIds::actorId($actorDid);
		if (!$added) {
			$this->reactions->deleteReaction($actorId, $objectId, $emoji);

			return true;
		}

		try {
			$this->streams->getStreamById($objectId);
		} catch (StreamNotFoundException) {
			return false;
		}
		try {
			$this->reactions->getReaction($actorId, $objectId, $emoji);
			return false;
		} catch (ActionDoesNotExistException) {
			// Not yet in the local reaction summary.
		}

		$createdAt = (string)($reaction['createdAt'] ?? '');
		$eventKey = $revision !== '' ? $revision : hash('sha256', $createdAt . "\\0" . $emoji);
		$record = new EmojiReact();
		$record->setId(
			$actorId . '/convo/' . rawurlencode($convoId) . '/' . rawurlencode($messageId)
			. '/reaction/' . rawurlencode($eventKey)
		);
		$record->setActorId($actorId);
		$record->setObjectId($objectId);
		$record->setContent($emoji);
		if ($createdAt !== '') {
			$record->setPublished($createdAt);
		}

		return $this->reactions->save($record);
	}

	/** Stores the reactions Bluesky includes in a message view. */
	private function storeMessageReactions(Identity $identity, string $convoId, array $message): void {
		foreach (is_array($message['reactions'] ?? null) ? $message['reactions'] : [] as $reaction) {
			if (is_array($reaction)) {
				$this->reaction($identity, $convoId, $message, $reaction, true);
			}
		}
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
			throw $e;
		}
	}

	/**
	 * A message sent from here went to Bluesky as `$messageId` in
	 * `$convoId`: the next one there answers it, the log's copy of it is not
	 * stored again, and reading it here is reading that conversation.
	 */
	public function sent(string $did, string $convoId, string $messageId, string $noteId): void {
		$this->remember($did, $convoId, $noteId);
		if ($messageId !== '') {
			$this->durableCache->setShared(self::SENT, $did . "\0" . $messageId, $noteId, self::KEPT);
		}
		// last: a message whose conversation is known is recorded in full (`ChatSender`)
		$this->durableCache->setShared(self::CONVO, $noteId, $convoId, self::KEPT);
		$this->durableCache->removeShared(self::ATTEMPT, $noteId);
	}

	/**
	 * A message here is about to be sent to Bluesky for the first time:
	 * remembered before it goes, so a retry after a failure can tell
	 * whether it went.
	 */
	public function attempting(string $noteId, int $time): void {
		$this->durableCache->setShared(self::ATTEMPT, $noteId, $time, self::ATTEMPT_KEPT);
	}

	/**
	 * When a message here was first attempted and not recorded as sent; 0
	 * for none.
	 */
	public function attempted(string $noteId): int {
		$time = $this->durableCache->getShared(self::ATTEMPT, $noteId);

		return is_int($time) ? $time : 0;
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
