<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ExternalInvitesRequest;
use OCA\Social\Db\ExternalSignupsRequest;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\External\SignupReturn;
use OCA\Social\Security\ClientAddress;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * How somebody without an account becomes an external user.
 *
 * A registration is admitted by the administrator's mode: `open` admits
 * anybody, `invite` only somebody with an invitation link, `approval` puts it
 * in a queue on the administration page. An invitation admits its holder in
 * every mode, approval included. Email confirmation, when it is switched on,
 * comes first in every mode. The account itself is made by
 * `ExternalUserService::createAccount()`, which checks everything again.
 *
 * @psalm-import-type ExternalSignupRow from ExternalSignupsRequest
 */
class ExternalSignupService {
	/** How long a confirmation link, and the code sent with it, works. */
	public const VERIFY_TTL = 86400;

	/** Wrong confirmation codes one registration takes before only its link confirms it. */
	public const CODE_ATTEMPTS = 5;

	/** Registrations one address may make in an hour. */
	public const PER_IP_PER_HOUR = 5;

	/**
	 * Confirmed registrations waiting for an administrator, before the form
	 * stops taking more that would have to wait too.
	 */
	public const MAX_PENDING = 1000;

	/**
	 * Unconfirmed registrations kept at most. Past it the oldest are
	 * forgotten rather than the form closing, so nobody can keep it shut by
	 * never confirming.
	 */
	public const MAX_UNVERIFIED = 5000;

	public const MIN_PASSWORD_LENGTH = 8;

	/** Invitations a user who is not an administrator may have open at once. */
	public const USER_INVITES_OPEN = 10;
	public const USER_INVITE_DAYS = 7;

	public const STATE_VERIFY = 'verify';
	public const STATE_APPROVAL = 'approval';
	public const STATE_CREATED = 'created';

	public function __construct(
		private ExternalUserService $externalUserService,
		private ExternalTwoFactorService $twoFactorService,
		private ExternalSignupsRequest $signupsRequest,
		private ExternalInvitesRequest $invitesRequest,
		private InstanceService $instanceService,
		private AtprotoConfig $atprotoConfig,
		private IHasher $hasher,
		private ISecureRandom $secureRandom,
		private IEventDispatcher $eventDispatcher,
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ConfigService $configService,
		private SignupMailLimiter $mailLimiter,
		private SignupChallengeService $challengeService,
		private SignupReturn $signupReturn,
	) {
	}

	/**
	 * What the registration page needs before anybody types anything.
	 *
	 * @return array<string, mixed>
	 */
	public function pageState(string $inviteToken = '', string $returnTo = ''): array {
		$invite = $this->validInvite($inviteToken);
		$mode = $this->externalUserService->mode();
		$signupNotice = $this->signupNoticeText();
		$open = $this->externalUserService->hasRoom()
			&& ($mode !== ExternalUserService::MODE_INVITE || $invite !== null);

		return [
			'open' => $open,
			'reason' => $open ? '' : $this->closedReason($mode, $inviteToken !== ''),
			'mode' => $mode,
			'invited' => $invite !== null,
			'inviteToken' => ($invite === null) ? '' : $inviteToken,
			'approval' => $mode === ExternalUserService::MODE_APPROVAL && $invite === null,
			'verifyEmail' => $this->externalUserService->verifiesEmail(),
			'twoFactorRequired' => $this->twoFactorService->state()['enforced'],
			'minAge' => $this->externalUserService->minimumAge(),
			'rules' => array_map(static fn (array $rule): string => $rule['text'], $this->instanceService->rules()),
			'privacyUrl' => trim($this->appConfig->getValueString('theming', 'privacyUrl', '')),
			'legalUrl' => trim($this->appConfig->getValueString('theming', 'imprintUrl', '')),
			'signupNotice' => $signupNotice,
			'signupNoticeRequired' => $this->externalUserService->requiresSignupNoticeAcceptance(),
			'signupNoticeVersion' => hash('sha256', $signupNotice),
			'proofOfWork' => $invite === null && $this->externalUserService->requiresProofOfWork(),
			'domain' => $this->addressHost(),
			'bluesky' => $this->bluesky(),
			'loginUrl' => $this->signupReturn->loginUrl($returnTo),
			// the authorization request of the app this registration started
			// from, as the login form takes it; '' when it did not
			'returnUrl' => $this->signupReturn->normalize($returnTo),
		];
	}

