<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Chat;

use OCA\Social\Service\DurableCache;
use RuntimeException;

/** Chat history pages stored without exceeding a MySQL TEXT cache row. */
class ChatBackfillPages {
	private const CHUNK_BYTES = 16384;
	private const INLINE_BYTES = 60000;

	public function __construct(
		private DurableCache $cache,
	) {
	}

	/** @param list<array> $messages */
	public function save(string $key, array $messages): void {
		$json = json_encode($messages, JSON_THROW_ON_ERROR);
		$this->remove($key);
		if (strlen($json) <= self::INLINE_BYTES) {
			$this->cache->setShared(ChatBackfill::CACHE, $key, $messages, ChatBackfill::TTL);
			return;
		}
		$chunks = str_split($json, self::CHUNK_BYTES);
		foreach ($chunks as $index => $chunk) {
			$this->cache->setShared(ChatBackfill::CACHE, $key . ':chunk:' . $index, base64_encode($chunk), ChatBackfill::TTL);
		}
		$this->cache->setShared(ChatBackfill::CACHE, $key, [
			'format' => 'chunks-v1', 'chunks' => count($chunks), 'sha256' => hash('sha256', $json),
		], ChatBackfill::TTL);
	}

	/** @return list<array> */
	public function read(string $key): array {
		$value = $this->cache->getShared(ChatBackfill::CACHE, $key);
		if (is_array($value) && ($value['format'] ?? '') === 'chunks-v1') {
			$count = $value['chunks'] ?? null;
			if (!is_int($count) || $count < 1 || !is_string($value['sha256'] ?? null)) {
				throw new RuntimeException('Invalid staged Bluesky chat page manifest');
			}
			$json = '';
			for ($index = 0; $index < $count; $index++) {
				$chunk = $this->cache->getShared(ChatBackfill::CACHE, $key . ':chunk:' . $index);
				$decoded = is_string($chunk) ? base64_decode($chunk, true) : false;
				if ($decoded === false || strlen($decoded) > self::CHUNK_BYTES) {
					throw new RuntimeException('A staged Bluesky chat page chunk is unavailable');
				}
				$json .= $decoded;
			}
			if (!hash_equals($value['sha256'], hash('sha256', $json))) {
				throw new RuntimeException('A staged Bluesky chat page failed its integrity check');
			}
			$value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		}
		if (!is_array($value) || !array_is_list($value)) {
			throw new RuntimeException('A staged Bluesky chat page expired before import completed');
		}
		foreach ($value as $message) {
			if (!is_array($message)) {
				throw new RuntimeException('A staged Bluesky chat page contains an invalid message');
			}
		}
		return $value;
	}

	public function remove(string $key): void {
		$value = $this->cache->getShared(ChatBackfill::CACHE, $key);
		if (is_array($value) && ($value['format'] ?? '') === 'chunks-v1' && is_int($value['chunks'] ?? null)) {
			for ($index = 0; $index < $value['chunks']; $index++) {
				$this->cache->removeShared(ChatBackfill::CACHE, $key . ':chunk:' . $index);
			}
		}
		$this->cache->removeShared(ChatBackfill::CACHE, $key);
	}
}
