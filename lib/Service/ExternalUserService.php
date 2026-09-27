<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Cron\ExternalPromoted;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\ExternalSignupsRequest;
use OCA\Social\Db\ExternalUsersRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\External\ExternalGroupBackend;
use OCA\Social\External\ExternalUserBackend;
use OCP\Accounts\IAccountManager;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserBackend;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;
use OCP\User\Backend\ICreateUserBackend;
use OCP\User\Backend\IPasswordHashBackend;
use OCP\User\Backend\ISetDisplayNameBackend;
use OCP\User\Events\BeforeUserCreatedEvent;
use OCP\User\Events\UserCreatedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Self-registered external users: who they are, how many there may be, what
 * the administrator decided about them, and the two things that change an
 * account's kind — creating one and promoting one to a local user.
 *
 * The signup flow that leads here is `ExternalSignupService`.
 */
class ExternalUserService {
	public const MODE_OPEN = 'open';
	public const MODE_INVITE = 'invite';
	public const MODE_APPROVAL = 'approval';
	public const MODES = [self::MODE_OPEN, self::MODE_INVITE, self::MODE_APPROVAL];

	public const MAX_ACCOUNTS = 1000000;
	/** Megabytes, 10 TB: past this a quota is a typo rather than a decision. */
	public const MAX_QUOTA_MB = 10485760;
	public const MAX_MIN_AGE = 99;
	public const HANDLE_MAX_LENGTH = 64;

	/**
	 * Lower case letters, digits and underscore, with dot and dash inside:
	 * the intersection of what a Nextcloud user id and a fediverse handle
	 * accept, in the one case a handle is compared in.
	 */
	private const HANDLE_PATTERN = '/^[a-z0-9_]+([a-z0-9_.-]*[a-z0-9_])?$/';

	/** Handles nobody registers, whatever the administrator adds to them. */
	public const RESERVED_HANDLES = [
		'abuse', 'admin', 'administrator', 'api', 'apps', 'hostmaster', 'info',
		'login', 'logout', 'moderator', 'nextcloud', 'noreply', 'no-reply',
		'postmaster', 'root', 'security', 'settings', 'signup', 'social',
		'staff', 'support', 'system', 'webmaster',
	];

	private const CAP_LOCK = 'social/external-users/cap';
	private const DEFAULT_APP = 'social';

	public function __construct(
		private ConfigService $configService,
		private ExternalUsersRequest $usersRequest,
		private ExternalSignupsRequest $signupsRequest,
		private ExternalUserBackend $userBackend,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private IUserManager $userManager,
		private IAccountManager $accountManager,
		private IConfig $config,
		private IAppManager $appManager,
		private IDBConnection $connection,
		private IEventDispatcher $eventDispatcher,
		private ILockingProvider $lockingProvider,
		private ISecureRandom $secureRandom,
		private IJobList $jobList,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
		private AccountService $accountService,
		private AccessBlockService $accessBlockService,
		private LoggerInterface $logger,
	) {
	}

