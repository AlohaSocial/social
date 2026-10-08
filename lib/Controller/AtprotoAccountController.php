<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Service\AtprotoConfig;
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

			return new DataResponse(self::export($identity));
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
		];
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Not signed in');
		}

		return $user->getUID();
	}
}
