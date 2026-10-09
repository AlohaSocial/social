<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\VerificationService;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The accounts this instance verified (`VerificationService`), for the
 * administration page and an account's profile menu.
 *
 * Verifying and taking a verification back are the moderators' — an
 * administrator or whoever the Social settings are delegated to, as for
 * every moderation route. Choosing the account the instance verifies in
 * the name of is the administrator's alone: it is the instance speaking.
 */
class VerificationController extends Controller {
	public function __construct(
		IRequest $request,
		private VerificationService $verifications,
		private CacheActorService $cacheActorService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The verifying account, whether verifications are published, and the
	 * verified accounts.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/verifications')]
	public function index(): DataResponse {
		return new DataResponse($this->verifications->state() + ['verifications' => $this->verifications->list()]);
	}

	/**
	 * Verifies an account, named by its handle, numeric id or actor id.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/verifications')]
	public function verify(string $account = ''): DataResponse {
		try {
			$actor = $this->cacheActorService->resolve($account, true);
		} catch (Throwable) {
			return new DataResponse(['error' => 'No account goes by that name'], Http::STATUS_NOT_FOUND);
		}
		try {
			$this->verifications->verify($actor, $this->userSession->getUser()?->getUID() ?? '');
		} catch (Throwable $e) {
			$this->logger->warning('Verifying an account failed', ['actor' => $actor->getId(), 'exception' => $e]);

			return new DataResponse(['error' => 'The account could not be verified'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse(['actor_id' => $actor->getId(), 'verification' => $this->verifications->exportOf($actor->getId())]);
	}

	/**
	 * Takes a verification back.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'DELETE', url: '/moderation/verifications')]
	public function unverify(string $account = ''): DataResponse {
		$actorId = $account;
		if (!str_starts_with($account, 'https://') && !str_starts_with($account, 'http://')) {
			try {
				$actorId = $this->cacheActorService->resolve($account)->getId();
			} catch (Throwable) {
				return new DataResponse(['error' => 'No account goes by that name'], Http::STATUS_NOT_FOUND);
			}
		}
		if (!$this->verifications->unverify($actorId)) {
			return new DataResponse(['error' => 'The account is not verified'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['actor_id' => $actorId, 'verification' => null]);
	}

	/**
	 * Chooses the local account the instance verifies in the name of, or
	 * with an empty `account` none.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/verifications/verifier')]
	public function setVerifier(string $account = ''): DataResponse {
		try {
			$this->verifications->setVerifier($account);
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($this->verifications->state());
	}
}
