<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Lexicon\LexiconException;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The PLC signature a tool moving an account away asks for (§13.2), as the
 * reference PDS serves it: `requestPlcOperationSignature` mails the person a
 * code (Nextcloud mail, account address), and `signPlcOperation` signs the
 * tool's proposed operation with this server's rotation key and returns it
 * for the new PDS to submit.
 *
 * Both need an OAuth session with `identity:*`; an app password is never
 * enough. The code is stored hashed, valid once for fifteen minutes. The
 * person's recovery key stays first among the rotation keys, as in
 * `IdentityService::handOver()`.
 */
class PlcSignatureService {
	public const REQUEST = 'com.atproto.identity.requestPlcOperationSignature';
	public const SIGN = 'com.atproto.identity.signPlcOperation';
	public const METHODS = [self::REQUEST, self::SIGN];
	/** how long a code is good for, in seconds: the reference PDS's fifteen minutes */
	public const TOKEN_LIFETIME = 900;
	/** where the code's hash and expiry are kept, a user setting */
	public const TOKEN_KEY = 'atproto_plc_token';
	/** the characters of a code, base32 as the reference PDS's */
	private const TOKEN_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	private const MAX_ROTATION_KEYS = 5;

	public function __construct(
		private AtprotoPlcLogRequest $plcLog,
		private InstanceKeyService $instanceKeys,
		private Lexicon $lexicon,
		private IUserManager $userManager,
		private IMailer $mailer,
		private IFactory $l10nFactory,
		private IConfig $config,
		private ISecureRandom $random,
		private ILimiter $limiter,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws XrpcException
	 */
	public function procedure(ClientSession $session, string $method, #[\SensitiveParameter] array $body): array {
		if (!$session->permissions()->mayManageIdentity()) {
			throw new XrpcException(403, 'InsufficientScope', 'Changing the identity needs a session granted identity:*; an app password is not enough');
		}
		$user = $this->userManager->get($session->userId);
		if ($user === null) {
			throw XrpcException::authenticationRequired();
		}

		return $method === self::REQUEST ? $this->request($session, $user) : $this->sign($session, $user, $body);
	}

	/**
	 * `requestPlcOperationSignature`: a new code, e-mailed; the last one
	 * is no longer good.
	 *
	 * @throws XrpcException
	 */
	private function request(ClientSession $session, IUser $user): array {
		$this->limit($user, 'request', 3);
		$email = (string)$user->getEMailAddress();
		if ($email === '') {
			throw XrpcException::invalidRequest('The account has no e-mail address to send the code to');
		}
		$raw = $this->random->generate(10, self::TOKEN_ALPHABET);
		$token = substr($raw, 0, 5) . '-' . substr($raw, 5);
		$this->config->setUserValue($user->getUID(), Application::APP_ID, self::TOKEN_KEY, (string)json_encode([
			'hash' => self::hash($token),
			'did' => $session->identity->did,
			'expires' => $this->time->getTime() + self::TOKEN_LIFETIME,
		]));
		if (!$this->send($user, $email, $token)) {
			$this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::TOKEN_KEY);
			throw new XrpcException(500, 'InternalServerError', 'The code could not be sent');
		}
		$this->logger->info('PLC operation code e-mailed', ['did' => $session->identity->did]);

		return [];
	}