	/** Whether somebody without an invitation may start a Social-only signup. */
	public function acceptsPublicRegistrations(): bool {
		return $this->externalUserService->acceptsPublicRegistrations();
	}

	public function requiresRegistrationApproval(): bool {
		return $this->externalUserService->mode() === ExternalUserService::MODE_APPROVAL;
	}

	public function invitationsEnabled(): bool {
		return $this->externalUserService->usersMayInvite();
	}

	/**
	 * Whether an account made here is on Bluesky too, and the host its
	 * handle is issued under (`HandleMapper`), for the page to preview it.
	 *
	 * @return array{enabled: bool, host: string}
	 */
	private function bluesky(): array {
		if (!$this->atprotoConfig->isEnabled()) {
			return ['enabled' => false, 'host' => ''];
		}
		try {
			return ['enabled' => true, 'host' => $this->atprotoConfig->handleHost()];
		} catch (SocialAppConfigException) {
			return ['enabled' => false, 'host' => ''];
		}
	}

	private function closedReason(string $mode, bool $triedInvite): string {
		if (!$this->externalUserService->isEnabled()) {
			return $this->l10n->t('This server does not accept registrations.');
		}
		if (!$this->externalUserService->hasRoom()) {
			return $this->l10n->t('This server has reached the maximum number of accounts and is not accepting new ones at the moment.');
		}
		if ($mode === ExternalUserService::MODE_INVITE) {
			return $triedInvite
				? $this->l10n->t('This invitation link is no longer valid.')
				: $this->l10n->t('This server accepts registrations by invitation only.');
		}

		return $this->l10n->t('This server does not accept registrations.');
	}

	private function signupNoticeText(): string {
		$notice = $this->externalUserService->signupNotice();

		return $notice !== '' ? $notice : $this->l10n->t('This account is limited to Aloha Social on this server. Review the server rules and linked privacy information before registering.');
	}

