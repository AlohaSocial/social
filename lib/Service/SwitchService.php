<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * Moving in from a network that is not this one: finding the people again
 * (posts are `PostImportService`'s, follow lists `MigrationService`'s).
 *
 * An Instagram export lists whom you followed there; a Threads handle is the
 * Instagram one at `threads.net`, and Threads federates, so each name is
 * worth one lookup. Nothing is followed here; the wizard asks.
 */
class SwitchService {
	/**
	 * How many names one request looks up: each is a WebFinger against another
	 * server, so the wizard asks batch by batch and shows its progress.
	 */
	public const PROBE_BATCH = 25;

	/** The most names one archive is read for. */
	private const MAX_CANDIDATES = 5000;

	/** Where an Instagram name can be followed, if its owner turned it on. */
	private const THREADS = 'threads.net';

	public function __construct(
		private CacheActorService $cacheActorService,
	) {
	}

	/**
	 * The handles worth looking up, out of an Instagram JSON export's
	 * `string_list_data[].value`, found by shape since its path varies between
	 * exports.
	 *
	 * @param string $body the uploaded file
	 *
	 * @return string[] handles at threads.net, in the order the archive lists
	 *                  them, without repeats
	 */
	public function candidates(string $body): array {
		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			return [];
		}

		$names = [];
		$this->harvest($decoded, $names);

		$handles = [];
		foreach ($names as $name) {
			$handle = strtolower($name) . '@' . self::THREADS;
			if (!in_array($handle, $handles, true)) {
				$handles[] = $handle;
			}

			if (count($handles) >= self::MAX_CANDIDATES) {
				break;
			}
		}

		return $handles;
	}

	/**
	 * Which of those accounts exist, one batch (`PROBE_BATCH`) at a time. The
	 * answer counts how many were checked, not found, so the caller's cursor
	 * always moves.
	 *
	 * @param string[] $handles what to look up
	 *
	 * @return array{checked: int, found: array<array{handle: string, name: string, url: string, avatar: string}>}
	 */
	public function probe(array $handles): array {
		$batch = array_slice(array_values($handles), 0, self::PROBE_BATCH);
		$found = [];

		foreach ($batch as $handle) {
			$handle = ltrim(trim((string)$handle), '@');
			if ($handle === '' || !str_contains($handle, '@')) {
				continue;
			}

			try {
				$actor = $this->cacheActorService->getFromAccount($handle);
			} catch (Exception) {
				// not there, or not federating: the common answer, and not a
				// failure worth telling anybody about
				continue;
			}

			$found[] = [
				'handle' => $actor->getAccount(),
				'name' => ($actor->getName() !== '') ? $actor->getName() : $actor->getPreferredUsername(),
				'url' => $actor->getId(),
				'avatar' => $actor->getIcon()?->getUrl() ?? '',
			];
		}

		return ['checked' => count($batch), 'found' => $found];
	}

	/**
	 * The card to post where you used to be: the handle this server publishes,
	 * which may differ from the Nextcloud user id, and an address to copy.
	 *
	 * @param Person $actor whose
	 *
	 * @return array{handle: string, url: string, text: string}
	 */
	public function announcement(Person $actor): array {
		$handle = '@' . $actor->getAccount();
		$url = $actor->getId();

		return [
			'handle' => $handle,
			'url' => $url,
			'text' => sprintf(
				"I've moved to the fediverse. You can find me at %s — %s",
				$handle,
				$url
			),
		];
	}

	/**
	 * Every `string_list_data[].value` anywhere in the document, found by shape
	 * since Instagram nests it differently in each export.
	 *
	 * @param array<mixed> $node where to look
	 * @param string[] $into what was found, by reference
	 */
	private function harvest(array $node, array &$into): void {
		foreach ($node as $key => $value) {
			if (!is_array($value)) {
				continue;
			}

			if ($key === 'string_list_data') {
				foreach ($value as $entry) {
					$name = is_array($entry) ? trim((string)($entry['value'] ?? '')) : '';
					// a handle, not a URL and not a display name
					if ($name !== '' && preg_match('/^[A-Za-z0-9._]{1,30}$/', $name) === 1) {
						$into[] = $name;
					}
				}

				continue;
			}

			$this->harvest($value, $into);
		}
	}
}
