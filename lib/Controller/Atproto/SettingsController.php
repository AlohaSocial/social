<?php
declare(strict_types=1);

namespace OCA\Social\Controller\Atproto;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JsonResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IConfig;

class SettingsController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IConfig $config
	) {
		parent::__construct($appName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 */
	public function getSettings(): JsonResponse {
		$userId = $this->userSession->getUserId();
		if (!$userId) {
			return new JsonResponse(['error' => 'Not logged in'], 401);
		}
		
		$prefix = 'social_atproto_user_' . $userId . '_';
		
		return new JsonResponse([
			'syncPosts' => $this->config->getUserValue($userId, 'social', $prefix . 'sync_posts', true),
			'syncInteractions' => $this->config->getUserValue($userId, 'social', $prefix . 'sync_interactions', true),
			'showBadge' => $this->config->getUserValue($userId, 'social', $prefix . 'show_badge', true)
		]);
	}
	
	/**
	 * @NoAdminRequired
	 */
	public function updateSettings(
		bool $syncPosts = true,
		bool $syncInteractions = true,
		bool $showBadge = true
	): JsonResponse {
		$userId = $this->userSession->getUserId();
		if (!$userId) {
			return new JsonResponse(['error' => 'Not logged in'], 401);
		}
		
		$prefix = 'social_atproto_user_' . $userId . '_';
		
		$this->config->setUserValue($userId, 'social', $prefix . 'sync_posts', $syncPosts);
		$this->config->setUserValue($userId, 'social', $prefix . 'sync_interactions', $syncInteractions);
		$this->config->setUserValue($userId, 'social', $prefix . 'show_badge', $showBadge);
		
		return new JsonResponse(['success' => true]);
	}
}