	/**
	 * A registration, as submitted by the form.
	 *
	 * A filled-in honeypot is answered as a success and stores nothing.
	 * Without a valid invitation, and unless switched off, the registration
	 * answers a `SignupChallengeService` challenge, used up either way.
	 *
	 * The form does not reveal whether an email address or username is taken:
	 * both are answered "check your email", and the mail explains. With email
	 * confirmation off the two can be told apart. `SignupMailLimiter` limits
	 * mails per address; a held-back mail is answered as sent, and a held-back
	 * confirmation stores nothing.
	 *
	 * A registration awaiting confirmation also gets a code, sent with the
	 * link; `pending` is its id, for the caller to bind the page to and never
	 * to hand out.
	 *
	 * @param string $returnTo where an app that started the registration wants the person back, see `SignupReturn`
	 * @return array{state: string, handle: string, pending?: int, loginUrl?: string}
	 * @throws ExternalUserException
	 */
	public function submit(
		string $handle,
		string $email,
		string $password,
		bool $acceptsRules,
		bool $confirmsAge,
		string $inviteToken,
		string $honeypot,
		string $remoteAddress,
		bool $acceptsSignupNotice = false,
		string $signupNoticeVersion = '',
		string $challenge = '',
		string $nonce = '',
		string $returnTo = '',
	): array {
		$returnTo = $this->signupReturn->normalize($returnTo);
		if ($honeypot !== '') {
			$this->logger->info('[ExternalSignupService] dropped a registration that filled in the honeypot');

			return ['state' => self::STATE_VERIFY, 'handle' => ''];
		}

		$invite = $this->validInvite($inviteToken);
		$mode = $this->externalUserService->mode();
		if (!$this->externalUserService->hasRoom() || ($mode === ExternalUserService::MODE_INVITE && $invite === null)) {
			throw new ExternalUserException($this->closedReason($mode, $inviteToken !== ''));
		}
		if ($invite === null && $this->externalUserService->requiresProofOfWork()
			&& !$this->challengeService->verify($challenge, $nonce)) {
			throw new ExternalUserException($this->l10n->t('The check of this browser did not succeed. Please try again.'), 'challenge');
		}

		$ipHash = $this->ipHash($remoteAddress);
		$now = $this->timeFactory->getTime();
		$needsApproval = $mode === ExternalUserService::MODE_APPROVAL && $invite === null;
		if ($this->signupsRequest->countFromIpSince($ipHash, $now - 3600) >= self::PER_IP_PER_HOUR
			|| ($needsApproval && $this->signupsRequest->countAwaitingApproval() >= self::MAX_PENDING)) {
			throw new ExternalUserException($this->l10n->t('Too many registrations right now, please try again later.'));
		}
		$unverified = $this->signupsRequest->countUnverified();
		if ($unverified >= self::MAX_UNVERIFIED) {
			$this->signupsRequest->purgeOldestUnverified($unverified - self::MAX_UNVERIFIED + 1);
		}

		$handle = $this->externalUserService->assertHandleOpen($handle);
		$email = $this->externalUserService->assertEmailAccepted($email);
		$this->assertPassword($password);
		if (!$acceptsRules) {
			throw new ExternalUserException($this->l10n->t('Please accept the server rules.'), 'rules');
		}
		if ($this->externalUserService->minimumAge() > 0 && !$confirmsAge) {
			throw new ExternalUserException(
				$this->l10n->t('Please confirm that you are at least %d years old.', [$this->externalUserService->minimumAge()]),
				'age'
			);
		}
		$noticeRequired = $this->externalUserService->requiresSignupNoticeAcceptance();
		$noticeText = $this->signupNoticeText();
		$noticeVersion = hash('sha256', $noticeText);
		$noticeAcceptedAt = 0;
		if ($noticeRequired) {
			if (!$acceptsSignupNotice) {
				throw new ExternalUserException($this->l10n->t('Please accept the registration notice.'), 'notice');
			}
			if (!hash_equals($noticeVersion, $signupNoticeVersion)) {
				throw new ExternalUserException($this->l10n->t('The registration notice changed. Reload this page and review it before continuing.'), 'notice');
			}
			$noticeAcceptedAt = $now;
		}

		// hashed on both paths below, so that the time an answer takes does
		// not tell them apart either
		$passwordHash = $this->hasher->hash($password);
		$inviteId = ($invite === null) ? 0 : $invite['id'];

		$emailInUse = $this->externalUserService->isEmailInUse($email);
		if ($emailInUse || $this->externalUserService->isLogin($handle)) {
			$claim = $this->mailLimiter->claim($email, SignupMailLimiter::KIND_NOTICE);
			if ($claim !== null && !$this->sendUnavailable($email, $handle, $emailInUse)) {
				$this->mailLimiter->release($claim);

				throw new ExternalUserException($this->l10n->t('The confirmation email could not be sent. Please try again later.'));
			}
			// a registration that is taken leaves a row the limits count,
			// so this leaves one too
			if ($this->externalUserService->verifiesEmail() || $needsApproval) {
				$this->signupsRequest->createPlaceholder($ipHash, $now);
			}

			return ['state' => self::STATE_VERIFY, 'handle' => $handle];
		}

		$claim = null;
		if ($this->externalUserService->verifiesEmail()) {
			$claim = $this->mailLimiter->claim($email, SignupMailLimiter::KIND_VERIFICATION);
			if ($claim === null) {
				$this->signupsRequest->createPlaceholder($ipHash, $now);

				return ['state' => self::STATE_VERIFY, 'handle' => $handle];
			}
		}

		if ($invite !== null && !$this->invitesRequest->consume($invite['id'])) {
			if ($claim !== null) {
				$this->mailLimiter->release($claim);
			}

			throw new ExternalUserException($this->l10n->t('This invitation link is no longer valid.'));
		}

		if ($claim !== null) {
			$token = $this->secureRandom->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
			$tokenHash = hash('sha256', $token);
			$code = $this->newCode();
			$id = $this->signupsRequest->create(
				$handle, $email, $passwordHash, $tokenHash, false, $needsApproval, $inviteId, $ipHash, $now,
				false, $noticeVersion, $noticeAcceptedAt, $noticeRequired ? $noticeText : '',
				$returnTo, $this->codeHash($code, $tokenHash),
			);
			if (!$this->sendVerification($email, $handle, $token, $code)) {
				$this->signupsRequest->delete($id);
				$this->mailLimiter->release($claim);

				throw new ExternalUserException($this->l10n->t('The confirmation email could not be sent. Please try again later.'));
			}

			return ['state' => self::STATE_VERIFY, 'handle' => $handle, 'pending' => $id];
		}

		if ($needsApproval) {
			$this->signupsRequest->create($handle, $email, $passwordHash, '', true, true, 0, $ipHash, $now, false, $noticeVersion, $noticeAcceptedAt, $noticeRequired ? $noticeText : '', $returnTo);

			return ['state' => self::STATE_APPROVAL, 'handle' => $handle];
		}

		$this->externalUserService->createAccount($handle, $email, $passwordHash, $this->origin($inviteId), 0, false, $noticeRequired ? $noticeVersion : '', $noticeAcceptedAt, $noticeRequired ? $noticeText : '');
		$this->sendWelcome($email, $handle, $returnTo);

		return ['state' => self::STATE_CREATED, 'handle' => $handle, 'loginUrl' => $this->signupReturn->loginUrl($returnTo)];
	}

