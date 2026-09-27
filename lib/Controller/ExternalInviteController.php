<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\Service\ExternalSignupService;
use OCA\Social\Service\ExternalUserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Invitations any user may send, when the administrator allows it.
 *
 * Single-use and valid for a week, at most ten open at a time per person;
 * an administrator's invitations have no such limits and are made on the
 * settings page.
 */
class ExternalInviteController extends Controller {
	public function __construct(
		IRequest $request,
		private ExternalSignupService $signupService,
		private ExternalUserService $externalUserService,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Whether this person may invite, and the invitations they sent. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/invites')]
	public function list(): DataResponse {
		$allowed = $this->externalUserService->isEnabled() && $this->externalUserService->usersMayInvite();

		return new DataResponse([
			'allowed' => $allowed,
			'invites' => $allowed ? $this->signupService->invites($this->uid()) : [],
		]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/invites')]
	public function create(string $note = ''): DataResponse {
		try {
			return new DataResponse($this->signupService->createInvite($this->uid(), $note, 1, ExternalSignupService::USER_INVITE_DAYS, false));
		} catch (ExternalUserException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** Withdraws one of this person's own invitations. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/invites/{id}')]
	public function revoke(int $id): DataResponse {
		if (!$this->signupService->revokeInvite($id, $this->uid())) {
			return new DataResponse(['message' => 'no such invitation'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([]);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}
}
