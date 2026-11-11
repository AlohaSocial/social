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
	 * The worker's time budget. Whatever is left over stays STANDBY and is
	 * delivered by `Cron\Queue`, so a post with many recipient inboxes (or a
	 * slow remote) holds a PHP-FPM worker and a database connection for
	 * seconds rather than minutes: every local post starts one of these.
	 */
	public const MAX_DURATION = 20;

	/**
	 * How many of a post's deliveries one round of the async drain takes on:
	 * one wave of `ActivityService::PARALLEL` hosts and a little more.
	 */
	public const ASYNC_ROWS = 40;

	/**
	 * How many rounds one token's drain may run, each a request of its own
	 * that the round before starts once it is done: up to
	 * `ASYNC_ROWS * MAX_ROUNDS` (400) inboxes within a minute or two, each
	 * worker held for one round only. What is left after the last round, and
	 * every row a round tried and failed, is the cron's.
	 */
	public const MAX_ROUNDS = 10;

	/**
	 * Delivers what is on standby under a token, for this server alone.
	 *
	 * Public because the server calls itself here with no session, so it is
	 * the signature `CurlService::asyncWithToken()` sends that decides: a
	 * request without a valid one is refused before the queue is read, and
	 * counted by Nextcloud's brute-force protection. Only failures are
	 * counted, so the server's own calls — which never fail it — are never
	 * slowed, where a rate limit on the address would have throttled its own
	 * fan-out on a busy instance.
	 *
	 * A follow-up round names its number in `AsyncRequestSigner::ROUND_HEADER`;
	 * the number is part of what is signed, and a round past MAX_ROUNDS is
	 * refused like a bad signature.
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

		// exit(), not a Response: the connection is gone and headers are sent, so
		// letting the framework render a response would only feed warnings into the
		// log. Registered shutdown handlers still run.
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
	 * What a round of the drain delivers.
	 *
	 * A row whose `last` is still ahead is held back on purpose — a video
	 * post waiting for its conversion, or a host the breaker has put off —
	 * and is the cron's to deliver once that time has come. After the first
	 * round a row already tried is left out too: it failed in an earlier
	 * round, and its retry follows the cron's backoff.
	 *
	 * @return list<RequestQueue>
	 */
	protected function due(string $token, int $round): array {
		$now = time();

		return array_values(array_filter(
			$this->requestQueueService->getRequestFromToken($token, RequestQueue::STATUS_STANDBY),
			static fn (RequestQueue $request): bool => $request->getLast() <= $now
				&& ($round === 0 || $request->getTries() === 0)
		));
	}

	/**
	 * Starts the next round when this one left deliveries that are due and
	 * were never tried, and the token has rounds left. A new request rather
	 * than a loop here, so no worker is held for more than MAX_DURATION.
	 */
	protected function nextRound(string $token, int $round): void {
		if ($round + 1 >= self::MAX_ROUNDS || $this->due($token, $round + 1) === []) {
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
	 * A delivery that failed in a way `ActivityService` does not end itself,
	 * which costs this row and nothing else.
	 *
	 * The row is `running` by then — marked before the attempt — and anything
	 * a delivery does not handle itself (a corrupt signing key, the database
	 * going away) used to come out of here unhandled, stranding it (never
	 * retried, never counted against MAX_TRIES, freed by the stale reaper an
	 * hour later) and abandoning every request left in the batch. There is no
	 * one to report an error to either: the connection was closed by async()
	 * before the drain started.
	 */
	protected function handBack(RequestQueue $request, Throwable $e): void {
		$this->logger->warning(
			'[QueueController] delivery of ' . $request->getToken() . ' failed: '
			. get_class($e) . ' ' . $e->getMessage(),
			['exception' => $e, 'token' => $request->getToken()]
		);

		try {
			$this->requestQueueService->endRequest($request, false);
		} catch (Throwable $dbError) {
			// the database is what just failed; the stale reaper is the backstop
			$this->logger->warning(
				'[QueueController] cannot return ' . $request->getToken() . ' to standby',
				['exception' => $dbError, 'token' => $request->getToken()]
			);
		}
	}
}
