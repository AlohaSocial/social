<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\ExternalInvitesRequest;
use OCA\Social\Db\ExternalSignupsRequest;
use OCA\Social\Exceptions\ExternalUserException;
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
 */
class ExternalSignupService {
	/** How long a confirmation link works. */
	public const VERIFY_TTL = 86400;

	/** Registrations one address may make in an hour. */
	public const PER_IP_PER_HOUR = 5;

	/** Registrations waiting in all, before the form stops taking more. */
	public const MAX_PENDING = 1000;

	public const MIN_PASSWORD_LENGTH = 8;

	/** Invitations a user who is not an administrator may have open at once. */
	public const USER_INVITES_OPEN = 10;
	public const USER_INVITE_DAYS = 7;

	public const STATE_VERIFY = 'verify';
	public const STATE_APPROVAL = 'approval';
	public const STATE_CREATED = 'created';

	public function __construct(
		private ExternalUserService $externalUserService,
		private ExternalSignupsRequest $signupsRequest,
		private ExternalInvitesRequest $invitesRequest,
		private InstanceService $instanceService,
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
	) {
	}

	/**
	 * What the registration page needs before anybody types anything.
	 *
	 * @return array<string, mixed>
	 */
	public function pageState(string $inviteToken = ''): array {
		$invite = $this->validInvite($inviteToken);
		$mode = $this->externalUserService->mode();
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
			'minAge' => $this->externalUserService->minimumAge(),
			'rules' => array_map(static fn (array $rule): string => $rule['text'], $this->instanceService->rules()),
			'privacyUrl' => trim($this->appConfig->getValueString('theming', 'privacyUrl', '')),
			'legalUrl' => trim($this->appConfig->getValueString('theming', 'imprintUrl', '')),
			'domain' => $this->domain(),
			'loginUrl' => $this->urlGenerator->linkToRoute('core.login.showLoginForm'),
		];
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

