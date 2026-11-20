<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Security\AsyncRequestSigner;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Tools\Traits\TAsync;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class QueueController
 *
 * @package OCA\Social\Controller
 */
class QueueController extends Controller {
	use TAsync;

	private RequestQueueService $requestQueueService;
	private ActivityService $activityService;
	private LoggerInterface $logger;

	public function __construct(
		IRequest $request,
		RequestQueueService $requestQueueService,
		ActivityService $activityService,
		LoggerInterface $logger,
		private AsyncRequestSigner $asyncRequestSigner,
		private CurlService $curlService,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->requestQueueService = $requestQueueService;
		$this->activityService = $activityService;
		$this->logger = $logger;
	}

	/**
	 * The worker's time budget; what is left stays STANDBY for `Cron\Queue`, so
	 * a large fan-out holds a PHP-FPM worker for seconds, not minutes.
	 */
	public const MAX_DURATION = 20;

	/**
	 * How many of a post's deliveries one round of the async drain takes on:
	 * one wave of `ActivityService::PARALLEL` hosts and a little more.
	 */
	public const ASYNC_ROWS = 40;

	/**
	 * How many rounds one token's drain may run, each its own request started
	 * by the previous one: up to `ASYNC_ROWS * MAX_ROUNDS` (400) inboxes, each
	 * worker held for one round. The rest, and every failed row, is the cron's.
	 */
	public const MAX_ROUNDS = 10;

	/**
	 * Delivers what is on standby under a token, for this server alone.
	 *
	 * Public because the server calls itself here without a session; the
	 * signature from `CurlService::asyncWithToken()` decides, and a bad one is
	 * refused before the queue is read and counted by brute-force protection
	 * (which only counts failures, so the server's own calls are never slowed).
	 * The round number in `AsyncRequestSigner::ROUND_HEADER` is signed too, and
	 * a round past MAX_ROUNDS is refused.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'socialAsyncRequest')]
	#[FrontpageRoute(verb: 'POST', url: '/async/request/{token}')]
	public function asyncForRequest(string $token): Response {
		$round = self::roundOf($this->request->getHeader(AsyncRequestSigner::ROUND_HEADER));
		if ($round === null
			|| !$this->asyncRequestSigner->verify($token, $this->request->getHeader(AsyncRequestSigner::HEADER), $round)) {
			$response = new DataResponse([], Http::STATUS_FORBIDDEN);
			$response->throttle(['token' => $token]);

			return $response;
		}

		$requests = $this->due($token, $round);
		if (empty($requests)) {
			return new DataResponse([], Http::STATUS_OK);
		}

		// From here the request is detached: async() has flushed an empty body and
		// closed the connection, and this worker only delivers queued activities.
		$this->async();
		$this->drain($requests);
		$this->nextRound($token, $round);

		// exit(), not a Response: the connection is closed and headers are sent.
		// Shutdown handlers still run.
		exit();
	}

	/** The round a call names: 0 when it names none, null for one not to run. */
	private static function roundOf(string $header): ?int {
		if ($header === '') {
			return 0;
		}
		if (!ctype_digit($header) || (int)$header >= self::MAX_ROUNDS) {
			return null;
		}

		return (int)$header;
	}

	/**
	 * What a round of the drain delivers: due rows only (a future `last` is a
	 * conversion wait or the breaker, left to the cron), and after the first
	 * round only rows never tried, as failures follow the cron's backoff.
	 * Only `$limit` rows are read.
	 *
	 * @return list<RequestQueue>
	 */
	protected function due(string $token, int $round, int $limit = self::ASYNC_ROWS): array {
		return $this->requestQueueService->getDueFromToken($token, $round > 0, $limit);
	}

	/**
	 * Starts the next round when due, never-tried deliveries remain and the
	 * token has rounds left; a new request rather than a loop, so no worker is
	 * held longer than MAX_DURATION.
	 */
	protected function nextRound(string $token, int $round): void {
		if ($round + 1 >= self::MAX_ROUNDS || $this->due($token, $round + 1, 1) === []) {
			return;
		}

		try {
			$this->curlService->asyncWithToken($token, $round + 1);
		} catch (SocialAppConfigException $e) {
			// the rows stay on standby, and the cron delivers them
			$this->logger->warning('[QueueController] cannot start the next round', ['exception' => $e]);
		}
	}

	/**
	 * Delivers the first ASYNC_ROWS of the rows due, several servers at a
	 * time, within MAX_DURATION. What is not reached stays on standby for the
	 * next round, or for `Cron\Queue`.
	 *
	 * @param list<RequestQueue> $requests
	 */
	protected function drain(array $requests): void {
		$wave = array_slice($requests, 0, self::ASYNC_ROWS);
		foreach ($wave as $request) {
			$request->setTimeout(ActivityService::TIMEOUT_ASYNC);
		}
		$this->activityService->manageInit();
		$this->activityService->manageRequests(
			$wave,
			time() + self::MAX_DURATION,
			fn (RequestQueue $request, Throwable $e) => $this->handBack($request, $e)
		);
	}

	/**
	 * Handles a delivery failure `ActivityService` does not handle itself (a
	 * corrupt key, the database going away), costing this row only: otherwise
	 * the `running` row would be stranded and the rest of the batch abandoned.
	 * There is nobody to report to, as async() closed the connection.
	 */
	protected function handBack(RequestQueue $request, Throwable $e): void {
		$token = AsyncRequestSigner::fingerprint($request->getToken());
		$this->logger->warning(
			'[QueueController] delivery of ' . $token . ' failed: '
			. get_class($e) . ' ' . $e->getMessage(),
			['exception' => $e, 'token' => $token]
		);

		try {
			$this->requestQueueService->endRequest($request, false);
		} catch (Throwable $dbError) {
			// the database is what just failed; the stale reaper is the backstop
			$this->logger->warning(
				'[QueueController] cannot return ' . $token . ' to standby',
				['exception' => $dbError, 'token' => $token]
			);
		}
	}
}