	public function isEnabled(): bool {
		return $this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_ENABLED) === '1';
	}

	public function maxAccounts(): int {
		return max(0, $this->configService->getAppValueInt(ConfigService::SOCIAL_EXTERNAL_MAX));
	}

	/** Megabytes of media one external user may upload; 0 is no quota. */
	public function mediaQuota(): int {
		return max(0, $this->configService->getAppValueInt(ConfigService::SOCIAL_EXTERNAL_QUOTA));
	}

	public function mode(): string {
		$mode = $this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_MODE);

		return in_array($mode, self::MODES, true) ? $mode : self::MODE_APPROVAL;
	}

	public function verifiesEmail(): bool {
		return $this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_VERIFY) !== '0';
	}

	public function minimumAge(): int {
		return min(self::MAX_MIN_AGE, max(0, $this->configService->getAppValueInt(ConfigService::SOCIAL_EXTERNAL_MIN_AGE)));
	}

	public function usersMayInvite(): bool {
		return $this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_USER_INVITES) === '1';
	}

	/** @return list<string> the handles the administrator reserved */
	public function reservedHandles(): array {
		$list = json_decode($this->configService->getAppValue(ConfigService::SOCIAL_EXTERNAL_RESERVED), true);
		if (!is_array($list)) {
			return [];
		}

		return array_values(array_unique(array_filter(array_map(
			static fn ($handle): string => is_string($handle) ? mb_strtolower(trim($handle)) : '',
			$list
		), static fn (string $handle): bool => $handle !== '')));
	}

	public function count(): int {
		return $this->usersRequest->count();
	}

	/** Whether a new external account may be created at all right now. */
	public function hasRoom(): bool {
		return $this->isEnabled() && $this->count() < $this->maxAccounts();
	}

	public function isExternal(?IUser $user): bool {
		return ExternalUserBackend::isExternal($user);
	}

	public function isExternalUid(string $uid): bool {
		return $uid !== '' && $this->userBackend->userExists($uid);
	}

	/**
	 * Everything the administration page shows about external users.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$restriction = $this->appManager->getAppRestriction(Application::APP_ID);

		return [
			'enabled' => $this->isEnabled(),
			'max' => $this->maxAccounts(),
			'quota' => $this->mediaQuota(),
			'mode' => $this->mode(),
			'verifyEmail' => $this->verifiesEmail(),
			'minAge' => $this->minimumAge(),
			'reserved' => $this->reservedHandles(),
			'userInvites' => $this->usersMayInvite(),
			'count' => $this->count(),
			'awaitingApproval' => $this->signupsRequest->countAwaitingApproval(),
			// Social restricted to groups that leave the externals out: they
			// could sign up and then not open the one app they came for
			'restricted' => $restriction !== [],
			'restrictionIncludesExternals' => $restriction === [] || in_array(ExternalGroupBackend::GROUP_ID, $restriction, true),
		];
	}

	/**
	 * Writes the administrator's settings, all or none.
	 *
	 * @param list<string> $reserved
	 * @return array<string, mixed> the settings as they now stand
	 * @throws InvalidArgumentException a value out of range; nothing is written
	 */
	public function saveSettings(
		bool $enabled,
		int $max,
		int $quota,
		string $mode,
		bool $verifyEmail,
		int $minAge,
		array $reserved,
		bool $userInvites,
	): array {
		if ($max < 0 || $max > self::MAX_ACCOUNTS) {
			throw new InvalidArgumentException('max must be between 0 and ' . self::MAX_ACCOUNTS);
		}
		if ($quota < 0 || $quota > self::MAX_QUOTA_MB) {
			throw new InvalidArgumentException('quota must be 0, or a size in MB up to ' . self::MAX_QUOTA_MB);
		}
		if (!in_array($mode, self::MODES, true)) {
			throw new InvalidArgumentException('mode must be one of ' . implode(', ', self::MODES));
		}
		if ($minAge < 0 || $minAge > self::MAX_MIN_AGE) {
			throw new InvalidArgumentException('minAge must be between 0 and ' . self::MAX_MIN_AGE);
		}

		$handles = [];
		foreach ($reserved as $handle) {
			$handle = mb_strtolower(trim(ltrim((string)$handle, '@')));
			if ($handle !== '') {
				$handles[] = $handle;
			}
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_ENABLED, $enabled ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_MAX, (string)$max);
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_QUOTA, (string)$quota);
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_MODE, $mode);
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_VERIFY, $verifyEmail ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_MIN_AGE, (string)$minAge);
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_RESERVED, (string)json_encode(array_values(array_unique($handles))));
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_USER_INVITES, $userInvites ? '1' : '0');

		if ($enabled) {
			$this->includeExternalsInRestriction();
		}

		return $this->settings();
	}

	/**
	 * Adds the externals' group to the groups Social is restricted to, when
	 * it is restricted at all. An app open to everybody is left alone.
	 */
	public function includeExternalsInRestriction(): void {
		$restriction = $this->appManager->getAppRestriction(Application::APP_ID);
		if ($restriction === [] || in_array(ExternalGroupBackend::GROUP_ID, $restriction, true)) {
			return;
		}

		$restriction[] = ExternalGroupBackend::GROUP_ID;
		$this->appManager->enableAppForGroups(Application::APP_ID, $restriction);
	}

	/**
	 * A handle as it would be registered, or why it cannot be.
	 *
	 * @param int $signupId a pending registration that may hold the handle already
	 * @throws ExternalUserException
	 */
	public function assertHandleAvailable(string $handle, int $signupId = 0): string {
		$handle = mb_strtolower(trim(ltrim($handle, '@')));

		if ($handle === '' || strlen($handle) > self::HANDLE_MAX_LENGTH) {
			throw new ExternalUserException(
				$this->l10n->t('A username must be between 1 and %d characters long.', [self::HANDLE_MAX_LENGTH]),
				'handle'
			);
		}
		if (preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
			throw new ExternalUserException(
				$this->l10n->t('A username may only contain lowercase letters, digits and underscores, with dots and dashes inside.'),
				'handle'
			);
		}

		try {
			$this->userManager->validateUserId($handle);
		} catch (InvalidArgumentException $e) {
			throw new ExternalUserException($this->l10n->t('This username cannot be used.'), 'handle');
		}

		$taken = $this->l10n->t('This username is already taken.');
		if (in_array($handle, self::RESERVED_HANDLES, true) || in_array($handle, $this->reservedHandles(), true)) {
			throw new ExternalUserException($taken, 'handle');
		}
		if ($this->userManager->userExists($handle)) {
			throw new ExternalUserException($taken, 'handle');
		}

		try {
			// deleted ones included: a handle in retention is still somebody's
			$this->accountService->getActor($handle);
			throw new ExternalUserException($taken, 'handle');
		} catch (ActorDoesNotExistException $e) {
		}

		$pending = $this->signupsRequest->getByHandle($handle);
		if ($pending !== null && $pending['id'] !== $signupId) {
			throw new ExternalUserException($taken, 'handle');
		}

		return $handle;
	}

	/**
	 * An email address as it would be registered, or why it cannot be.
	 *
	 * @throws ExternalUserException
	 */
	public function assertEmailAvailable(string $email, int $signupId = 0): string {
		$email = mb_strtolower(trim($email));
		if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new ExternalUserException($this->l10n->t('Please enter a valid email address.'), 'email');
		}
		if ($this->accessBlockService->isBlockedEmail($email)) {
			throw new ExternalUserException($this->l10n->t('This server does not accept registrations from that email domain.'), 'email');
		}

		$used = $this->l10n->t('This email address is already in use.');
		if ($this->userManager->getByEmail($email) !== []) {
			throw new ExternalUserException($used, 'email');
		}
		$pending = $this->signupsRequest->getByEmail($email);
		if ($pending !== null && $pending['id'] !== $signupId) {
			throw new ExternalUserException($used, 'email');
		}

		return $email;
	}

	/**
	 * Creates an external account: the Nextcloud user and its Social account.
	 *
	 * Serialised on a lock so two approvals racing for the last free place
	 * cannot both have it, and every check is repeated inside it: the person
	 * may have registered while the instance still had room.
	 *
	 * @param string $origin `open`, `invite:<id>`, `approval` or `occ`, kept for the record
	 * @throws ExternalUserException
	 */
	public function createAccount(string $handle, string $email, string $passwordHash, string $origin, int $signupId = 0): IUser {
		$this->lock();
		try {
			if (!$this->hasRoom()) {
				throw new ExternalUserException($this->l10n->t('This server is not accepting new accounts at the moment.'));
			}
			$handle = $this->assertHandleAvailable($handle, $signupId);
			$email = $this->assertEmailAvailable($email, $signupId);

			$this->eventDispatcher->dispatchTyped(new BeforeUserCreatedEvent($handle, ''));
			$this->usersRequest->create($handle, $passwordHash, $handle, $origin, $this->timeFactory->getTime());
			$this->userBackend->forget($handle);
		} finally {
			$this->unlock();
		}

		$user = $this->userManager->get($handle);
		if ($user === null) {
			throw new ExternalUserException($this->l10n->t('The account could not be created.'));
		}

		try {
			$this->prepare($user, $email);
			$this->eventDispatcher->dispatchTyped(new UserCreatedEvent($user, ''));
			$this->accountService->createActor($user->getUID(), $handle);
		} catch (Throwable $e) {
			$this->logger->warning('[ExternalUserService] could not finish an external account, removing it', [
				'handle' => $handle,
				'exception' => $e,
			]);
			$user->delete();

			throw new ExternalUserException($this->l10n->t('The account could not be created.'));
		}

		$this->logger->info('created an external Social account', ['handle' => $handle, 'origin' => $origin]);

		return $user;
	}

	/**
	 * What every external account starts with: the email, and nothing about
	 * them published beyond this server — no federated address book entry,
	 * no lookup server, no core profile page. Social is their start page.
	 */
	private function prepare(IUser $user, string $email): void {
		$user->setSystemEMailAddress($email);

		$account = $this->accountManager->getAccount($user);
		foreach ($account->getAllProperties() as $property) {
			$name = $property->getName();
			$property->setScope(
				in_array($name, [IAccountManager::PROPERTY_DISPLAYNAME, IAccountManager::PROPERTY_EMAIL, IAccountManager::PROPERTY_AVATAR], true)
					? IAccountManager::SCOPE_LOCAL
					: IAccountManager::SCOPE_PRIVATE
			);
		}
		$account->setProperty(IAccountManager::PROPERTY_PROFILE_ENABLED, '0', IAccountManager::SCOPE_PRIVATE, IAccountManager::NOT_VERIFIED);
		$this->accountManager->updateAccount($account);

		$this->config->setUserValue($user->getUID(), 'core', 'defaultapp', self::DEFAULT_APP);
	}

	/**
	 * Turns an external user into an ordinary local one.
	 *
	 * The row moves to Nextcloud's database backend with the same user id and
	 * the same password hash, so the person logs in as before, keeps their
	 * two-factor setup and their Social account, and from now on is subject
	 * to the instance's normal app and group rules.
	 *
	 * @throws ExternalUserException
	 */
	public function promote(string $uid): void {
		$user = $this->userManager->get($uid);
		if (!$this->isExternal($user)) {
			throw new ExternalUserException($this->l10n->t('This is not an external user.'));
		}
		/** @var IUser $user */
		$uid = $user->getUID();

		$database = $this->databaseBackend();
		$hash = $this->userBackend->getPasswordHash($uid);
		$displayName = $this->userBackend->getDisplayName($uid);

		$this->connection->beginTransaction();
		try {
			if (!$database->createUser($uid, $this->throwawayPassword())) {
				throw new ExternalUserException($this->l10n->t('The account could not be promoted.'));
			}
			if ($hash !== null) {
				$database->setPasswordHash($uid, $hash);
			}
			if ($database instanceof ISetDisplayNameBackend) {
				$database->setDisplayName($uid, $displayName);
			}
			$this->usersRequest->delete($uid);
			$this->connection->commit();
		} catch (Throwable $e) {
			$this->connection->rollBack();
			if ($e instanceof ExternalUserException) {
				throw $e;
			}
			$this->logger->error('[ExternalUserService] promotion failed', ['uid' => $uid, 'exception' => $e]);

			throw new ExternalUserException($this->l10n->t('The account could not be promoted.'));
		}

		$this->userBackend->forget($uid);
		if ($this->config->getUserValue($uid, 'core', 'defaultapp') === self::DEFAULT_APP) {
			$this->config->deleteUserValue($uid, 'core', 'defaultapp');
		}
		// the system address book skipped this account while it was external;
		// written in a later request, which sees the user on its new backend
		$this->jobList->add(ExternalPromoted::class, ['uid' => $uid]);

		$this->logger->info('promoted an external Social account to a local user', ['uid' => $uid]);
	}

	/**
	 * The external accounts for the administration page, with what each has
	 * uploaded.
	 *
	 * @return list<array{uid: string, displayName: string, email: string, created: int, lastLogin: int, enabled: bool, mediaBytes: int, origin: string}>
	 */
	public function list(string $search = '', int $limit = 50, int $offset = 0): array {
		$rows = $this->usersRequest->search($search, max(1, min($limit, 200)), max(0, $offset));
		$bytes = $this->cacheDocumentsRequest->localBytesByAccount(array_map(
			static fn (array $row): string => $row['uid'],
			$rows
		));

		$list = [];
		foreach ($rows as $row) {
			$user = $this->userManager->get($row['uid']);
			$list[] = [
				'uid' => $row['uid'],
				'displayName' => ($user === null) ? $row['uid'] : $user->getDisplayName(),
				'email' => ($user === null) ? '' : (string)$user->getEMailAddress(),
				'created' => $row['creation'],
				'lastLogin' => ($user === null) ? 0 : $user->getLastLogin(),
				'enabled' => $user !== null && $user->isEnabled(),
				'mediaBytes' => $bytes[$row['uid']] ?? 0,
				'origin' => $row['origin'],
			];
		}

		return $list;
	}

	/**
	 * @throws ExternalUserException
	 */
	private function databaseBackend(): ICreateUserBackend&IPasswordHashBackend {
		foreach ($this->userManager->getBackends() as $backend) {
			if ($backend instanceof IUserBackend
				&& $backend->getBackendName() === 'Database'
				&& $backend instanceof ICreateUserBackend
				&& $backend instanceof IPasswordHashBackend) {
				return $backend;
			}
		}

		throw new ExternalUserException($this->l10n->t('This server has no local user database to promote the account to.'));
	}

	/**
	 * A password nobody will use, overwritten by the real hash straight away,
	 * and long and varied enough to pass any password policy on the way.
	 */
	private function throwawayPassword(): string {
		return 'Aa1!' . $this->secureRandom->generate(60, ISecureRandom::CHAR_ALPHANUMERIC . ISecureRandom::CHAR_SYMBOLS);
	}

	/**
	 * @throws ExternalUserException
	 */
	private function lock(): void {
		for ($attempt = 0; $attempt < 50; $attempt++) {
			try {
				$this->lockingProvider->acquireLock(self::CAP_LOCK, ILockingProvider::LOCK_EXCLUSIVE);

				return;
			} catch (LockedException $e) {
				usleep(100000);
			}
		}

		throw new ExternalUserException($this->l10n->t('The server is busy, please try again.'));
	}

	private function unlock(): void {
		try {
			$this->lockingProvider->releaseLock(self::CAP_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (Throwable $e) {
			$this->logger->warning('[ExternalUserService] could not release the cap lock', ['exception' => $e]);
		}
	}
}
