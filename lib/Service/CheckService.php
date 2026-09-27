<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\NoUserException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Class CheckService
 *
 * @package OCA\Social\Service
 */
class CheckService {
	use TArrayTools;
	use TStringTools;

	public const CACHE_PREFIX = 'social_check_';

	/**
	 * What each client-API probe saw, in the order the bases were tried.
	 *
	 * A failed probe is the only thing an administrator has to work from, and
	 * "nothing answers" is the one thing it should not say: the rules being
	 * absent and the rules pointing somewhere that is not this Nextcloud look
	 * identical from the outside and are fixed differently.
	 *
	 * The bases are tried in order of how much they mean -- the address Social
	 * is configured for, then the host this request came in on, then the
	 * server's base URL -- so the first recorded attempt is the one to show.
	 *
	 * @var list<array{base: string, status: int, reason: string}>
	 */
	private array $attempts = [];

	private ?string $userId = null;
	private ICache $cache;

	public function __construct(
		private IUserManager $userManager,
		?string $userId,
		ICacheFactory $cacheFactory,
		private IConfig $config,
		private IClientService $clientService,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private FollowsRequest $followRequest,
		private ActorsRequest $actorsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private StreamRequest $streamRequest,
		private AccountService $accountService,
		private ConfigService $configService,
		private MiscService $miscService,
	) {
		// not the plain ICache the server hands out: that is the *user's* file
		// cache, and it throws when nobody is logged in. A setup check runs
		// with no session, and what is remembered here — whether this
		// instance answers its own WebFinger — belongs to the instance, not
		// to whoever happened to ask first.
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/check');
		$this->userId = $userId;
	}

	/**
	 * @return array
	 */
	public function checkDefault(): array {
		$handle = $this->probeAccount();

		$checks = [];
		// null, not false: with no account on this instance there is nothing to
		// ask WebFinger about, and "not checked" is not "broken"
		$checks['wellknown'] = ($handle === null) ? null : $this->checkWellKnown($handle);
		$checks['cloudAddress'] = $this->checkCloudAddress();
		$checks['clientApi'] = $this->checkClientApiRoot();

		$success = true;
		foreach ($checks as $check) {
			if ($check === false) {
				$success = false;
			}
		}

		return [
			'success' => $success,
			'checks' => $checks,
			'addresses' => $this->cloudAddresses(),
			// what the client-API probe actually saw, so a page can say why
			// rather than only that. Empty where it succeeded.
			'clientApi' => ($checks['clientApi'] === false) ? $this->clientApiDiagnosis() : [],
		];
	}

	/**
	 * Whether the address the app builds its ids from still matches the one the
	 * server says it is reachable at.
	 *
	 * The app reads `overwrite.cli.url` once, when it is first opened, and never
	 * again — every actor id, every note id and the WebFinger answer are built
	 * from that stored copy. Change the server's URL afterwards and the two
	 * drift apart silently: WebFinger starts answering for a host nobody asks
	 * about, and the app reports that .well-known is misconfigured when in fact
	 * .well-known is fine and the app is looking in the wrong place.
	 *
	 * This is only ever reported, never corrected. The stored address is baked
	 * into every id already written, so changing it is `occ social:reset`
	 * territory and not something to do behind an administrator's back.
	 */
	public function checkCloudAddress(): bool {
		$expected = $this->derivedCloudAddress();
		$configured = $this->configuredCloudAddress();

		// nothing to compare against, or nothing configured yet: the setup
		// screen deals with the second case and there is no first case to fix
		if ($expected === '' || $configured === '') {
			return true;
		}

		return $this->sameAddress($configured, $expected);
	}

	/**
	 * The address the app would derive from the server's configuration if it
	 * were being set up right now.
	 */
	public function derivedCloudAddress(): string {
		$address = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		if ($address === '') {
			return '';
		}

		$frontControllerActive
			= ($this->config->getSystemValue('htaccess.IgnoreFrontController', false) === true
			   || getenv('front_controller_active') === 'true');

		return $frontControllerActive ? $address : $address . '/index.php';
	}

