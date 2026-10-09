<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\ModerationList\AccountListSource;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bluesky's lists, to mute or block everybody on one: named by their
 * bsky.app link (`/profile/<handle>/lists/<rkey>`) or `at://` address, read
 * from the AppView (`getList`), each account cached as it is found. A
 * subscription is told to Bluesky where it lives: a mute list is muted at
 * the AppView (`muteActorList`, private, so a Bluesky app signed in here
 * hides the same accounts), and a block list is published as an
 * `app.bsky.graph.listblock` record only when the person publishes their
 * blocks (`BlueskyBlocks`), as a Bluesky block is public.
 */
class BlueskyModerationLists implements AccountListSource {
	public const LISTBLOCK = 'app.bsky.graph.listblock';
	private const PAGE = 100;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private IdentityService $identities,
		private BlueskyActorService $actors,
		private ActorMapper $mapper,
		private BlueskyBlocks $blocks,
		private Publisher $publisher,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function describe(string $reference): ?array {
		$uri = $this->config->isEnabled() ? $this->uriOf($reference) : '';
		if ($uri === '') {
			return null;
		}
		try {
			$answer = $this->appView->query('app.bsky.graph.getList', ['list' => $uri, 'limit' => 1]);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky list not read', ['list' => $uri, 'exception' => $e]);

			return null;
		}
		$list = is_array($answer['list'] ?? null) ? $answer['list'] : null;

		return $list === null ? null : ['uri' => (string)($list['uri'] ?? $uri), 'name' => (string)($list['name'] ?? '')];
	}

	#[\Override]
	public function members(string $uri, int $limit): array {
		if (!$this->config->isEnabled() || !str_starts_with($uri, 'at://')) {
			return [];
		}
		$ids = [];
		$cursor = '';
		do {
			try {
				$answer = $this->appView->query('app.bsky.graph.getList', ['list' => $uri, 'limit' => self::PAGE] + ($cursor === '' ? [] : ['cursor' => $cursor]));
			} catch (Throwable $e) {
				$this->logger->info('Bluesky list not read', ['list' => $uri, 'exception' => $e]);
				// a list that cannot be read is not a list that is empty
				return $ids;
			}
			foreach (is_array($answer['items'] ?? null) ? $answer['items'] : [] as $item) {
				$profile = is_array($item['subject'] ?? null) ? $item['subject'] : [];
				$did = (string)($profile['did'] ?? '');
				if ($did === '' || ($profile['handle'] ?? '') === '') {
					continue;
				}
				try {
					if ($this->actors->cached($did) === null) {
						$this->actors->store($this->mapper->person($profile));
					}
					$ids[] = BlueskyIds::actorId($did);
				} catch (Throwable $e) {
					$this->logger->debug('An account on a Bluesky list was not cached', ['did' => $did, 'exception' => $e]);
				}
			}
			$cursor = (string)($answer['cursor'] ?? '');
		} while ($cursor !== '' && count($ids) < $limit);

		return array_slice(array_values(array_unique($ids)), 0, $limit);
	}

	#[\Override]
	public function subscribed(Person $viewer, string $uri, string $kind, bool $on): void {
		if (!str_starts_with($uri, 'at://')) {
			return;
		}
		try {
			if ($kind === ActorRelation::TYPE_MUTE) {
				$identity = $this->identities->forActor($viewer, false);
				if ($identity !== null) {
					$this->appView->procedureAs($identity->did, $this->identities->signingKey($identity), $on ? 'app.bsky.graph.muteActorList' : 'app.bsky.graph.unmuteActorList', ['list' => $uri]);
				}

				return;
			}
			if (!$on) {
				$this->publisher->removeRecord(self::LISTBLOCK, self::localId($viewer, $uri));
			} elseif ($this->blocks->isPublished($viewer->getUserId())) {
				$this->publisher->writeRecord($viewer, self::LISTBLOCK, [
					'$type' => self::LISTBLOCK,
					'subject' => $uri,
					'createdAt' => Syntax::datetime($this->time->getTime()),
				], self::localId($viewer, $uri));
			}
		} catch (Throwable $e) {
			$this->logger->warning('A list subscription not told to Bluesky', ['list' => $uri, 'exception' => $e]);
		}
	}

	private static function localId(Person $viewer, string $uri): string {
		return $viewer->getId() . '#listblock/' . md5($uri);
	}

	/**
	 * The list's `at://` address from its bsky.app link or the address
	 * itself; '' for anything else.
	 */
	private function uriOf(string $reference): string {
		$reference = trim($reference);
		if (str_starts_with($reference, 'at://')) {
			$parsed = Syntax::parseAtUri($reference);

			return ($parsed !== null && $parsed['collection'] === 'app.bsky.graph.list' && $parsed['rkey'] !== '') ? $reference : '';
		}
		if (preg_match('#^https://bsky\\.app/profile/([^/?\\#]+)/lists/([a-zA-Z0-9._~-]+)#', $reference, $m) !== 1) {
			return '';
		}
		$actor = rawurldecode($m[1]);
		if (!Syntax::isDid($actor)) {
			try {
				$actor = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $actor])['did'] ?? '');
			} catch (Throwable) {
				return '';
			}
		}

		return Syntax::isDid($actor) ? 'at://' . $actor . '/app.bsky.graph.list/' . $m[2] : '';
	}
}
