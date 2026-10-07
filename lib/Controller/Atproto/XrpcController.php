<?php
declare(strict_types=1);

namespace OCA\Social\Controller\Atproto;

use OCA\Social\Atproto\Identity\AtprotoDid;
use OCA\Social\Atproto\Identity\HandleMapper;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\BlobService;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Atproto\Repository\Record;
use OCP\AppFramework\Http\JsonResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\AppFramework\Controller;

class XrpcController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IdentityService $identityService,
		private readonly HandleMapper $handleMapper,
		private readonly AtprotoDid $atprotoDid,
		private readonly Repository $repository,
		private readonly BlobService $blobService
	) {
		parent::__construct($appName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function describeServer(): JsonResponse {
		return new JsonResponse([
			'did' => $this->getServiceDid(),
			'availableAccountMigrations' => [],
			'description' => 'Aloha Social AT Protocol PDS',
			'links' => [
				'privacy' => $this->getServerUrl() . '/privacy',
				'terms' => $this->getServerUrl() . '/terms'
			]
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function resolveHandle(string $handle): JsonResponse {
		$identity = $this->identityService->getIdentityByHandle($handle);
		if (!$identity) {
			return new JsonResponse(['error' => 'Handle not found'], Http::STATUS_NOT_FOUND);
		}
		
		return new JsonResponse([
			'did' => $identity['did'],
			'handle' => $identity['handle']
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function getRepo(string $did): JsonResponse {
		$head = $this->repository->getHead($did);
		if (!$head) {
			return new JsonResponse(['error' => 'Repository not found'], Http::STATUS_NOT_FOUND);
		}
		
		// Return CAR file (simplified - would stream actual CAR)
		return new JsonResponse([
			'root' => $head['commit_cid'],
			'blocks' => [] // Would contain actual blocks
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function getLatestCommit(string $did): JsonResponse {
		$head = $this->repository->getHead($did);
		if (!$head) {
			return new JsonResponse(['error' => 'Repository not found'], Http::STATUS_NOT_FOUND);
		}
		
		return new JsonResponse([
			'cid' => $head['commit_cid'],
			'rev' => $head['rev']
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function getRecord(string $did, string $collection, string $rkey): JsonResponse {
		$record = $this->repository->getRecord($did, $collection, $rkey);
		if (!$record) {
			return new JsonResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}
		
		return new JsonResponse([
			'cid' => $record->cid,
			'value' => $record->getValue()
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function createRecord(string $did, string $collection, string $rkey, array $record): JsonResponse {
		// Verify auth - would check service auth token
		$identity = $this->identityService->getIdentityByDid($did);
		if (!$identity) {
			return new JsonResponse(['error' => 'Repository not found'], Http::STATUS_NOT_FOUND);
		}
		
		$createdRecord = $this->repository->createRecord($did, $collection, $rkey, $record);
		$this->repository->commit($did, $this->identityService->getSigningKey($identity['actor_id'] ?? 0));
		
		return new JsonResponse([
			'cid' => $createdRecord->cid,
			'uri' => $createdRecord->getAtUri()
		], Http::STATUS_CREATED);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function deleteRecord(string $did, string $collection, string $rkey): JsonResponse {
		$identity = $this->identityService->getIdentityByDid($did);
		if (!$identity) {
			return new JsonResponse(['error' => 'Repository not found'], Http::STATUS_NOT_FOUND);
		}
		
		$this->repository->deleteRecord($did, $collection, $rkey);
		$this->repository->commit($did, $this->identityService->getSigningKey($identity['actor_id'] ?? 0));
		
		return new JsonResponse(['success' => true]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function uploadBlob(string $did, int $documentId, string $mimeType): JsonResponse {
		$identity = $this->identityService->getIdentityByDid($did);
		if (!$identity) {
			return new JsonResponse(['error' => 'Repository not found'], Http::STATUS_NOT_FOUND);
		}
		
		try {
			$blob = $this->blobService->uploadBlob($did, $documentId, $mimeType);
			return new JsonResponse($blob);
		} catch (\Throwable $e) {
			return new JsonResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function createSession(string $identifier, string $password): JsonResponse {
		// App password authentication for Bluesky apps
		// Would verify against Nextcloud user manager
		return new JsonResponse([
			'accessJwt' => 'jwt-token',
			'refreshJwt' => 'refresh-token',
			'handle' => $identifier,
			'did' => 'did:plc:...',
			'email' => 'user@example.com',
			'emailConfirmed' => true,
			'active' => true
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getServiceAuth(string $aud, string $lxm): JsonResponse {
		// Return service auth token for AppView proxy
		return new JsonResponse([
			'token' => 'service-auth-jwt'
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function getWellKnownAtprotoDid(string $handle): JsonResponse {
		$identity = $this->identityService->getIdentityByHandle($handle);
		if (!$identity) {
			return new JsonResponse('', Http::STATUS_NOT_FOUND);
		}
		
		// Return plain text DID
		return new JsonResponse($identity['did'], Http::STATUS_OK, ['Content-Type' => 'text/plain']);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function getWellKnownDidJson(): JsonResponse {
		// Service DID document
		return new JsonResponse([
			'@context' => 'https://www.w3.org/ns/did/v1',
			'id' => $this->getServiceDid(),
			'verificationMethod' => [[
				'id' => $this->getServiceDid() . '#atproto',
				'type' => 'Multikey',
				'controller' => $this->getServiceDid(),
				'publicKeyMultibase' => $this->getServiceSigningKey()
			]],
			'service' => [[
				'id' => '#atproto_pds',
				'type' => 'AtprotoPersonalDataServer',
				'serviceEndpoint' => $this->getServerUrl()
			]]
		]);
	}
	
	private function getServerUrl(): string {
		return \OCP\Config::getSystemValue('overwrite.cli.url', 'http://localhost');
	}
	
	private function getServiceDid(): string {
		$host = parse_url($this->getServerUrl(), PHP_URL_HOST) ?? 'localhost';
		return 'did:web:' . $host;
	}
	
	private function getServiceSigningKey(): string {
		// Would load from instance key storage
		return 'z...'; // multibase encoded public key
	}
}