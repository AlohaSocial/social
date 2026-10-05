<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finishes a move *from the new side*, when the old server is this app too.
 *
 * A move is sent by the old server, and Mastodon offers no API for it: a
 * person moving from Mastodon always ends in Mastodon's own form. Between
 * two instances of this app the last step can be done from here. The old
 * instance is an OAuth server like any Mastodon; this one registers itself
 * there as an application asking for one scope, `write:migration`, sends the
 * person to log in and consent on their old Nextcloud, and with the token
 * that comes back calls the old instance's move route naming this account.
 *
 * The scope is the whole of the safety: a token with `write:migration` can
 * do nothing else, and nothing else can do this — the old instance accepts
 * that scope spelled out, never the broad `write` every phone app holds.
 * The old instance still checks, as it does for its own button, that the
 * target names the old account in `alsoKnownAs`, which the move-in here set.
 */
class MoveFinishService {
	public const SCOPE = 'write:migration';
	/** how long a started finish waits for the person to come back, in seconds */
	public const TTL = 900;

	private const PENDING = 'move_finish';
	private const TIMEOUT = 15;

	public function __construct(
		private MigrationService $migrationService,
		private FediverseDirectoryService $directoryService,
		private CurlService $curlService,
		private ConfigService $configService,
		private AccountService $accountService,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $secureRandom,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the old account's server is this app, which is what makes the
	 * last step doable from here. Asked of NodeInfo, so it costs two requests.
	 */
	public function canFinish(Person $old): bool {
		$host = (string)parse_url($old->getId(), PHP_URL_HOST);
		$scheme = (string)parse_url($old->getId(), PHP_URL_SCHEME);
		if ($host === '' || !in_array($scheme, ['https', 'http'], true)) {
			return false;
		}

		return $this->directoryService->softwareName($host, $scheme) === Application::SOFTWARE_NAME;
	}

	/**
	 * Registers this instance with the old one and says where to send the
	 * person: the old instance's consent page, asking for the one scope.
	 *
	 * @return string the authorize URL
	 * @throws InvalidResourceException when the old server is not this app, or will not register us
	 */
	public function start(string $userId, string $handle): string {
		$old = $this->migrationService->resolveActor($handle);
		if (!$this->canFinish($old)) {
			throw new InvalidResourceException(
				'the old account is not on an Aloha Social server; finish the move there, with its own button'
			);
		}
		$base = self::baseOf($old->getId());
		$callback = $this->urlGenerator->linkToRouteAbsolute('social.Migration.moveInFinishCallback');

		$app = $this->post($base . '/api/v1/apps', [
			'client_name' => 'Aloha Social at ' . $this->configService->getSocialAddress(),
			'redirect_uris' => $callback,
			'scopes' => self::SCOPE,
			'website' => $this->configService->getSocialUrl(),
		]);
		$clientId = (string)($app['client_id'] ?? '');
		$clientSecret = (string)($app['client_secret'] ?? '');
		if ($clientId === '' || $clientSecret === '') {
			throw new InvalidResourceException('the old server would not register this one as an application');
		}

		$state = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->store($userId, [
			'status' => 'pending',
			'state' => $state,
			'base' => $base,
			'old' => $old->getId(),
			'acct' => $old->getAccount(),
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'callback' => $callback,
			'created' => time(),
		]);

		return $base . '/oauth/authorize?' . http_build_query([
			'response_type' => 'code',
			'client_id' => $clientId,
			'redirect_uri' => $callback,
			'scope' => self::SCOPE,
			'state' => $state,
		]);
	}

	/**
	 * The person is back with a code: turns it into a token and has the old
	 * instance move the followers here. What came of it is kept for the page.
	 *
	 * @return array<string, mixed> the status afterwards
	 * @throws InvalidResourceException when nothing was started, the state is wrong or the old server refused
	 */
	public function finish(string $userId, string $code, string $state): array {
		$pending = $this->pending($userId);
		if ($pending === null || ($pending['status'] ?? '') !== 'pending') {
			throw new InvalidResourceException('no move was started from here');
		}
		if ((int)$pending['created'] < time() - self::TTL) {
			$this->store($userId, ['status' => 'failed', 'acct' => $pending['acct'], 'error' => 'the move took too long to confirm; start it again']);
			throw new InvalidResourceException('the move took too long to confirm; start it again');
		}
		if ($code === '' || !hash_equals((string)$pending['state'], $state)) {
			throw new InvalidResourceException('this is not the move that was started');
		}

		try {
			$token = $this->post($pending['base'] . '/oauth/token', [
				'grant_type' => 'authorization_code',
				'code' => $code,
				'client_id' => $pending['client_id'],
				'client_secret' => $pending['client_secret'],
				'redirect_uri' => $pending['callback'],
			]);
			$accessToken = (string)($token['access_token'] ?? '');
			if ($accessToken === '') {
				throw new InvalidResourceException('the old server did not hand out a token');
			}

			// by id rather than by handle: the old server resolves an id as it
			// is, where a handle is parsed first and one on a bare hostname is
			// not one
			$actor = $this->accountService->getActorFromUserId($userId);
			$moved = $this->post(
				$pending['base'] . '/api/v1/migration/move/authorized',
				['target' => $actor->getId()],
				['Authorization' => 'Bearer ' . $accessToken]
			);
			if (($moved['moved_to'] ?? '') === '') {
				throw new InvalidResourceException(
					'the old server refused the move: ' . (string)($moved['error'] ?? 'no answer')
				);
			}
		} catch (Throwable $e) {
			$this->logger->notice('[MoveFinishService] finishing a move from here failed', [
				'userId' => $userId, 'old' => $pending['old'], 'exception' => $e,
			]);
			$this->store($userId, ['status' => 'failed', 'acct' => $pending['acct'], 'error' => $e->getMessage()]);

			throw $e instanceof InvalidResourceException ? $e : new InvalidResourceException($e->getMessage(), 0, $e);
		}

		$this->store($userId, ['status' => 'done', 'acct' => $pending['acct'], 'old' => $pending['old'], 'at' => time()]);

		return $this->status($userId);
	}

	/**
	 * Where a finish started from here stands, for the page: `none`,
	 * `pending`, `done` or `failed`, with the old account and the reason.
	 *
	 * @return array{status: string, acct: string, at: int, error: string}
	 */
	public function status(string $userId): array {
		$pending = $this->pending($userId);
		if ($pending === null) {
			return ['status' => 'none', 'acct' => '', 'at' => 0, 'error' => ''];
		}
		$status = (string)($pending['status'] ?? 'none');
		if ($status === 'pending' && (int)($pending['created'] ?? 0) < time() - self::TTL) {
			$status = 'failed';
			$pending['error'] = 'the move took too long to confirm; start it again';
		}

		return [
			'status' => $status,
			'acct' => (string)($pending['acct'] ?? ''),
			'at' => (int)($pending['at'] ?? 0),
			'error' => (string)($pending['error'] ?? ''),
		];
	}

	/** The app's base URL on the old server, off the actor id it serves. */
	private static function baseOf(string $actorId): string {
		$cut = strrpos($actorId, '/@');
		if ($cut === false) {
			throw new InvalidResourceException('the old account does not look like one of this app');
		}

		return substr($actorId, 0, $cut);
	}

	/**
	 * A JSON POST to the old server, through the guarded client: the same
	 * federation and local-address checks every outbound request gets. An
	 * answer that is not a 2xx comes back as an exception from the client.
	 *
	 * @param array<string, string> $body
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private function post(string $url, array $body, array $headers = []): array {
		try {
			return $this->curlService->retrieveJson('post', $url, [
				'timeout' => self::TIMEOUT,
				'json_headers' => false,
				'headers' => $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
				'body' => (string)json_encode($body),
			]);
		} catch (Throwable $e) {
			$status = $e->getCode() > 0 ? ' status ' . $e->getCode() : '';
			throw new InvalidResourceException(
				'the old server answered ' . (string)parse_url($url, PHP_URL_PATH) . ' with' . $status . ': ' . $e->getMessage(), 0, $e
			);
		}
	}

	/** @return array<string, mixed>|null */
	private function pending(string $userId): ?array {
		$stored = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::PENDING, ''), true);

		return is_array($stored) ? $stored : null;
	}

	/** @param array<string, mixed> $data */
	private function store(string $userId, array $data): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::PENDING, (string)json_encode($data));
	}
}
