<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * The server API of the Aloha Directory (its `docs/server-integration.md`).
 *
 * Every request but `enrol` is signed with the directory key; verification
 * is signed with the key that waits for its domain proof. Answers come back as
 * arrays, refusals as `DirectoryApiException` carrying the directory's error.
 */
class DirectoryClient {
	private const TIMEOUT = 15;
	private const KEY_ROTATION_CONTEXT = 'aloha-directory-key-rotation:';

	public function __construct(
		private CurlService $curlService,
		private DirectoryConfig $config,
		private MessageSigner $signer,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Starts an enrolment: the directory answers with the challenge to serve
	 * at `/.well-known/aloha-directory`.
	 *
	 * @return array<string, mixed>
	 */
	public function enrol(string $domain, DirectoryKey $key, string $contactEmail): array {
		$body = ['domain' => $domain, 'public_key' => $key->publicKey()];
		if ($contactEmail !== '') {
			$body['contact_email'] = $contactEmail;
		}

		return $this->request('POST', '/api/v1/servers/enrol', $body, null);
	}

	/**
	 * Asks the directory to check the challenge; it answers 202 and checks in
	 * the background.
	 *
	 * @return array<string, mixed>
	 */
	public function verify(string $domain, DirectoryKey $pendingKey): array {
		return $this->request('POST', $this->server($domain, '/verify'), null, $pendingKey);
	}

	/**
	 * `queued`, `failed` (with `error` and `message`) or `verified`; signed
	 * with the pending key, or with the new key once verified.
	 *
	 * @return array<string, mixed>
	 */
	public function verificationStatus(string $domain, DirectoryKey $key): array {
		return $this->request('GET', $this->server($domain, '/verify'), null, $key);
	}

	/**
	 * @param array<string, mixed> $profile
	 *
	 * @return array<string, mixed>
	 */
	public function putProfile(string $domain, DirectoryKey $key, array $profile): array {
		return $this->request('PUT', $this->server($domain, '/profile'), $profile, $key);
	}

	/**
	 * @param array<string, mixed> $heartbeat
	 *
	 * @return array<string, mixed>
	 */
	public function putHeartbeat(string $domain, DirectoryKey $key, array $heartbeat): array {
		return $this->request('PUT', $this->server($domain, '/heartbeat'), $heartbeat, $key);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(string $domain, DirectoryKey $key): array {
		return $this->request('GET', $this->server($domain, '/status'), null, $key);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function comment(string $domain, DirectoryKey $key, string $body): array {
		return $this->request('POST', $this->server($domain, '/comments'), ['body' => $body], $key);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function respondToComplaint(string $domain, DirectoryKey $key, int $complaintId, string $body): array {
		return $this->request('POST', $this->server($domain, '/complaints/' . $complaintId . '/responses'), ['body' => $body], $key);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function withdraw(string $domain, DirectoryKey $key): array {
		return $this->request('DELETE', $this->server($domain, ''), null, $key);
	}

	/**
	 * Replaces the key, signed with the current one; the new key proves it is
	 * wanted by signing the rotation statement.
	 *
	 * @return array<string, mixed>
	 */
	public function rotateKey(string $domain, DirectoryKey $current, DirectoryKey $next): array {
		$proof = $next->sign(self::KEY_ROTATION_CONTEXT . $domain . ':' . $next->keyId());

		return $this->request('POST', $this->server($domain, '/key'), [
			'public_key' => $next->publicKey(),
			'proof' => Encoding::base64UrlEncode($proof),
		], $current);
	}

	private function server(string $domain, string $suffix): string {
		return '/api/v1/servers/' . rawurlencode($domain) . $suffix;
	}

	/**
	 * @param ?array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	private function request(string $method, string $path, ?array $body, ?DirectoryKey $key): array {
		$raw = $body === null ? '' : (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$headers = ['Accept' => 'application/json'];
		if ($raw !== '') {
			$headers['Content-Type'] = 'application/json';
		}
		if ($key !== null) {
			$headers += $this->signer->sign($key, $method, $this->config->authority(), $path, $raw, $this->time->getTime());
		}

		$status = 0;
		$contentType = null;
		try {
			$answer = $this->curlService->doRequest(
				$method,
				$this->config->directoryUrl() . $path,
				[
					'headers' => $headers,
					'body' => $raw,
					'timeout' => self::TIMEOUT,
					'json_headers' => false,
					'accept_errors' => true,
					// a signed request goes to the directory and nowhere else
					'max_redirects' => 0,
					'federation' => false,
				],
				$contentType,
				$status,
			);
		} catch (Throwable $e) {
			throw new DirectoryApiException(0, 'unreachable', 'The Aloha Directory could not be reached: ' . $e->getMessage());
		}

		$status = (int)$status;
		$data = json_decode($answer, true);
		$data = is_array($data) ? $data : [];
		if ($status < 200 || $status >= 300) {
			$details = $data['details'] ?? [];
			throw new DirectoryApiException(
				$status,
				is_string($data['error'] ?? null) ? $data['error'] : 'http_' . $status,
				is_string($data['message'] ?? null) ? $data['message'] : 'The Aloha Directory answered HTTP ' . $status,
				is_array($details) ? $details : [],
			);
		}

		return $data;
	}
}