	/** Whether a registration waiting for its email can be confirmed with a code as well as the link. */
	public function confirmsByCode(): bool {
		return $this->externalUserService->verifiesEmail();
	}

	/**
	 * The code from the confirmation email, typed into the page the
	 * registration was made on. `$pendingId` is that page's registration from
	 * its session; 0 is answered as a wrong code. After `CODE_ATTEMPTS` wrong
	 * codes only the link confirms it.
	 *
	 * @return array{state: string, handle: string, loginUrl?: string}
	 * @throws ExternalUserException with the field `code`, or `code-locked` once no code is taken any more
	 */
	public function confirmCode(int $pendingId, string $code): array {
		$code = (string)preg_replace('/\s+/', '', $code);
		$row = ($pendingId > 0) ? $this->signupsRequest->get($pendingId) : null;
		if ($row !== null && ($row['verified'] || $row['token'] === ''
			|| $row['creation'] < $this->timeFactory->getTime() - self::VERIFY_TTL)) {
			throw new ExternalUserException($this->l10n->t('This code is not valid any more. Please register again.'), 'code-locked');
		}
		if ($row !== null && ($row['codeHash'] ?? '') === '') {
			throw new ExternalUserException($this->codeLockedMessage(), 'code-locked');
		}

		if ($row === null || preg_match('/^[0-9]{6}$/', $code) !== 1
			|| !hash_equals($row['codeHash'] ?? '', $this->codeHash($code, $row['token']))) {
			if ($row !== null && $this->signupsRequest->addCodeAttempt($row['id'], self::CODE_ATTEMPTS) >= self::CODE_ATTEMPTS) {
				throw new ExternalUserException($this->codeLockedMessage(), 'code-locked');
			}

			throw new ExternalUserException($this->l10n->t('That code is not right. Check the email and try again.'), 'code');
		}

		return $this->confirm($row);
	}

	public function codeLockedMessage(): string {
		return $this->l10n->t('Too many wrong codes. Follow the link in the email to confirm your address instead.');
	}

	/**
	 * The link from the confirmation email.
	 *
	 * @return array{state: string, handle: string, loginUrl?: string}
	 * @throws ExternalUserException
	 */
	public function verify(string $token): array {
		$row = ($token === '') ? null : $this->signupsRequest->getByTokenHash(hash('sha256', $token));
		if ($row === null || $row['verified']
			|| $row['creation'] < $this->timeFactory->getTime() - self::VERIFY_TTL) {
			throw new ExternalUserException($this->l10n->t('This confirmation link is not valid any more. Please register again.'));
		}

		return $this->confirm($row);
	}

	/**
	 * A registration whose address is confirmed, by its link or its code:
	 * the account, or the queue where approval applies. `loginUrl` comes back
	 * to the app that started the registration, if one did.
	 *
	 * @param ExternalSignupRow $row
	 * @return array{state: string, handle: string, loginUrl?: string}
	 */
	private function confirm(array $row): array {
		// the mode when the address is confirmed counts, so a registration
		// sent before approval was switched on does not walk past it
		if ($row['approval']
			|| ($row['inviteId'] === 0 && $this->externalUserService->mode() === ExternalUserService::MODE_APPROVAL)) {
			$this->signupsRequest->markVerified($row['id'], true);

			return ['state' => self::STATE_APPROVAL, 'handle' => $row['handle']];
		}

		$this->externalUserService->createAccount(
			$row['handle'], $row['email'], $row['password'], $this->origin($row['inviteId']), $row['id'],
			$row['emailVerified'] ?? false, $row['noticeVersion'] ?? '', $row['noticeAcceptedAt'] ?? 0, $row['noticeSnapshot'] ?? '',
		);
		$this->signupsRequest->delete($row['id']);
		$this->sendWelcome($row['email'], $row['handle'], $row['returnTo'] ?? '');

		return ['state' => self::STATE_CREATED, 'handle' => $row['handle'], 'loginUrl' => $this->signupReturn->loginUrl($row['returnTo'] ?? '')];
	}

