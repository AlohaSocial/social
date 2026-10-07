<?php

declare(strict_types=1);

namespace OCA\Social\Controller;

use OCA\Social\Atproto\Identity\IdentityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class AtprotoWellKnownController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IdentityService $identities,
	) {
		parent::__construct($appName, $request);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/atproto-did')]
	public function atprotoDid(): DataDisplayResponse {
		if (!$this->identities->isEnabled()) {
			return new DataDisplayResponse('', 404, ['Content-Type' => 'text/plain']);
		}
		// Handle discovery follows the incoming host, even when Nextcloud's
		// canonical overwritehost points at a different ActivityPub origin.
		$identity = $this->identities->getIdentityByHandle(strtolower((string)parse_url('https://' . ($this->request->getHeader('Host') ?: $this->request->getServerHost()), PHP_URL_HOST)));
		return new DataDisplayResponse($identity !== null && $identity['state'] === IdentityService::STATE_ACTIVE ? $identity['did'] : '', $identity !== null && $identity['state'] === IdentityService::STATE_ACTIVE ? 200 : 404, ['Content-Type' => 'text/plain; charset=utf-8']);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/did.json')]
	public function didJson(): DataResponse {
		if (!$this->identities->isEnabled()) {
			return new DataResponse(['error' => 'Unavailable'], 404);
		}
		$endpoint = $this->identities->getPdsEndpoint();
		$did = $this->identities->getServiceDid();
		$key = $this->identities->getInstanceKey('service');
		return new DataResponse(['@context' => ['https://www.w3.org/ns/did/v1', 'https://w3id.org/security/multikey/v1'], 'id' => $did,
			'verificationMethod' => [['id' => $did . '#atproto', 'type' => 'Multikey', 'controller' => $did, 'publicKeyMultibase' => $key['multibase']]],
			'service' => [['id' => $did . '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $endpoint]]]);
	}
}
