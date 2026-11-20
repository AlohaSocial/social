<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Moves whose job died while running (worker killed, server restarted).
 * Core drops a queued job before running it, so without this such a move
 * would hold the account for good.
 *
 * A move is stopped when it is `running`, unwritten for AFTER, and has no
 * queued job and no lock (Cron\AtprotoMove::lockOf()). It is queued again up
 * to RESUMES times, then fails with `move-interrupted`. A move away that
 * stopped during the DID handover goes on from activation once the
 * directory names the other server; a move here that stopped at the
 * best-effort follows or posts is done.
 */
class StaleMoves {
	/** how long a running move goes without being written to before it counts as stopped */
	public const AFTER = 3600;
	/** how often a stopped move is queued again before it fails */
	public const RESUMES = 1;
	/** how many stopped moves one run looks at */
	private const LIMIT = 20;

	public function __construct(
		private AtprotoMoveRequest $moves,
		private IJobList $jobList,
		private ILockingProvider $locks,
		private IdentityService $identities,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return int how many stopped moves were queued again or ended
	 */
	public function recover(): int {
		$recovered = 0;
		foreach ($this->moves->getStale($this->time->getTime() - self::AFTER, self::LIMIT) as $move) {
			if ($this->jobList->has(AtprotoMove::class, ['move' => $move->id])
				|| $this->locks->isLocked(AtprotoMove::lockOf($move->id), ILockingProvider::LOCK_EXCLUSIVE)) {
				continue;
			}
			try {
				$this->settleHandOver($move);
			} catch (AtprotoException $e) {
				$this->logger->info('Stopped Bluesky account move left for the next run: the directory could not be asked', ['did' => $move->did, 'exception' => $e]);
				continue;
			}
			$resumed = (int)($move->progress['resumed'] ?? 0);
			if ($resumed < self::RESUMES) {
				$move->progress['resumed'] = $resumed + 1;
				if (!$this->moves->updateIfRunning($move)) {
					continue;
				}
				$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);
				$this->logger->warning('Bluesky account move queued again: its job stopped', ['did' => $move->did, 'direction' => $move->direction, 'step' => $move->step]);
			} else {
				$this->end($move);
				if (!$this->moves->updateIfRunning($move)) {
					continue;
				}
				$this->logger->warning('Bluesky account move ended: its job stopped again', ['did' => $move->did, 'direction' => $move->direction, 'step' => $move->step, 'state' => $move->state]);
			}
			$recovered++;
		}

		return $recovered;
	}

	/**
	 * A move away that stopped while the DID was handed over goes on from
	 * the activation once the directory names the other server.
	 *
	 * @throws AtprotoException when the directory cannot be asked
	 */
	private function settleHandOver(Move $move): void {
		if ($move->direction !== Move::AWAY) {
			return;
		}
		if ($move->step === Move::STEP_IDENTITY && $this->identities->pointsAt($move->did, MoveAwayService::endpointOf($move))) {
			$move->step = Move::STEP_ACTIVATE;
		}
		$move->progress['stage'] = MoveAwayService::stageOf($move);
	}

	/**
	 * A move stopped too often: done if it was a move here at the best-effort
	 * follows or posts, waiting for the person's blob decision if a tool drove
	 * it, otherwise failed.
	 */
	private function end(Move $move): void {
		if ($move->direction !== Move::AWAY && in_array($move->step, [Move::STEP_FOLLOWS, Move::STEP_POSTS], true)) {
			$move->step = Move::STEP_DONE;
			$move->state = Move::DONE;
			if ($move->direction === Move::IN) {
				// the old server's session is not needed once the move is done
				$move->session = '';
			}

			return;
		}
		if ($move->direction !== Move::INBOUND) {
			$move->fail('The move stopped before it was finished', 'move-interrupted');

			return;
		}
		$move->state = Move::NEEDS_DECISION;
		$move->progress = ['code' => 'blobs-missing'] + $move->progress;
	}
}
