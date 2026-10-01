<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Tools\Traits\TAsync;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->requestQueueService = $requestQueueService;
		$this->activityService = $activityService;
		$this->logger = $logger;
	}

	/**
	 * The whole worker's time budget. Whatever is left over stays STANDBY and is
	 * delivered by the cron, so a post with many recipient inboxes (or a slow
	 * remote) cannot pin a PHP worker indefinitely.
	 */
	public const MAX_DURATION = 90;

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/async/request/{token}')]
	public function asyncForRequest(string $token): Response {
		// a row whose `last` is still ahead is held back on purpose — a video
		// post waiting for its conversion, or a host the breaker has put off —
		// and is the cron's to deliver once that time has come
		$now = time();
		$requests = array_values(array_filter(
			$this->requestQueueService->getRequestFromToken($token, RequestQueue::STATUS_STANDBY),
			static fn (RequestQueue $request): bool => $request->getLast() <= $now
		));

		if (empty($requests)) {
			return new DataResponse([], Http::STATUS_OK);
		}

		// From here the request is detached: async() has flushed an empty body and
		// closed the connection, and this worker only delivers queued activities.
		$this->async();

		// a post's whole fan-out, several servers at a time: one after
		// another, the budget ran out on the first few slow peers
		foreach ($requests as $request) {
			$request->setTimeout(ActivityService::TIMEOUT_ASYNC);
		}
		$this->activityService->manageInit();
		$this->activityService->manageRequests(
			$requests,
			time() + self::MAX_DURATION,
			fn (RequestQueue $request, Throwable $e) => $this->handBack($request, $e)
		);

		// exit(), not a Response: the connection is gone and headers are sent, so
		// letting the framework render a response would only feed warnings into the
		// log. Registered shutdown handlers still run.
		exit();
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
