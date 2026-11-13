<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use OCA\Social\Atproto\Protocol\Encoding;

/**
 * HTTP message signatures (RFC 9421) in the Aloha Directory's profile: one
 * signature labelled `dir`, covering the method, the directory's own host, the
 * path, the query when there is one and the body digest (RFC 9530), with
 * `alg="ed25519"`, `tag="aloha-directory"` and a random nonce.
 *
 * The directory accepts every signature once only, and Ed25519 is
 * deterministic, so the nonce is what keeps a retry in the same second from
 * being refused as a replay.
 */
class MessageSigner {
	public const LABEL = 'dir';
	public const ALG = 'ed25519';
	public const TAG = 'aloha-directory';

	/**
	 * @param string $authority the directory's host, e.g. `directory.aloha.social`
	 * @param string $pathAndQuery the path, with the query string if there is one
	 * @param ?string $nonce fixed only by tests
	 *
	 * @return array<string, string> the headers to send
	 */
	public function sign(
		DirectoryKey $key,
		string $method,
		string $authority,
		string $pathAndQuery,
		string $body,
		int $created,
		?string $nonce = null,
	): array {
		[$path, $query] = array_pad(explode('?', $pathAndQuery, 2), 2, null);
		$values = [
			'@method' => strtoupper($method),
			'@authority' => strtolower($authority),
			'@path' => $path,
		];
		if ($query !== null) {
			$values['@query'] = '?' . $query;
		}

		$headers = [];
		if ($body !== '') {
			$headers['Content-Digest'] = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
			$values['content-digest'] = $headers['Content-Digest'];
		}

		$params = '(' . implode(' ', array_map(static fn (string $component): string => '"' . $component . '"', array_keys($values))) . ')'
			. ';created=' . $created
			. ';keyid="' . $key->keyId() . '"'
			. ';alg="' . self::ALG . '"'
			. ';tag="' . self::TAG . '"'
			. ';nonce="' . ($nonce ?? Encoding::base64UrlEncode(random_bytes(16))) . '"';

		$lines = [];
		foreach ($values as $component => $value) {
			$lines[] = '"' . $component . '": ' . $value;
		}
		$lines[] = '"@signature-params": ' . $params;

		$headers['Signature-Input'] = self::LABEL . '=' . $params;
		$headers['Signature'] = self::LABEL . '=:' . base64_encode($key->sign(implode("\n", $lines))) . ':';

		return $headers;
	}
}
