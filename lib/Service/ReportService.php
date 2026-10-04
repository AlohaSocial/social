<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ReportsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\Report;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Moderation reports: stores reports filed locally over the client API or
 * received from remote instances as Flag activities, and tells the instance
 * admins about new ones.
 */
class ReportService {
	public function __construct(
		private ReportsRequest $reportsRequest,
		private CacheActorService $cacheActorService,
		private ModeratorService $moderatorService,
		private INotificationManager $notificationManager,
		private ReportForwardService $reportForwardService,
		private StreamRequest $streamRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A report filed by a local user over POST /api/v1/reports.
	 *
	 * @param string[] $statusIds
	 * @param bool $forward Mastodon's `forward`: also tell the instance the
	 *                      reported account is on. Ignored for a local
	 *                      account, which has no other instance to tell.
	 */
	public function reportFromLocal(
		Person $reporter, Person $target, array $statusIds, string $comment, string $category,
		bool $forward = false,
	): Report {
		$report = new Report();
		$report->setActorId($reporter->getId())
			->setAccountId($target->getId())
			->setStatusIds($statusIds)
			->setComment($comment)
			->setCategory($category)
			->setLocal(true)
			->setTargetAccount($target);

		$this->reportsRequest->save($report);
		$this->notifyAdmins($report);

		if ($forward) {
			$this->forward($report, $target);
		}

		return $report;
	}

	/**
	 * Forwards the report, and records whether the remote instance took it.
	 *
	 * Done after the report is stored, so the moderators here have it whatever
	 * the other instance does with it, and never throws: the report was filed
	 * successfully even when the forward could not be delivered.
	 */
	private function forward(Report $report, Person $target): void {
		try {
			if (!$this->reportForwardService->forward($report, $target)) {
				return;
			}

			$report->setForwarded(true);
			$this->reportsRequest->setForwarded($report->getId(), true);
		} catch (Exception $e) {
			$this->logger->warning('could not forward a report', [
				'report' => $report->getId(), 'exception' => $e,
			]);
		}
	}

	/**
	 * A report received from a remote instance as a Flag activity. Mastodon
	 * sends `object` as a list mixing the reported account and status ids; the
	 * first id that resolves to a local account is stored as the target, the
	 * remaining ids as the reported statuses. A Flag that names no local
	 * account but a local post is a report about that post's author.
	 *
	 * Resolved against the cache alone, in one query: a local account is
	 * always there, and a lookup that fetched every id it missed made this
	 * instance send a request to each URL a sender cared to list, inside the
	 * inbox request. The posts are looked up by id in the local table only.
	 *
	 * A Flag naming nothing of this instance is dropped, and null is returned:
	 * there is nothing here for a moderator to act on, and storing it put a
	 * row and a notification in front of every moderator for any URL a sender
	 * cared to list.
	 */
	public function reportFromFlag(Flag $flag): ?Report {
		$cached = $this->cacheActorService->getCachedFromIds($flag->getObjectIds());
		$accountId = '';
		$statusIds = [];
		foreach ($flag->getObjectIds() as $objectId) {
			$actor = $cached[$objectId] ?? null;
			if ($accountId === '' && $actor !== null && $actor->isLocal()) {
				$accountId = $objectId;
				continue;
			}
			$statusIds[] = $objectId;
		}

		if ($accountId === '') {
			$accountId = $this->localAuthorOf($statusIds);
		}

		if ($accountId === '') {
			$this->logger->notice(
				'incoming Flag names no account or post of this instance, dropped',
				['actor' => $flag->getActorId(), 'objects' => $flag->getObjectIds()]
			);

			return null;
		}

		$report = new Report();
		$report->setActorId($flag->getActorId())
			->setAccountId($accountId)
			->setStatusIds($statusIds)
			->setComment($flag->getContent())
			->setCategory(Report::CATEGORY_OTHER)
			->setLocal(false);

		$this->reportsRequest->save($report);
		$this->notifyAdmins($report);

		return $report;
	}

	/**
	 * The author of the first id that is a post of this instance, or '' when
	 * none is.
	 *
	 * @param string[] $ids
	 */
	private function localAuthorOf(array $ids): string {
		foreach ($ids as $id) {
			try {
				$post = $this->streamRequest->getStreamById($id);
			} catch (StreamNotFoundException $e) {
				continue;
			}

			if ($post->isLocal() && $post->getAttributedTo() !== '') {
				return $post->getAttributedTo();
			}
		}

		return '';
	}

	/**
	 * The reports for the moderation panel, with their target accounts filled
	 * in from what is already cached.
	 *
	 * One query for the accounts of the whole page, and no federated request
	 * on a miss: resolving per report meant up to 200 synchronous requests to
	 * other instances per admin page load, and a single unreachable one held
	 * the page for its curl timeout. A report whose account is not cached
	 * still shows — with the id it was filed against, which is what the
	 * moderator needs to act on it.
	 *
	 * @return Report[] target accounts resolved where possible
	 */
	public function getReports(bool $includeResolved = false): array {
		return $this->withTargets($this->reportsRequest->getAll($includeResolved));
	}

	/**
	 * One page of the open reports, or of the resolved ones, newest first,
	 * with how many there are in all so the panel can say what it is not
	 * showing.
	 *
	 * @param int $page 1-based
	 *
	 * @return array{reports: Report[], total: int, page: int, perPage: int}
	 */
	public function page(bool $resolved, int $page = 1): array {
		$page = max(1, $page);
		$reports = $this->reportsRequest->getPage(
			$resolved, ReportsRequest::PAGE, ($page - 1) * ReportsRequest::PAGE
		);

		return [
			'reports' => $this->withTargets($reports),
			'total' => $this->reportsRequest->count($resolved),
			'page' => $page,
			'perPage' => ReportsRequest::PAGE,
		];
	}

	/** How many reports have been resolved. */
	public function countResolved(): int {
		return $this->reportsRequest->count(true);
	}

	/**
	 * The reported accounts looked up once for the whole list, not once a row.
	 *
	 * @param Report[] $reports
	 *
	 * @return Report[] the same reports, target accounts resolved where possible
	 */
	private function withTargets(array $reports): array {
		$accounts = $this->cacheActorService->getCachedFromIds(
			array_map(static fn (Report $report): string => $report->getAccountId(), $reports)
		);

		foreach ($reports as $report) {
			$account = $accounts[$report->getAccountId()] ?? null;
			if ($account !== null) {
				$report->setTargetAccount($account);
			}
		}

		return $reports;
	}

	public function countOpen(): int {
		return $this->reportsRequest->countOpen();
	}

	/**
	 * @throws ReportNotFoundException
	 */
	public function setResolved(int $id, bool $resolved): Report {
		$this->reportsRequest->getById($id); // throws when unknown
		$this->reportsRequest->setResolved($id, $resolved);

		return $this->reportsRequest->getById($id);
	}

	/**
	 * Tells everyone who can act on it, which is not only the `admin` group:
	 * the moderation routes accept whoever the Social settings section has
	 * been delegated to, and a moderator who is never told a report was filed
	 * is a moderator who finds it a week later.
	 *
	 * Read from the groups that hold the right. This used to walk every
	 * Nextcloud account and ask the group manager about each one, on a path a
	 * remote `Flag` can trigger at will.
	 */
	private function notifyAdmins(Report $report): void {
		try {
			foreach ($this->moderatorService->moderators() as $userId) {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp('social')
					->setDateTime(new \DateTime('now'))
					->setUser($userId)
					->setObject('report', (string)$report->getId())
					->setSubject('report_new', [
						'reporter' => $report->getActorId(),
						'account' => $report->getAccountId(),
						'local' => $report->isLocal(),
					]);
				$this->notificationManager->notify($notification);
			}
		} catch (Exception $e) {
			$this->logger->warning('could not notify admins about report', ['exception' => $e]);
		}
	}
}
