<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use InvalidArgumentException;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * DPoP proofs (RFC 9449), checked: the proof is signed by the key in its own
 * header, names this request's method and address, is fresh, carries this
 * server's current nonce, is not a replay, and — on a request with an
 * access token — names that token. What it proves is the key's thumbprint,
 * which every token of the session is bound to.
 */
class DpopVerifier {
	/** how far a proof's `iat` may be from now */
	private const MAX_AGE = 300;
	private const MAX_FUTURE = 60;

	public function __construct(
		private DpopNonce $nonce,
		private AtprotoOAuthRequest $request,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param string $proof the `DPoP` header
	 * @param string $method the HTTP method, upper case
	 * @param string $url the address the client used, without query
	 * @param string $accessToken the token the request carries, '' for none
	 * @return string the thumbprint of the key that signed it
	 * @throws OAuthException `use_dpop_nonce` with a fresh nonce, or `invalid_dpop_proof`
	 */
	public function verify(string $proof, string $method, string $url, string $accessToken = ''): string {
		if ($proof === '') {
			throw OAuthException::invalidDpop('A DPoP proof is required');
		}
		try {
			$jws = Jose::decode($proof);
			$jwk = is_array($jws['header']['jwk'] ?? null) ? $jws['header']['jwk'] : [];
			$key = Jwk::publicKey($jwk);
			$thumbprint = Jwk::thumbprint($jwk);
		} catch (InvalidArgumentException $e) {
			throw OAuthException::invalidDpop('The DPoP proof is not readable: ' . $e->getMessage());
		}
		if (($jws['header']['typ'] ?? '') !== 'dpop+jwt' || !Jose::verify($jws, $key)) {
			throw OAuthException::invalidDpop('The DPoP proof is not signed by its key');
		}
		$claims = $jws['claims'];
		$now = $this->time->getTime();
		$iat = is_int($claims['iat'] ?? null) ? $claims['iat'] : 0;
		if ($iat < $now - self::MAX_AGE || $iat > $now + self::MAX_FUTURE) {
			throw OAuthException::invalidDpop('The DPoP proof is not fresh');
		}
		if (strtoupper((string)($claims['htm'] ?? '')) !== strtoupper($method) || !self::sameTarget((string)($claims['htu'] ?? ''), $url)) {
			throw OAuthException::invalidDpop('The DPoP proof is for another request');
		}
		if ($accessToken !== '' && !hash_equals(Encoding::base64UrlEncode(hash('sha256', $accessToken, true)), (string)($claims['ath'] ?? ''))) {
			throw OAuthException::invalidDpop('The DPoP proof is for another token');
		}
		$jti = (string)($claims['jti'] ?? '');
		if ($jti === '' || strlen($jti) > 256) {
			throw OAuthException::invalidDpop('The DPoP proof has no id');
		}
		if (!$this->nonce->isValid((string)($claims['nonce'] ?? ''))) {
			throw new OAuthException('use_dpop_nonce', 'Use the DPoP nonce this server provides', 400, ['DPoP-Nonce' => $this->nonce->current()]);
		}
		if (!$this->request->firstUse(hash('sha256', 'dpop|' . $thumbprint . '|' . $jti), $now + self::MAX_AGE + self::MAX_FUTURE)) {
			throw OAuthException::invalidDpop('The DPoP proof was used before');
		}

		return $thumbprint;
	}

	/**
	 * Whether a proof's `htu` names this address: scheme, host, port and
	 * path, with no query or fragment (RFC 9449 §4.3).
	 */
	private static function sameTarget(string $htu, string $url): bool {
		$a = parse_url($htu);
		$b = parse_url($url);
		if (!is_array($a) || !is_array($b)) {
			return false;
		}
		$normal = static fn (array $u): string => strtolower(($u['scheme'] ?? '') . '://' . ($u['host'] ?? ''))
			. (isset($u['port']) ? ':' . $u['port'] : '') . ($u['path'] ?? '/');

		return $normal($a) === $normal($b);
	}
}
