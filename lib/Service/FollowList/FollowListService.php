<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\FollowList;

use OCA\Social\Atproto\Reader\BlueskyFollowListSource;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Tools\Nid;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who follows an account on another server, and whom it follows, wherever
 * they do: the follows this server knows, and the ones the network the
 * account lives on lists (`FollowListSource`), one list with nothing to
 * tell them apart. What the network lists is read in the background
 * (`Cron\FillFollowLists`) and kept for an hour in the table, where the web
 * request reads it. An account that keeps its list to itself there has it
 * kept here too: the list is empty.
 */
class FollowListService {
	public const FOLLOWERS = ProbeOptions::FOLLOWERS;
	public const FOLLOWING = ProbeOptions::FOLLOWING;
	/** how long what a network listed is kept */
	private const KEPT = 3600;
	private const CACHE = 'social.followlists';
	/** the most a network is asked for */
	public const LIMIT = 100;

	public function __construct(
		private CacheActorService $cacheActors,
		private FollowsRequest $follows,
		private DurableCache $durableCache,
		private RemoteFetchQueue $queue,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * One page of the list: the accounts known here, paged as always, and
	 * after the last of them the ones the network lists. A page that reaches
	 * into those is continued by the `max_id` of its last account: `next`
	 * is that cursor ('' at the end), and null on a page of this server's
	 * own accounts alone, which pages as it always has.
	 *
	 * @param ProbeOptions $options the page asked for, its probe and account set
	 * @return array{accounts: Person[], next: string|null, filling: bool}
	 */
	public function page(Person $account, string $direction, ProbeOptions $options): array {
		$filling = $this->queue->fillFollowList($account, $direction);
		$listed = $this->durableCache->getShared(self::CACHE, self::key($account->getId(), $direction));
		if (is_array($listed) && ($listed['hidden'] ?? false) === true) {
			return ['accounts' => [], 'next' => '', 'filling' => $filling];
		}
		$ids = is_array($listed) && is_array($listed['ids'] ?? null) ? array_values(array_filter($listed['ids'], 'is_string')) : [];
		$network = $this->listed($account, $direction, $ids);
		$limit = $options->getLimit();

		$maxId = (string)$options->getMaxId();
		foreach ($network as $position => $person) {
			if ((string)$person->getNid() === $maxId) {
				$accounts = array_slice($network, $position + 1, $limit);

				return ['accounts' => $accounts, 'next' => self::next($accounts, count($network) > $position + 1 + $limit), 'filling' => $filling];
			}
		}

		$local = $this->cacheActors->probeActors($options);
		$newer = (string)$options->getMinId() !== '0' || (string)$options->getSince() !== '0';
		if ($network === [] || $newer || count($local) >= $limit) {
			return ['accounts' => $local, 'next' => null, 'filling' => $filling];
		}
		$room = $limit - count($local);
		$accounts = array_merge($local, array_slice($network, 0, $room));

		return ['accounts' => $accounts, 'next' => self::next($accounts, count($network) > $room), 'filling' => $filling];
	}

	/**
	 * The background read (`Cron\FillFollowLists`): who the networks list
	 * for the account, or that it hides the list.
	 */
	public function fill(string $actorId, string $direction): void {
		$account = $this->cacheActors->getCachedFromIds([$actorId])[$actorId] ?? null;
		if ($account === null || $account->isLocal()) {
			return;
		}
		$ids = [];
		$hidden = false;
		foreach ($this->sources() as $source) {
			if (!$source->supports($account)) {
				continue;
			}
			try {
				$found = $source->accounts($account, $direction, self::LIMIT);
				if ($found === null) {
					$hidden = true;
				} else {
					$ids = array_merge($ids, $found);
				}
			} catch (Throwable $e) {
				$this->logger->info('A follow list was not read', ['account' => $actorId, 'source' => $source::class, 'exception' => $e]);
			}
		}
		$this->durableCache->setShared(self::CACHE, self::key($actorId, $direction), [
			'ids' => $hidden ? [] : array_values(array_unique($ids)),
			'hidden' => $hidden,
		], self::KEPT);
	}

	/**
	 * The accounts the network listed that this server can show and does
	 * not have in its own list, in the network's order.
	 *
	 * @param list<string> $ids
	 * @return list<Person>
	 */
	private function listed(Person $account, string $direction, array $ids): array {
		$ids = array_values(array_diff($ids, [$account->getId()]));
		if ($ids === []) {
			return [];
		}
		$between = $this->follows->getBetweenMany($account->getId(), $ids);
		$known = [];
		foreach ($between[$direction === self::FOLLOWING ? 'following' : 'followedBy'] as $id => $follow) {
			if ($follow->getType() === Follow::TYPE && $follow->isAccepted()) {
				$known[$id] = true;
			}
		}
		$cached = $this->cacheActors->getCachedFromIds($ids);
		$accounts = [];
		foreach ($ids as $id) {
			$person = $cached[$id] ?? null;
			// every account in the API is addressed by its numeric id
			if ($person === null || isset($known[$id]) || Nid::compare($person->getNid(), 0) <= 0) {
				continue;
			}
			$person->setExportFormat(ACore::FORMAT_LOCAL);
			$accounts[] = $person;
		}

		return $accounts;
	}

	/**
	 * @param Person[] $accounts
	 */
	private static function next(array $accounts, bool $more): string {
		$last = end($accounts);

		return ($more && $last instanceof Person) ? (string)$last->getNid() : '';
	}

	private static function key(string $actorId, string $direction): string {
		return md5($direction . "\0" . $actorId);
	}

	/**
	 * @return list<FollowListSource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([ActivityPubFollowListSource::class, BlueskyFollowListSource::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof FollowListSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
