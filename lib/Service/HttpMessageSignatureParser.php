<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\SignatureException;
use OCP\IRequest;

/**
 * RFC 9421 HTTP Message Signatures on the wire: parses `Signature-Input` and
 * `Signature`, rebuilds the §2.5 signature base and checks the two RSA
 * algorithms an actor's `publicKeyPem` can carry. What a signature means is
 * SignatureService's call, so this can be tested against the RFC vectors
 * alone.
 */
class HttpMessageSignatureParser {
	/** RSASSA-PKCS1-v1_5 over SHA-256: what Mastodon signs with for RSA keys. */
	public const ALG_RSA_V1_5_SHA256 = 'rsa-v1_5-sha256';

	/** RSASSA-PSS over SHA-512, MGF1-SHA-512, 64-byte salt (RFC 9421 §3.3.1). */
	public const ALG_RSA_PSS_SHA512 = 'rsa-pss-sha512';

	public const SUPPORTED_ALGORITHMS = [self::ALG_RSA_V1_5_SHA256, self::ALG_RSA_PSS_SHA512];

	/**
	 * Derived components (§2.2) this verifier rebuilds. `@status` and
	 * `@query-param` are refused by name: no ActivityPub sender covers them.
	 */
	public const DERIVED_COMPONENTS = [
		'@method', '@target-uri', '@authority', '@scheme', '@request-target', '@path', '@query',
	];

	/**
	 * Component parameters (§2.1) that change how a value is serialised
	 * (`sf`, `bs`, `key`, `req`, `tr`). Nothing that federates uses them, so a
	 * signature relying on one is refused with the parameter's name.
	 */
	public const REFUSED_COMPONENT_PARAMETERS = ['sf', 'bs', 'key', 'req', 'tr'];

	/**
	 * The members of a `Signature-Input` header, keyed by label: component
	 * identifiers and signature parameters, plus `serialized`, the member
	 * exactly as received, which is copied into `@signature-params`.
	 *
	 * @return array<string, array{components: list<array{name: string, params: array<string, mixed>}>, params: array<string, mixed>, serialized: string}>
	 * @throws SignatureException
	 */
	public function parseSignatureInput(string $header): array {
		$inputs = [];
		foreach ($this->parseDictionary($header) as $label => $member) {
			if ($member['type'] !== 'inner-list') {
				throw new SignatureException(
					'malformed Signature-Input: ' . $label . ' is not an inner list'
				);
			}

			$components = [];
			foreach ($member['value'] as $item) {
				if ($item['type'] !== 'string') {
					throw new SignatureException(
						'malformed Signature-Input: component identifiers must be strings'
					);
				}
				$components[] = ['name' => $item['value'], 'params' => $this->plainParameters($item['params'])];
			}

			$inputs[$label] = [
				'components' => $components,
				'params' => $this->plainParameters($member['params']),
				'serialized' => $member['raw'],
			];
		}

		return $inputs;
	}

	/**
	 * The members of a `Signature` header, keyed by label: the raw signature
	 * bytes each byte sequence carried.
	 *
	 * @return array<string, string>
	 * @throws SignatureException
	 */
	public function parseSignature(string $header): array {
		$signatures = [];
		foreach ($this->parseDictionary($header) as $label => $member) {
			if ($member['type'] !== 'bytes') {
				throw new SignatureException(
					'malformed Signature: ' . $label . ' is not a byte sequence'
				);
			}
			$signatures[$label] = $member['value'];
		}

		return $signatures;
	}

