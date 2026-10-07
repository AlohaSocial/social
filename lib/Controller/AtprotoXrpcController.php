<?php
declare(strict_types=1);
namespace OCA\Social\Controller;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Atproto\Protocol\{Car, DagCbor};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JsonResponse, DataDisplayResponse};
use OCP\AppFramework\Http\Attribute\{FrontpageRoute, PublicPage, NoCSRFRequired, AnonRateLimit};
use OCP\IRequest;
/** Public PDS read surface. Client writes are registered separately with token authentication. */
class AtprotoXrpcController extends Controller {
	public function __construct(string $appName, IRequest $request, private readonly IdentityService $identities, private readonly Repository $repository, private readonly \OCP\IDBConnection $db, private readonly \OCA\Social\Atproto\Repository\BlobService $blobs) { parent::__construct($appName, $request); }
	private function error(string $error, string $message, int $status = 400): JsonResponse { return new JsonResponse(['error' => $error, 'message' => $message], $status); }
	private function unavailable(?string $did = null): ?JsonResponse {
		if (!$this->identities->isEnabled()) { return $this->error('Unavailable', 'AT Protocol is disabled', 503); }
		if ($did !== null) {
			$identity = $this->identities->getIdentityByDid($did);
			if (!$identity) { return $this->error('RepoNotFound', 'Repository not found', 404); }
			if ($identity['state'] !== IdentityService::STATE_ACTIVE) { return $this->error('RepoDeactivated', 'Repository is not active', 403); }
		}
		return null;
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.server.describeServer')]
	public function describeServer(): JsonResponse {
		if ($error = $this->unavailable()) { return $error; }
		$host = parse_url($this->identities->getPdsEndpoint(), PHP_URL_HOST);
		return new JsonResponse(['did' => 'did:web:' . $host, 'availableUserDomains' => ['.' . $host], 'inviteCodeRequired' => true,
			'phoneVerificationRequired' => false, 'links' => new \stdClass()]);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.identity.resolveHandle')]
	public function resolveHandle(string $handle): JsonResponse {
		if ($error = $this->unavailable()) { return $error; }
		$identity = $this->identities->getIdentityByHandle($handle);
		return !$identity || $identity['state'] !== IdentityService::STATE_ACTIVE ? $this->error('HandleNotFound', 'Handle not found', 404) : new JsonResponse(['did' => $identity['did']]);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.sync.getRepo')]
	public function getRepo(string $did): JsonResponse|DataDisplayResponse {
		if ($error = $this->unavailable($did)) { return $error; }
		return new DataDisplayResponse($this->repository->exportCar($did), 200, ['Content-Type' => 'application/vnd.ipld.car']);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.sync.getLatestCommit')]
	public function getLatestCommit(string $did): JsonResponse {
		if ($error = $this->unavailable($did)) { return $error; }
		$head = $this->repository->getHead($did);
		return empty($head['commit_cid']) ? $this->error('RepoNotFound', 'Repository not found', 404) : new JsonResponse(['cid' => $head['commit_cid'], 'rev' => $head['rev']]);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.repo.getRecord')]
	public function getRecord(string $repo, string $collection, string $rkey): JsonResponse {
		if ($error = $this->unavailable()) { return $error; }
		$identity = str_starts_with($repo, 'did:') ? $this->identities->getIdentityByDid($repo) : $this->identities->getIdentityByHandle($repo);
		if (!$this->identities->isEnabled()) { return $this->unavailable(); }
		if (!$identity) { return $this->error('RepoNotFound', 'Repository not found', 404); }
		if ($error = $this->unavailable($identity['did'])) { return $error; }
		$record = $this->repository->getRecord($identity['did'], $collection, $rkey);
		return $record === null ? $this->error('RecordNotFound', 'Record not found', 404) : new JsonResponse(['uri' => $record->getAtUri(), 'cid' => $record->cid, 'value' => $record->getValue()]);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.sync.getRecord')]
	public function getRecordProof(string $did, string $collection, string $rkey): JsonResponse|DataDisplayResponse {
		if ($error = $this->unavailable($did)) { return $error; }
		if (!$this->repository->getRecord($did, $collection, $rkey)) { return $this->error('RecordNotFound', 'Record not found', 404); }
		// A full CAR is a valid inclusion proof, including the signed commit, MST and record.
		return new DataDisplayResponse($this->repository->exportCar($did), 200, ['Content-Type' => 'application/vnd.ipld.car']);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.sync.getBlocks')]
	public function getBlocks(string $did, array $cids): JsonResponse|DataDisplayResponse {
		if ($error = $this->unavailable($did)) { return $error; }
		if ($cids === [] || count($cids) > 100) { return $this->error('InvalidRequest', 'Request between 1 and 100 blocks'); }
		$blocks = [];
		foreach ($cids as $cid) {
			$bytes = $this->repository->getBlock($did, $cid);
			if ($bytes === null) { return $this->error('BlockNotFound', 'Block not found', 404); } $blocks[$cid] = $bytes;
		}
		$head = $this->repository->getHead($did);
		return new DataDisplayResponse(Car::encode($head['commit_cid'], $blocks), 200, ['Content-Type' => 'application/vnd.ipld.car']);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.sync.listRepos')]
	public function listRepos(int $limit = 500, ?string $cursor = null): JsonResponse {
		if ($error = $this->unavailable()) { return $error; }
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.did', 'r.commit_cid', 'r.rev')->from('social_atproto_repo', 'r')->innerJoin('r', 'social_atproto_identity', 'i', 'i.did = r.did')
			->where($qb->expr()->eq('i.state', $qb->createNamedParameter(IdentityService::STATE_ACTIVE)))->andWhere($qb->expr()->gt('r.did', $qb->createNamedParameter($cursor ?? '')))
			->orderBy('r.did', 'ASC')->setMaxResults(max(1, min(1000, $limit)));
		$rows = $qb->executeQuery()->fetchAllAssociative();
		$repos = array_map(static fn ($row) => ['did' => $row['did'], 'head' => $row['commit_cid'], 'rev' => $row['rev'], 'active' => true], $rows);
		$data = ['repos' => $repos]; if ($rows !== []) { $data['cursor'] = end($rows)['did']; } return new JsonResponse($data);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.sync.getBlob')]
	public function getBlob(string $did, string $cid): JsonResponse|DataDisplayResponse {
		if ($error = $this->unavailable($did)) { return $error; }
		$blob = $this->blobs->read($did, $cid);
		return $blob === null ? $this->error('BlobNotFound', 'Blob not found', 404) : new DataDisplayResponse($blob['bytes'], 200, ['Content-Type' => $blob['mime']]);
	}
	#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/xrpc/com.atproto.sync.listBlobs')]
	public function listBlobs(string $did, int $limit = 500, ?string $cursor = null): JsonResponse {
		if ($error = $this->unavailable($did)) { return $error; }
		$all = array_column($this->blobs->listBlobs($did), 'cid');
		$all = array_values(array_filter($all, static fn ($cid) => strcmp($cid, $cursor ?? '') > 0));
		$cids = array_slice($all, 0, max(1, min(1000, $limit))); $data = ['cids' => $cids];
		if ($cids !== []) { $data['cursor'] = end($cids); } return new JsonResponse($data);
	}

}
