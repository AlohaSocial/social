<?php
declare(strict_types=1);
namespace OCA\Social\Controller;
use OCA\Social\Atproto\Identity\IdentityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JsonResponse, DataDisplayResponse};
use OCP\AppFramework\Http\Attribute\{FrontpageRoute, PublicPage, NoCSRFRequired, AnonRateLimit};
use OCP\IRequest;
class AtprotoWellKnownController extends Controller {
	public function __construct(string $appName, IRequest $request, private readonly IdentityService $identities) { parent::__construct($appName, $request); }
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/atproto-did')]
	public function atprotoDid(): DataDisplayResponse {
		if (!$this->identities->isEnabled()) { return new DataDisplayResponse('', 404, ['Content-Type' => 'text/plain']); }
		$identity = $this->identities->getIdentityByHandle(strtolower($this->request->getServerHost()));
		return new DataDisplayResponse($identity !== null && $identity['state'] === IdentityService::STATE_ACTIVE ? $identity['did'] : '', $identity !== null && $identity['state'] === IdentityService::STATE_ACTIVE ? 200 : 404, ['Content-Type' => 'text/plain; charset=utf-8']);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/did.json')]
	public function didJson(): JsonResponse {
		if (!$this->identities->isEnabled()) { return new JsonResponse(['error' => 'Unavailable'], 404); }
		$endpoint = $this->identities->getPdsEndpoint(); $did = 'did:web:' . parse_url($endpoint, PHP_URL_HOST); $key = $this->identities->getInstanceKey('service');
		return new JsonResponse(['@context' => ['https://www.w3.org/ns/did/v1', 'https://w3id.org/security/multikey/v1'], 'id' => $did,
			'verificationMethod' => [['id' => $did . '#atproto', 'type' => 'Multikey', 'controller' => $did, 'publicKeyMultibase' => $key['multibase']]],
			'service' => [['id' => $did . '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $endpoint]]]);
	}
}