	/**
	 * The signature base of RFC 9421 §2.5: each covered component as
	 * `"name": value`, then `"@signature-params"` exactly as received.
	 * `$authority`, this instance's own address, stands in for `@authority`,
	 * the authority of `@target-uri` and `host`, so a captured request cannot
	 * be replayed against another instance.
	 *
	 * @param list<array{name: string, params: array<string, mixed>}> $components
	 * @throws SignatureException
	 */
	public function signatureBase(
		IRequest $request, array $components, string $serializedParams, string $authority,
	): string {
		$lines = [];
		$seen = [];
		foreach ($components as $component) {
			$name = strtolower($component['name']);
			if (str_starts_with($name, '@') && !in_array($name, self::DERIVED_COMPONENTS, true)) {
				throw new SignatureException(
					in_array($name, ['@query-param', '@status'], true)
						? 'unsupported derived component: ' . $name
						: 'unknown derived component: ' . $name
				);
			}
			foreach (array_keys($component['params']) as $parameter) {
				if (in_array($parameter, self::REFUSED_COMPONENT_PARAMETERS, true)) {
					throw new SignatureException(
						'unsupported component parameter: ' . $parameter . ' on ' . $name
					);
				}
				throw new SignatureException(
					'unknown component parameter: ' . $parameter . ' on ' . $name
				);
			}

			if (isset($seen[$name])) {
				throw new SignatureException('component is covered twice: ' . $name);
			}
			$seen[$name] = true;

			$value = str_starts_with($name, '@')
				? $this->derivedComponent($name, $request, $authority)
				: $this->fieldComponent($name, $request, $authority);

			$lines[] = '"' . $component['name'] . '": ' . $value;
		}

		$lines[] = '"@signature-params": ' . $serializedParams;

		return implode("\n", $lines);
	}

	public function isSupportedAlgorithm(string $algorithm): bool {
		return in_array($algorithm, self::SUPPORTED_ALGORITHMS, true);
	}

