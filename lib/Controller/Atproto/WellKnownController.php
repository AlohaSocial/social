<?php
declare(strict_types=1);

namespace OCA\Social\Controller\Atproto;

use OCP\AppFramework\Http\JsonResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\AppFramework\Controller;

class WellKnownController extends Controller {
	public function __construct(
		$appName,
		IRequest $request
	) {
		parent::__construct($appName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function atprotoDid(string $handle): JsonResponse {
		// Route to XRPC controller
		// This is handled by the web server rewrite rules
		return new JsonResponse('', Http::STATUS_NOT_FOUND);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function didJson(): JsonResponse {
		// Service DID document
		$host = \OCP\Config::getSystemValue('overwrite.cli.url', 'http://localhost');
		$host = parse_url($host, PHP_URL_HOST) ?? 'localhost';
		
		return new JsonResponse([
			'@context' => 'https://www.w3.org/ns/did/v1',
			'id' => 'did:web:' . $host,
			'verificationMethod' => [[
				'id' => 'did:web:' . $host . '#atproto',
				'type' => 'Multikey',
				'controller' => 'did:web:' . $host,
				'publicKeyMultibase' => '' // Would load from instance key
			]],
			'service' => [[
				'id' => '#atproto_pds',
				'type' => 'AtprotoPersonalDataServer',
				'serviceEndpoint' => 'https://' . $host
			]]
		]);
	}
}