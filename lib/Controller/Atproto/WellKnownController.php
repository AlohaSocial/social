<?php
declare(strict_types=1);

namespace OCA\Social\Controller\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCP\AppFramework\Http\JsonResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\IConfig;

class WellKnownController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IdentityService $identityService,
		private readonly IConfig $config
	) {
		parent::__construct($appName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
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
	 */
	public function didJson(): JsonResponse {
		$socialUrl = $this->config->getSystemValue('social_url', '');
		if (empty($socialUrl)) {
			$socialUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		}
		
		$host = parse_url($socialUrl, PHP_URL_HOST) ?? 'localhost';
		$serviceDid = 'did:web:' . $host;
		
		// Get service signing key
		$qb = $this->db->getQueryBuilder();
		$qb->select('public_key')
			->from('social_atproto_instance_key')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('service')))
			->orderBy('created', 'DESC')
			->setMaxResults(1);
		
		$result = $qb->executeQuery()->fetchAssociative();
		$publicKey = $result['public_key'] ?? '';
		
		if (empty($publicKey)) {
			// Generate a placeholder if not set yet
			$publicKey = 'z6Mk' . bin2hex(random_bytes(32)); // placeholder
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