	/**
	 * `signPlcOperation`: the operation the tool proposes, each field the
	 * current one where it names none, signed to follow the DID's latest
	 * operation with the rotation key of this server that one names.
	 *
	 * @throws XrpcException
	 */
	private function sign(ClientSession $session, IUser $user, #[\SensitiveParameter] array $body): array {
		$this->limit($user, 'sign', 10);
		try {
			$this->lexicon->validateInput($body === [] ? new \stdClass() : $body, self::SIGN);
		} catch (LexiconException $e) {
			throw XrpcException::invalidRequest($e->getMessage());
		}
		$this->useToken($user, $session->identity->did, (string)($body['token'] ?? ''));

		$did = $session->identity->did;
		if ($this->plcLog->getPending($did) !== []) {
			throw XrpcException::invalidRequest('An earlier PLC operation for this DID is not confirmed yet; try again later');
		}
		$head = $this->plcLog->confirmedCid($did);
		$last = null;
		foreach ($this->plcLog->getByDid($did) as $row) {
			if ($row['cid'] === $head) {
				$last = $row['operation'];
			}
		}
		if ($head === '' || !is_array($last) || ($last['type'] ?? '') !== PlcOperation::TYPE_OPERATION) {
			throw XrpcException::invalidRequest('The DID has no operation this server can follow');
		}

		$rotation = [];
		if ($session->identity->recoveryPublic !== '') {
			$rotation[] = $session->identity->recoveryPublic;
		}
		foreach (is_array($body['rotationKeys'] ?? null) ? $body['rotationKeys'] : self::strings($last['rotationKeys'] ?? null) as $key) {
			if (!is_string($key) || !str_starts_with($key, 'did:key:')) {
				throw XrpcException::invalidRequest('A rotation key is not a did:key');
			}
			if (!in_array($key, $rotation, true)) {
				$rotation[] = $key;
			}
		}
		if (count($rotation) > self::MAX_ROTATION_KEYS) {
			throw XrpcException::invalidRequest('At most ' . self::MAX_ROTATION_KEYS . ' rotation keys');
		}
		$operation = [
			'type' => PlcOperation::TYPE_OPERATION,
			'rotationKeys' => $rotation,
			'verificationMethods' => $body['verificationMethods'] ?? $last['verificationMethods'] ?? [],
			'alsoKnownAs' => array_key_exists('alsoKnownAs', $body) ? $body['alsoKnownAs'] : ($last['alsoKnownAs'] ?? []),
			'services' => $body['services'] ?? $last['services'] ?? [],
			'prev' => $head,
		];

		$authority = self::strings($last['rotationKeys'] ?? null);
		foreach ($this->instanceKeys->rotationKeys() as $key) {
			if (in_array($key->didKey(), $authority, true)) {
				$this->logger->info('PLC operation signed for a tool moving the account', ['did' => $did]);

				return ['operation' => PlcOperation::sign($operation, $key)];
			}
		}

		throw XrpcException::invalidRequest('No rotation key of this server is named by the DID\'s latest operation');
	}

	/**
	 * The e-mailed code, good once and while it lasts.
	 *
	 * @throws XrpcException
	 */
	private function useToken(IUser $user, string $did, #[\SensitiveParameter] string $token): void {
		if (trim($token) === '') {
			throw XrpcException::invalidRequest('An e-mailed code is required: ask for one with ' . self::REQUEST);
		}
		$stored = json_decode($this->config->getUserValue($user->getUID(), Application::APP_ID, self::TOKEN_KEY, ''), true);
		if (!is_array($stored) || ($stored['did'] ?? '') !== $did || !hash_equals((string)($stored['hash'] ?? ''), self::hash($token))) {
			throw new XrpcException(400, 'InvalidToken', 'Token is invalid');
		}
		$this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::TOKEN_KEY);
		if ((int)($stored['expires'] ?? 0) < $this->time->getTime()) {
			throw new XrpcException(400, 'ExpiredToken', 'Token is expired');
		}
	}

	private static function hash(#[\SensitiveParameter] string $token): string {
		return hash('sha256', strtoupper(trim($token)));
	}

	/**
	 * @throws XrpcException
	 */
	private function limit(IUser $user, string $what, int $perHour): void {
		try {
			$this->limiter->registerUserRequest('social-atproto-plc-' . $what, $perHour, 3600, $user);
		} catch (IRateLimitExceededException) {
			throw new XrpcException(429, 'RateLimitExceeded', 'Too many attempts; try again later');
		}
	}

	private function send(IUser $user, string $email, #[\SensitiveParameter] string $token): bool {
		$l10n = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));
		$template = $this->mailer->createEMailTemplate('social.PlcOperationCode', ['token' => $token]);
		$template->setSubject($l10n->t('Your code to move your Bluesky account'));
		$template->addHeader();
		$template->addHeading($l10n->t('Your code to move your Bluesky account'));
		$template->addBodyText($l10n->t('A tool you signed in to asks to point your Bluesky account at another server. To allow it, enter this code there. It is good for 15 minutes.'));
		$template->addBodyText($token);
		$template->addBodyText($l10n->t('If you are not moving your Bluesky account, ignore this e-mail and nothing changes.'));
		$template->addFooter();
		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->useTemplate($template);

			return $this->mailer->send($message) === [];
		} catch (Throwable $e) {
			$this->logger->warning('PLC operation code not e-mailed', ['exception' => $e]);

			return false;
		}
	}

	/**
	 * @return list<string>
	 */
	private static function strings(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}
}
