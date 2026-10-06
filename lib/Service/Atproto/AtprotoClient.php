<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use Exception;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * The XRPC transport: one HTTP call, one decoded JSON document, or an
 * exception that says which of the three things happened.
 *
 * This deliberately does not go through `CurlService`. That class is the
 * federation path — ActivityPub content negotiation, the block list, the
 * signed fetch, and a `RequestContentException` that carries a status code
 * and no body. XRPC answers every refusal with a document naming the error
 * and a sentence about it, and those two are the only things a person sees
 * when an app password is wrong; throwing the body away would reduce every
 * failure to a number. The block list belongs to the fediverse as well: an
 * instance may refuse to federate with a host and still be expected to read
 * Bluesky. What is kept from the federation path is the part that is about
 * the server rather than about peers — the timeouts, the CA bundle, the
 * proxy, and `allow_local_remote_servers` for an instance whose PDS runs on
 * its own network.
 *
 * Sessions are created here rather than in a caller, because they are the
 * only state one call has that the next one needs: a linked account is
 * logged in once per process, and a `401` on a call with a session — never
 * a call without one — logs it in again and tries once more. Re-logging-in
 * rather than using `refreshSession` is one round trip either way, and it
 * works after the refresh token has itself expired, which is the case a
 * long-running cron pass is most likely to hit.
 */
class AtprotoClient {
	/** @var array<string, array<string, string>> */
	private array $sessions = [];

	public function __construct(
		private ConfigService $configService,
		private IClientService $clientService,
		private CurlService $curlService,
		private PrivateKeyCipher $cipher,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The app view every `app.bsky.*` read is proxied to, without a trailing
	 * slash.
	 */
	public function appView(): string {
		return rtrim($this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_APPVIEW), '/');
	}