	/**
	 * The registrations waiting for an administrator, without their hashes.
	 *
	 * @return list<array{id: int, handle: string, email: string, created: int}>
	 */
	public function awaitingApproval(): array {
		return array_map(static fn (array $row): array => [
			'id' => $row['id'],
			'handle' => $row['handle'],
			'email' => $row['email'],
			'created' => $row['creation'],
		], $this->signupsRequest->awaitingApproval());
	}

	/**
	 * @throws ExternalUserException
	 */
	public function approve(int $id): string {
		$row = $this->signupsRequest->get($id);
		if ($row === null || !$row['verified'] || !$row['approval']) {
			throw new ExternalUserException($this->l10n->t('There is no such registration waiting.'));
		}

		$this->externalUserService->createAccount(
			$row['handle'], $row['email'], $row['password'], 'approval', $row['id'],
			$row['emailVerified'] ?? false, $row['noticeVersion'] ?? '', $row['noticeAcceptedAt'] ?? 0, $row['noticeSnapshot'] ?? '',
		);
		$this->signupsRequest->delete($row['id']);
		$this->sendWelcome($row['email'], $row['handle'], $row['returnTo'] ?? '');

		return $row['handle'];
	}

	/**
	 * @param string $reason sent to the person when it is not empty
	 * @throws ExternalUserException
	 */
	public function reject(int $id, string $reason = ''): void {
		$row = $this->signupsRequest->get($id);
		if ($row === null || !$this->signupsRequest->delete($id)) {
			throw new ExternalUserException($this->l10n->t('There is no such registration waiting.'));
		}

		$this->sendRejection($row['email'], $row['handle'], trim($reason));
	}

	/**
	 * An invitation, made by an administrator or, when that is switched on,
	 * by any user.
	 *
	 * @param bool $byAdministrator administrators choose uses and expiry freely
	 * @return array<string, mixed> the invitation, with its link
	 * @throws ExternalUserException
	 */
	public function createInvite(string $creator, string $note, int $maxUses, int $days, bool $byAdministrator): array {
		if (!$byAdministrator) {
			if (!$this->mayInvite($creator)) {
				throw new ExternalUserException($this->l10n->t('Invitations are not available on this server.'));
			}
			if (count($this->openInvites($creator)) >= self::USER_INVITES_OPEN) {
				throw new ExternalUserException($this->l10n->t('You have too many open invitations already.'));
			}
			$maxUses = 1;
			$days = self::USER_INVITE_DAYS;
		}

		$maxUses = max(0, min($maxUses, 10000));
		$days = max(0, min($days, 3650));
		$now = $this->timeFactory->getTime();
		$token = $this->secureRandom->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
		$id = $this->invitesRequest->create(
			$token,
			$creator,
			mb_substr(trim($note), 0, 255),
			$maxUses,
			($days === 0) ? 0 : $now + $days * 86400,
			$now
		);

		$invite = $this->invitesRequest->get($id);
		if ($invite === null) {
			throw new ExternalUserException($this->l10n->t('The invitation could not be created.'));
		}

		return $this->inviteEntity($invite);
	}

	/**
	 * Invitations, all of them or one person's.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function invites(?string $creator = null): array {
		return array_map(fn (array $invite): array => $this->inviteEntity($invite), $this->invitesRequest->list($creator));
	}

	/**
	 * @param string|null $creator only this person's invitation, or anybody's for null
	 */
	public function revokeInvite(int $id, ?string $creator = null): bool {
		$invite = $this->invitesRequest->get($id);
		if ($invite === null || ($creator !== null && $invite['creator'] !== $creator)) {
			return false;
		}

		return $this->invitesRequest->delete($id);
	}

