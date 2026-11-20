<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\AccountNotSetUpException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\PostReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An author's own posts that are waiting for a moderator: what is waiting,
 * and taking one back, so a held post can be found again.
 *
 * Session routes, since unpublished text should not be readable by a
 * third-party token; results are scoped in SQL (`PostHoldsRequest`).
 */
class ReviewController extends Controller {
	use OwnAccount;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private AccountService $accountService,
		private PostReviewService $postReviewService,
		private LoggerInterface $logger,
	) {
		parent::__construct('social', $request);
	}

	/** What of the caller's own is waiting, newest first. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/review')]
	public function held(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->ownAccountOf($this->userId);
			$held = $this->postReviewService->forActor($actor);

			return new DataResponse([
				'held' => $held,
				'reasons' => array_map(
					fn ($one): string => $this->postReviewService->reasonText($one->getReason()),
					$held
				),
			], Http::STATUS_OK);
		} catch (AccountNotSetUpException $e) {
			return self::notSetUp($e);
		} catch (Throwable $e) {
			$this->logger->warning('could not read the held posts', [
				'userId' => $this->userId, 'exception' => $e,
			]);

			return new DataResponse(['error' => 'could not read the held posts'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Takes one back; not a moderation decision, so it leaves no trace.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/review/{id}')]
	public function withdraw(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->ownAccountOf($this->userId);
			$this->postReviewService->withdraw($actor, $id);

			return new DataResponse(['withdrawn' => (string)$id], Http::STATUS_OK);
		} catch (AccountNotSetUpException $e) {
			return self::notSetUp($e);
		} catch (Throwable $e) {
			// Somebody else's or gone: the same answer, so nothing leaks.
			return new DataResponse(['error' => 'no such held post'], Http::STATUS_NOT_FOUND);
		}
	}
}
