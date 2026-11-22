<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\FollowService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Follows from here everyone a Bluesky account follows, without moving it:
 * its public follow records are read from its PDS (no password) and followed
 * in the background as a `bluesky_follows` import. Only a whole move brings
 * the followers along.
 */
class FollowFrom {
	/** the most follows one run takes over */
	public const MAX_FOLLOWS = 5000;
	private const PAGE = 100;
	/** the most pages of follow records one run reads: twice what MAX_FOLLOWS takes */
	public const MAX_PAGES = 100;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PlcClient $plc,
		private PdsClient $pds,
		private BlueskyActorService $blueskyActors,
		private AccountService $accountService,
		private FollowService $followService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The account a handle or DID names, and its PDS, for the import's
	 * options: found now, so what cannot be found is said at once.
	 *
	 * @return array{did: string, pds: string, handle: string}
	 * @throws MoveErrorException
	 */
	public function resolve(string $typed): array {
		if (!$this->config->isEnabled()) {
			throw new MoveErrorException('Bluesky is not enabled on this server', 'bluesky-off');
		}
		$typed = strtolower(trim(ltrim(trim($typed), '@')));
		$did = $typed;
		if (!Syntax::isDid($typed)) {
			if (!Syntax::isHandle($typed)) {
				throw new MoveErrorException('That is not a Bluesky handle', 'handle-invalid');
			}
			try {
				$did = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $typed])['did'] ?? '');
			} catch (Throwable) {
				$did = '';
			}
			if (!Syntax::isDid($did)) {
				throw new MoveErrorException('No Bluesky account goes by ' . $typed, 'handle-not-found');
			}
		}
		if (!str_starts_with($did, 'did:plc:')) {
			throw new MoveErrorException('Only accounts with a did:plc can be read this way', 'did-not-supported');
		}
		try {
			$data = $this->plc->data($did);
		} catch (AtprotoException $e) {
			throw new MoveErrorException($e->getMessage(), 'plc-unreachable', $e);
		}
		$endpoint = (string)($data['services']['atproto_pds']['endpoint'] ?? '');
		if ($data === null || $endpoint === '') {
			throw new MoveErrorException('The directory knows no server for ' . $did, 'did-not-found');
		}
		try {
			$origin = $this->pds->origin($endpoint);
		} catch (AtprotoException $e) {
			throw new MoveErrorException($e->getMessage(), MoveAwayService::codeOf($e), $e);
		}

		return [
			'did' => $did,
			'pds' => $origin,
			'handle' => (string)preg_replace('#^at://#', '', (string)($data['alsoKnownAs'][0] ?? $typed)),
		];
	}

	/**
	 * Follows from the person's account everyone the Bluesky account
	 * follows, one at a time; one already followed is skipped, one that
	 * fails does not stop the rest.
	 *
	 * @param array{did?: string, pds?: string} $options what resolve() found
	 * @param callable(int, int): void|null $progress told how many are done, of how many
	 * @return array{followed: int, skipped: int, failed: array<string, string>}
	 * @throws AtprotoException when the follows cannot be read
	 */
	public function run(string $userId, array $options, ?callable $progress = null): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$subjects = $this->subjects((string)($options['pds'] ?? ''), (string)($options['did'] ?? ''));
		$result = ['followed' => 0, 'skipped' => 0, 'failed' => []];
		$total = count($subjects);
		foreach ($subjects as $i => $subject) {
			if ($progress !== null) {
				$progress($i, $total);
			}
			try {
				if ($this->followService->followActor($actor, $this->blueskyActors->resolve($subject))) {
					$result['followed']++;
				} else {
					$result['skipped']++;
				}
			} catch (FollowSameAccountException) {
				$result['skipped']++;
			} catch (Throwable $e) {
				$result['failed'][$subject] = $e->getMessage();
				$this->logger->notice('Bluesky follow not taken over', ['actor' => $actor->getId(), 'subject' => $subject, 'exception' => $e]);
			}
		}
		if ($progress !== null) {
			$progress($total, $total);
		}

		return $result;
	}

	/**
	 * The DIDs the account follows, from its public follow records. The
	 * PDS is the person's to name, so the reading ends at a cursor it named
	 * before, at a page that adds no account, and after MAX_PAGES pages.
	 *
	 * @return list<string>
	 * @throws AtprotoException
	 */
	private function subjects(string $pds, string $did): array {
		if ($pds === '' || !Syntax::isDid($did)) {
			throw new AtprotoException('The import names no Bluesky account');
		}
		$subjects = [];
		$cursors = [];
		$cursor = '';
		do {
			$cursors[$cursor] = true;
			$page = $this->pds->expect($this->pds->origin($pds), 'com.atproto.repo.listRecords', 'GET', [
				'repo' => $did, 'collection' => RecordMapper::FOLLOW, 'limit' => self::PAGE,
			] + ($cursor !== '' ? ['cursor' => $cursor] : []));
			$before = count($subjects);
			foreach (is_array($page['records'] ?? null) ? $page['records'] : [] as $record) {
				$subject = is_array($record) ? (string)($record['value']['subject'] ?? '') : '';
				if (Syntax::isDid($subject) && $subject !== $did) {
					$subjects[$subject] = true;
				}
			}
			$cursor = (string)($page['cursor'] ?? '');
		} while ($cursor !== '' && !isset($cursors[$cursor]) && count($subjects) > $before
			&& count($subjects) < self::MAX_FOLLOWS && count($cursors) < self::MAX_PAGES);

		return array_slice(array_keys($subjects), 0, self::MAX_FOLLOWS);
	}
}
