<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\Atproto\AtprotoAccountService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\StreamService;
use OCA\Social\Model\ActivityPub\ACore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The Bluesky card of the settings page: what is linked, what is read, and
 * the two buttons that change it.
 *
 * Session routes with CSRF rather than client-API ones, for the same reason
 * the migration routes are: a link is a credential for an account on another
 * network, and a third-party token holding `write` should not be able to
 * hand this instance one.
 */
class AtprotoController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AtprotoAccountService $accountService,
		private AtprotoRequest $atprotoRequest,
		private AccountService $localAccountService,
		private StreamService $streamService,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * What the card draws: the switch, the account linked by whoever is
	 * signed in, and the profiles this instance reads.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/atproto')]
	public function index(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		return new DataResponse($this->accountService->status($userId), Http::STATUS_OK);
	}

	/**
	 * Links an account after the PDS has accepted the pair, which is what
	 * makes a refusal worth showing: the handle was not a handle, the app
	 * password was not accepted, or the network that publishes the handle
	 * could not be asked.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/atproto/link')]
	public function link(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		$body = $this->request->getParams();

		try {
			$account = $this->accountService->link(
				$userId,
				(string)($body['handle'] ?? ''),
				(string)($body['appPassword'] ?? '')
			);
		} catch (AtprotoException $e) {
			$this->logger->info('a Bluesky link was refused', [
				'user' => $userId, 'reason' => $e->getMessage(),
			]);

			return new DataResponse(
				['message' => $e->getMessage()],
				$e->getStatus() >= 400 && $e->getStatus() < 500 ? $e->getStatus() : Http::STATUS_BAD_REQUEST
			);
		}

		return new DataResponse([
			'handle' => $account->getHandle(),
			'did' => $account->getDid(),
			'state' => $account->getState(),
		], Http::STATUS_OK);
	}

	/**
	 * Removes the link this person's own account holds. Removing a link that
	 * was never made is a **404** rather than a 200 that changed nothing.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/atproto')]
	public function unlink(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		if (!$this->accountService->unlink($userId)) {
			return new DataResponse(['message' => 'no Bluesky account is linked'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([], Http::STATUS_OK);
	}

	/** Return this account's locally known posts that have a Bluesky record. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/atproto/profile')]
	public function profile(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}
		$account = $this->atprotoRequest->getAccount($userId);
		if ($account === null) {
			return new DataResponse([], Http::STATUS_OK);
		}

		try {
			$actorId = $this->localAccountService->getActorFromUserId($userId)->getId();
		} catch (\Throwable $e) {
			$this->logger->debug('could not resolve local actor for ATProto profile', ['exception' => $e]);
			return new DataResponse([], Http::STATUS_OK);
		}

		$statuses = [];
		foreach ($this->atprotoRequest->getLinksForDid($account->getDid(), 100) as $link) {
			try {
				$status = $this->streamService->getStreamById($link->getLocalId(), true, ACore::FORMAT_LOCAL);
				if ($status->getAttributedTo() === $actorId) {
					$statuses[] = $status;
				}
			} catch (\Throwable $e) {
				$this->logger->debug('stale ATProto profile link skipped', ['exception' => $e]);
			}
		}

		return new DataResponse($statuses, Http::STATUS_OK);
	}

	/**
	 * The Nextcloud account behind the reader, which is what a link is stored
	 * under — `null` when nobody is signed in, which these routes answer
	 * rather than let the settings service be asked about no one.
	 */
	private function currentUserId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	private function signedOut(): DataResponse {
		return new DataResponse(['message' => 'no signed-in account'], Http::STATUS_UNAUTHORIZED);
	}
}