	/**
	 * The address the app builds ids from, and the one the server reports.
	 *
	 * @return array{configured: string, expected: string}
	 */
	public function cloudAddresses(): array {
		return [
			'configured' => $this->configuredCloudAddress(),
			'expected' => $this->derivedCloudAddress(),
		];
	}

	/** The base URL every id is minted from, or '' before it is set. */
	public function configuredSocialUrl(): string {
		return (string)$this->configService->getAppValue(ConfigService::SOCIAL_URL);
	}

	/**
	 * Whether `social_url`, which every id is minted from, is on the scheme,
	 * host and port of `cloud_url`.
	 *
	 * They can disagree on an instance set up before `social_url` was derived
	 * from `cloud_url`: it was then taken from the first request that opened
	 * the app, and an internal hostname or a proxy that does not pass the
	 * scheme on is inside every id since. Reported, never corrected, for the
	 * same reason as the cloud address.
	 */
	public function checkSocialUrl(): bool {
		$configured = $this->configuredCloudAddress();
		$socialUrl = $this->configuredSocialUrl();
		if ($configured === '' || $socialUrl === '') {
			return true;
		}

		return $this->origin($configured) === $this->origin($socialUrl);
	}

	private function origin(string $url): string {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return strtolower($url);
		}

		$scheme = strtolower($parts['scheme'] ?? '');
		$port = $parts['port'] ?? (($scheme === 'https') ? 443 : 80);

