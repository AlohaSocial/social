<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\BlueskyVerifications;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Db\VerificationsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Details;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Accounts this instance verified: a company running the server vouching
 * for its own people, or a community for the accounts it knows.
 *
 * Moderators verify any account, local or not; the check is shown here on
 * the account wherever it appears (`exportOf()`, the account entity's
 * `verification`), naming the verifying account the administrator chose,
 * or the instance when none is chosen. With a verifying account and
 * Bluesky switched on, a verification of an account that has a DID is
 * also published, as a record in that account's repository
 * (`BlueskyVerifications`), and written again whenever the account's
 * handle or display name changes, since a record that names an old one no
 * longer counts. An account without a DID — most of the Fediverse — is
 * verified here only.
 *
 * @psalm-import-type VerificationRow from VerificationsRequest
 */
class VerificationService {
	/** @var array<string, int>|null when each verified account was verified, read once per process */
	private ?array $index = null;
	/** @var array{name: string, did: string}|null */
	private ?array $issuer = null;

	public function __construct(
		private VerificationsRequest $request,
		private ConfigService $configService,
		private CacheActorService $cacheActorService,
		private AtprotoConfig $atprotoConfig,
		private IAppConfig $appConfig,
		private IJobList $jobList,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * The local account the instance verifies in the name of, or null.
	 */
	public function verifier(): ?Person {
		$id = $this->configService->getAppValue(ConfigService::VERIFICATION_ACCOUNT);
		if ($id === '') {
			return null;
		}
		try {
			$actor = $this->cacheActorService->getFromId($id);
		} catch (Throwable $e) {
			$this->logger->info('The verifying account is not here', ['actor' => $id, 'exception' => $e]);

			return null;
		}

		return $actor->isLocal() ? $actor : null;
	}

	/**
	 * Makes a local account the one the instance verifies in the name of,
	 * or with '' none. It is given a Bluesky identity when it has none, as
	 * the records live in its repository, and every verification moves
	 * there, off the request.
	 *
	 * @param string $reference the account: its handle, numeric id or actor id
	 * @throws InvalidArgumentException with what to tell the administrator
	 */
	public function setVerifier(string $reference): ?Person {
		$before = $this->configService->getAppValue(ConfigService::VERIFICATION_ACCOUNT);
		$actor = null;
		if (trim($reference) !== '') {
			try {
				$actor = $this->cacheActorService->resolve($reference);
			} catch (Throwable) {
				throw new InvalidArgumentException('No account here goes by that name');
			}
			if (!$actor->isLocal() || $actor->getMovedTo() !== '') {
				throw new InvalidArgumentException('Only an account on this server can verify others');
			}
			$this->prepareIdentity($actor);
		}
		$id = $actor?->getId() ?? '';
		$this->configService->setAppValue(ConfigService::VERIFICATION_ACCOUNT, $id);
		$this->issuer = null;
		if ($id !== $before && $this->atprotoConfig->isEnabled()) {
			$this->jobList->add(AtprotoPublish::class, ['action' => 'verifications', 'id' => 'all']);
		}

		return $actor;
	}

	/**
	 * The verifying account as the administration page shows it, and
	 * whether verifications are published: with Bluesky on and a verifying
	 * account that has a DID.
	 *
	 * @return array{verifier: array{actor_id: string, account: string, name: string, did: string}|null, publishing: bool}
	 */
	public function state(): array {
		$verifier = $this->verifier();
		if ($verifier === null) {
			return ['verifier' => null, 'publishing' => false];
		}
		$did = $this->issuer()['did'];

		return [
			'verifier' => [
				'actor_id' => $verifier->getId(),
				'account' => $verifier->getPreferredUsername(),
				'name' => $verifier->getName(),
				'did' => $did,
			],
			'publishing' => $did !== '',
		];
	}

	/**
	 * Whether the instance verified the account.
	 */
	public function isVerified(string $actorId): bool {
		return isset($this->index()[$actorId]);
	}

	/**
	 * What an account entity says about the instance's verification of the
	 * account: who verified it — the verifying account's name, or the
	 * instance's — the DID that issued the published record, '' when none
	 * was, and when; null for an account the instance did not verify.
	 *
	 * @return array{by: string, issuer: string, created_at: string}|null
	 */
	public function exportOf(string $actorId): ?array {
		$index = $this->index();
		if (!isset($index[$actorId])) {
			return null;
		}
		$issuer = $this->issuer();

		return [
			'by' => $issuer['name'],
			'issuer' => $issuer['did'],
			'created_at' => gmdate('Y-m-d\TH:i:s', $index[$actorId]) . '.000Z',
		];
	}

	/**
	 * Verifies an account, or leaves an existing verification as it is.
	 *
	 * @param string $verifiedBy the moderator's user id
	 * @return VerificationRow
	 */
	public function verify(Person $subject, string $verifiedBy): array {
		$existing = $this->request->get($subject->getId());
		if ($existing !== null) {
			return $existing;
		}
		$facts = $this->subjectFacts($subject);
		$this->request->add($subject->getId(), $facts['did'], $facts['handle'], $facts['displayName'], $verifiedBy, $this->time->getTime());
		$this->index = null;
		$this->publish($facts);
		$this->logger->info('Account verified', ['actor' => $subject->getId(), 'by' => $verifiedBy]);

		return $this->request->get($subject->getId()) ?? [
			'actorId' => $subject->getId(),
			'verifiedBy' => $verifiedBy,
			'creation' => $this->time->getTime(),
		] + $facts;
	}

	/**
	 * Takes a verification back, and its record wherever it was published.
	 *
	 * @return bool whether the account was verified
	 */
	public function unverify(string $actorId): bool {
		$row = $this->request->get($actorId);
		if ($row === null) {
			return false;
		}
		$this->request->remove($actorId);
		$this->index = null;
		$this->bluesky()?->withdraw($row['did']);
		$this->logger->info('Verification taken back', ['actor' => $actorId]);

		return true;
	}

	/**
	 * The verified accounts, newest first, for the administration page.
	 *
	 * @return list<array{actor_id: string, account: string, name: string, did: string, local: bool, verified_by: string, created_at: string}>
	 */
	public function list(int $limit = 1000): array {
		$rows = $this->request->getAll($limit);
		$actors = $this->cacheActorService->getCachedFromIds(array_column($rows, 'actorId'));
		$list = [];
		foreach ($rows as $row) {
			$actor = $actors[$row['actorId']] ?? null;
			$list[] = [
				'actor_id' => $row['actorId'],
				'account' => $actor === null ? $row['handle'] : ($actor->isLocal() ? $actor->getPreferredUsername() : $actor->getAccount()),
				'name' => $actor?->getName() ?? $row['displayName'],
				'did' => $row['did'],
				'local' => $actor?->isLocal() ?? false,
				'verified_by' => $row['verifiedBy'],
				'created_at' => gmdate('Y-m-d\TH:i:s', $row['creation']) . '.000Z',
			];
		}

		return $list;
	}

	/**
	 * A verified account whose handle or display name may have changed —
	 * a local one whose profile is being published, a Bluesky one just
	 * read again: its verification is published again when what the record
	 * names is no longer true. Anything else is left alone.
	 */
	public function refresh(Person $actor): void {
		$row = $this->request->get($actor->getId());
		if ($row === null) {
			return;
		}
		$facts = $this->subjectFacts($actor);
		if ($facts['did'] === $row['did'] && $facts['handle'] === $row['handle'] && $facts['displayName'] === $row['displayName']) {
			return;
		}
		$this->request->updateSubject($actor->getId(), $facts['did'], $facts['handle'], $facts['displayName']);
		if ($row['did'] !== '' && $row['did'] !== $facts['did']) {
			$this->bluesky()?->withdraw($row['did']);
		}
		$this->publish($facts);
	}

	/**
	 * Every verification published again in the current verifying
	 * account's repository, and taken back where no account verifies any
	 * more: after the administrator chose another one.
	 *
	 * @return int how many records were written
	 */
	public function republishAll(): int {
		$bluesky = $this->bluesky();
		if ($bluesky === null) {
			return 0;
		}
		$verifier = $this->verifier();
		$written = 0;
		foreach ($this->request->getAll(100000) as $row) {
			if ($row['did'] === '') {
				continue;
			}
			if ($verifier === null || !$this->atprotoConfig->isEnabled()) {
				$bluesky->withdraw($row['did']);
				continue;
			}
			$written += $bluesky->publish($verifier, $row['did'], $row['handle'], $row['displayName']) ? 1 : 0;
		}

		return $written;
	}

	/**
	 * What a record about the account names: its DID, handle and display
	 * name. A local account's are its Bluesky identity's and its profile
	 * record's, a Bluesky account's those the AppView gave; an account
	 * with neither has no DID and is verified here only.
	 *
	 * @return array{did: string, handle: string, displayName: string}
	 */
	private function subjectFacts(Person $subject): array {
		if ($subject->isLocal()) {
			$identity = null;
			try {
				$identity = $this->identities()?->forActor($subject);
			} catch (Throwable $e) {
				$this->logger->info('No Bluesky identity for a verified account', ['actor' => $subject->getId(), 'exception' => $e]);
			}

			return [
				'did' => $identity?->did ?? '',
				'handle' => $identity?->handle ?? $subject->getPreferredUsername(),
				'displayName' => RecordMapper::displayNameOf($subject),
			];
		}
		if (BlueskyIds::isActorId($subject->getId())) {
			$handle = (string)($subject->getDetails(Details::ATPROTO)['handle'] ?? '');

			return [
				'did' => BlueskyIds::didOf($subject->getId()),
				'handle' => $handle !== '' ? $handle : $subject->getPreferredUsername(),
				'displayName' => $subject->getName(),
			];
		}

		return ['did' => '', 'handle' => $subject->getAccount(), 'displayName' => $subject->getName()];
	}

	/**
	 * @param array{did: string, handle: string, displayName: string} $facts
	 */
	private function publish(array $facts): void {
		if ($facts['did'] === '' || !$this->atprotoConfig->isEnabled()) {
			return;
		}
		$verifier = $this->verifier();
		if ($verifier !== null) {
			$this->bluesky()?->publish($verifier, $facts['did'], $facts['handle'], $facts['displayName']);
		}
	}

	/**
	 * The verifying account's Bluesky identity, made now if it has none,
	 * and its profile, so the network knows the name the checks are shown
	 * under.
	 */
	private function prepareIdentity(Person $verifier): void {
		if (!$this->atprotoConfig->isEnabled()) {
			return;
		}
		try {
			$this->identities()?->forActor($verifier, true);
			$publisher = $this->container?->get(Publisher::class);
			if ($publisher instanceof Publisher) {
				$publisher->publishProfile($verifier);
			}
		} catch (Throwable $e) {
			$this->logger->warning('The verifying account has no Bluesky identity yet', ['actor' => $verifier->getId(), 'exception' => $e]);
		}
	}

	/** @return array<string, int> */
	private function index(): array {
		return $this->index ??= $this->request->getIndex();
	}

	/**
	 * The name the checks are shown under — the verifying account's, else
	 * the instance's — and the DID its records are issued by.
	 *
	 * @return array{name: string, did: string}
	 */
	private function issuer(): array {
		if ($this->issuer !== null) {
			return $this->issuer;
		}
		$verifier = $this->verifier();
		$did = '';
		if ($verifier !== null && $this->atprotoConfig->isEnabled()) {
			try {
				$did = $this->identities()?->forActor($verifier, false)?->did ?? '';
			} catch (Throwable) {
			}
		}
		$name = $verifier === null ? '' : trim($verifier->getName());

		return $this->issuer = [
			'name' => $name !== '' ? $name : $this->appConfig->getValueString('theming', 'name', 'Aloha Social'),
			'did' => $did,
		];
	}

	/**
	 * Resolved when first needed rather than injected: the Bluesky side
	 * needs services that need this one. Null without a container, as in a
	 * unit test.
	 */
	private function bluesky(): ?BlueskyVerifications {
		$service = $this->container?->get(BlueskyVerifications::class);

		return $service instanceof BlueskyVerifications ? $service : null;
	}

	private function identities(): ?IdentityService {
		$service = $this->container?->get(IdentityService::class);

		return $service instanceof IdentityService ? $service : null;
	}
}
