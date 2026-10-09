<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Publisher\TextMapper;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use Psr\Log\LoggerInterface;

/**
 * A direct message written here to people on Bluesky, sent to them as a
 * Bluesky direct message: one conversation with all of them
 * (`getConvoForMembers`), the text without the mentions that address it,
 * its links and hashtags as facets. Pictures stay here: Bluesky's direct
 * messages carry none. Answering a conversation that is a request on
 * Bluesky (somebody the person does not follow started it) accepts it
 * there, as answering it in a Bluesky app does.
 */
class ChatSender {
	/** Bluesky's limit for one message */
	public const MAX_GRAPHEMES = 1000;
	public const MAX_BYTES = 10000;

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private CacheActorService $cacheActors,
		private TextMapper $text,
		private ChatStore $store,
		private ChatPoller $poller,
		private LoggerInterface $logger,
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
		$sent = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.sendMessage', [], [
			'convoId' => $convo,
			'message' => ['text' => $message['text']] + ($message['facets'] === [] ? [] : ['facets' => $message['facets']]),
		]);
		// so the log's copy of it is known to be this one
		$this->store->sent($identity->did, $convo, (string)($sent['id'] ?? ''), $post->getId());
		// the answer is likely soon
		$this->poller->wake($identity->did);

		return true;
	}
}
