<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use OCA\Social\Atproto\Model\Event;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Db\AtprotoEventRequest;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Writes the firehose: one frame per thing that happened, numbered by the
 * database and kept for the replay window. The daemon reads them; a web
 * request only ever appends.
 */
class EventService {
	/** how long a frame can be replayed: what the reference relay keeps */
	public const REPLAY_WINDOW = 72 * 3600;

	public function __construct(
		private AtprotoEventRequest $eventRequest,
		private ITimeFactory $time,
	) {
	}

	/**
	 * A commit: the ops, and a CAR of the commit, the changed tree nodes and
	 * the records written.
	 *
	 * @param array<int, array{action: string, path: string, cid: Cid|null, prev: Cid|null}> $ops
	 * @param array<string, string> $blocks the CAR's blocks, the commit first
	 * @return int the sequence number
	 */
	public function commit(string $did, Cid $commitCid, string $rev, ?string $since, ?Cid $prevData, array $ops, array $blocks): int {
		$body = [
			'rebase' => false,
			'tooBig' => false,
			'repo' => $did,
			'commit' => $commitCid,
			'rev' => $rev,
			'since' => $since,
			'blocks' => new Bytes(Car::encode([$commitCid], $blocks)),
			'ops' => array_map(static function (array $op): array {
				$out = ['action' => $op['action'], 'path' => $op['path'], 'cid' => $op['cid']];
				if ($op['prev'] !== null) {
					$out['prev'] = $op['prev'];
				}

				return $out;
			}, $ops),
			'blobs' => [],
			'time' => $this->now(),
		];
		if ($prevData !== null) {
			$body['prevData'] = $prevData;
		}

		return $this->append($did, Event::KIND_COMMIT, $body);
	}

	/** the handle changed, or an account was just made */
	public function identity(string $did, string $handle): int {
		return $this->append($did, Event::KIND_IDENTITY, ['did' => $did, 'handle' => $handle, 'time' => $this->now()]);
	}

	/**
	 * @param string $status `deactivated`, `deleted`, `takendown` or `suspended`; '' when active
	 */
	public function account(string $did, bool $active, string $status = ''): int {
		$body = ['did' => $did, 'active' => $active, 'time' => $this->now()];
		if (!$active && $status !== '') {
			$body['status'] = $status;
		}

		return $this->append($did, Event::KIND_ACCOUNT, $body);
	}

	/**
	 * The repository is at this commit, whatever the stream said before: a
	 * relay that missed or dropped commits fetches the repository again.
	 */
	public function sync(string $did, Commit $commit): int {
		$cid = $commit->cid();

		return $this->append($did, Event::KIND_SYNC, [
			'did' => $did,
			'blocks' => new Bytes(Car::encode([$cid], [$cid->toString() => $commit->toBytes()])),
			'rev' => $commit->rev,
			'time' => $this->now(),
		]);
	}

	/**
	 * Drops frames past the window.
	 *
	 * @return int how many went
	 */
	public function prune(): int {
		return $this->eventRequest->pruneBefore($this->time->getTime() - self::REPLAY_WINDOW);
	}

	/**
	 * @return Event[] the frames after $seq, oldest first
	 */
	public function after(int $seq, int $limit = 200): array {
		return $this->eventRequest->after($seq, $limit);
	}

	public function latestSeq(): int {
		return $this->eventRequest->latestSeq();
	}

	public function oldestSeq(): int {
		return $this->eventRequest->oldestSeq();
	}

	public function countInWindow(): int {
		return $this->eventRequest->countSince($this->time->getTime() - self::REPLAY_WINDOW);
	}

	private function append(string $did, string $kind, array $body): int {
		return $this->eventRequest->append($did, $kind, DagCbor::encode($body), $this->time->getTime());
	}

	private function now(): string {
		return Syntax::datetime($this->time->getTime());
	}
}