	/** Forgets unconfirmed registrations and spent invitations. */
	public function purge(): int {
		$now = $this->timeFactory->getTime();

		return $this->signupsRequest->purgeUnverifiedBefore($now - self::VERIFY_TTL)
			+ $this->invitesRequest->purgeSpent($now);
	}

	/** @return list<array<string, mixed>> */
	private function openInvites(string $creator): array {
		$now = $this->timeFactory->getTime();

		return array_values(array_filter(
			$this->invites($creator),
			static fn (array $invite): bool => ($invite['expires'] === 0 || $invite['expires'] > $now)
				&& ($invite['maxUses'] === 0 || $invite['uses'] < $invite['maxUses'])
		));
	}

	/**
	 * @param array{id: int, token: string, creator: string, note: string, maxUses: int, uses: int, expires: int, creation: int} $invite
	 * @return array<string, mixed>
	 */
	private function inviteEntity(array $invite): array {
		return [
			'id' => $invite['id'],
			'url' => $this->urlGenerator->linkToRouteAbsolute('social.Signup.page', ['invite' => $invite['token']]),
			'creator' => $invite['creator'],
			'note' => $invite['note'],
			'maxUses' => $invite['maxUses'],
			'uses' => $invite['uses'],
			'expires' => $invite['expires'],
			'created' => $invite['creation'],
		];
	}

	/**
	 * Whether this person may send invitations: users may when the
	 * administrator lets them, and an external user never does, since an
	 * invited registration skips approval and invitations from invitees
	 * would chain past it.
	 */
	public function mayInvite(string $uid): bool {
		return $this->externalUserService->isEnabled()
			&& $this->externalUserService->usersMayInvite()
			&& !$this->externalUserService->isExternalUid($uid);
	}

	/**
	 * An invitation that may still be used. One an external user made is not:
	 * see mayInvite().
	 *
	 * @return array{id: int, token: string, creator: string, note: string, maxUses: int, uses: int, expires: int, creation: int}|null
	 */
	private function validInvite(string $token): ?array {
		$invite = $this->invitesRequest->getByToken(trim($token));
		if ($invite === null || $this->externalUserService->isExternalUid($invite['creator'])) {
			return null;
		}
		if ($invite['expires'] > 0 && $invite['expires'] < $this->timeFactory->getTime()) {
			return null;
		}
		if ($invite['maxUses'] > 0 && $invite['uses'] >= $invite['maxUses']) {
			return null;
		}

		return $invite;
	}

	/**
	 * @throws ExternalUserException
	 */
	private function assertPassword(string $password): void {
		if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
			throw new ExternalUserException(
				$this->l10n->t('The password must be at least %d characters long.', [self::MIN_PASSWORD_LENGTH]),
				'password'
			);
		}

