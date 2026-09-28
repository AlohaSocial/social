<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\Service\ExternalAdminState;
use OCA\Social\Service\ExternalSignupService;
use OCA\Social\Service\ExternalTwoFactorService;
use OCA\Social\Service\ExternalUserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The External users cards of the Social settings page.
 *
 * Administrators only, like `ServerSettingsController`: this controller
 * carries none of the relaxing attributes, so the server dispatches it for
 * an administrator with a session and a CSRF token and for nobody else. Who
 * may have an account on this server is not a moderator's decision. The
 * changes that cannot be taken back, and the two-factor switch, ask for the
 * administrator's password again.
 */
class ExternalUsersController extends Controller {
	public function __construct(
		IRequest $request,
		private ExternalUserService $externalUserService,
		private ExternalSignupService $signupService,
		private ExternalTwoFactorService $twoFactorService,
		private ExternalAdminState $adminState,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Everything the cards show. */
	#[FrontpageRoute(verb: 'GET', url: '/admin/external')]
	public function state(): DataResponse {
		return new DataResponse($this->everything());
	}

	/**
	 * Writes the settings, all or none.
	 *
	 * @param int $max how many external accounts may exist
	 * @param int $quota megabytes of media each may upload; 0 is no quota
	 * @param string $mode open, invite or approval
	 * @param array<array-key, mixed> $reserved handles nobody may register
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/external')]
	public function save(
		bool $enabled = false,
		int $max = 100,
		int $quota = 1024,
		string $mode = ExternalUserService::MODE_APPROVAL,
		bool $verifyEmail = true,
		int $minAge = 16,
		array $reserved = [],
		bool $userInvites = false,
		string $signupNotice = '',
		bool $signupNoticeRequired = false,
	): DataResponse {
		try {
			return new DataResponse($this->externalUserService->saveSettings(
				$enabled, $max, $quota, $mode, $verifyEmail, $minAge, array_values(array_map('strval', $reserved)), $userInvites, $signupNotice, $signupNoticeRequired,
			));
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** Lets external users into Social where it is restricted to groups. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/external/restriction')]
	public function allowInRestriction(): DataResponse {
		$this->externalUserService->includeExternalsInRestriction();

		return new DataResponse($this->externalUserService->settings());
	}

	/** Requires two-factor authentication of external users, or stops requiring it. */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/admin/external/two-factor')]
	public function twoFactor(bool $enforced = false): DataResponse {
		return new DataResponse($this->twoFactorService->set($enforced));
	}

	/** A page of external accounts. */
	#[FrontpageRoute(verb: 'GET', url: '/admin/external/users')]
	public function users(
		string $search = '',
		int $offset = 0,
		string $status = 'any',
		string $source = 'any',
		string $lastLogin = 'any',
		int $minimumMediaBytes = 0,
		string $noticeAcceptance = 'any',
		int $registeredAfter = 0,
		int $registeredBefore = 0,
	): DataResponse {
		return new DataResponse($this->externalUserService->listPage($search, 50, $offset, [
			'status' => $status,
			'source' => $source,
			'lastLogin' => $lastLogin,
			'minimumMediaBytes' => $minimumMediaBytes,
			'noticeAcceptance' => $noticeAcceptance,
			'registeredAfter' => $registeredAfter,
			'registeredBefore' => $registeredBefore,
		]));
	}

	/** Makes an external user an ordinary local user. */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/admin/external/users/{uid}/promote')]
	public function promote(string $uid): DataResponse {
		try {
			$this->externalUserService->promote($uid);
		} catch (ExternalUserException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($this->everything());
	}

	/** Disables an external account, or enables it again. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/external/users/{uid}/enabled')]
	public function setEnabled(string $uid, bool $enabled = true): DataResponse {
		$user = $this->externalUser($uid);
		if ($user === null) {
			return new DataResponse(['message' => 'no such external user'], Http::STATUS_NOT_FOUND);
		}

		$user->setEnabled($enabled);

		return new DataResponse(['uid' => $user->getUID(), 'enabled' => $user->isEnabled()]);
	}

	/** Deletes an external account, with its Social account and everything it posted. */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/admin/external/users/{uid}')]
	public function delete(string $uid): DataResponse {
		$user = $this->externalUser($uid);
		if ($user === null) {
			return new DataResponse(['message' => 'no such external user'], Http::STATUS_NOT_FOUND);
		}
		if (!$user->delete()) {
			return new DataResponse(['message' => 'the account could not be deleted'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse($this->everything());
	}

	/** Admits a registration waiting for approval. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/external/requests/{id}')]
	public function approve(int $id): DataResponse {
		try {
			$this->signupService->approve($id);
		} catch (ExternalUserException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($this->everything());
	}

	/**
	 * Turns a registration down.
	 *
	 * @param string $reason sent to the person when it is not empty
	 */
	#[FrontpageRoute(verb: 'DELETE', url: '/admin/external/requests/{id}')]
	public function reject(int $id, string $reason = ''): DataResponse {
		try {
			$this->signupService->reject($id, $reason);
		} catch (ExternalUserException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($this->everything());
	}

	/**
	 * An invitation link.
	 *
	 * @param int $maxUses how many registrations it admits; 0 is any number
	 * @param int $days how long it works; 0 is for ever
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/external/invites')]
	public function invite(string $note = '', int $maxUses = 1, int $days = 7): DataResponse {
		try {
			return new DataResponse($this->signupService->createInvite($this->currentUid(), $note, $maxUses, $days, true));
		} catch (ExternalUserException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** Withdraws anybody's invitation. */
	#[FrontpageRoute(verb: 'DELETE', url: '/admin/external/invites/{id}')]
	public function revokeInvite(int $id): DataResponse {
		if (!$this->signupService->revokeInvite($id)) {
			return new DataResponse(['message' => 'no such invitation'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($this->everything());
	}

	/** @return array<string, mixed> */
	private function everything(): array {
		return $this->adminState->current();
	}

	private function externalUser(string $uid): ?IUser {
		$user = $this->userManager->get($uid);

		return $this->externalUserService->isExternal($user) ? $user : null;
	}

	private function currentUid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}
}
