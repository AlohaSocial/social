<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Service\DurableCache;

/** Durable, bounded initial history import, separate from live chat log polling. */
class ChatBackfill {
	public const CACHE = 'social.atproto.chat.first-import';
	public const TTL = 604800;
	public const PAGE = 100;
	private const PAGE_BUDGET = 10;

	public function __construct(
		private AppViewClient $appView,
		private ChatStore $store,
		private DurableCache $durableCache,
		private ChatBackfillPages $pages,
	) {
	}

	/**
	 * Fetch a conversation's unread window into bounded durable pages, then
	 * store those pages oldest-first. The state survives cron time limits and
	 * process restarts without advancing the account log cursor.
	 *
	 * @param list<string> $members
	 * @return array{handled: int, complete: bool}
	 */
	public function import(Identity $identity, PrivateKey $key, string $convo, int $unread, array $members): array {
		$baseKey = hash('sha256', $identity->did . "\0" . $convo);
		$state = $this->durableCache->getShared(self::CACHE, $baseKey);
		if (!is_array($state)) {
			$state = ['unread' => $unread, 'incoming' => 0, 'pages' => 0, 'cursor' => '', 'storing' => false, 'storePage' => -1];
		}
		$unread = (int)$state['unread'];

		if (!$state['storing']) {
			for ($batch = 0; $batch < self::PAGE_BUDGET; $batch++) {
				$params = ['convoId' => $convo, 'limit' => self::PAGE];
				if ($state['cursor'] !== '') {
					$params['cursor'] = $state['cursor'];
				}
				$page = $this->appView->chatAs($identity->did, $key, 'chat.bsky.convo.getMessages', $params);
				if (!is_array($page['messages'] ?? null) || !array_is_list($page['messages'])) {
					throw new \RuntimeException('Bluesky chat message response is missing its message list');
				}
				if (isset($page['cursor']) && !is_string($page['cursor'])) {
					throw new \RuntimeException('Bluesky chat message response has an invalid cursor');
				}
				$list = $page['messages'];
				foreach ($list as $message) {
					if (!is_array($message)) {
						throw new \RuntimeException('Bluesky chat message response contains an invalid message');
					}
				}
				if ($list === []) {
					throw new \RuntimeException('Bluesky chat history ended before all unread messages were found');
				}
				$kept = [];
				foreach ($list as $message) {
					$kept[] = $message;
					$sender = (string)($message['sender']['did'] ?? '');
					if ($sender !== '' && $sender !== $identity->did) {
						$state['incoming']++;
						if ($state['incoming'] >= $unread) {
							break;
						}
					}
				}
				$state['storing'] = $state['incoming'] >= $unread;
				$pageIndex = (int)$state['pages'];
				$this->pages->save($baseKey . ':' . $pageIndex, $kept);
				$state['pages']++;
				$next = $page['cursor'] ?? '';
				if (!$state['storing'] && ($next === '' || $next === $state['cursor'])) {
					$this->clearBackfill($baseKey, (int)$state['pages']);
					throw new \RuntimeException('Bluesky chat pagination ended before all unread messages were found');
				}
				$state['cursor'] = $next;
				if ($state['storing']) {
					$state['storePage'] = (int)$state['pages'] - 1;
				}
				$this->durableCache->setShared(self::CACHE, $baseKey, $state, self::TTL);
				if ($state['storing']) {
					break;
				}
			}
			if (!$state['storing']) {
				return ['handled' => 0, 'complete' => false];
			}
		}

		$handled = 0;
		for ($batch = 0; $batch < self::PAGE_BUDGET && $state['storePage'] >= 0; $batch++) {
			$messages = $this->pages->read($baseKey . ':' . $state['storePage']);
			foreach (array_reverse($messages) as $message) {
				if (($message['$type'] ?? '') === 'chat.bsky.convo.defs#deletedMessageView') {
					continue;
				}
				$handled += $this->store->received($identity, $convo, $message, $members) ? 1 : 0;
			}
			$state['storePage']--;
			$this->durableCache->setShared(self::CACHE, $baseKey, $state, self::TTL);
		}
		if ($state['storePage'] >= 0) {
			return ['handled' => $handled, 'complete' => false];
		}
		$this->clearBackfill($baseKey, (int)$state['pages']);

		return ['handled' => $handled, 'complete' => true];
	}

	private function clearBackfill(string $baseKey, int $pages): void {
		$this->durableCache->removeShared(self::CACHE, $baseKey);
		for ($page = 0; $page < $pages; $page++) {
			$this->pages->remove($baseKey . ':' . $page);
		}
	}

}
