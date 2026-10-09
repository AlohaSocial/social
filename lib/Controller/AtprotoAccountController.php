<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\CustomHandleService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Move\BridgyTwin;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * A person's own Bluesky identity, for Settings → Your account: the handle
 * and DID, and the recovery phrase.
 */
class AtprotoAccountController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AtprotoConfig $config,
		private AccountService $accountService,
		private IdentityService $identities,
		private Publisher $publisher,
		private LabelerService $labelers,
		private AppPasswordService $appPasswords,
		private AuthorizationServer $oauth,
		private CustomHandleService $customHandles,
		private MoveAwayService $moveAway,
		private MoveInService $moveIn,
		private InboundMoveService $inbound,
		private BridgyTwin $bridgy,
		private BlueskyBlocks $blocks,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `GET /api/v1/social/bluesky/identity`: the viewer's identity, made
	 * now if there is none yet.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/identity')]
	public function identity(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$actor = $this->accountService->getActorFromUserId($this->userId(), true);
			$identity = $this->identities->forActor($actor);
			if ($identity === null) {
				return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
			}
			$this->publisher->publishProfile($actor);

			return new DataResponse(self::export($identity) + ['publish_blocks' => $this->blocks->isPublished($this->userId())]);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * `POST /api/v1/social/bluesky/recovery`: issues a recovery phrase —
	 * the first, or a new one that replaces the last. Shown once, never
	 * stored, so the password is asked for first.
	 */
	/**
	 * Switches the viewer's own Bluesky presence off or on: deactivated, the
	 * account is announced inactive and nothing more goes to Bluesky; the
	 * DID and the repository stay theirs for switching back on.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/state')]
	public function state(bool $active): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$actor = $this->accountService->getActorFromUserId($this->userId(), true);
			$identity = $this->identities->forActor($actor, false);
			if ($identity === null) {
				return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
			}
			if ($active) {
				$this->identities->activate($identity);
			} else {
				$this->identities->deactivate($identity);
			}

			return new DataResponse(self::export($this->identities->getByDid($identity->did)));
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Whether the viewer's blocks of Bluesky accounts are published to
	 * Bluesky, where a block is public: on, the ones they hold are published
	 * now; off, every published one is withdrawn.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/publish-blocks')]
	public function publishBlocks(bool $publish): DataResponse {
		if ($this->ownIdentity() === null) {
			return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
		}
		try {
			$this->blocks->setPublished($this->accountService->getActorFromUserId($this->userId()), $publish);

			return new DataResponse(['publish_blocks' => $this->blocks->isPublished($this->userId())]);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The viewer's Bluesky labelers, Bluesky's own first, each with its
	 * label values and the viewer's setting for each.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/labelers')]
	public function labelers(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['labelers' => $this->labelers->forUser($this->userId())]);
	}

	/**
	 * Subscribes the viewer to a labeler, by handle or DID.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/labelers')]
	public function subscribeLabeler(string $labeler): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$this->labelers->subscribe($this->userId(), $labeler);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['labelers' => $this->labelers->forUser($this->userId())]);
	}

	/**
	 * Unsubscribes the viewer from a labeler; Bluesky's own stays.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/labelers')]
	public function unsubscribeLabeler(string $did): DataResponse {
		$this->labelers->unsubscribe($this->userId(), $did);

		return new DataResponse(['labelers' => $this->labelers->forUser($this->userId())]);
	}

	/**
	 * What one label of a subscribed labeler does for the viewer.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/social/bluesky/labelers/setting')]
	public function labelerSetting(string $did, string $label, string $setting): DataResponse {
		try {
			$this->labelers->setSetting($this->userId(), $did, $label, $setting);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['labelers' => $this->labelers->forUser($this->userId())]);
	}

	/**
	 * The viewer's app passwords for Bluesky apps, by name; never the
	 * passwords themselves.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/app-passwords')]
	public function appPasswords(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['app_passwords' => $this->appPasswords->list($this->userId())]);
	}

	/**
	 * A new app password: the answer is the only time it is seen.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/app-passwords')]
	public function createAppPassword(string $name, bool $privileged = false): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$created = $this->appPasswords->create($this->userId(), $name, $privileged);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($created + ['app_passwords' => $this->appPasswords->list($this->userId())]);
	}

	/**
	 * Revokes an app password, and every Bluesky app signed in with it.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/app-passwords/{id}')]
	public function revokeAppPassword(int $id): DataResponse {
		$this->appPasswords->revoke($this->userId(), $id);

		return new DataResponse(['app_passwords' => $this->appPasswords->list($this->userId())]);
	}

	/**
	 * A handle on a domain the viewer owns, once the domain names their DID.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/handle')]
	public function setHandle(string $handle): DataResponse {
		$identity = $this->ownIdentity();
		if ($identity === null) {
			return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
		}
		try {
			return new DataResponse(self::export($this->customHandles->set($identity, $handle)));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The handle this server assigned, again.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/handle')]
	public function clearHandle(): DataResponse {
		$identity = $this->ownIdentity();
		if ($identity === null) {
			return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
		}
		try {
			return new DataResponse(self::export($this->customHandles->clear($identity)));
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The viewer's latest move of their Bluesky account, or null.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/move')]
	public function move(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['move' => $this->inbound->latest($this->userId())?->export()]);
	}

	/**
	 * Moves the viewer's Bluesky account to another PDS: the account is made
	 * there now, the rest follows in the background.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move')]
	public function moveAway(string $pds, string $handle, string $email, string $password, string $inviteCode = ''): DataResponse {
		$identity = $this->ownIdentity();
		if ($identity === null) {
			return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
		}
		try {
			return new DataResponse(['move' => $this->moveAway->start($this->userId(), $identity, $pds, $handle, $email, $password, $inviteCode)->export()]);
		} catch (\InvalidArgumentException|AtprotoException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Starts a failed move again, from where it stopped.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move/retry')]
	public function retryMove(): DataResponse {
		try {
			$latest = $this->moveAway->latest($this->userId());
			$move = $latest !== null && $latest->direction === Move::IN
				? $this->moveIn->retry($latest)
				: $this->moveAway->retry($this->userId());

			return new DataResponse(['move' => $move->export()]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * Moves a Bluesky account here: the viewer signs in to it with its own
	 * password, which is not kept, and the copy starts in the background.
	 * Their Bluesky account here so far is replaced once the move is done.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move-in')]
	public function moveIn(string $handle, string $password, string $authFactorToken = ''): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			return new DataResponse(['move' => $this->moveIn->start($this->userId(), $handle, $password, $authFactorToken)->export()]);
		} catch (\InvalidArgumentException|AtprotoException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The code the old Bluesky server e-mailed: the move here goes on.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move/code')]
	public function moveCode(string $code): DataResponse {
		try {
			return new DataResponse(['move' => $this->moveIn->code($this->userId(), $code)->export()]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * The Bluesky account Bridgy Fed made for the viewer's Fediverse account,
	 * or null. Asked to search, Bluesky's search is given the viewer's
	 * Fediverse address, for a twin they gave a domain of their own.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/bridgy-twin')]
	public function bridgyTwin(bool $search = false): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		try {
			$actor = $this->accountService->getActorFromUserId($this->userId(), true);
		} catch (Throwable) {
			return new DataResponse(['twin' => null]);
		}

		return new DataResponse(['twin' => $this->bridgy->find($actor, $search)]);
	}

	/**
	 * Invites a Bluesky account to move here, driven by the other side: a
	 * Bridgy Fed twin is asked to by Bridgy, for anything else the answer
	 * carries what a migration tool needs, the one-time code once.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move-invite')]
	public function moveInvite(string $account): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$invited = $this->inbound->invite($this->userId(), $account);

			return new DataResponse(['move' => $invited['move']->export()] + $invited);
		} catch (\InvalidArgumentException|AtprotoException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Calls off a move here the other side has not finished.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/move-invite')]
	public function cancelMoveInvite(): DataResponse {
		try {
			return new DataResponse(['move' => $this->inbound->cancel($this->userId())->export()]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * The Bluesky apps the viewer signed in to through OAuth.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/oauth-sessions')]
	public function oauthSessions(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['sessions' => $this->oauth->sessionsOf($this->userId())]);
	}

	/**
	 * Signs one of those apps out.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/oauth-sessions/{id}')]
	public function endOAuthSession(int $id): DataResponse {
		$this->oauth->endSession($this->userId(), $id);

		return new DataResponse(['sessions' => $this->oauth->sessionsOf($this->userId())]);
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/recovery')]
	public function recovery(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$actor = $this->accountService->getActorFromUserId($this->userId(), true);
			$identity = $this->identities->forActor($actor);
			if ($identity === null || !$identity->isActive()) {
				return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
			}
			$phrase = $this->identities->issueRecoveryKey($identity);

			return new DataResponse(['phrase' => $phrase] + self::export($this->identities->getByDid($identity->did)));
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The identity as the client sees it.
	 */
	public static function export(Identity $identity): array {
		return [
			'handle' => $identity->handle,
			'did' => $identity->did,
			'url' => 'https://bsky.app/profile/' . $identity->handle,
			'state' => $identity->state,
			'recovery_key' => $identity->recoveryPublic !== '',
			// the handle this server gave, which keeps resolving, and the one
			// on the person's own domain when they set one
			'assigned_handle' => $identity->assignedHandle(),
			'custom_handle' => $identity->customHandle,
			'custom_handle_broken' => $identity->customHandleBroken(),
		];
	}

	/**
	 * The viewer's identity, as it is; null when Bluesky is off or there is none.
	 */
	private function ownIdentity(): ?Identity {
		if (!$this->config->isEnabled()) {
			return null;
		}
		try {
			return $this->identities->forActor($this->accountService->getActorFromUserId($this->userId(), true), false);
		} catch (Throwable) {
			return null;
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Not signed in');
		}

		return $user->getUID();
	}
}
