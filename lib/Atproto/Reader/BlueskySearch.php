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
use OCA\Social\Tools\Nid;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finding Bluesky accounts by name: the public AppView's typeahead, asked
 * for text that looks like the start of a handle — a dot in it and no `@`
 * — so a Fediverse address or a plain username never leaves the instance.
 * What comes back is the cached actor for an account already here and the
 * account as the AppView described it for the rest, stored by nothing: a
 * typeahead is asked once per keystroke, and an account becomes a cached
 * row when somebody opens, follows or mentions it, or through `keep()`.
 */
class BlueskySearch {
	public const LIMIT = 8;

	/** How long the AppView's answer to one query is reused. */
	public const ANSWER_TTL = 60;

	private ?ICache $answers = null;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private ActorMapper $mapper,
		private Blocklist $blocklist,
		private LoggerInterface $logger,
		private ?BlueskyActorService $actors = null,
		?ICacheFactory $cacheFactory = null,
	) {
		$this->answers = $cacheFactory?->createDistributed('social.bsky.typeahead');
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
			&& preg_match('/^[a-zA-Z0-9.-]+$/D', $query) === 1;
	}

	/**
	 * The accounts whose handle or name starts like the query: the cached
	 * one for an account here, the unstored one, with no id yet, for the
	 * rest. Nothing is written.
	 *
	 * @return Person[] the handle as account
	 */
	public function typeahead(string $query, int $limit = self::LIMIT): array {
		if (!$this->isCandidate($query)) {
			return [];
		}
		$people = [];
		foreach ($this->answer(trim(ltrim($query, '@')), max(1, min(25, $limit))) as $actor) {
			if (!is_array($actor) || ($actor['did'] ?? '') === '' || ($actor['handle'] ?? '') === '' || $this->blocklist->isBlockedDid((string)$actor['did'])) {
				continue;
			}
			try {
				$people[] = $this->actors?->cached((string)$actor['did']) ?? $this->mapper->person($actor);
			} catch (Throwable $e) {
				$this->logger->debug('Bluesky account not mapped', ['did' => $actor['did'], 'exception' => $e]);
			}
		}

		return $people;
	}

	/**
	 * The accounts as cached rows, each one the typeahead found and nobody
	 * stored yet stored now: an account without a row has no id a client
	 * can open or follow it by. For an answer that hands out ids — a
	 * client's search that asked for `resolve` — and nothing else.
	 *
	 * @param Person[] $people what `typeahead()` answered
	 * @return Person[]
	 */
	public function keep(array $people): array {
		if ($this->actors === null) {
			return $people;
		}
		$kept = [];
		foreach ($people as $person) {
			if (Nid::compare($person->getNid(), 0) > 0) {
				$kept[] = $person;
				continue;
			}
			$did = (string)($person->getDetails(ActorMapper::DETAIL)['did'] ?? '');
			try {
				$this->actors->store($person);
				$kept[] = $this->actors->cached($did) ?? $person;
			} catch (Throwable $e) {
				$this->logger->notice('Bluesky account not stored', ['did' => $did, 'exception' => $e]);
			}
		}

		return $kept;
	}

	/**
	 * The AppView's `actors` for a query, reused for ANSWER_TTL seconds: the
	 * same prefix is asked again by the next keystroke's correction, by a
	 * second tab and by the composer and the search at once.
	 *
	 * @return array<mixed>
	 */
	private function answer(string $query, int $limit): array {
		$key = md5(strtolower($query) . '|' . $limit);
		$cached = $this->answers?->get($key);
		if (is_array($cached)) {
			return $cached;
		}
		try {
			$answer = $this->appView->query('app.bsky.actor.searchActorsTypeahead', ['q' => $query, 'limit' => $limit], timeout: AppViewClient::INTERACTIVE_TIMEOUT);
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky typeahead not answered', ['exception' => $e]);

			return [];
		}
		$actors = is_array($answer['actors'] ?? null) ? $answer['actors'] : [];
		$this->answers?->set($key, $actors, self::ANSWER_TTL);

		return $actors;
	}
}