	/**
	 * A registration, as submitted by the form.
	 *
	 * A filled-in honeypot is answered as if the registration had worked and
	 * nothing is stored: a bot learns nothing from the answer.
	 *
	 * @return array{state: string, handle: string}
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
	): array {
		if ($honeypot !== '') {
			$this->logger->info('[ExternalSignupService] dropped a registration that filled in the honeypot');

			return ['state' => self::STATE_VERIFY, 'handle' => ''];
		}

		$invite = $this->validInvite($inviteToken);
		$mode = $this->externalUserService->mode();
		if (!$this->externalUserService->hasRoom() || ($mode === ExternalUserService::MODE_INVITE && $invite === null)) {
			throw new ExternalUserException($this->closedReason($mode, $inviteToken !== ''));
		}

		$ipHash = $this->ipHash($remoteAddress);
		$now = $this->timeFactory->getTime();
		if ($this->signupsRequest->countFromIpSince($ipHash, $now - 3600) >= self::PER_IP_PER_HOUR
			|| $this->signupsRequest->count() >= self::MAX_PENDING) {
			throw new ExternalUserException($this->l10n->t('Too many registrations right now, please try again later.'));
		}

		$handle = $this->externalUserService->assertHandleAvailable($handle);
		$email = $this->externalUserService->assertEmailAvailable($email);
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

		if ($invite !== null && !$this->invitesRequest->consume($invite['id'])) {
			throw new ExternalUserException($this->l10n->t('This invitation link is no longer valid.'));
		}

		$passwordHash = $this->hasher->hash($password);
		$needsApproval = $mode === ExternalUserService::MODE_APPROVAL && $invite === null;
		$inviteId = ($invite === null) ? 0 : $invite['id'];

		if ($this->externalUserService->verifiesEmail()) {
			$token = $this->secureRandom->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
			$id = $this->signupsRequest->create($handle, $email, $passwordHash, hash('sha256', $token), false, $needsApproval, $inviteId, $ipHash, $now);
			if (!$this->sendVerification($email, $handle, $token)) {
				$this->signupsRequest->delete($id);

				throw new ExternalUserException($this->l10n->t('The confirmation email could not be sent. Please try again later.'));
			}

			return ['state' => self::STATE_VERIFY, 'handle' => $handle];
		}

		if ($needsApproval) {
			$this->signupsRequest->create($handle, $email, $passwordHash, '', true, true, 0, $ipHash, $now);

			return ['state' => self::STATE_APPROVAL, 'handle' => $handle];
		}

		$this->externalUserService->createAccount($handle, $email, $passwordHash, $this->origin($inviteId));
		$this->sendWelcome($email, $handle);

		return ['state' => self::STATE_CREATED, 'handle' => $handle];
	}

	/**
	 * The link from the confirmation email.
	 *
	 * @return array{state: string, handle: string}
	 * @throws ExternalUserException
	 */
	public function verify(string $token): array {
		$row = ($token === '') ? null : $this->signupsRequest->getByTokenHash(hash('sha256', $token));
		if ($row === null || $row['verified']
			|| $row['creation'] < $this->timeFactory->getTime() - self::VERIFY_TTL) {
			throw new ExternalUserException($this->l10n->t('This confirmation link is not valid any more. Please register again.'));
		}

		if ($row['approval']) {
			$this->signupsRequest->markVerified($row['id']);

			return ['state' => self::STATE_APPROVAL, 'handle' => $row['handle']];
		}

		$this->externalUserService->createAccount($row['handle'], $row['email'], $row['password'], $this->origin($row['inviteId']), $row['id']);
		$this->signupsRequest->delete($row['id']);
		$this->sendWelcome($row['email'], $row['handle']);

		return ['state' => self::STATE_CREATED, 'handle' => $row['handle']];
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

		$this->externalUserService->createAccount($row['handle'], $row['email'], $row['password'], 'approval', $row['id']);
		$this->signupsRequest->delete($row['id']);
		$this->sendWelcome($row['email'], $row['handle']);

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
			if (!$this->externalUserService->isEnabled() || !$this->externalUserService->usersMayInvite()) {
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
	 * @return array{id: int, token: string, creator: string, note: string, maxUses: int, uses: int, expires: int, creation: int}|null
	 */
	private function validInvite(string $token): ?array {
		$invite = $this->invitesRequest->getByToken(trim($token));
		if ($invite === null) {
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
	 * The address a registration came from, hashed with this instance's
	 * secret: enough to count registrations per address, not enough to say
	 * which address it was.
	 */
	private function ipHash(string $remoteAddress): string {
		return hash('sha256', $remoteAddress . '|' . $this->config->getSystemValueString('secret', ''));
	}

	private function domain(): string {
		return (string)parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);
	}

	private function sendVerification(string $email, string $handle, string $token): bool {
		$link = $this->urlGenerator->linkToRouteAbsolute('social.Signup.verify', ['token' => $token]);
		$template = $this->mailer->createEMailTemplate('social.SignupVerify', ['handle' => $handle]);
		$template->setSubject($this->l10n->t('Confirm your email address'));
		$template->addHeader();
		$template->addHeading($this->l10n->t('Welcome, @%s', [$handle]));
		$template->addBodyText($this->l10n->t('Please confirm your email address to finish creating your account on %s. The link works for 24 hours.', [$this->domain()]));
		$template->addBodyButton($this->l10n->t('Confirm email address'), $link);
		$template->addBodyText($this->l10n->t('If you did not register, you can ignore this email.'));
		$template->addFooter();

		return $this->send($email, $template);
	}

	private function sendWelcome(string $email, string $handle): void {
		$template = $this->mailer->createEMailTemplate('social.SignupWelcome', ['handle' => $handle]);
		$template->setSubject($this->l10n->t('Your account is ready'));
		$template->addHeader();
		$template->addHeading($this->l10n->t('Welcome, @%s', [$handle]));
		$template->addBodyText($this->l10n->t('Your account @%1$s@%2$s is ready. Log in with your username and the password you chose.', [$handle, $this->domain()]));
		$template->addBodyButton($this->l10n->t('Log in'), $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm'));
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
