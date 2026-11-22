<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\NetworkGrowthService;
use OCA\Social\Service\NetworkStatsService;
use OCA\Social\Service\StatisticsService;
use OCA\Social\Tools\Csv;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The reader's own numbers, for the Statistics tab of their profile and for
 * mobile clients. `read:statuses` suffices: the figures are counted from the
 * caller's own posts, and there is no account parameter. Rate limited, since
 * the answer walks all of the account's posts.
 */
class StatisticsController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private StatisticsService $statisticsService,
		private NetworkStatsService $networkStatsService,
		private NetworkGrowthService $networkGrowthService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * @param int $days the window, one of `StatisticsService::WINDOWS`
	 * @param bool $fresh count again rather than read the cached answer
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 90, period: 3600)]
	#[UserRateLimit(limit: 90, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statistics')]
	public function statistics(int $days = 0, bool $fresh = false): DataResponse {
		try {
			$this->initViewer(['read:statuses']);

			$statistics = $this->statisticsService->cachedForAccount($this->viewer(), $days, $fresh);
			// the size of the network as a whole; null when switched off or the
			// survey did not answer, and the page leaves the section out
			$statistics['network'] = $this->networkStatsService->network();
			// and whether it is growing, from a second source, since the snapshot
			// above has no history
			$statistics['growth'] = $this->networkGrowthService->growth();
			// and the platforms it is made of
			$statistics['software'] = $this->networkStatsService->software();

			return new DataResponse($statistics, Http::STATUS_OK);
		} catch (ClientNotFoundException|InsufficientScopeException $e) {
			return $this->error($e);
		} catch (Throwable $e) {
			$this->logger->warning('could not build the statistics', [
				'userId' => $this->viewer?->getUserId(), 'exception' => $e,
			]);

			return new DataResponse(
				['error' => 'could not build the statistics'], Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * The same figures as a file to keep, since this app stores no history: CSV
	 * for a spreadsheet, or the whole answer as `json`.
	 *
	 * @param int $days the window, one of `StatisticsService::WINDOWS`
	 * @param string $format `csv` or `json`
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statistics/export')]
	public function export(int $days = 0, string $format = 'csv'): Response {
		try {
			$this->initViewer(['read:statuses']);
			$statistics = $this->statisticsService->cachedForAccount($this->viewer(), $days);
		} catch (ClientNotFoundException|InsufficientScopeException $e) {
			return $this->error($e);
		} catch (Throwable $e) {
			$this->logger->warning('could not build the statistics', ['exception' => $e]);

			return new DataResponse(
				['error' => 'could not build the statistics'], Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$stamp = gmdate('Y-m-d');
		if (strtolower($format) === 'json') {
			return $this->attachment(
				(string)json_encode($statistics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
				'application/json',
				'social-statistics-' . $stamp . '.json'
			);
		}

		return $this->attachment(
			$this->asCsv($statistics),
			'text/csv',
			'social-statistics-' . $stamp . '.csv'
		);
	}

	/** A file the browser saves rather than draws. */
	private function attachment(string $body, string $type, string $name): Response {
		$response = new DataDownloadResponse($body, $name, $type);
		$response->cacheFor(0);

		return $response;
	}

	/**
	 * The figures worth charting, one per row: totals, rates and the per-month
	 * series, flattened.
	 *
	 * @param array<string, mixed> $statistics
	 */
	private function asCsv(array $statistics): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, ['section', 'name', 'value'], ',', '"', '');

		$rows = [
			['account', 'handle', $statistics['account']['acct'] ?? ''],
			['account', 'followers', $statistics['account']['followers'] ?? 0],
			['account', 'following', $statistics['account']['following'] ?? 0],
			['window', 'days', $statistics['window']['days'] ?? 0],
			['window', 'posts counted', $statistics['window']['counted'] ?? 0],
			['window', 'capped', ($statistics['window']['capped'] ?? false) ? 'yes' : 'no'],
		];

		foreach (['posts', 'engagement', 'rates', 'visibility', 'consistency', 'media'] as $section) {
			foreach ($statistics[$section] ?? [] as $name => $value) {
				if (is_scalar($value)) {
					$rows[] = [$section, (string)$name, $value];
				}
			}
		}

		foreach (['by_month' => 'posts', 'engagement_by_month' => 'engagement'] as $key => $label) {
			foreach ($statistics[$key] ?? [] as $month => $value) {
				$rows[] = ['by month', $month . ' ' . $label, $value];
			}
		}

		foreach (['originals', 'replies', 'boosts'] as $kind) {
			foreach ($statistics['activity'][$kind] ?? [] as $month => $value) {
				$rows[] = ['activity', $month . ' ' . $kind, $value];
			}
		}

		foreach (['inbound', 'outbound'] as $direction) {
			foreach ($statistics['partners'][$direction] ?? [] as $partner) {
				$rows[] = [
					'partners ' . $direction,
					(string)($partner['account'] ?? ''),
					$partner['replies'] ?? 0,
				];
			}
		}

		foreach (['languages', 'domains', 'hashtags'] as $section) {
			foreach ($statistics[$section] ?? [] as $entry) {
				$rows[] = [$section, (string)($entry['name'] ?? ''), $entry['count'] ?? 0];
			}
		}

		foreach ($statistics['audience']['instances'] ?? [] as $server) {
			$rows[] = ['followers by server', (string)($server['host'] ?? ''), $server['count'] ?? 0];
		}
		$rows[] = ['audience', 'starter pack joins', $statistics['audience']['starter_pack_joins'] ?? 0];
		foreach ($statistics['engagement_sources']['servers'] ?? [] as $server) {
			$rows[] = ['engagement by server', (string)($server['host'] ?? ''), $server['count'] ?? 0];
		}
		foreach ($statistics['reach']['networks'] ?? [] as $network) {
			$rows[] = ['posts also on', (string)($network['network'] ?? ''), $network['posts'] ?? 0];
		}

		foreach ($rows as $row) {
			// account names, hashtags and server names are other people's words, and a
			// spreadsheet would run one that reads as a formula
			fputcsv($handle, array_map(
				static fn ($cell) => is_string($cell) ? Csv::neutralise($cell) : $cell,
				$row
			), ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}
}
