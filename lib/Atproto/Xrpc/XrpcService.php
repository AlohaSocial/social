<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Xrpc;

use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\Identity\HandleVerifier;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ModerationService;

/**
 * The XRPC methods this instance serves, each answering an array (JSON)
 * or an XrpcBytes (a CAR, a blob). The controller maps the HTTP side;
 * everything that is a protocol decision is here, where it can be tested.
 */
class XrpcService {
	public const MAX_LIST = 100;

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private RepositoryService $repositories,
		private AtprotoRepoRequest $repoRequest,
		private AtprotoBlobRequest $blobRequest,
		private PictureService $pictures,
		private VideoBlobService $videos,
		private ConfigService $configService,
		private ModerationService $moderation,
		private AtprotoIdentityRequest $identityRequest,
		private HandleVerifier $handles,
		private PlcClient $plc,
	) {
	}

	/**
	 * @param array<string, string|string[]> $params the query
	 * @return array|XrpcBytes
	 * @throws XrpcException
	 */
	public function query(string $method, array $params): array|XrpcBytes {
		if (!$this->config->isEnabled() && $method !== '_health') {
			throw XrpcException::notImplemented($method);
		}

		return match ($method) {
			'_health' => ['version' => 'aloha-social/' . $this->configService->getAppValue('installed_version')],
			'com.atproto.server.describeServer' => $this->describeServer(),
			'com.atproto.identity.resolveHandle' => $this->resolveHandle(self::string($params, 'handle')),
			'com.atproto.identity.resolveDid' => $this->resolveDid(self::string($params, 'did')),
			'com.atproto.identity.resolveIdentity' => $this->resolveIdentity(self::string($params, 'identifier')),
			'com.atproto.sync.getRepo' => $this->getRepo(self::string($params, 'did')),
			'com.atproto.sync.getLatestCommit' => $this->getLatestCommit(self::string($params, 'did')),
			'com.atproto.sync.getRepoStatus' => $this->getRepoStatus(self::string($params, 'did')),
			'com.atproto.sync.getRecord' => $this->getSyncRecord(self::string($params, 'did'), self::string($params, 'collection'), self::string($params, 'rkey')),
			'com.atproto.sync.getBlocks' => $this->getBlocks(self::string($params, 'did'), self::strings($params, 'cids')),
			'com.atproto.sync.getBlob' => $this->getBlob(self::string($params, 'did'), self::string($params, 'cid')),
			'com.atproto.sync.listBlobs' => $this->listBlobs(self::string($params, 'did'), self::limit($params), self::string($params, 'cursor', false)),
			'com.atproto.sync.listRepos' => $this->listRepos(self::limit($params, 500, 1000), self::string($params, 'cursor', false)),
			'com.atproto.sync.subscribeRepos' => throw new XrpcException(426, 'UpgradeRequired', 'subscribeRepos is a WebSocket; this URL answers only an upgrade request'),
			'com.atproto.repo.describeRepo' => $this->describeRepo(self::string($params, 'repo')),
			'com.atproto.repo.getRecord' => $this->getRecord(self::string($params, 'repo'), self::string($params, 'collection'), self::string($params, 'rkey')),
			'com.atproto.repo.listRecords' => $this->listRecords(self::string($params, 'repo'), self::string($params, 'collection'), self::limit($params, 50), self::string($params, 'cursor', false), self::string($params, 'reverse', false) === 'true'),
			default => throw XrpcException::notImplemented($method),
		};
	}

	/**
	 * A procedure (POST) nobody signed in made. A signed-in app's sessions
	 * and writes are `ClientXrpc`'s; what reaches here asks to be signed in,
	 * or is not something this PDS answers — a relay's `requestCrawl`, say.
	 *
	 * @throws XrpcException
	 * @psalm-suppress NoValue every procedure that reaches here is refused
	 */
	public function procedure(string $method, array $body): array {
		if (!$this->config->isEnabled()) {
			throw XrpcException::notImplemented($method);
		}

		return match ($method) {
			'com.atproto.server.createSession', 'com.atproto.server.refreshSession', 'com.atproto.server.deleteSession',
			'com.atproto.repo.createRecord', 'com.atproto.repo.putRecord', 'com.atproto.repo.deleteRecord',
			'com.atproto.repo.applyWrites', 'com.atproto.repo.uploadBlob', 'com.atproto.repo.importRepo' => throw XrpcException::authenticationRequired(),
			default => throw XrpcException::notImplemented($method),
		};
	}

	private function describeServer(): array {
		return [
			'did' => $this->config->serviceDid(),
			'availableUserDomains' => ['.' . $this->config->handleHost()],
			'inviteCodeRequired' => true,
			'blobUploadLimit' => ClientXrpc::MAX_UPLOAD,
		];
	}

	private function resolveHandle(string $handle): array {
		$handle = Syntax::normalizeHandle($handle);
		if (!Syntax::isHandle($handle)) {
			throw XrpcException::invalidRequest('Not a handle');
		}
		try {
			$identity = $this->identities->getByHandle($handle);
		} catch (AtprotoIdentityNotFoundException) {
			$did = $this->handles->resolve($handle);
			if ($did === null) {
				throw new XrpcException(404, 'HandleNotFound', 'Unable to resolve handle');
			}

			return ['did' => $did];
		}
		if (!$identity->isActive()) {
			throw XrpcException::invalidRequest('Unable to resolve handle');
		}

		return ['did' => $identity->did];
	}

	/** Resolve a managed identity or a PLC DID without trusting its handle claim. */
	private function resolveDid(string $did): array {
		if (!Syntax::isDid($did)) {
			throw XrpcException::invalidRequest('Not a DID');
		}
		if ($did === $this->config->serviceDid()) {
			return ['didDoc' => $this->identities->instanceDocument()];
		}
		try {
			$identity = $this->identities->getByDid($did);
		} catch (AtprotoIdentityNotFoundException) {
			try {
				$document = Syntax::isDidPlc($did) ? $this->plc->document($did) : null;
			} catch (AtprotoException $e) {
				throw new XrpcException(502, 'DidResolutionFailed', 'DID directory could not be reached');
			}
			if ($document === null || ($document['id'] ?? '') !== $did) {
				throw new XrpcException(404, 'DidNotFound', 'Could not resolve DID');
			}

			return ['didDoc' => $document];
		}
		if ($identity->state === Identity::STATE_TOMBSTONED) {
			throw new XrpcException(404, 'DidNotFound', 'Could not resolve DID');
		}
		if (!$identity->isActive()) {
			throw new XrpcException(400, 'DidDeactivated', 'DID is deactivated');
		}

		return ['didDoc' => $this->identities->document($identity)];
	}

	/** Resolve handles and DIDs into identityInfo; never advertise an unverified handle. */
	private function resolveIdentity(string $identifier): array {
		$identity = null;
		$handle = '';
		if (Syntax::isDid($identifier)) {
			try {
				$identity = $this->identities->getByDid($identifier);
			} catch (AtprotoIdentityNotFoundException) {
			}
		} elseif (Syntax::isResolvableHandle($identifier)) {
			$handle = Syntax::normalizeHandle($identifier);
			try {
				$identity = $this->identities->getByHandle($handle);
			} catch (AtprotoIdentityNotFoundException) {
				$did = $this->handles->resolve($handle);
				if ($did === null) {
					throw new XrpcException(404, 'HandleNotFound', 'Could not resolve handle');
				}
				try {
					$identity = $this->identities->getByDid($did);
				} catch (AtprotoIdentityNotFoundException) {
					$identity = null;
				}
				$identifier = $did;
			}
		} else {
			throw XrpcException::invalidRequest('Not an atproto identifier');
		}

		if ($identity !== null) {
			if (!$identity->isActive()) {
				throw new XrpcException(400, 'DidDeactivated', 'DID is deactivated');
			}
			$document = $this->identities->document($identity);
			$handleIsAssigned = $identity->handle === $identity->assignedHandle();
			$handleIsVerified = $handleIsAssigned || (!$identity->customHandleBroken()
				&& in_array('at://' . $identity->handle, $document['alsoKnownAs'] ?? [], true)
				&& $this->handles->verify($identity->handle, $identity->did) !== '');
			$resolvedHandle = $handleIsVerified ? $identity->handle : 'handle.invalid';
			if ($handle === '') {
				$handle = $resolvedHandle;
			}

			return ['did' => $identity->did, 'handle' => $resolvedHandle, 'didDoc' => $document];
		}

		if (!Syntax::isDid($identifier)) {
			throw new XrpcException(404, 'HandleNotFound', 'Could not resolve handle');
		}
		$document = $this->resolveDid($identifier)['didDoc'];
		if ($handle === '') {
			foreach (($document['alsoKnownAs'] ?? []) as $alias) {
				if (is_string($alias) && str_starts_with($alias, 'at://')) {
					$candidate = substr($alias, 5);
					if (Syntax::isResolvableHandle($candidate)) {
						$handle = $candidate;
						break;
					}
				}
			}
		}
		$verified = $handle !== ''
			&& in_array('at://' . $handle, $document['alsoKnownAs'] ?? [], true)
			&& $this->handles->verify($handle, $identifier) !== '';

		return ['did' => $identifier, 'handle' => $verified ? $handle : 'handle.invalid', 'didDoc' => $document];
	}

	/**
	 * The whole repository, written out as it is read.
	 */
	private function getRepo(string $did): XrpcBytes {
		$identity = $this->repoIdentity($did);
		try {
			return new XrpcBytes('', 'application/vnd.ipld.car', 200, null, -1, [], $this->repositories->streamCar($identity->did));
		} catch (AtprotoException) {
			throw new XrpcException(404, 'RepoNotFound', 'Repository has no commits yet');
		}
	}

	private function getLatestCommit(string $did): array {
		$identity = $this->repoIdentity($did);
		$head = $this->repositories->getHead($identity->did);
		if ($head === null) {
			throw new XrpcException(404, 'RepoNotFound', 'Repository has no commits yet');
		}

		return ['cid' => $head->commitCid, 'rev' => $head->rev];
	}

	private function getRepoStatus(string $did): array {
		try {
			$identity = $this->identities->getByDid($did);
		} catch (AtprotoIdentityNotFoundException) {
			throw new XrpcException(404, 'RepoNotFound', 'Could not find repo');
		}
		$inactive = $this->inactiveStatus($identity);
		$status = ['did' => $identity->did, 'active' => $inactive === ''];
		if ($inactive !== '') {
			$status['status'] = $inactive;

			return $status;
		}
		$head = $this->repositories->getHead($identity->did);
		if ($head !== null) {
			$status['rev'] = $head->rev;
		}

		return $status;
	}

	/**
	 * The record with its proof: the commit, the tree and the record, as a
	 * CAR with the commit as root. The whole tree rather than the one path,
	 * which is a superset of the proof a verifier walks.
	 */
	private function getSyncRecord(string $did, string $collection, string $rkey): XrpcBytes {
		$identity = $this->repoIdentity($did);
		$record = $this->storedRecord($identity, $collection, $rkey);
		$head = $this->repositories->getHead($identity->did);
		$commit = $this->repoRequest->getBlock($identity->did, (string)$head?->commitCid);
		if ($head === null || $commit === null) {
			throw new XrpcException(404, 'RepoNotFound', 'Repository has no commits yet');
		}
		$blocks = [$head->commitCid => $commit];
		$this->repoRequest->eachBlock($identity->did, static function (Cid $cid, string $kind, string $bytes) use (&$blocks): void {
			if ($kind === AtprotoRepoRequest::BLOCK_MST) {
				$blocks[$cid->toString()] = $bytes;
			}
		});
		$blocks[$record->cid->toString()] = $record->bytes;

		return new XrpcBytes(Car::encode([Cid::parse($head->commitCid)], $blocks), 'application/vnd.ipld.car');
	}

	/**
	 * Blocks by CID — tree nodes, commits, records — as a CAR with no
	 * roots: what a verifier fetches to walk a proof. One CID the
	 * repository does not have refuses the whole request, as the reference
	 * PDS does.
	 */
	private function getBlocks(string $did, array $cids): XrpcBytes {
		$identity = $this->repoIdentity($did);
		foreach ($cids as $cid) {
			if (!Cid::isValid($cid)) {
				throw XrpcException::invalidRequest('Not a CID: ' . $cid);
			}
		}
		$found = $this->repoRequest->getBlocks($identity->did, $cids);
		$missing = array_diff($cids, array_keys($found));
		if ($missing !== []) {
			throw new XrpcException(404, 'BlockNotFound', 'Could not find cids: ' . implode(', ', $missing));
		}

		return new XrpcBytes(Car::encode([], $found), 'application/vnd.ipld.car');
	}

	/**
	 * A blob, served from this instance's origin as bytes a browser neither
	 * runs nor sniffs: sandboxed, and a download unless it is a picture, a
	 * video or a sound.
	 */
	private function getBlob(string $did, string $cid): XrpcBytes {
		$identity = $this->repoIdentity($did);
		if (!Cid::isValid($cid)) {
			throw XrpcException::invalidRequest('Not a CID');
		}
		$blob = $this->blobRequest->get($identity->did, $cid);
		if ($blob === null) {
			throw new XrpcException(404, 'BlobNotFound', 'Blob not found');
		}
		$type = BlobRef::servableType($blob->mime);
		$headers = ['Content-Security-Policy' => "default-src 'none'; sandbox", 'X-Content-Type-Options' => 'nosniff'];
		if ($type === BlobRef::OPAQUE_TYPE) {
			$headers['Content-Disposition'] = 'attachment';
		}
		try {
			if (str_starts_with($blob->mime, 'video/')) {
				$opened = $this->videos->open($blob);

				return new XrpcBytes('', $type, 200, $opened['stream'], $opened['size'], $headers);
			}

			return new XrpcBytes($this->pictures->read($blob), $type, 200, null, -1, $headers);
		} catch (AtprotoException) {
			throw new XrpcException(404, 'BlobNotFound', 'Blob not found');
		}
	}

	private function listBlobs(string $did, int $limit, string $cursor): array {
		$identity = $this->repoIdentity($did);
		$blobs = $this->blobRequest->list($identity->did, $limit + 1, $cursor);
		$more = count($blobs) > $limit;
		$blobs = array_slice($blobs, 0, $limit);
		$answer = ['cids' => array_map(static fn ($blob): string => $blob->cid->toString(), $blobs)];
		if ($more && $blobs !== []) {
			$answer['cursor'] = end($blobs)->cid->toString();
		}

		return $answer;
	}

	private function listRepos(int $limit, string $cursor): array {
		$offset = ctype_digit($cursor) ? (int)$cursor : 0;
		$heads = $this->repoRequest->getHeads($limit + 1, $offset);
		$more = count($heads) > $limit;
		$heads = array_slice($heads, 0, $limit);
		$identities = $this->identityRequest->getByDids(array_map(static fn ($head): string => $head->did, $heads));
		$suspended = $this->moderation->suspendedAmong(array_map(static fn (Identity $identity): string => $identity->actorId, array_values($identities)));
		$repos = [];
		foreach ($heads as $head) {
			$identity = $identities[$head->did] ?? null;
			if ($identity === null) {
				continue;
			}
			$inactive = $this->inactiveStatus($identity, $suspended);
			$repo = ['did' => $head->did, 'head' => $head->commitCid, 'rev' => $head->rev, 'active' => $inactive === ''];
			if ($inactive !== '') {
				$repo['status'] = $inactive;
			}
			$repos[] = $repo;
		}
		$answer = ['repos' => $repos];
		if ($more) {
			$answer['cursor'] = (string)($offset + $limit);
		}

		return $answer;
	}

	private function describeRepo(string $repo): array {
		$identity = $this->repoIdentity($repo);
		$collections = [];
		foreach ($this->repoRequest->getLeaves($identity->did) as $path => $cid) {
			$collections[explode('/', $path, 2)[0]] = true;
		}
		ksort($collections);

		return [
			'handle' => $identity->handle,
			'did' => $identity->did,
			'didDoc' => $this->identities->document($identity),
			'collections' => array_keys($collections),
			'handleIsCorrect' => true,
		];
	}

	private function getRecord(string $repo, string $collection, string $rkey): array {
		$identity = $this->repoIdentity($repo);
		$record = $this->storedRecord($identity, $collection, $rkey);

		return ['uri' => $record->uri(), 'cid' => $record->cid->toString(), 'value' => DagCbor::toLexJson($record->value())];
	}

	private function listRecords(string $repo, string $collection, int $limit, string $cursor, bool $reverse): array {
		$identity = $this->repoIdentity($repo);
		if (!Syntax::isNsid($collection)) {
			throw XrpcException::invalidRequest('Not a collection');
		}
		$records = $this->repositories->listRecords($identity->did, $collection, $limit + 1, $cursor, $reverse);
		$more = count($records) > $limit;
		$records = array_slice($records, 0, $limit);
		$answer = ['records' => array_map(static fn ($record): array => [
			'uri' => $record->uri(), 'cid' => $record->cid->toString(), 'value' => DagCbor::toLexJson($record->value()),
		], $records)];
		if ($more && $records !== []) {
			$answer['cursor'] = end($records)->rkey;
		}

		return $answer;
	}

	/**
	 * The identity a `did` or `repo` parameter names: a DID or a handle.
	 *
	 * @throws XrpcException
	 */
	private function repoIdentity(string $identifier): Identity {
		$identity = null;
		try {
			if (Syntax::isDid($identifier)) {
				$identity = $this->identities->getByDid($identifier);
			} elseif (Syntax::isHandle($identifier)) {
				$identity = $this->identities->getByHandle(Syntax::normalizeHandle($identifier));
			}
		} catch (AtprotoIdentityNotFoundException) {
		}
		if ($identity === null) {
			throw new XrpcException(404, 'RepoNotFound', 'Could not find repo: ' . $identifier);
		}
		// kept while it is switched off (§4.6), moved away or taken down, and served to nobody
		match ($this->inactiveStatus($identity)) {
			'deleted' => throw new XrpcException(404, 'RepoNotFound', 'Could not find repo: ' . $identifier),
			'takendown' => throw new XrpcException(400, 'RepoTakendown', 'Repo has been takendown: ' . $identifier),
			'deactivated' => throw new XrpcException(400, 'RepoDeactivated', 'Repo has been deactivated: ' . $identifier),
			default => null,
		};

		return $identity;
	}

	/**
	 * Why a repository is not served, as `getRepoStatus` and `listRepos`
	 * name it: `deleted`, `takendown` while a moderator's suspension stands,
	 * `deactivated` when switched off or moved away; '' when it is served.
	 *
	 * @param array<string, true>|null $suspended the suspended accounts of a page, asked at once
	 */
	private function inactiveStatus(Identity $identity, ?array $suspended = null): string {
		if ($identity->state === Identity::STATE_TOMBSTONED) {
			return 'deleted';
		}
		if ($suspended !== null ? isset($suspended[$identity->actorId]) : $this->moderation->isSuspended($identity->actorId)) {
			return 'takendown';
		}

		return $identity->isActive() ? '' : 'deactivated';
	}

	/**
	 * @throws XrpcException
	 */
	private function storedRecord(Identity $identity, string $collection, string $rkey) {
		if (!Mst::isValidKey($collection . '/' . $rkey)) {
			throw XrpcException::invalidRequest('Not a record path');
		}
		$record = $this->repositories->getRecord($identity->did, $collection, $rkey);
		if ($record === null) {
			throw new XrpcException(404, 'RecordNotFound', 'Could not locate record');
		}

		return $record;
	}

	/**
	 * @param array<string, string|string[]> $params
	 * @throws XrpcException
	 */
	private static function string(array $params, string $name, bool $required = true): string {
		$value = $params[$name] ?? '';
		if (is_array($value)) {
			$value = (string)reset($value);
		}
		$value = trim((string)$value);
		if ($value === '' && $required) {
			throw XrpcException::invalidRequest('Params must have the property "' . $name . '"');
		}

		return $value;
	}

	/**
	 * A parameter given once or more, as the query strings `cids=a&cids=b`
	 * and `cids[]=a&cids[]=b` both give it.
	 *
	 * @param array<string, string|string[]> $params
	 * @return list<string>
	 * @throws XrpcException
	 */
	private static function strings(array $params, string $name): array {
		$values = $params[$name] ?? [];
		$values = array_values(array_filter(array_map(static fn ($value): string => trim((string)$value), is_array($values) ? $values : [$values]), static fn (string $value): bool => $value !== ''));
		if ($values === []) {
			throw XrpcException::invalidRequest('Params must have the property "' . $name . '"');
		}

		return array_values(array_unique($values));
	}

	/**
	 * @param array<string, string|string[]> $params
	 */
	private static function limit(array $params, int $default = self::MAX_LIST, int $max = self::MAX_LIST): int {
		$value = $params['limit'] ?? '';
		if (is_array($value)) {
			$value = (string)reset($value);
		}
		if ($value === '' || !ctype_digit((string)$value)) {
			return $default;
		}

		return max(1, min($max, (int)$value));
	}
}
