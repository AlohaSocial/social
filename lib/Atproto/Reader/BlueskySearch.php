<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finding Bluesky accounts by name: the public AppView's typeahead, asked
 * for text that looks like the start of a handle — a dot in it and no `@`
 * — so a Fediverse address or a plain username never leaves the instance.
 * What comes back are cached actors: the one already here, or the account
 * stored now, so it has the id a client opens or follows it by.
 */
class BlueskySearch {
	public const LIMIT = 8;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private ActorMapper $mapper,
		private Blocklist $blocklist,
		private LoggerInterface $logger,
		private ?BlueskyActorService $actors = null,
	) {
	}

	/**
	 * Whether a search text is one Bluesky is asked about.
	 */
	public function isCandidate(string $query): bool {
		$query = trim(ltrim($query, '@'));

		return $this->config->isEnabled()
			&& $query !== ''
			&& !str_contains($query, '@')
			&& !str_contains($query, ' ')
			&& !str_contains($query, '/')
			&& str_contains($query, '.')
			&& preg_match('/^[a-zA-Z0-9.-]+$/', $query) === 1;
	}

	/**
	 * @return Person[] cached accounts, the handle as account
	 */
	public function typeahead(string $query, int $limit = self::LIMIT): array {
		if (!$this->isCandidate($query)) {
			return [];
		}
		try {
			$answer = $this->appView->query('app.bsky.actor.searchActorsTypeahead', ['q' => trim(ltrim($query, '@')), 'limit' => max(1, min(25, $limit))]);
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky typeahead not answered', ['exception' => $e]);

			return [];
		}
		$people = [];
		foreach (is_array($answer['actors'] ?? null) ? $answer['actors'] : [] as $actor) {
			if (!is_array($actor) || ($actor['did'] ?? '') === '' || ($actor['handle'] ?? '') === '' || $this->blocklist->isBlockedDid((string)$actor['did'])) {
				continue;
			}
			try {
				$people[] = $this->cachedOf($this->mapper->person($actor), (string)$actor['did']);
			} catch (Throwable $e) {
				$this->logger->debug('Bluesky account not mapped', ['did' => $actor['did'], 'exception' => $e]);
			}
		}

		return $people;
	}

	/**
	 * The account as it is cached here, stored first when it is new: an
	 * account without a stored row has no id a client can use.
	 */
	private function cachedOf(Person $person, string $did): Person {
		if ($this->actors === null) {
			return $person;
		}
		$cached = $this->actors->cached($did);
		if ($cached === null) {
			$this->actors->store($person);
			$cached = $this->actors->cached($did);
		}

		return $cached ?? $person;
	}
}
