<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AnnualReportService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mastodon's annual reports ("Wrapstodon"): the year in numbers an account
 * may ask for once the year is over, generated on request and kept.
 */
class AnnualReportApiController extends MastodonApiController {
	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		FollowService $followService,
		private AnnualReportService $annualReportService,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	// --- the year an account had --------------------------------------------

	/**
	 * Every year this account has a report for, newest first.
	 *
	 * Mastodon's `#Wrapstodon`. A client that has the feature — the official
	 * apps do — shows a card in December that says nothing at all on an
	 * instance which does not serve these, which is what this app was.
	 *
	 * The wrapper is Mastodon's: the reports, plus the accounts and statuses
	 * they name, so a client can draw the three best posts without a second
	 * round of requests.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/annual_reports')]
	public function annualReports(): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			$reports = [];
			foreach ($this->annualReportService->years($actor) as $year) {
				$reports[] = $this->annualReportService->forYear($actor, $year);
			}

			return new DataResponse($this->wrapReports($actor, $reports), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One year of it. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/annual_reports/{year}')]
	public function annualReport(int $year): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			if ($this->annualReportService->state($actor, $year) !== 'available') {
				// a year the account wrote nothing in has no report, and
				// twelve empty months would be worse than saying so
				return new DataResponse($this->wrapReports($actor, []), Http::STATUS_OK);
			}

			return new DataResponse(
				$this->wrapReports($actor, [$this->annualReportService->forYear($actor, $year)]),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Whether a year has a report.
	 *
	 * `generating` never comes back: the report is a query over posts that are
	 * already here rather than a job, so there is nothing to wait for.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/annual_reports/{year}/state')]
	public function annualReportState(int $year): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			return new DataResponse(
				['state' => $this->annualReportService->state($actor, $year)], Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Marks one read, so a client stops offering it. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/annual_reports/{year}/read')]
	public function annualReportRead(int $year): DataResponse {
		try {
			$this->initViewer(true);
			$this->annualReportService->markRead($this->currentSession(), $year);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Asks for one to be generated — and there is nothing to generate.
	 *
	 * The report is a query, so it is ready the moment it is asked for. The
	 * route exists because a Mastodon client calls it before it reads, and a
	 * 404 there is a client that never asks again.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/annual_reports/{year}/generate')]
	public function annualReportGenerate(int $year): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's `WrappedAnnualReports`: the reports, and the accounts and
	 * statuses they name, so a client can draw the three best posts without a
	 * second round of requests.
	 *
	 * @param array<int, array<string, mixed>> $reports
	 *
	 * @return array<string, mixed>
	 */
	private function wrapReports(Person $actor, array $reports): array {
		$nids = [];
		foreach ($reports as $report) {
			foreach ($report['data']['top_statuses'] ?? [] as $nid) {
				if (is_string($nid) && $nid !== '') {
					$nids[$nid] = true;
				}
			}
		}

		$statuses = [];
		foreach (array_keys($nids) as $nid) {
			try {
				$status = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
				$status->setExportFormat(ACore::FORMAT_LOCAL);
				$statuses[] = $status;
			} catch (Throwable $e) {
				// a post deleted since it was the year's best: the report still
				// stands, and the client draws what it was handed
			}
		}

		$actor->setExportFormat(ACore::FORMAT_LOCAL);

		return [
			'annual_reports' => $reports,
			'accounts' => ($reports === []) ? [] : [$actor],
			'statuses' => $statuses,
		];
	}
}
