<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\XRPC;

use OCP\AppFramework\Http\JsonResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\AppFramework\Controller;

class AppViewProxyController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly \OCP\Http\Client\IClientService $clientService
	) {
		parent::__construct($appName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function proxy(string $nsid, array $params = []): JsonResponse {
		$appViewUrl = \OCP\Config::getAppValue('social', 'atproto_appview', 'https://public.api.bsky.app');
		$url = $appViewUrl . '/xrpc/' . $nsid;
		
		// Get service auth token for the requesting user
		$serviceAuth = $this->getServiceAuthToken();
		
		$client = $this->clientService->newClient();
		
		try {
			$response = $client->get($url, [
				'query' => $params,
				'headers' => [
					'Authorization' => 'Bearer ' . $serviceAuth,
					'Accept' => 'application/json'
				]
			]);
			
			return new JsonResponse(
				json_decode($response->getBody(), true) ?? [],
				$response->getStatusCode()
			);
		} catch (\Throwable $e) {
			return new JsonResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}
	
	private function getServiceAuthToken(): string {
		// Would generate service auth JWT signed by user's signing key
		// aud: did:web:api.bsky.app
		return 'service-auth-jwt';
	}
}