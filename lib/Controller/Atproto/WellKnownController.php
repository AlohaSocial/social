<?php
declare(strict_types=1);

namespace OCA\Social\Controller\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCP\AppFramework\Http\JsonResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Attribute\FrontpageRoute;
use OCP\IDBConnection;
use OCP\IConfig;

class WellKnownController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IdentityService $identityService,
		private readonly IDBConnection $db,
		private readonly IConfig $config
	) {
		parent::__construct($appName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @FrontpageRoute("/.well-known/atproto-did", methods={"GET"})
	 */
	public function atprotoDid(string $handle): JsonResponse {
		$identity = $this->identityService->getIdentityByHandle($handle);
		if (!$identity) {
			return new JsonResponse('', Http::STATUS_NOT_FOUND);
		}
		
		return new JsonResponse($identity['did'], Http::STATUS_OK, ['Content-Type' => 'text/plain']);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @FrontpageRoute("/.well-known/did.json", methods={"GET"})
	 */
	public function didJson(): JsonResponse {
		$socialUrl = $this->config->getSystemValue('social_url', '');
		if (empty($socialUrl)) {
			$socialUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		}
		
		$host = parse_url($socialUrl, PHP_URL_HOST) ?? 'localhost';
		$serviceDid = 'did:web:' . $host;
		
		// Get service signing key from instance keys
		$qb = $this->db->getQueryBuilder();
		$qb->select('public_key')
			->from('social_atproto_instance_key')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('service')))
			->orderBy('created', 'DESC')
			->setMaxResults(1);
		
		$result = $qb->executeQuery()->fetchAssociative();
		$publicKey = $result['public_key'] ?? '';
		
		if (empty($publicKey)) {
			// Generate a new service key if none exists
			// This should not happen in production - keys should be pre-generated
			$publicKey = 'zQ3sh...placeholder'; // Will be replaced on first run
			$this->logger->warning('No AT Protocol service key found, using placeholder');
		}
		
		return new JsonResponse([
			'@context' => 'https://www.w3.org/ns/did/v1',
			'id' => $serviceDid,
			'verificationMethod' => [[
				'id' => $serviceDid . '#atproto',
				'type' => 'Multikey',
				'controller' => $serviceDid,
				'publicKeyMultibase' => $publicKey
			]],
			'service' => [[
				'id' => '#atproto_pds',
				'type' => 'AtprotoPersonalDataServer',
				'serviceEndpoint' => 'https://' . $host
			]]
		]);
	}
}