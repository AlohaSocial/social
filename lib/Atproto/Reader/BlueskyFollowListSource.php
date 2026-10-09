<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\FollowList\FollowListService;
use OCA\Social\Service\FollowList\FollowListSource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who follows an account on Bluesky, and whom it follows, as the AppView
 * lists them (`getFollowers`, `getFollows`), a few pages of them. Each
 * account is the one cached here, or is cached as it is found; an account
 * of this server's own is named by its local id. Bluesky has no way to
 * hide the lists.
 */
class BlueskyFollowListSource implements FollowListSource {
	/** the most pages one read asks for */
	private const PAGES = 3;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private ActorMapper $mapper,
		private BlueskyActorService $actors,
		private LocalRecordResolver $local,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Person $account): bool {
		return $this->config->isEnabled() && BlueskyActorService::isBluesky($account);
	}

	#[\Override]
	public function accounts(Person $account, string $direction, int $limit): ?array {
		$did = BlueskyIds::didOf($account->getId());
		[$method, $key] = $direction === FollowListService::FOLLOWING ? ['app.bsky.graph.getFollows', 'follows'] : ['app.bsky.graph.getFollowers', 'followers'];
		$ids = [];
		$cursor = '';
		for ($page = 0; $page < self::PAGES && count($ids) < $limit; $page++) {
			$params = ['actor' => $did, 'limit' => max(1, min(100, $limit - count($ids)))];
			if ($cursor !== '') {
				$params['cursor'] = $cursor;
			}
			try {
				$answer = $this->appView->query($method, $params);
			} catch (Throwable $e) {
				$this->logger->info('A Bluesky follow list was not read', ['did' => $did, 'exception' => $e]);
				break;
			}
			foreach (is_array($answer[$key] ?? null) ? $answer[$key] : [] as $profile) {
				$id = is_array($profile) ? $this->idOf($profile) : '';
				if ($id !== '' && $id !== $account->getId()) {
					$ids[] = $id;
				}
			}
			$cursor = is_string($answer['cursor'] ?? null) ? $answer['cursor'] : '';
			if ($cursor === '') {
				break;
			}
		}

		return array_slice(array_values(array_unique($ids)), 0, $limit);
	}

	/**
	 * The actor id of one listed profile, cached here when it is new; ''
	 * for a profile without a DID or a handle.
	 */
	private function idOf(array $profile): string {
		$did = (string)($profile['did'] ?? '');
		if ($did === '' || ($profile['handle'] ?? '') === '') {
			return '';
		}
		$local = $this->local->actorId($did);
		if ($local !== '') {
			return $local;
		}
		try {
			if ($this->actors->cached($did) === null) {
				$this->actors->store($this->mapper->person($profile));
			}

			return BlueskyIds::actorId($did);
		} catch (Throwable $e) {
			$this->logger->debug('A Bluesky account was not cached', ['did' => $did, 'exception' => $e]);

			return '';
		}
	}
}