	/**
	 * The PLC directory did:plc documents are read from.
	 */
	public function plc(): string {
		return rtrim($this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_PLC), '/');
	}

	/**
	 * A call nobody has to be signed in for.
	 *
	 * @param array<string, mixed> $params query parameters
	 * @param array<string, mixed> $body the document for a POST, `[]` for a GET
	 * @return array<string, mixed> the decoded answer
	 *
	 * @throws AtprotoException
	 */
	public function get(string $nsid, array $params = [], ?string $base = null): array {
		return $this->call($nsid, $params, [], $base ?? $this->appView(), '');
	}

	/**
	 * A call nobody has to be signed in for, sent as a document.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoException
	 */
	public function post(string $nsid, array $body = [], ?string $base = null): array {
		return $this->call($nsid, [], $body, $base ?? $this->appView(), '');
	}

	/**
	 * A call made on behalf of a linked account, with its session.
	 *
	 * @param array<string, mixed> $params query parameters
	 * @param array<string, mixed> $body the document for a POST, `[]` for a GET
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoException
	 */
	public function authedGet(string $nsid, array $params, AtprotoAccount $account, string $base): array {
		return $this->withSession($nsid, $params, [], $base, $account);
	}

	/**
	 * A call made on behalf of a linked account, sent as a document.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoException
	 */
	public function authedPost(string $nsid, array $body, AtprotoAccount $account, string $base): array {
		return $this->withSession($nsid, [], $body, $base, $account);
	}

	/** Uploads one binary blob to the linked account's PDS. */
	public function authedBlobPost(string $binary, string $mimeType, AtprotoAccount $account, string $base): array {
		$session = $this->sessionOf($account);
		try {
			return $this->blobPost($binary, $mimeType, $base, $session['accessJwt']);
		} catch (AtprotoException $e) {
			if ($e->getStatus() !== 401) {
				throw $e;
			}
		}

		$this->forgetSession($account);
		$session = $this->sessionOf($account);

		return $this->blobPost($binary, $mimeType, $base, $session['accessJwt']);
	}

	/**
	 * Forgets the session of an account, whether it was unlinked, its app
	 * password was replaced, or its last call said the session was no good.
	 */
	public function forgetSession(AtprotoAccount $account): void {
		unset($this->sessions[$this->sessionKey($account)]);
	}

	/**
	 * The call, with a session, and one more attempt after logging in again.
	 *
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoException
	 */
	private function withSession(
		string $nsid, array $params, array $body, string $base, AtprotoAccount $account,
	): array {
		$session = $this->sessionOf($account);

		try {
			return $this->call($nsid, $params, $body, $base, $session['accessJwt']);
		} catch (AtprotoException $e) {
			if ($e->getStatus() !== 401) {
				throw $e;
			}
		}

		// the session expired or was revoked: log in again and say it once
		$this->forgetSession($account);
		$session = $this->sessionOf($account);

		return $this->call($nsid, $params, $body, $base, $session['accessJwt']);
	}

	/**
	 * The account's session, logging in if this process has none.
	 *
	 * @return array<string, string>
	 *
	 * @throws AtprotoException
	 */
	private function sessionOf(AtprotoAccount $account): array {
		$key = $this->sessionKey($account);
		if (isset($this->sessions[$key])) {
			return $this->sessions[$key];
		}

		// the password at rest is sealed with the instance secret; it is
		// unsealed here, where the one call that needs it in the clear is made
		$password = $this->cipher->open($account->getAppPassword());
		if ($password === '') {
			throw new AtprotoException(
				'the stored app password for ' . $account->getHandle() . ' cannot be read; link the account again',
				400
			);
		}

		$result = $this->call(
			'com.atproto.server.createSession',
			[],
			[
				'identifier' => $account->getHandle(),
				'password' => $password,
			],
			$this->pdsOf($account),
			'',
		);

		$accessJwt = (string)($result['accessJwt'] ?? '');
		$refreshJwt = (string)($result['refreshJwt'] ?? '');
		if ($accessJwt === '') {
			throw new AtprotoException('the PDS answered without a session token', 0);
		}

		return $this->sessions[$key] = [
			'accessJwt' => $accessJwt,
			'refreshJwt' => $refreshJwt,
			'did' => (string)($result['did'] ?? $account->getDid()),
		];
	}

	private function sessionKey(AtprotoAccount $account): string {
		if ($account->getNid() > 0) {
			return 'nid:' . $account->getNid();
		}

		return $account->getUserId() . '@' . $account->getHandle();
	}

	/**
	 * Where a linked account's own calls go: the PDS that holds its
	 * repository, which is on the row rather than looked up each time
	 * because nothing else can change it.
	 */
	private function pdsOf(AtprotoAccount $account): string {
		$pds = rtrim($account->getPds(), '/');

		return $pds !== '' ? $pds : $this->appView();
	}

	/**
	 * One call: the URL, the document, the answer.
	 *
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoException
	 */
	private function call(string $nsid, array $params, array $body, string $base, string $bearer): array {
		$method = $body === [] ? 'get' : 'post';
		$url = rtrim($base, '/') . '/xrpc/' . $nsid;
		if ($params !== []) {
			$url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
		}

		$headers = $this->headers();
		if ($bearer !== '') {
			$headers['authorization'] = 'Bearer ' . $bearer;
		}

		$payload = null;
		if ($method === 'post') {
			$headers['content-type'] = 'application/json';
			$payload = (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		return $this->send($method, $url, $headers, $payload, $nsid);
	}

	/** @return array<string, mixed> */
	private function blobPost(string $binary, string $mimeType, string $base, string $bearer): array {
		$url = rtrim($base, '/') . '/xrpc/com.atproto.repo.uploadBlob';
		$headers = $this->headers();
		$headers['content-type'] = $mimeType !== '' ? $mimeType : 'application/octet-stream';
		$headers['authorization'] = 'Bearer ' . $bearer;

		return $this->send('post', $url, $headers, $binary, 'com.atproto.repo.uploadBlob');
	}

	/**
	 * A document read from an address that is not an XRPC endpoint: the PLC
	 * directory's entry for a did, the did:web document a handle points at.
	 * Both are plain JSON, and both answer a refusal with a status and no
	 * XRPC envelope — which is why they are read by the same code as a call,
	 * and only differ in the address.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoException
	 */
	public function document(string $url): array {
		return $this->send('get', $url, $this->headers(), null, $url);
	}

	/**
	 * @param array<string, string> $headers
	 * @param string|null $payload the document to send, `null` for none
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoException
	 */
	private function send(string $method, string $url, array $headers, ?string $payload, string $what): array {
		$options = $this->configService->requestOptions();
		$options['http_errors'] = false;
		$options['headers'] = $headers;
		if ($payload !== null) {
			$options['body'] = $payload;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $method === 'post' ? $client->post($url, $options) : $client->get($url, $options);
		} catch (Exception $e) {
			// no answer at all: the peer is down, unreachable, or refused the
			// connection. Status 0 is what `isTransient()` reads as "back off"
			$this->logger->debug('AT-Proto call failed to reach ' . $url, ['exception' => $e]);

			throw new AtprotoException($e->getMessage(), 0, '', $e);
		}

		$status = $response->getStatusCode();
		$answer = $this->decode($response->getBody());

		if ($status >= 300) {
			$error = is_array($answer) ? (string)($answer['error'] ?? '') : '';
			$message = is_array($answer) ? trim((string)($answer['message'] ?? '')) : '';

			// a peer is not obliged to answer an error with an XRPC document,
			// and the first line of whatever it sent is better than a number
			if ($message === '') {
				$message = is_string($answer) ? trim($answer) : '';
			}
			if ($message === '') {
				$message = 'the peer answered with status ' . $status;
			}

			throw new AtprotoException($message, $status, $error);
		}

		if (!is_array($answer)) {
			throw new AtprotoException('the peer answered ' . $what . ' with no JSON document', $status);
		}

		return $answer;
	}

	/**
	 * What every request here is asked with: JSON in, and this app's agent
	 * name, so a PDS log says who read it.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return [
			'accept' => 'application/json',
			'user-agent' => $this->curlService->userAgent(),
		];
	}

	/**
	 * The body as a document, as a string when it is not one, and `''` when
	 * there was nothing to read.
	 */
	private function decode(string $body): mixed {
		if (trim($body) === '') {
			return '';
		}

		$decoded = json_decode($body, true);

		return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
	}
}
