<?php
declare(strict_types=1);

namespace OCA\Social\Controller\Atproto;

use OCA\Social\Atproto\Identity\AtprotoDid;
use OCA\Social\Atproto\Identity\HandleMapper;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\KeyManager;
use OCA\Social\Atproto\Repository\BlobService;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Atproto\Repository\Record;
use OCP\AppFramework\Http\JsonResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\AppFramework\Attribute\FrontpageRoute;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Token\Plain;

class XrpcController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IdentityService $identityService,
		private readonly HandleMapper $handleMapper,
		private readonly AtprotoDid $atprotoDid,
		private readonly Repository $repository,
		private readonly BlobService $blobService,
		private readonly IUserManager $userManager,
		private readonly IConfig $config,
		private readonly ICrypto $crypto,
		private readonly KeyManager $keyManager
	) {
		parent::__construct($appName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @FrontpageRoute("/xrpc/com.atproto.server.describeServer", methods={"GET"})
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
	 * @FrontpageRoute("/xrpc/com.atproto.identity.resolveHandle", methods={"GET"})
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
	 * @FrontpageRoute("/xrpc/com.atproto.sync.getRepo", methods={"GET"})
	 */
	public function getRepo(string $did): \OCP\AppFramework\Http\StreamResponse {
		$head = $this->repository->getHead($did);
		if (!$head) {
			// Return error as JSON since we can't stream
			return new \OCP\AppFramework\Http\StreamResponse(
				function() {
					echo json_encode(['error' => 'Repository not found']);
				},
				Http::STATUS_NOT_FOUND,
				['Content-Type' => 'application/json']
			);
		}
		
		// Export CAR file and stream it
		$carData = $this->repository->exportCar($did);
		
		return new \OCP\AppFramework\Http\StreamResponse(
			function() use ($carData) {
				echo $carData;
			},
			Http::STATUS_OK,
			[
				'Content-Type' => 'application/vnd.ipld.car',
				'Content-Disposition' => 'attachment; filename="repo.car"',
				'Content-Length' => strlen($carData)
			]
		);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @FrontpageRoute("/xrpc/com.atproto.sync.getLatestCommit", methods={"GET"})
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
	 * @FrontpageRoute("/xrpc/com.atproto.sync.getRecord", methods={"GET"})
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
	 * @FrontpageRoute("/xrpc/com.atproto.repo.createRecord", methods={"POST"})
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
	 * @FrontpageRoute("/xrpc/com.atproto.repo.deleteRecord", methods={"POST"})
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
	public function createSession(string $identifier, string $password): JsonResponse {
		// Verify app password against Nextcloud user
		$user = $this->userManager->get($identifier);
		if (!$user) {
			return new JsonResponse(['error' => 'Invalid identifier or password'], Http::STATUS_UNAUTHORIZED);
		}
		
		if (!$this->userManager->checkPassword($user, $password)) {
			return new JsonResponse(['error' => 'Invalid identifier or password'], Http::STATUS_UNAUTHORIZED);
		}
		
		// Get actor ID for this user
		$actorId = $this->getActorId($identifier);
		if (!$actorId) {
			return new JsonResponse(['error' => 'No Social actor found'], Http::STATUS_NOT_FOUND);
		}
		
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity) {
			return new JsonResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
		}
		
		// Generate JWT tokens
		$accessJwt = $this->generateAccessJwt($identity);
		$refreshJwt = $this->generateRefreshJwt($identity);
		
		// Store refresh token hash
		$this->storeRefreshToken($identity['did'], $refreshJwt);
		
		return new JsonResponse([
			'accessJwt' => $accessJwt,
			'refreshJwt' => $refreshJwt,
			'handle' => $identity['handle'],
			'did' => $identity['did'],
			'email' => $user->getEmail(),
			'emailConfirmed' => true,
			'active' => true
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function refreshSession(string $refreshJwt): JsonResponse {
		// Verify refresh token and issue new access token
		$identity = $this->verifyRefreshToken($refreshJwt);
		if (!$identity) {
			return new JsonResponse(['error' => 'Invalid refresh token'], Http::STATUS_UNAUTHORIZED);
		}
		
		$accessJwt = $this->generateAccessJwt($identity);
		$newRefreshJwt = $this->generateRefreshJwt($identity);
		
		// Rotate refresh token
		$this->rotateRefreshToken($identity['did'], $refreshJwt, $newRefreshJwt);
		
		return new JsonResponse([
			'accessJwt' => $accessJwt,
			'refreshJwt' => $newRefreshJwt,
			'handle' => $identity['handle'],
			'did' => $identity['did'],
			'email' => $identity['email'] ?? '',
			'emailConfirmed' => true,
			'active' => true
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getSession(string $authorization): JsonResponse {
		// Validate access JWT
		$identity = $this->validateAccessJwt($authorization);
		if (!$identity) {
			return new JsonResponse(['error' => 'Invalid access token'], Http::STATUS_UNAUTHORIZED);
		}
		
		return new JsonResponse([
			'did' => $identity['did'],
			'handle' => $identity['handle'],
			'email' => $identity['email'] ?? '',
			'emailConfirmed' => true,
			'active' => true
		]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function deleteSession(string $refreshJwt): JsonResponse {
		// Revoke refresh token
		$this->revokeRefreshToken($refreshJwt);
		return new JsonResponse(['success' => true]);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getServiceAuth(string $aud, string $lxm): JsonResponse {
		// Get the current user's identity
		$authHeader = $this->request->getHeader('Authorization');
		if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
			return new JsonResponse(['error' => 'Missing authorization'], Http::STATUS_UNAUTHORIZED);
		}
		
		$accessJwt = substr($authHeader, 7);
		$identity = $this->validateAccessJwt($accessJwt);
		if (!$identity) {
			return new JsonResponse(['error' => 'Invalid access token'], Http::STATUS_UNAUTHORIZED);
		}
		
		// Generate service auth JWT
		$serviceAuth = $this->generateServiceAuth($identity, $aud, $lxm);
		
		return new JsonResponse([
			'token' => $serviceAuth
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
	
	// JWT helper methods
	
	private function generateAccessJwt(array $identity): string {
		$config = Configuration::forSymmetricSigner(
			new \Lcobucci\JWT\Signer\Hmac\Sha256(),
			InMemory::plainText($this->getJwtSecret())
		);
		
		$now = new \DateTimeImmutable();
		$token = $config->builder()
			->issuedBy($this->getServerUrl())
			->permittedFor('did:web:api.bsky.app')
			->identifiedBy($identity['did'])
			->issuedAt($now)
			->expiresAt($now->modify('+2 hours'))
			->withClaim('scope', 'com.atproto.repo.*')
			->getToken($config->signer(), $config->signingKey());
		
		return $token->toString();
	}
	
	private function generateRefreshJwt(array $identity): string {
		$config = Configuration::forSymmetricSigner(
			new \Lcobucci\JWT\Signer\Hmac\Sha256(),
			InMemory::plainText($this->getJwtSecret())
		);
		
		$jti = bin2hex(random_bytes(16));
		$now = new \DateTimeImmutable();
		$token = $config->builder()
			->issuedBy($this->getServerUrl())
			->permittedFor($this->getServerUrl())
			->identifiedBy($jti)
			->relatedTo($identity['did'])
			->issuedAt($now)
			->expiresAt($now->modify('+90 days'))
			->withClaim('type', 'refresh')
			->getToken($config->signer(), $config->signingKey());
		
		return $token->toString();
	}
	
	private function generateServiceAuth(array $identity, string $aud, string $lxm): string {
		$config = Configuration::forSymmetricSigner(
			new \Lcobucci\JWT\Signer\Hmac\Sha256(),
			InMemory::plainText($this->getJwtSecret())
		);
		
		$now = new \DateTimeImmutable();
		$token = $config->builder()
			->issuedBy($this->getServiceDid())
			->permittedFor($aud)
			->identifiedBy($identity['did'])
			->issuedAt($now)
			->expiresAt($now->modify('+1 minute'))
			->withClaim('lxm', $lxm)
			->getToken($config->signer(), $config->signingKey());
		
		return $token->toString();
	}
	
	private function validateAccessJwt(string $jwt): ?array {
		try {
			$config = Configuration::forSymmetricSigner(
				new \Lcobucci\JWT\Signer\Hmac\Sha256(),
				InMemory::plainText($this->getJwtSecret())
			);
			
			$token = $config->parser()->parse($jwt);
			$config->validator()->validate($token, ...[
				new \Lcobucci\JWT\Validation\Constraint\IssuedBy($this->getServerUrl()),
				new \Lcobucci\JWT\Validation\Constraint\PermittedFor('did:web:api.bsky.app'),
				new \Lcobucci\JWT\Validation\Constraint\SignedWith($config->signingKey())
			]);
			
			$did = $token->claims()->get('sub');
			return $this->identityService->getIdentityByDid($did);
		} catch (\Throwable $e) {
			return null;
		}
	}
	
	private function verifyRefreshToken(string $jwt): ?array {
		try {
			$config = Configuration::forSymmetricSigner(
				new \Lcobucci\JWT\Signer\Hmac\Sha256(),
				InMemory::plainText($this->getJwtSecret())
			);
			
			$token = $config->parser()->parse($jwt);
			$config->validator()->validate($token, ...[
				new \Lcobucci\JWT\Validation\Constraint\IssuedBy($this->getServerUrl()),
				new \Lcobucci\JWT\Validation\Constraint\SignedWith($config->signingKey())
			]);
			
			$did = $token->claims()->get('sub');
			$jti = $token->claims()->get('jti');
			
			// Check if refresh token is still valid (not revoked)
			if (!$this->isRefreshTokenValid($did, $jti)) {
				return null;
			}
			
			return $this->identityService->getIdentityByDid($did);
		} catch (\Throwable $e) {
			return null;
		}
	}
	
	private function getJwtSecret(): string {
		return $this->config->getSystemValue('secret', '') . '_atproto_jwt';
	}
	
	private function storeRefreshToken(string $did, string $refreshJwt): void {
		$config = Configuration::forSymmetricSigner(
			new \Lcobucci\JWT\Signer\Hmac\Sha256(),
			InMemory::plainText($this->getJwtSecret())
		);
		$token = $config->parser()->parse($refreshJwt);
		$jti = $token->claims()->get('jti');
		$expires = $token->claims()->get('exp');
		
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_session')
			->values([
				'did' => $qb->createNamedParameter($did),
				'jti' => $qb->createNamedParameter($jti),
				'refresh_token_hash' => $qb->createNamedParameter(hash('sha256', $refreshJwt)),
				'expires' => $qb->createNamedParameter((new \DateTimeImmutable('@' . $expires))->format('Y-m-d H:i:s')),
				'created' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))
			])
			->executeStatement();
	}
	
	private function isRefreshTokenValid(string $did, string $jti): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->selectCount('*', 'count')
			->from('social_atproto_session')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('jti', $qb->createNamedParameter($jti)))
			->andWhere($qb->expr()->gt('expires', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))));
		
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}
	
	private function rotateRefreshToken(string $did, string $oldJwt, string $newJwt): void {
		$config = Configuration::forSymmetricSigner(
			new \Lcobucci\JWT\Signer\Hmac\Sha256(),
			InMemory::plainText($this->getJwtSecret())
		);
		$oldToken = $config->parser()->parse($oldJwt);
		$oldJti = $oldToken->claims()->get('jti');
		
		$newToken = $config->parser()->parse($newJwt);
		$newJti = $newToken->claims()->get('jti');
		$expires = $newToken->claims()->get('exp');
		
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atproto_session')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('jti', $qb->createNamedParameter($oldJti)))
			->executeStatement();
		
		$qb->insert('social_atproto_session')
			->values([
				'did' => $qb->createNamedParameter($did),
				'jti' => $qb->createNamedParameter($newJti),
				'refresh_token_hash' => $qb->createNamedParameter(hash('sha256', $newJwt)),
				'expires' => $qb->createNamedParameter((new \DateTimeImmutable('@' . $expires))->format('Y-m-d H:i:s')),
				'created' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))
			])
			->executeStatement();
	}
	
	private function revokeRefreshToken(string $refreshJwt): void {
		$config = Configuration::forSymmetricSigner(
			new \Lcobucci\JWT\Signer\Hmac\Sha256(),
			InMemory::plainText($this->getJwtSecret())
		);
		$token = $config->parser()->parse($refreshJwt);
		$jti = $token->claims()->get('jti');
		
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atproto_session')
			->where($qb->expr()->eq('jti', $qb->createNamedParameter($jti)))
			->executeStatement();
	}
	
	private function getActorId(string $userId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('social_actor')
			->where($qb->expr()->eq('preferredUsername', $qb->createNamedParameter($userId)));
		
		return (int)($qb->executeQuery()->fetchOne() ?? 0);
	}
}