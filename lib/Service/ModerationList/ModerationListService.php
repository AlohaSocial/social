<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\ModerationList;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Reader\BlueskyModerationLists;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\TimelineRevisionService;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists of accounts to mute or block that a person subscribes to: every
 * account on the list is muted or blocked here, as one they muted or
 * blocked themselves would be, wherever it is, and kept in step as the list
 * changes (`refresh()`). Only the mutes and blocks a list made are its own
 * to take back: an account the person had muted already stays muted when
 * the list goes.
 */
class ModerationListService {
	public const KINDS = [ActorRelation::TYPE_MUTE, ActorRelation::TYPE_BLOCK];
	/** the most accounts one list mutes or blocks */
	public const MAX_MEMBERS = 1000;
	public const MAX_LISTS = 20;
	private const KEY = 'moderation_lists';
	/** the people who subscribe to any list, so a refresh finds them */
	private const SUBSCRIBERS = 'moderation_list_subscribers';

	public function __construct(
		private IConfig $config,
		private ActorRelationRequest $relations,
		private TimelineRevisionService $revisions,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * @return list<array{uri: string, name: string, kind: string, accounts: int}>
	 */
	public function list(string $userId): array {
		return array_map(static fn (array $list): array => [
			'uri' => $list['uri'], 'name' => $list['name'], 'kind' => $list['kind'], 'accounts' => count($list['added']),
		], $this->stored($userId));
	}

	/**
	 * @throws InvalidActionException a list nobody here can read, or one too many
	 */
	public function subscribe(Person $viewer, string $reference, string $kind): array {
		if (!in_array($kind, self::KINDS, true)) {
			throw new InvalidActionException('kind must be mute or block');
		}
		$userId = $viewer->getUserId();
		$lists = $this->stored($userId);
		if (count($lists) >= self::MAX_LISTS) {
			throw new InvalidActionException('too many lists');
		}
		$described = null;
		foreach ($this->sources() as $source) {
			$described = $source->describe($reference);
			if ($described !== null) {
				break;
			}
		}
		if ($described === null || !isset($source)) {
			throw new InvalidActionException('not a list this server can read');
		}
		$lists = array_values(array_filter($lists, static fn (array $list): bool => $list['uri'] !== $described['uri']));
		$list = ['uri' => $described['uri'], 'name' => $described['name'], 'kind' => $kind, 'added' => []];
		$list = $this->apply($viewer, $source, $list);
		$lists[] = $list;
		$this->store($userId, $lists);
		$source->subscribed($viewer, $list['uri'], $kind, true);

		return ['uri' => $list['uri'], 'name' => $list['name'], 'kind' => $kind, 'accounts' => count($list['added'])];
	}

	public function unsubscribe(Person $viewer, string $uri): void {
		$userId = $viewer->getUserId();
		$kept = [];
		foreach ($this->stored($userId) as $list) {
			if ($list['uri'] !== $uri) {
				$kept[] = $list;
				continue;
			}
			foreach ($list['added'] as $actorId) {
				$this->relations->delete($viewer->getId(), $actorId, $list['kind']);
			}
			foreach ($this->sources() as $source) {
				$source->subscribed($viewer, $uri, $list['kind'], false);
			}
		}
		$this->store($userId, $kept);
		$this->revisions->bumpForActor($viewer->getId());
	}

	/**
	 * Every list of one person read again: the accounts added to it muted or
	 * blocked, the ones taken off it no longer.
	 */
	public function refresh(Person $viewer): void {
		$lists = [];
		foreach ($this->stored($viewer->getUserId()) as $list) {
			foreach ($this->sources() as $source) {
				try {
					$list = $this->apply($viewer, $source, $list);
				} catch (Throwable $e) {
					$this->logger->info('A moderation list was not read', ['list' => $list['uri'], 'exception' => $e]);
				}
			}
			$lists[] = $list;
		}
		$this->store($viewer->getUserId(), $lists);
	}

	/**
	 * @return list<string> the users who subscribe to any list
	 */
	public function subscribers(): array {
		$ids = json_decode($this->config->getAppValue(Application::APP_ID, self::SUBSCRIBERS, '[]'), true);

		return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
	}

	/**
	 * The list's members muted or blocked, the ones this list added and that
	 * are no longer on it let go.
	 */
	private function apply(Person $viewer, AccountListSource $source, array $list): array {
		$members = $source->members($list['uri'], self::MAX_MEMBERS);
		if ($members === []) {
			return $list;
		}
		$added = $list['added'];
		foreach (array_diff($added, $members) as $gone) {
			$this->relations->delete($viewer->getId(), $gone, $list['kind']);
		}
		$added = array_values(array_intersect($added, $members));
		foreach ($members as $actorId) {
			if ($actorId === $viewer->getId() || in_array($actorId, $added, true) || $this->relations->exists($viewer->getId(), $actorId, $list['kind'])) {
				continue;
			}
			$this->relations->save($viewer->getId(), $actorId, $list['kind']);
			$added[] = $actorId;
		}
		$this->revisions->bumpForActor($viewer->getId());

		return ['added' => $added] + $list;
	}

	/**
	 * @return list<array{uri: string, name: string, kind: string, added: list<string>}>
	 */
	private function stored(string $userId): array {
		$lists = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY, '[]'), true);
		$out = [];
		foreach (is_array($lists) ? $lists : [] as $list) {
			if (is_array($list) && is_string($list['uri'] ?? null) && in_array($list['kind'] ?? '', self::KINDS, true)) {
				$added = [];
				foreach (is_array($list['added'] ?? null) ? $list['added'] : [] as $actorId) {
					if (is_string($actorId)) {
						$added[] = $actorId;
					}
				}
				$out[] = ['uri' => $list['uri'], 'name' => (string)($list['name'] ?? ''), 'kind' => $list['kind'], 'added' => $added];
			}
		}

		return $out;
	}

	private function store(string $userId, array $lists): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY, (string)json_encode($lists, JSON_UNESCAPED_SLASHES));
		$subscribers = array_values(array_diff($this->subscribers(), [$userId]));
		if ($lists !== []) {
			$subscribers[] = $userId;
		}
		$this->config->setAppValue(Application::APP_ID, self::SUBSCRIBERS, (string)json_encode($subscribers));
	}

	/**
	 * @return list<AccountListSource>
	 */
	private function sources(): array {
		$source = $this->container?->get(BlueskyModerationLists::class);

		return $source instanceof AccountListSource ? [$source] : [];
	}
}