	/**
	 * Whether `$signature` is `$algorithm`'s signature over `$base` under the
	 * RSA public key `$publicKey`.
	 *
	 * @throws SignatureException for an algorithm this class does not implement
	 */
	public function verify(string $algorithm, string $publicKey, string $base, string $signature): bool {
		$key = $publicKey === '' ? false : openssl_pkey_get_public($publicKey);
		if ($key === false) {
			return false;
		}

		switch ($algorithm) {
			case self::ALG_RSA_V1_5_SHA256:
				return openssl_verify($base, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
			case self::ALG_RSA_PSS_SHA512:
				return $this->verifyRsaPssSha512($base, $signature, $key);
			default:
				throw new SignatureException('unsupported signature algorithm: ' . $algorithm);
		}
	}

	/**
	 * @throws SignatureException
	 */
	private function derivedComponent(string $name, IRequest $request, string $authority): string {
		$requestTarget = $request->getRequestUri();
		$query = strpos($requestTarget, '?');

		switch ($name) {
			case '@method':
				return $request->getMethod();
			case '@authority':
				return strtolower($authority);
			case '@scheme':
				return strtolower($request->getServerProtocol());
			case '@target-uri':
				return strtolower($request->getServerProtocol()) . '://' . strtolower($authority) . $requestTarget;
			case '@request-target':
				return $requestTarget;
			case '@path':
				$path = $query === false ? $requestTarget : substr($requestTarget, 0, $query);

				return $path === '' ? '/' : $path;
			case '@query':
				// §2.2.7: a request without a query is still signed as `?`
				return $query === false ? '?' : substr($requestTarget, $query);
			default:
				// unreachable: signatureBase() refuses anything outside DERIVED_COMPONENTS first
				throw new SignatureException('unknown derived component: ' . $name);
		}
	}

	/**
	 * @throws SignatureException
	 */
	private function fieldComponent(string $name, IRequest $request, string $authority): string {
		$value = $request->getHeader($name);
		if ($value === '') {
			throw new SignatureException('covered header is missing: ' . $name);
		}

		if ($name === 'host') {
			return $authority;
		}

		// §2.1: obs-fold becomes a single space, then leading and trailing
		// whitespace go; several instances of a field arrive already joined
		// with `, ` by the web server, which is the serialisation §2.1 asks for
		return trim((string)preg_replace('/\r?\n[ \t]+/', ' ', $value), " \t");
	}

	/**
	 * RSASSA-PSS verification (RFC 8017 §8.1.2 and §9.1.2) with SHA-512, MGF1
	 * over SHA-512 and a 64-byte salt, as RFC 9421 §3.3.1 fixes them.
	 *
	 * Written out because `openssl_verify()` takes a padding argument only from
	 * PHP 8.5 on; OpenSSL does the raw, unpadded RSA operation and the encoded
	 * message is checked here.
	 *
	 */
	private function verifyRsaPssSha512(string $message, string $signature, \OpenSSLAsymmetricKey $key): bool {
		$details = openssl_pkey_get_details($key);
		if ($details === false || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) {
			return false;
		}

		$modulusBits = (int)$details['bits'];
		if (strlen($signature) !== intdiv($modulusBits + 7, 8)) {
			return false;
		}

		if (!@openssl_public_decrypt($signature, $encoded, $key, OPENSSL_NO_PADDING)) {
			return false;
		}

		$hashLength = 64;
		$emBits = $modulusBits - 1;
		$emLength = intdiv($emBits + 7, 8);
		if ($emLength < $hashLength + 2) {
			return false;
		}

		// the raw operation yields a modulus-length integer; the encoded
		// message is its low emLen bytes and anything above has to be zero
		if (strlen($encoded) > $emLength) {
			if (ltrim(substr($encoded, 0, strlen($encoded) - $emLength), "\0") !== '') {
				return false;
			}
			$encoded = substr($encoded, -$emLength);
		}
		if (strlen($encoded) !== $emLength || $encoded[$emLength - 1] !== "\xbc") {
			return false;
		}

		$maskedDb = substr($encoded, 0, $emLength - $hashLength - 1);
		$hash = substr($encoded, $emLength - $hashLength - 1, $hashLength);

		$unusedBits = 8 * $emLength - $emBits;
		if ($unusedBits > 0 && (ord($maskedDb[0]) >> (8 - $unusedBits)) !== 0) {
			return false;
		}

		$db = $maskedDb ^ $this->mgf1Sha512($hash, $emLength - $hashLength - 1);
		if ($unusedBits > 0) {
			$db[0] = chr(ord($db[0]) & (0xff >> $unusedBits));
		}

		// The salt length is read from the padding (DB is PS || 0x01 || salt)
		// rather than assumed: OpenSSL signers pick 64 bytes on some builds and
		// the longest that fits on others. This is RSA_PSS_SALTLEN_AUTO, and
		// the padding is still checked byte for byte.
		$paddingLength = strspn($db, "\0");
		if ($paddingLength >= strlen($db) || $db[$paddingLength] !== "\x01") {
			return false;
		}
		$salt = substr($db, $paddingLength + 1);

		$expected = hash(
			'sha512',
			str_repeat("\0", 8) . hash('sha512', $message, true) . $salt,
			true
		);

		return hash_equals($expected, $hash);
	}

	/** MGF1 (RFC 8017 §B.2.1) over SHA-512. */
	private function mgf1Sha512(string $seed, int $length): string {
		$mask = '';
		for ($counter = 0; strlen($mask) < $length; $counter++) {
			$mask .= hash('sha512', $seed . pack('N', $counter), true);
		}

		return substr($mask, 0, $length);
	}

	// RFC 8941 structured fields: only what the two headers need

	/**
	 * @param array<string, array{type: string, value: mixed}> $parameters
	 * @return array<string, mixed>
	 */
	private function plainParameters(array $parameters): array {
		$plain = [];
		foreach ($parameters as $key => $item) {
			$plain[$key] = $item['value'];
		}

		return $plain;
	}

	/**
	 * A Dictionary (RFC 8941 §4.2.2): `label=member, label=member`.
	 *
	 * @return array<string, array{type: string, value: mixed, params: array<string, array{type: string, value: mixed}>, raw: string}>
	 * @throws SignatureException
	 */
	private function parseDictionary(string $input): array {
		$pos = 0;
		$length = strlen($input);
		$this->skipSpaces($input, $pos);

		$members = [];
		while ($pos < $length) {
			$key = $this->parseKey($input, $pos);

			if ($pos < $length && $input[$pos] === '=') {
				$pos++;
				$member = $this->parseItemOrInnerList($input, $pos);
			} else {
				// a member without a value is Boolean true
				$member = [
					'type' => 'boolean', 'value' => true,
					'params' => $this->parseParameters($input, $pos), 'raw' => '',
				];
			}

			// a repeated label: the last one wins (§4.2.2 step 3.3)
			$members[$key] = $member;

			$this->skipWhitespace($input, $pos);
			if ($pos >= $length) {
				return $members;
			}
			if ($input[$pos] !== ',') {
				throw new SignatureException(
					'malformed structured field: unexpected "' . $input[$pos] . '" at ' . $pos
				);
			}
			$pos++;
			$this->skipWhitespace($input, $pos);
			if ($pos >= $length) {
				throw new SignatureException('malformed structured field: trailing comma');
			}
		}

		return $members;
	}

	/**
	 * @return array{type: string, value: mixed, params: array<string, array{type: string, value: mixed}>, raw: string}
	 * @throws SignatureException
	 */
	private function parseItemOrInnerList(string $input, int &$pos): array {
		if ($pos < strlen($input) && $input[$pos] === '(') {
			return $this->parseInnerList($input, $pos);
		}

		$start = $pos;
		$item = $this->parseBareItem($input, $pos);
		$params = $this->parseParameters($input, $pos);

		return $item + ['params' => $params, 'raw' => substr($input, $start, $pos - $start)];
	}

	/**
	 * An Inner List (§4.2.1.2): `("a" "b";p=1);created=1`.
	 *
	 * @return array{type: string, value: list<array{type: string, value: mixed, params: array<string, array{type: string, value: mixed}>}>, params: array<string, array{type: string, value: mixed}>, raw: string}
	 * @throws SignatureException
	 */
	private function parseInnerList(string $input, int &$pos): array {
		$start = $pos;
		$length = strlen($input);
		$pos++; // the "("
		$items = [];

		while ($pos < $length) {
			$this->skipSpaces($input, $pos);
			if ($pos >= $length) {
				break;
			}
			if ($input[$pos] === ')') {
				$pos++;
				$params = $this->parseParameters($input, $pos);

				return [
					'type' => 'inner-list', 'value' => $items, 'params' => $params,
					'raw' => substr($input, $start, $pos - $start),
				];
			}

			$item = $this->parseBareItem($input, $pos);
			$item['params'] = $this->parseParameters($input, $pos);
			$items[] = $item;

			if ($pos < $length && $input[$pos] !== ' ' && $input[$pos] !== ')') {
				throw new SignatureException('malformed inner list: items must be separated by a space');
			}
		}

		throw new SignatureException('malformed inner list: not closed');
	}

	/**
	 * Parameters (§4.2.3.2): `;key=value;flag`.
	 *
	 * @return array<string, array{type: string, value: mixed}>
	 * @throws SignatureException
	 */
	private function parseParameters(string $input, int &$pos): array {
		$params = [];
		$length = strlen($input);
		while ($pos < $length && $input[$pos] === ';') {
			$pos++;
			$this->skipSpaces($input, $pos);
			$key = $this->parseKey($input, $pos);
			if ($pos < $length && $input[$pos] === '=') {
				$pos++;
				$params[$key] = $this->parseBareItem($input, $pos);
			} else {
				$params[$key] = ['type' => 'boolean', 'value' => true];
			}
		}

		return $params;
	}

	/**
	 * A Key (§4.2.3.3): lowercase letter or `*`, then letters, digits, `_-.*`.
	 *
	 * @throws SignatureException
	 */
	private function parseKey(string $input, int &$pos): string {
		if (preg_match('/\G[a-z*][a-z0-9_.*-]*/', $input, $m, 0, $pos) !== 1) {
			throw new SignatureException(
				'malformed structured field: expected a key at ' . $pos
			);
		}
		$pos += strlen($m[0]);

		return $m[0];
	}

	/**
	 * A Bare Item (§4.2.3.1): Integer, Decimal, String, Token, Byte Sequence
	 * or Boolean, told apart by the first character.
	 *
	 * @return array{type: string, value: mixed}
	 * @throws SignatureException
	 */
	private function parseBareItem(string $input, int &$pos): array {
		if ($pos >= strlen($input)) {
			throw new SignatureException('malformed structured field: unexpected end of input');
		}

		$first = $input[$pos];
		if ($first === '"') {
			return ['type' => 'string', 'value' => $this->parseString($input, $pos)];
		}
		if ($first === ':') {
			return ['type' => 'bytes', 'value' => $this->parseByteSequence($input, $pos)];
		}
		if ($first === '?') {
			if (preg_match('/\G\?([01])/', $input, $m, 0, $pos) !== 1) {
				throw new SignatureException('malformed structured field: bad boolean at ' . $pos);
			}
			$pos += 2;

			return ['type' => 'boolean', 'value' => $m[1] === '1'];
		}
		if ($first === '-' || ctype_digit($first)) {
			if (preg_match('/\G-?\d{1,15}(\.\d{1,3})?/', $input, $m, 0, $pos) !== 1) {
				throw new SignatureException('malformed structured field: bad number at ' . $pos);
			}
			$pos += strlen($m[0]);

			return isset($m[1])
				? ['type' => 'decimal', 'value' => (float)$m[0]]
				: ['type' => 'integer', 'value' => (int)$m[0]];
		}
		if (preg_match('/\G[A-Za-z*][A-Za-z0-9:\/!#$%&\'*+.^_`|~-]*/', $input, $m, 0, $pos) === 1) {
			$pos += strlen($m[0]);

			return ['type' => 'token', 'value' => $m[0]];
		}

		throw new SignatureException(
			'malformed structured field: unexpected "' . $first . '" at ' . $pos
		);
	}

	/**
	 * A String (§4.2.3.4): printable ASCII between double quotes, with `\"`
	 * and `\\` as the only escapes.
	 *
	 * @throws SignatureException
	 */
	private function parseString(string $input, int &$pos): string {
		$length = strlen($input);
		$pos++; // the opening quote
		$value = '';
		while ($pos < $length) {
			$char = $input[$pos++];
			if ($char === '\\') {
				if ($pos >= $length || ($input[$pos] !== '"' && $input[$pos] !== '\\')) {
					throw new SignatureException('malformed structured field: bad escape in string');
				}
				$value .= $input[$pos++];
				continue;
			}
			if ($char === '"') {
				return $value;
			}
			$byte = ord($char);
			if ($byte < 0x20 || $byte > 0x7e) {
				throw new SignatureException('malformed structured field: non-printable byte in string');
			}
			$value .= $char;
		}

		throw new SignatureException('malformed structured field: unterminated string');
	}

	/**
	 * A Byte Sequence (§4.2.3.6): base64 between colons, decoded.
	 *
	 * @throws SignatureException
	 */
	private function parseByteSequence(string $input, int &$pos): string {
		if (preg_match('/\G:([A-Za-z0-9+\/=]*):/', $input, $m, 0, $pos) !== 1) {
			throw new SignatureException('malformed structured field: bad byte sequence at ' . $pos);
		}
		$pos += strlen($m[0]);

		$decoded = base64_decode($m[1], true);
		if ($decoded === false) {
			throw new SignatureException('malformed structured field: byte sequence is not base64');
		}

		return $decoded;
	}

	private function skipSpaces(string $input, int &$pos): void {
		while ($pos < strlen($input) && $input[$pos] === ' ') {
			$pos++;
		}
	}

	private function skipWhitespace(string $input, int &$pos): void {
		while ($pos < strlen($input) && ($input[$pos] === ' ' || $input[$pos] === "\t")) {
			$pos++;
		}
	}
}
