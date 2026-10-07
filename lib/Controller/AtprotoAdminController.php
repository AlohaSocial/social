<?php
declare(strict_types=1);
namespace OCA\Social\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JsonResponse;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\{IRequest, IConfig, IDBConnection};
use OCA\Social\Atproto\Identity\IdentityService;
/** Session and administrator checks, plus CSRF protection, apply to every action. */
class AtprotoAdminController extends Controller {
	public function __construct(string $appName, IRequest $request, private readonly IConfig $config, private readonly IDBConnection $db, private readonly IdentityService $identities) { parent::__construct($appName, $request); }
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/atproto/settings')]
	public function getSettings(): JsonResponse {
		return new JsonResponse(['enabled' => $this->identities->isEnabled(), 'endpoint' => $this->identities->getPdsEndpoint()]);
	}
	#[FrontpageRoute(verb: 'POST', url: '/api/admin/atproto/settings')]
	public function updateSettings(bool $enabled): JsonResponse {
		$this->config->setAppValue('social', 'atproto_enabled', $enabled ? '1' : '0');
		return $this->getSettings();
	}
	#[FrontpageRoute(verb: 'GET', url: '/api/admin/atproto/status')]
	public function getStatus(): JsonResponse {
		$counts = [];
		foreach (['identities' => 'identity', 'records' => 'record', 'blobs' => 'blob', 'events' => 'event', 'queued' => 'outbox'] as $key => $table) {
			$qb = $this->db->getQueryBuilder(); $counts[$key] = (int)$qb->select($qb->func()->count('*'))->from('social_atproto_' . $table)->executeQuery()->fetchOne();
		}
		return new JsonResponse(['enabled' => $this->identities->isEnabled(), 'counts' => $counts]);
	}
}
