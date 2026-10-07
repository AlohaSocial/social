<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Atproto\Identity\IdentityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class AtprotoIdentityController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IdentityService $identityService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 */
	#[\OCP\AppFramework\Http\Attribute\FrontpageRoute(verb: 'GET', url: '/api/atproto/identity')]
	public function getIdentity(): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if (!$userId) {
			return new DataResponse(['error' => 'Not logged in'], 401);
		}

		$actorId = $this->getActorId($userId);
		if (!$actorId) {
			return new DataResponse(['identity' => null]);
		}

		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) {
			return new DataResponse(['identity' => null]);
		}

		return new DataResponse([
			'did' => $identity['did'],
			'handle' => $identity['handle'],
			'state' => $identity['state'],
			'profileUrl' => 'https://bsky.app/profile/' . $identity['did']
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	#[\OCP\AppFramework\Http\Attribute\FrontpageRoute(verb: 'POST', url: '/api/atproto/identity')]
	public function createIdentity(): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if (!$userId) {
			return new DataResponse(['error' => 'Not logged in'], 401);
		}

		$actorId = $this->getActorId($userId);
		if (!$actorId) {
			return new DataResponse(['error' => 'No Social actor found'], 404);
		}

		try {
			$identity = $this->identityService->createIdentity($actorId);
			if ($identity['state'] !== IdentityService::STATE_ACTIVE) {
				return new DataResponse(['error' => 'PLC registration is pending', 'did' => $identity['did'], 'handle' => $identity['handle'], 'state' => $identity['state']], 503);
			}
			return new DataResponse([
				'success' => true,
				'did' => $identity['did'],
				'handle' => $identity['handle']
			]);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[\OCP\AppFramework\Http\Attribute\FrontpageRoute(verb: 'POST', url: '/api/atproto/identity/recovery')]
	public function getRecoveryPhrase(): DataResponse {
		if (!$this->identityService->isEnabled()) {
			return new DataResponse(['error' => 'AT Protocol is disabled'], 503);
		}
		$userId = $this->userSession->getUser()?->getUID();
		if (!$userId) {
			return new DataResponse(['error' => 'Not logged in'], 401);
		}

		$actorId = $this->getActorId($userId);
		if (!$actorId) {
			return new DataResponse(['error' => 'No Social actor found'], 404);
		}

		$phrase = $this->identityService->getRecoveryPhrase($actorId);
		if (!$phrase) {
			return new DataResponse(['error' => 'Recovery phrase not available'], 404);
		}

		return new DataResponse(['recoveryPhrase' => $phrase]);
	}

	private function getActorId(string $userId): ?string {
		return $this->identityService->actorIdForUser($userId);
	}
}
