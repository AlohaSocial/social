<?php
declare(strict_types=1);

namespace OCA\Social\Controller\Atproto;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JsonResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Social\Atproto\Identity\IdentityService;

class IdentityController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IdentityService $identityService,
		private readonly IUserSession $userSession
	) {
		parent::__construct($appName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 */
	public function getIdentity(): JsonResponse {
		$userId = $this->userSession->getUserId();
		if (!$userId) {
			return new JsonResponse(['error' => 'Not logged in'], 401);
		}
		
		$actorId = $this->getActorId($userId);
		if (!$actorId) {
			return new JsonResponse(['identity' => null]);
		}
		
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) {
			return new JsonResponse(['identity' => null]);
		}
		
		return new JsonResponse([
			'did' => $identity['did'],
			'handle' => $identity['handle'],
			'state' => $identity['state'],
			'profileUrl' => 'https://bsky.app/profile/' . $identity['handle']
		]);
	}
	
	/**
	 * @NoAdminRequired
	 */
	public function createIdentity(): JsonResponse {
		$userId = $this->userSession->getUserId();
		if (!$userId) {
			return new JsonResponse(['error' => 'Not logged in'], 401);
		}
		
		$actorId = $this->getActorId($userId);
		if (!$actorId) {
			return new JsonResponse(['error' => 'No Social actor found'], 404);
		}
		
		try {
			$identity = $this->identityService->createIdentity($actorId);
			return new JsonResponse([
				'success' => true,
				'did' => $identity['did'],
				'handle' => $identity['handle']
			]);
		} catch (\Throwable $e) {
			return new JsonResponse(['error' => $e->getMessage()], 500);
		}
	}
	
	/**
	 * @NoAdminRequired
	 */
	public function getRecoveryPhrase(): JsonResponse {
		$userId = $this->userSession->getUserId();
		if (!$userId) {
			return new JsonResponse(['error' => 'Not logged in'], 401);
		}
		
		$actorId = $this->getActorId($userId);
		if (!$actorId) {
			return new JsonResponse(['error' => 'No Social actor found'], 404);
		}
		
		$phrase = $this->identityService->getRecoveryPhrase($actorId);
		if (!$phrase) {
			return new JsonResponse(['error' => 'Recovery phrase not available'], 404);
		}
		
		return new JsonResponse(['recoveryPhrase' => $phrase]);
	}
	
	/**
	 * @NoAdminRequired
	 */
	public function regenerateRecovery(): JsonResponse {
		$userId = $this->userSession->getUserId();
		if (!$userId) {
			return new JsonResponse(['error' => 'Not logged in'], 401);
		}
		
		$actorId = $this->getActorId($userId);
		if (!$actorId) {
			return new JsonResponse(['error' => 'No Social actor found'], 404);
		}
		
		$this->identityService->rotateKeys($actorId, true);
		
		$phrase = $this->identityService->getRecoveryPhrase($actorId);
		return new JsonResponse(['recoveryPhrase' => $phrase]);
	}
	
	private function getActorId(string $userId): ?int {
		// This would need to be implemented to map Nextcloud user ID to Social actor ID
		// For now, return a placeholder
		return 1;
	}
}