<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Publisher\TextMapper;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ReactionService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * A direct message written here to people on Bluesky, sent to them as a
 * Bluesky direct message: one conversation with all of them
 * (`getConvoForMembers`), the text without the mentions that address it,
 * its links and hashtags as facets. Pictures stay here: Bluesky's direct
 * messages carry none. Answering a conversation that is a request on
 * Bluesky (somebody the person does not follow started it) accepts it
 * there, as answering it in a Bluesky app does.
 *
 * A message goes at most once. One recorded as sent is not sent again; as
 * `sendMessage` takes no idempotency key, the first attempt is recorded
 * before it goes (`ChatStore::attempting()`), and a retry looks among the
 * conversation's newest messages for the account's own of the same text
 * since then: found, it is recorded as sent, and only otherwise sent.
 */
class ChatSender {
	/** Bluesky's limit for one message */
	public const MAX_GRAPHEMES = 1000;
	public const MAX_BYTES = 10000;
	/** how many of a conversation's newest messages a retry looks through */
	public const LOOKBACK = 100;
	/** how far the chat service's clock may be behind this one, in seconds */
	private const SKEW = 60;

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private CacheActorService $cacheActors,
		private TextMapper $text,
		private ChatStore $store,
		private ChatPoller $poller,
		private LoggerInterface $logger,
		private ITimeFactory $time,
	) {
	}

	/**
	 * The Bluesky accounts a direct message is addressed to, by DID.
	 *
	 * @return list<string>
	 */
	public static function recipients(Stream $post): array {
		$dids = [];
		foreach ($post->getToAll() as $id) {
			$did = BlueskyIds::isActorId($id) ? BlueskyIds::didOf($id) : '';
			if ($did !== '') {
				$dids[] = $did;
			}
		}

		return array_values(array_unique($dids));
	}

	/**
	 * The text of a message as it goes out, at most Bluesky's limit.
	 *
	 * @return array{text: string, facets: array}
	 */
	public function textOf(Stream $post): array {
		$text = $this->text->fromHtml($post->getContent(), static fn (): ?string => null)['text'];
		// the mentions that address a message are not part of what it says
		$text = trim((string)preg_replace('/^(?:\s*@[^\s@]+(?:@[^\s@]+)?)+\s*/u', '', $text));
		if (Lexicon::graphemes($text) > self::MAX_GRAPHEMES || strlen($text) > self::MAX_BYTES) {
			$text = TextMapper::cut($text, self::MAX_GRAPHEMES - 1, self::MAX_BYTES - strlen('…')) . '…';
		}

		return ['text' => $text, 'facets' => TextMapper::facetsOf($text)];
	}

	/**
	 * Sends a direct message written here to its recipients on Bluesky.
	 *
	 * @return bool whether a message was sent
	 */
	public function send(Stream $post): bool {
		if (!$this->poller->isOn() || $post->getVisibility() !== Stream::TYPE_DIRECT || !$post->isLocal()) {
			return false;
		}
		$recipients = self::recipients($post);
		$message = $this->textOf($post);
		if ($recipients === [] || $message['text'] === '') {
			return false;
		}
		$identity = $this->identities->forActor($this->cacheActors->getFromId($post->getAttributedTo()), false);
		if ($identity === null || !$identity->isActive()) {
			return false;
		}
		if ($this->store->convoOf($post->getId()) !== '') {
			// an earlier attempt sent and recorded it
			return false;
		}
		$key = $this->identities->signingKey($identity);
		$view = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.getConvoForMembers', ['members' => $recipients])['convo'] ?? [];
		$convo = is_array($view) ? (string)($view['id'] ?? '') : '';
		if ($convo === '') {
			$this->logger->notice('No Bluesky conversation for a direct message', ['post' => $post->getId()]);

			return false;
		}
		if (($view['status'] ?? '') === ChatState::REQUEST) {
			$this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.acceptConvo', [], ['convoId' => $convo]);
		}
		$since = $this->store->attempted($post->getId());
		$messageId = $since > 0 ? $this->sentSince($identity->did, $key, $convo, $message['text'], $since, $post->getId()) : null;
		if ($messageId === null) {
			if ($since === 0) {
				$this->store->attempting($post->getId(), $this->time->getTime());
			}
			$sent = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.sendMessage', [], [
				'convoId' => $convo,
				'message' => ['text' => $message['text']] + ($message['facets'] === [] ? [] : ['facets' => $message['facets']]),
			]);
			$messageId = (string)($sent['id'] ?? '');
		}
		// so the log's copy of it is known to be this one
		$this->store->sent($identity->did, $convo, $messageId, $post->getId());
		// the answer is likely soon
		$this->poller->wake($identity->did);

		return true;
	}

	/**
	 * Sends an Aloha reaction to a Bluesky chat message. Null means the post
	 * is not a message in a conversation owned by this account.
	 */
	public function react(Person $actor, Stream $post, string $emoji, bool $added): ?bool {
		if ($post->getVisibility() !== Stream::TYPE_DIRECT) {
			return null;
		}
		$identity = $this->identities->forActor($actor, false);
		if ($identity === null || !$identity->isActive()) {
			return (ChatStore::messageLocationLooksLikeBluesky($post->getId()) || self::recipients($post) !== [])
				? throw new ItemUnknownException('Link an active Bluesky identity before reacting to this message')
				: null;
		}
		$location = $this->store->messageLocation($identity->did, $post->getId());
		if ($location === null) {
			if (self::recipients($post) !== []) {
				throw new ItemUnknownException('The Bluesky message mapping is unavailable; this reaction cannot be delivered');
			}
			return null;
		}
		if (!ReactionService::isUsableOutboundEmoji($emoji) || preg_match('/^\\X$/u', $emoji) !== 1) {
			throw new ItemUnknownException('Bluesky chat reactions must be one Unicode emoji');
		}
		if (!$this->poller->isOn()) {
			throw new \RuntimeException('Bluesky chat is not enabled for this account');
		}
		$method = $added ? 'chat.bsky.convo.addReaction' : 'chat.bsky.convo.removeReaction';
		$this->appView->chatAs($identity->did, $this->identities->signingKey($identity), $method, [], [
			'convoId' => $location['convoId'],
			'messageId' => $location['messageId'],
			'value' => $emoji,
		]);
		$this->poller->wake($identity->did);

		return true;
	}

	/**
	 * The id of the account's message of `$text` in a conversation, sent no
	 * earlier than `$since` and not recorded as another message sent from
	 * here: an earlier attempt's at `$noteId`, whose answer was lost or not
	 * recorded. Null when the newest messages hold none.
	 */
	private function sentSince(string $did, PrivateKey $key, string $convo, string $text, int $since, string $noteId): ?string {
		$answer = $this->appView->chatAs($did, $key, 'chat.bsky.convo.getMessages', ['convoId' => $convo, 'limit' => self::LOOKBACK]);
		foreach (is_array($answer['messages'] ?? null) ? $answer['messages'] : [] as $message) {
			if (!is_array($message)) {
				continue;
			}
			$sentAt = strtotime((string)($message['sentAt'] ?? ''));
			if ($sentAt === false) {
				continue;
			}
			// newest first: the rest are older still
			if ($sentAt < $since - self::SKEW) {
				break;
			}
			$id = (string)($message['id'] ?? '');
			if ($id !== '' && ($message['sender']['did'] ?? '') === $did && ($message['text'] ?? null) === $text
				&& in_array($this->store->sentHere($did, $id), ['', $noteId], true)) {
				return $id;
			}
		}

		return null;
	}
}