		try {
			$this->eventDispatcher->dispatchTyped(new ValidatePasswordPolicyEvent($password));
		} catch (HintException $e) {
			throw new ExternalUserException($e->getHint(), 'password');
		}
	}

	private function origin(int $inviteId): string {
		return ($inviteId > 0) ? 'invite:' . $inviteId : ExternalUserService::MODE_OPEN;
	}

	/**
	 * The address a registration came from, as `ClientAddress` counts it,
	 * hashed with this instance's secret: enough to count registrations per
	 * address, not enough to say which address it was.
	 */
	private function ipHash(string $remoteAddress): string {
		return hash('sha256', ClientAddress::bucket($remoteAddress) . '|' . $this->config->getSystemValueString('secret', ''));
	}

	private function domain(): string {
		return (string)parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);
	}

	/**
	 * The host addresses here end in: the social address, which the
	 * administrator may have set apart from the host this page is served on.
	 */
	private function addressHost(): string {
		try {
			return $this->configService->getSocialAddress();
		} catch (SocialAppConfigException) {
			return $this->domain();
		}
	}

	/** Six digits, for a person to type; good for one registration and `CODE_ATTEMPTS` tries. */
	private function newCode(): string {
		return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
	}

	/**
	 * A code as it is stored: keyed with this instance's secret and the
	 * registration's own token, so a stolen table neither gives the codes
	 * away to a lookup table nor lets one registration's code confirm another.
	 */
	private function codeHash(string $code, string $tokenHash): string {
		return hash_hmac('sha256', $code, $this->config->getSystemValueString('secret', '') . '|' . $tokenHash);
	}

	private function sendVerification(string $email, string $handle, string $token, string $code): bool {
		$link = $this->urlGenerator->linkToRouteAbsolute('social.Signup.verify', ['token' => $token]);
		$template = $this->mailer->createEMailTemplate('social.SignupVerify', ['handle' => $handle, 'code' => $code]);
		$template->setSubject($this->l10n->t('Confirm your email address'));
		$template->addHeader();
		$template->addHeading($this->l10n->t('Welcome, @%s', [$handle]));
		$template->addBodyText($this->l10n->t('Please confirm your email address to finish creating your account on %s. The link works for 24 hours.', [$this->domain()]));
		$template->addBodyText($this->l10n->t('Still on the registration page? Type this code there: %s', [$code]));
		$template->addBodyButton($this->l10n->t('Confirm email address'), $link);
		$template->addBodyText($this->l10n->t('If you did not register, you can ignore this email.'));
		$template->addFooter();

		return $this->send($email, $template);
	}

	/**
	 * Tells the owner of an address why the registration made with it made
	 * nothing: the address is registered here already, or the username
	 * cannot be had.
	 */
	private function sendUnavailable(string $email, string $handle, bool $emailInUse): bool {
		$template = $this->mailer->createEMailTemplate('social.SignupUnavailable', ['handle' => $handle, 'emailInUse' => $emailInUse]);
		if ($emailInUse) {
			$template->setSubject($this->l10n->t('You already have an account'));
			$template->addHeader();
			$template->addHeading($this->l10n->t('You already have an account'));
			$template->addBodyText($this->l10n->t('Somebody, probably you, tried to register on %s with this email address. It is registered there already, so no new account was made.', [$this->domain()]));
			$template->addBodyText($this->l10n->t('Log in with the account you have. If you forgot its password, you can reset it on the login page. If you registered recently, confirm the registration with the link in the earlier email.'));
			$template->addBodyButton($this->l10n->t('Log in'), $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm'));
		} else {
			$template->setSubject($this->l10n->t('Choose another username'));
			$template->addHeader();
			$template->addHeading($this->l10n->t('Choose another username'));
			$template->addBodyText($this->l10n->t('The username @%1$s cannot be used on %2$s, so no account was made. Please register again with another username.', [$handle, $this->domain()]));
			$template->addBodyButton($this->l10n->t('Register'), $this->urlGenerator->linkToRouteAbsolute('social.Signup.page'));
		}
		$template->addBodyText($this->l10n->t('If you did not register, you can ignore this email.'));
		$template->addFooter();

		return $this->send($email, $template);
	}

	/** @param string $returnTo the app's authorization request the Log in button continues, see `SignupReturn` */
	private function sendWelcome(string $email, string $handle, string $returnTo = ''): void {
		$template = $this->mailer->createEMailTemplate('social.SignupWelcome', ['handle' => $handle]);
		$template->setSubject($this->l10n->t('Your account is ready'));
		$template->addHeader();
		$template->addHeading($this->l10n->t('Welcome, @%s', [$handle]));
		$template->addBodyText($this->l10n->t('Your account @%1$s@%2$s is ready. Log in with your username and the password you chose.', [$handle, $this->addressHost()]));
		$template->addBodyButton($this->l10n->t('Log in'), $this->signupReturn->absoluteLoginUrl($returnTo));
		$template->addFooter();

		$this->send($email, $template);
	}

	private function sendRejection(string $email, string $handle, string $reason): void {
		$template = $this->mailer->createEMailTemplate('social.SignupRejected', ['handle' => $handle]);
		$template->setSubject($this->l10n->t('Your registration was not accepted'));
		$template->addHeader();
		$template->addHeading($this->l10n->t('Your registration was not accepted'));
		$template->addBodyText($this->l10n->t('The administrators of %s did not accept your registration as @%s.', [$this->domain(), $handle]));
		if ($reason !== '') {
			$template->addBodyText($reason);
		}
		$template->addFooter();

		$this->send($email, $template);
	}

	/** Whether the email left this server. */
	private function send(string $email, IEMailTemplate $template): bool {
		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->useTemplate($template);

			return $this->mailer->send($message) === [];
		} catch (Throwable $e) {
			$this->logger->error('[ExternalSignupService] could not send a registration email', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return false;
		}
	}
}