		return $scheme . '://' . strtolower($parts['host'] ?? '') . ':' . $port;
	}

	private function configuredCloudAddress(): string {
		try {
			// getCloudUrl() predates return types and can hand back anything
			return (string)$this->configService->getCloudUrl();
		} catch (SocialAppConfigException $e) {
			return '';
		}
	}

	private function sameAddress(string $one, string $other): bool {
		return strtolower(rtrim($one, '/')) === strtolower(rtrim($other, '/'));
	}

	/**
	 * The account the WebFinger probe asks about: any one that exists.
	 *
	 * It used to ask about the reader's Nextcloud user id, which is wrong
	 * twice over. A handle is chosen when the account is set up and need not
	 * match the user id, and somebody who has not answered the setup screen
	 * has no account at all — so the probe asked about an account nobody has,
	 * got the 404 it deserved, and the app told an administrator that
	 * .well-known was misconfigured when it was fine. This is the same account
	 * the WebFinger setup check asks about, so the app and
	 * Administration → Overview now agree.
	 */
	private function probeAccount(): ?string {
		try {
			$actor = $this->actorsRequest->getAny();
		} catch (Exception $e) {
			return null;
		}

		return $actor?->getPreferredUsername();
	}

	/**
	 * Whether `/.well-known/webfinger` answers for a local account.
	 *
	 * Probed at the address the app is set up for first, then at the address
	 * this request came in on, then at the server's base URL. A success is
	 * remembered for an hour.
	 *
	 * @param string|null $username the local account to ask about; the
	 *                              current user's when not given. A setup
	 *                              check runs for an administrator who may
	 *                              never have opened the app, so it passes an
	 *                              account that is known to exist.
	 */
	public function checkWellKnown(?string $username = null): bool {
		$state = (bool)($this->cache->get(self::CACHE_PREFIX . 'wellknown') === 'true');
		if ($state === true) {
			return true;
		}

		$username ??= (string)$this->userId;

		$address = $this->configuredSocialBase();
		if ($address !== '' && $this->requestWellKnown($address, $username)) {
			return true;
		}

		if ($this->requestWellKnown(
			$this->request->getServerProtocol() . '://' . $this->request->getServerHost(), $username
		)) {
			return true;
		}

		if ($this->requestWellKnown($this->urlGenerator->getBaseUrl(), $username)) {
			return true;
		}

		return false;
	}

	/**
	 * The address other servers ask about this instance's accounts at, as a
	 * base URL: `social_address` when the admin set one, otherwise the host
	 * of the cloud URL.
	 *
	 * This used to read an app value named `address`, which nothing ever
	 * wrote, so the branch was dead and the probe always started from the
	 * request's own Host header — which is not where a remote server looks.
	 */
	private function configuredSocialBase(): string {
		try {
			$host = trim($this->configService->getSocialAddress());
		} catch (SocialAppConfigException $e) {
			return '';
		}

		if ($host === '') {
			return '';
		}

		if (parse_url($host, PHP_URL_SCHEME) !== null) {
			return rtrim($host, '/');
		}

		$scheme = 'https';
		try {
			$cloudScheme = parse_url((string)$this->configService->getCloudUrl(), PHP_URL_SCHEME);
			if (is_string($cloudScheme) && $cloudScheme !== '') {
				$scheme = strtolower($cloudScheme);
			}
		} catch (SocialAppConfigException $e) {
			// no cloud URL yet: https is what a public instance answers on
		}

		return $scheme . '://' . $host;
	}

	/**
	 * @param bool $light
	 *
	 * @return array
	 */
	public function checkInstallationStatus(bool $light = false): array {
		$result = [];
		if (!$light) {
			$result = [
				'invalidFollows' => $this->removeInvalidFollows(),
				'invalidNotes' => $this->removeInvalidNotes()
			];
		}

		//		$this->checkStatusTableFollows();
		//		$this->checkStatusTableStreamDest();
		try {
			$this->checkLocalAccountFollowingItself();
		} catch (Exception $e) {
		}

		return $result;
	}

	/**
	 * create a fake follow entry. Mandatory to have Home Stream working.
	 */
	public function checkStatusTableFollows() {
		if ($this->followRequest->countFollows() > 0) {
			return;
		}

		$follow = new Follow();
		$follow->setId($this->uuid());
		$follow->setType('Unknown');
		$follow->setActorId($this->uuid());
		$follow->setObjectId($this->uuid());
		$follow->setFollowId($this->uuid());

		$this->followRequest->save($follow);
	}

	/**
	 * create entries in follows so that user follows itself.
	 *
	 * @throws AccountAlreadyExistsException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function checkLocalAccountFollowingItself() {
		$users = $this->userManager->search('');

		foreach ($users as $user) {
			try {
				$actor = $this->accountService->getActorFromUserId($user->getUID());
			} catch (ActorDoesNotExistException $e) {
				continue;
			}

			$this->followRequest->generateLoopbackAccount($actor);
		}
	}

	/**
	 * @return int
	 */
	public function removeInvalidFollows(): int {
		$count = 0;
		$follows = $this->followRequest->getAll();
		foreach ($follows as $follow) {
			try {
				$this->cacheActorsRequest->getFromId($follow->getActorId());
				$this->cacheActorsRequest->getFromId($follow->getObjectId());
			} catch (CacheActorDoesNotExistException $e) {
				$this->followRequest->deleteById($follow->getId());
				$count++;
			}
		}

		$this->miscService->log('removeInvalidFollows removed ' . $count . ' entries', 1);

		return $count;
	}

	/**
	 * @return int
	 */
	public function removeInvalidNotes(): int {
		$count = 0;
		$streams = $this->streamRequest->getAll(Note::TYPE);
		foreach ($streams as $stream) {
			try {
				// Check if it's enough for Note, Announce, ...
				$this->cacheActorsRequest->getFromId($stream->getAttributedTo());
			} catch (CacheActorDoesNotExistException $e) {
				$this->streamRequest->deleteById($stream->getId(), Note::TYPE);
				$count++;
			}
		}

		$this->miscService->log('removeInvalidNotes removed ' . $count . ' entries', 1);

		return $count;
	}

	/**
	 * Whether a base address is the one the admin configured for this instance.
	 *
	 * Only that address earns the right to be fetched even when it resolves
	 * locally; one of the candidates below is built from the `Host` header the
	 * caller sent, and letting that reach a local address turns the check into
	 * a way to probe the server's own network from outside.
	 */
	private function isConfiguredBase(string $base): bool {
		$configured = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		if ($configured === '') {
			return false;
		}

		return $this->sameAddress($base, $configured)
			|| $this->sameAddress($base, $this->derivedCloudAddress());
	}

	/**
	 * Whether the Mastodon client API answers at the root of this instance.
	 *
	 * A client is given a domain and builds `https://<domain>/api/v1/...`
	 * itself; none of them can be told the `/index.php/apps/social` prefix the
	 * routes actually live under. So unless the web server maps the root paths
	 * onto the app, every client fails at its first request and the account
	 * cannot be added at all.
	 *
	 * `/api/v1/instance` is the probe because it is the first thing a client
	 * asks for and the only one that needs no token.
	 */
	public function checkClientApiRoot(): bool {
		$known = (string)$this->cache->get(self::CACHE_PREFIX . 'clientapi');
		if ($known !== '') {
			return $known === 'true';
		}

		$this->attempts = [];

		$address = $this->configuredSocialBase();
		if ($address !== '' && $this->requestClientApi($address)) {
			return true;
		}

		if ($this->requestClientApi(
			$this->request->getServerProtocol() . '://' . $this->request->getServerHost()
		)) {
			return true;
		}

		if ($this->requestClientApi($this->urlGenerator->getBaseUrl())) {
			return true;
		}

		$this->cache->set(
			self::CACHE_PREFIX . 'clientapi_why', (string)json_encode($this->attempts), 300
		);
		$this->configService->setAppValue(ConfigService::CLIENT_API_ROOT, '0');

		// A failure is remembered as well, and for a much shorter time than a
		// success: this runs on every page load of the app for an
		// administrator, and without it every one of those would pay for three
		// HTTP requests that are all going to fail. Short, because the next
		// thing an administrator does after reading the warning is edit the
		// web server, and they should not have to wait an hour to see it go.
		$this->cache->set(self::CACHE_PREFIX . 'clientapi', 'false', 300);

		return false;
	}

	/**
	 * One probe of `<base>/api/v1/instance`.
	 *
	 * The body is checked, not only the status: a server that answers the root
	 * with the Nextcloud login page, or with a catch-all index, would otherwise
	 * read as a working client API.
	 */
	private function requestClientApi(string $base): bool {
		try {
			$scheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
			if (!in_array($scheme, ['http', 'https'], true)) {
				// not recorded: a base that is not a URL is this app having
				// nothing to try, not something an administrator can act on
				return false;
			}

			$options = [];
			$options['nextcloud']['allow_local_address'] = $this->isConfiguredBase($base);
			$options['verify'] = $this->config->getSystemValue('social.checkssl', true);

			$response = $this->clientService->newClient()
				->get(rtrim($base, '/') . '/api/v1/instance', $options);
			$status = $response->getStatusCode();
			if ($status !== Http::STATUS_OK) {
				// 404 is the interesting one, and the reason this is recorded
				// at all: it is what both a missing rule and a rule pointing at
				// the wrong document root produce.
				$this->noted($base, $status, 'status');

				return false;
			}

			$raw = (string)$response->getBody();
			$body = json_decode($raw, true);
			if (!is_array($body) || !array_key_exists('uri', $body)) {
				// something answered, and it was not this app: a login page, a
				// catch-all index, another server's error page
				$this->noted($base, $status, 'not-social');

				return false;
			}

			$this->cache->set(self::CACHE_PREFIX . 'clientapi', 'true', 3600);
			$this->configService->setAppValue(ConfigService::CLIENT_API_ROOT, '1');
			$this->configService->setAppValue(
				ConfigService::CLIENT_API_SCHEME, $this->answeredInScheme($scheme, $raw) ? '1' : '0'
			);

			return true;
		} catch (Exception $e) {
			// nothing was reachable there at all -- a refused connection, a
			// name that does not resolve, a certificate this server will not
			// accept
			$this->noted($base, 0, 'unreachable');
		}

		return false;
	}

	/**
	 * Whether the answer's own addresses use the scheme it was asked over.
	 *
	 * A proxy that does not pass the scheme on leaves Nextcloud believing the
	 * request arrived over plain HTTP, and every absolute URL it builds says
	 * so -- on a site that is HTTPS. `mod_proxy` sends `X-Forwarded-For` and
	 * `X-Forwarded-Host` by itself and not the scheme, so an Apache set up
	 * from a recipe that omits `RequestHeader set X-Forwarded-Proto` is in
	 * exactly this state.
	 *
	 * Nothing about it is visible from inside: the app answers, the routes
	 * work, and the addresses in the answers are wrong. An iOS client refuses
	 * them outright and its sign-in stops with nothing to see, which is how
	 * this was found -- in a web server's access log, not in the app.
	 */
	private function answeredInScheme(string $scheme, string $body): bool {
		if ($scheme !== 'https') {
			// asked over plain HTTP, so http:// in the answer is the truth
			return true;
		}

		return !str_contains($body, '"http://') && !str_contains($body, '"http:\\/\\/');
	}

	/** Records one probe, without its body: a page may show this to anybody. */
	private function noted(string $base, int $status, string $reason): void {
		$this->attempts[] = ['base' => $base, 'status' => $status, 'reason' => $reason];
	}

	/**
	 * Whether the last successful probe came back with addresses in the scheme
	 * it was asked over. False only once a probe has found otherwise, so an
	 * instance nobody has checked does not accuse its own web server.
	 */
	public function clientApiSchemeIsWrong(): bool {
		return $this->configService->getAppValue(ConfigService::CLIENT_API_SCHEME) === '0';
	}

	/**
	 * Whether the root rewrite is known to work, without going and finding out.
	 *
	 * `checkClientApiRoot()` will make up to three outbound requests on a cold
	 * cache, which is fine behind an administrator opening a settings page and
	 * not fine on a route any client may call: it would make an unauthenticated
	 * endpoint into a way to have this server fetch things.
	 *
	 * So the probe's conclusion is *written down* rather than only cached. The
	 * cache it otherwise uses is `createDistributed()`, which on an instance
	 * with no distributed cache configured falls back to the local one -- APCu,
	 * which the web server and `occ` do not share. The answer then depended on
	 * which process happened to have run the check, and a client asking for the
	 * discovery document got whichever it was. An app value is read the same by
	 * every process and survives a restart.
	 */
	public function clientApiRootIsKnownGood(): bool {
		return $this->configService->getAppValue(ConfigService::CLIENT_API_ROOT) === '1';
	}

	/**
	 * What the last failed run of the client-API probe saw.
	 *
	 * @return list<array{base: string, status: int, reason: string}>
	 */
	public function clientApiDiagnosis(): array {
		$raw = (string)$this->cache->get(self::CACHE_PREFIX . 'clientapi_why');
		if ($raw === '') {
			return $this->attempts;
		}

		$attempts = json_decode($raw, true);

		return is_array($attempts) ? array_values($attempts) : [];
	}

	private function requestWellKnown(string $base, string $username): bool {
		try {
			$scheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
			if (!in_array($scheme, ['http', 'https'], true)) {
				return false;
			}

			$url = $base . '/.well-known/webfinger?resource=acct:' . $username . '@' . parse_url($base, PHP_URL_HOST);
			$options['nextcloud']['allow_local_address'] = $this->isConfiguredBase($base);
			$options['verify'] = $this->config->getSystemValue('social.checkssl', true);

			$response = $this->clientService->newClient()
				->get($url, $options);
			if ($response->getStatusCode() === Http::STATUS_OK) {
				$this->cache->set(self::CACHE_PREFIX . 'wellknown', 'true', 3600);

				return true;
			}
		} catch (Exception $e) {
			// a 4xx from the peer, a refused connection, a bad URL: all of them
			// mean the same thing here, which is that the well-known endpoint
			// did not answer
		}

		return false;
	}
}
