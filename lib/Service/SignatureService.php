<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use DateTimeZone;
use Exception;
use JsonLdException;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\LinkedDataSignatureMissingException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use stdClass;

class SignatureService {
	use TArrayTools;

	public const ORIGIN_HEADER = 1;
	public const ORIGIN_SIGNATURE = 2;
	public const ORIGIN_REQUEST = 3;

	public const DATE_HEADER = 'D, d M Y H:i:s T';
	public const DATE_OBJECT = 'Y-m-d\TH:i:s\Z';

	/**
	 * How old a signed request's `Date` (or RFC 9421 `created`) may be, and
	 * how far ahead of this server's clock: Mastodon's bounds, which tolerate
	 * a skewed clock or a queued delivery. A request inside the window is
	 * taken in once (see `isReplayed()`).
	 */
	public const DATE_PAST = 43200; // 12h
	public const DATE_FUTURE = 3600; // 1h

	/**
	 * How far an LD signature's `created` may lie from now. Forwarded
	 * activities arrive with the original signature, so the window is generous —
	 * it exists to bound the replay cache, which is what actually blocks
	 * re-posting a captured activity.
	 */
	public const LD_WINDOW = 86400; // 24h

	/**
	 * How much longer than its acceptance window a signature is remembered.
	 * A request dated at the window's future edge is still accepted on the
	 * last second of the window's whole width after it was taken in; a
	 * memory that lapsed on that second would let it through again.
	 */
	public const REPLAY_MARGIN = 60;

	/** The `DurableCache` namespace of the LD signatures already accepted. */
	public const LD_SEEN_NAMESPACE = 'social.ldsig';

	/** The `DurableCache` namespace of the signed requests already taken in. */
	public const REQUEST_SEEN_NAMESPACE = 'social.httpsig';

	/**
	 * How long a request may take fetching the signing key of a keyId this
	 * instance has never seen. Everything before that fetch is free for an
	 * attacker to satisfy, so this bounds how long an unauthenticated inbox
	 * POST can hold a PHP worker; known keys need no fetch. The values match
	 * what Mastodon allows, as a slow first contact must still fit. Note that
	 * `CurlService::retrieveObject()` retries unsigned after a 401/403, so a
	 * worker can be held for about twice the read budget.
	 */
	public const UNKNOWN_KEY_TIMEOUT = 10;

	/** Seconds for DNS+TCP+TLS alone, within UNKNOWN_KEY_TIMEOUT. */
	public const UNKNOWN_KEY_CONNECT_TIMEOUT = 5;

	/**
	 * How long a key retrieval that failed on its own terms — no such actor,
	 * an answer that is not one — is remembered, in seconds.
	 */
	public const KEY_FAILURE_TTL = 300;

	/**
	 * How long a key retrieval that got no answer (unreachable, or slower than
	 * the budget above) is remembered: briefly, as it says the peer had a bad
	 * minute, not something about the keyId.
	 */
	public const KEY_UNREACHABLE_TTL = 30;

	/**
	 * Digest algorithms this server can compute, keyed by the name as it
	 * appears (lowercased) in a Digest or Content-Digest header.
	 */
	public const DIGEST_ALGORITHMS = [
		'sha-256' => 'sha256',
		'sha256' => 'sha256',
		'sha-512' => 'sha512',
		'sha512' => 'sha512',
	];

	/** How much of a sender-written value an exception message quotes. */
	private const EXCERPT = 200;

	/** How much of a signature base an exception message quotes. */
	private const EXCERPT_BASE = 1000;

	/** The shortest interval between two forced refreshes of the same keyId. */
	public const KEY_REFRESH_INTERVAL = 300;

	/**
	 * The `DurableCache` namespace of the actors that fragment-less keyIds
	 * were found to belong to (`CacheActorService::getFromKeyId()`), so a
	 * known peer's delivery is a database read and not a fetch of its key.
	 */
	public const KEY_HOLDER_NAMESPACE = 'social.keyholder';

	/** How long a keyId's owner is remembered: a key does not change hands. */
	public const KEY_HOLDER_TTL = 2592000; // 30 days

	private CacheActorService $cacheActorService;
	private CacheActorsRequest $cacheActorsRequest;
	private ConfigService $configService;
	private HttpSignatureService $httpSignatureService;
	private HttpMessageSignatureParser $messageSignatures;
	private ICache $keyAttempts;
	private LoggerInterface $logger;

	public function __construct(
		CacheActorService $cacheActorService,
		CacheActorsRequest $cacheActorsRequest,
		ConfigService $configService,
		HttpSignatureService $httpSignatureService,
		ICacheFactory $cacheFactory,
		LoggerInterface $logger,
		private DurableCache $durableCache,
		private FediverseService $fediverseService,
		private ?ITimeFactory $timeFactory = null,
	) {
		$this->cacheActorService = $cacheActorService;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->configService = $configService;
		$this->httpSignatureService = $httpSignatureService;
		$this->messageSignatures = new HttpMessageSignatureParser();
		$this->keyAttempts = $cacheFactory->createDistributed('social.keys');
		$this->logger = $logger;
	}

	/**
	 * @param Person $actor
	 */
	/**
	 * @throws SignatureException
	 */
	public function generateKeys(Person &$actor) {
		$res = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		// Unchecked, a failure here would persist an actor with an empty key
		// pair — every delivery from it fails signature checks, undiagnosably.
		if ($res === false || !openssl_pkey_export($res, $privateKey)) {
			throw new SignatureException('cannot generate a key pair: ' . openssl_error_string());
		}

		$details = openssl_pkey_get_details($res);
		if ($details === false || ($details['key'] ?? '') === '') {
			throw new SignatureException('cannot export the public key: ' . openssl_error_string());
		}

		$actor->setPublicKey($details['key']);
		$actor->setPrivateKey($privateKey);
	}

	/**
	 * The HTTP signature a queued delivery goes out with.
	 *
	 * @param string $url the URL the delivery is sent to
	 * @param string $body the bytes that go on the wire
	 *
	 * @return array<string, string> the headers to send
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SignatureException
	 * @throws SocialAppConfigException
	 */
	public function signRequest(string $url, string $body, RequestQueue $queue): array {
		return $this->httpSignatureService->signDelivery($url, $body, $queue);
	}

	/**
	 * @param IRequest $request
	 *
	 * @param string $data
	 * @param int $time
	 *
	 * @return string
	 * @throws DateTimeException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SignatureException
	 * @throws SignatureIsGoneException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function checkRequest(
		IRequest $request, string $data, int &$time = 0, string &$signer = '',
	): string {
		// RFC 9421 announces itself with Signature-Input. Without it, the
		// Signature header is a draft-cavage one and is read as it always was.
		$messageSignature = $this->selectMessageSignature($request);

		$time = $messageSignature === null
			? $this->checkDateHeader($request)
			: $this->checkMessageSignatureTime($request, $messageSignature);

		$this->checkContentLength($request, $data);
		$this->checkDigest($request, $data);

		try {
			$origin = $messageSignature === null
				? $this->checkRequestSignature($request, $data, $signer)
				: $this->checkMessageSignature($request, $data, $messageSignature, $signer);

			return $origin;
		} catch (RequestContentException $e) {
			if ($e->getCode() === Http::STATUS_GONE) {
				throw new SignatureIsGoneException();
			}

			// The signing key could not be retrieved: failing here keeps this method
			// the single place that decides whether a request is authenticated.
			throw new SignatureException(
				'signing key could not be retrieved: ' . get_class($e) . ' ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * Whether this exact signed request was already taken in: the window
	 * above is twelve hours wide, and a captured delivery (a Follow after its
	 * Undo) must not be replayed. A peer's retry signs a new `Date`. The
	 * request is remembered only once taken in (`rememberRequest()`), so bytes
	 * resent after a failure are still processed; kept in `DurableCache`, so
	 * it works without a memory cache.
	 */
	public function isReplayed(IRequest $request): bool {
		return $this->durableCache->get(self::REQUEST_SEEN_NAMESPACE, $this->requestKey($request)) !== null;
	}

	/**
	 * Records a signed request as taken in, for longer than its date (or
	 * `created`) could still be accepted.
	 */
	public function rememberRequest(IRequest $request): void {
		$this->durableCache->set(
			self::REQUEST_SEEN_NAMESPACE, $this->requestKey($request), 1,
			self::DATE_PAST + self::DATE_FUTURE + self::REPLAY_MARGIN
		);
	}

	private function requestKey(IRequest $request): string {
		return md5($request->getHeader('Signature') . "\n" . $request->getHeader('Signature-Input'));
	}

	/**
	 * The Date header, parsed and held to the replay window.
	 *
	 * @return int the request time it names
	 * @throws DateTimeException
	 * @throws SignatureException
	 */
	private function checkDateHeader(IRequest $request): int {
		$header = $request->getHeader('date');
		if ($header === '') {
			throw new SignatureException('missing date header');
		}

		$time = self::parseHttpDate($header);
		if ($time === null) {
			throw new DateTimeException('date header is not an HTTP date: ' . self::excerpt($header));
		}

		if ($time < ($this->now() - self::DATE_PAST)) {
			throw new SignatureException('object is too old');
		}

		if ($time > ($this->now() + self::DATE_FUTURE)) {
			// without an upper bound, a request stamped into the far future
			// stays replayable until that date is finally "too old"
			throw new SignatureException('object is from the future');
		}

		return $time;
	}

	/** The time a signature's date is held against. */
	private function now(): int {
		return $this->timeFactory?->getTime() ?? time();
	}

	/**
	 * The timestamp an HTTP `Date` header names, or null when it is not one.
	 * Only RFC 7231's IMF-fixdate is read (with `UTC` for `GMT` and a day
	 * without its leading zero): a free-form parse accepts `now` or `+1 hour`,
	 * which would never age out of the replay window.
	 */
	public static function parseHttpDate(string $header): ?int {
		if (preg_match('/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), (\d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2}) (?:GMT|UTC)$/', trim($header), $m) !== 1) {
			return null;
		}

		// the weekday is left out of the parse: PHP moves the date forward to
		// match a weekday that disagrees with it. `d` takes a day with or
		// without its leading zero; `!` zeroes what the format does not name
		$date = DateTime::createFromFormat('!d M Y H:i:s', $m[1], new DateTimeZone('UTC'));

		return self::wellFormed($date) ? $date->getTimestamp() : null;
	}

	/**
	 * The timestamp of a Linked Data signature's `created`, or null when it is
	 * not an RFC 3339 date-time; a relative one would never age out.
	 */
	public static function parseObjectDate(string $created): ?int {
		if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/', trim($created), $m) !== 1) {
			return null;
		}

		$date = DateTime::createFromFormat('!Y-m-d\TH:i:sP', $m[1] . $m[2]);

		return self::wellFormed($date) ? $date->getTimestamp() : null;
	}

	/**
	 * Whether `createFromFormat()` produced a date it did not have to roll
	 * over: an impossible date such as 31 Feb, or 25:00, is moved on silently
	 * with nothing but a warning to say so.
	 *
	 * @psalm-assert-if-true DateTime $date
	 */
	private static function wellFormed(DateTime|false $date): bool {
		if ($date === false) {
			return false;
		}

		$errors = DateTime::getLastErrors();

		return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
	}

	/**
	 * A value the sender wrote, made fit for an exception message that reaches
	 * the log: non-printable bytes escaped, and cut at `$max` bytes with the
	 * length left out.
	 */
	private static function excerpt(string $value, int $max = self::EXCERPT): string {
		$escaped = addcslashes($value, "\0..\37\177..\377");
		if (strlen($escaped) <= $max) {
			return $escaped;
		}

		return substr($escaped, 0, $max) . '… (' . (strlen($escaped) - $max) . ' more bytes)';
	}

	/**
	 * The RFC 9421 signature this request is verified against, or null when
	 * the request carries no Signature-Input and is a draft-cavage one. Of
	 * several labels, the first with a signature value, a keyid and an
	 * algorithm implemented here is verified, so an extra Ed25519 signature is
	 * no reason to refuse. Nothing here touches the network.
	 *
	 * @return array{label: string, components: list<array{name: string, params: array<string, mixed>}>, params: array<string, mixed>, serialized: string, signature: string}|null
	 * @throws SignatureException
	 */
	private function selectMessageSignature(IRequest $request): ?array {
		$inputHeader = $request->getHeader('Signature-Input');
		if ($inputHeader === '') {
			return null;
		}

		$inputs = $this->messageSignatures->parseSignatureInput($inputHeader);
		$signatures = $this->messageSignatures->parseSignature($request->getHeader('Signature'));

		$candidates = [];
		foreach ($inputs as $label => $input) {
			$keyId = $input['params']['keyid'] ?? '';
			if (!isset($signatures[$label]) || !is_string($keyId) || $keyId === '') {
				continue;
			}
			$candidates[] = $input + ['label' => $label, 'signature' => $signatures[$label]];
		}

		if ($candidates === []) {
			throw new SignatureException(
				'no usable signature: every label lacks a signature value or a keyid - ' . self::excerpt($inputHeader)
			);
		}

		foreach ($candidates as $candidate) {
			if ($this->messageSignatures->isSupportedAlgorithm($this->messageSignatureAlgorithm($candidate))) {
				return $candidate;
			}
		}

		// the same refusal, by name, that the draft-cavage path gives an
		// algorithm it cannot verify: an Ed25519 key has no place to live here
		throw new SignatureException(
			'unsupported signature algorithm: ' . self::excerpt($this->messageSignatureAlgorithm($candidates[0]))
		);
	}

	/**
	 * The algorithm a signature's `alg` parameter names. Absent, RFC 9421 lets
	 * the key decide — and the keys ActivityPub actors publish are RSA, for
	 * which Mastodon signs `rsa-v1_5-sha256`.
	 *
	 * @param array{params: array<string, mixed>, ...} $signature
	 */
	private function messageSignatureAlgorithm(array $signature): string {
		$algorithm = $signature['params']['alg'] ?? HttpMessageSignatureParser::ALG_RSA_V1_5_SHA256;

		return is_string($algorithm) ? strtolower($algorithm) : 'not a string';
	}

	/**
	 * The draft-cavage replay window applied to RFC 9421: a signed `created`,
	 * an optional `expires`, and the Date header when sent. Whatever is there
	 * has to hold; that `date` or `created` is covered at all is the coverage
	 * check's business.
	 *
	 * @param array{params: array<string, mixed>, ...} $signature
	 * @return int the request time: `created` when present, else the Date header
	 * @throws DateTimeException
	 * @throws SignatureException
	 */
	private function checkMessageSignatureTime(IRequest $request, array $signature): int {
		$params = $signature['params'];
		$now = $this->now();
		$time = 0;

		if ($request->getHeader('date') !== '') {
			$time = $this->checkDateHeader($request);
		}

		if (array_key_exists('created', $params)) {
			$created = $params['created'];
			if (!is_int($created)) {
				throw new SignatureException('signature created is not an integer');
			}
			if ($created < $now - self::DATE_PAST) {
				throw new SignatureException('signature created is too old');
			}
			if ($created > $now + self::DATE_FUTURE) {
				throw new SignatureException('signature created is from the future');
			}
			$time = $created;
		}

		if (array_key_exists('expires', $params)) {
			$expires = $params['expires'];
			if (!is_int($expires)) {
				throw new SignatureException('signature expires is not an integer');
			}
			if ($expires < $now) {
				throw new SignatureException('signature has expired');
			}
		}

		return $time;
	}

	/**
	 * Verifies the RFC 9421 signature chosen by selectMessageSignature(), in
	 * the draft-cavage path's order: the key's origin, the mandatory covered
	 * set, the signature base, then the key, refreshed once when it does not
	 * verify.
	 *
	 * @param array{label: string, components: list<array{name: string, params: array<string, mixed>}>, params: array<string, mixed>, serialized: string, signature: string} $signature
	 * @return string the key's origin host
	 * @throws InvalidOriginException
	 * @throws SignatureException
	 * @throws Exception anything retrieveKey() raises
	 */
	private function checkMessageSignature(
		IRequest $request, string $data, array $signature, string &$signer = '',
	): string {
		$keyId = $signature['params']['keyid'];
		$origin = $this->getKeyOrigin($keyId);
		// an instance this one does not federate with is refused before its
		// key is fetched, not after
		$this->fediverseService->authorized($origin);

		$covered = array_map(
			static fn (array $component): string => strtolower($component['name']),
			$signature['components']
		);
		$this->requireCoveredComponents($covered, $data, array_key_exists('created', $signature['params']));

		// the authority verified is this instance's own, as for `host` on the
		// draft-cavage path; a peer that signed another one is told why in the log
		$authority = array_intersect($covered, ['host', '@authority', '@target-uri']) === []
			? $this->configService->getCloudAuthority()
			: $this->signedHost($request->getHeader('host'));
		$base = $this->messageSignatures->signatureBase(
			$request, $signature['components'], $signature['serialized'], $authority
		);
		$algorithm = $this->messageSignatureAlgorithm($signature);

		$this->verifyWithKey($keyId, function (string $publicKey) use ($algorithm, $base, $signature, $keyId): void {
			if (!$this->messageSignatures->verify($algorithm, $publicKey, $base, $signature['signature'])) {
				throw new SignatureException(
					'signature cannot be checked - label: ' . self::excerpt($signature['label'])
					. ' - keyId: ' . self::excerpt($keyId) . ' - algo: ' . $algorithm
					. ' - base: ' . self::excerpt($base, self::EXCERPT_BASE)
				);
			}
		});
		$signer = $this->keyOwner($keyId);

		return $origin;
	}

	/**
	 * The RFC 9421 counterpart of the mandatory set the draft-cavage path
	 * demands: the method and the full target (or authority and path) so a
	 * captured request cannot be replayed elsewhere, the digest so it binds the
	 * body, and a time so it cannot be replayed later.
	 *
	 * @param list<string> $covered lowercased component names
	 * @throws SignatureException
	 */
	private function requireCoveredComponents(array $covered, string $data, bool $hasCreated): void {
		if (!in_array('@method', $covered, true)) {
			throw new SignatureException('component is not signed: @method');
		}

		$hasTarget = in_array('@target-uri', $covered, true)
			|| (in_array('@authority', $covered, true)
				&& (in_array('@path', $covered, true) || in_array('@request-target', $covered, true)));
		if (!$hasTarget) {
			throw new SignatureException('component is not signed: @target-uri');
		}

		if ($data !== ''
			&& !in_array('content-digest', $covered, true)
			&& !in_array('digest', $covered, true)) {
			throw new SignatureException('header is not signed: digest');
		}

		if (!$hasCreated && !in_array('date', $covered, true)) {
			throw new SignatureException('header is not signed: date');
		}
	}

	/**
	 * The host a signature is verified against: always the configured one,
	 * with its port when the cloud URL names a non-default one, so a captured
	 * request cannot be replayed against another instance. A deployment whose
	 * peers reach another host or port (a second domain, a proxy rewriting
	 * Host) fails every inbound verification.
	 */
	private function signedHost(string $sent): string {
		$configured = $this->configService->getCloudAuthority();
		if ($sent !== '' && strtolower($sent) !== strtolower($configured)) {
			$this->logger->notice(
				'the host a peer signed is not the configured host, so its signature cannot verify',
				['signedHost' => $sent, 'configuredHost' => $configured]
			);
		}

		return $configured;
	}

	/**
	 * Runs `$verify` with the key `$keyId` names, and once more with a freshly
	 * fetched copy if it does not verify, as the peer may have rotated it. A
	 * retrieval failure is not retried.
	 *
	 * @param callable(string): void $verify throws SignatureException when the key does not verify
	 * @throws SignatureException
	 * @throws Exception anything retrieveKey() raises
	 */
	private function verifyWithKey(string $keyId, callable $verify): void {
		$publicKey = $this->retrieveKey($keyId);
		try {
			$verify($publicKey);
		} catch (SignatureException $e) {
			$verify($this->retrieveKey($keyId, true));
		}
	}

	/**
	 * A declared body length has to match the body. The header is optional, as
	 * chunked transfer encoding sends none.
	 *
	 * @throws SignatureException
	 */
	private function checkContentLength(IRequest $request, string $data): void {
		$length = $request->getHeader('content-length');
		if ($length === '') {
			return;
		}

		if (strlen($data) !== (int)$length) {
			throw new SignatureException(
				'content-length does not match the body -- sent: ' . self::excerpt($length)
				. ', body: ' . strlen($data)
			);
		}
	}

	/**
	 * The body has to match a digest the sender computed over it: RFC 3230's
	 * `Digest` (`algorithm=base64`, case-insensitive, possibly a list) or
	 * RFC 9530's `Content-Digest` (`sha-256=:base64:`).
	 *
	 * At least one digest this server can compute has to be present and match;
	 * an unknown algorithm alone binds nothing. Every entry of both headers is
	 * checked, as the signature may cover only one of them.
	 *
	 * @throws SignatureException
	 */
	private function checkDigest(IRequest $request, string $data): void {
		$digests = array_merge(
			$this->parseDigestHeader($request->getHeader('digest')),
			$this->parseDigestHeader($request->getHeader('content-digest'))
		);

		if ($digests === []) {
			throw new SignatureException('no digest header');
		}

		$checked = 0;
		foreach ($digests as [$algorithm, $sent]) {
			$expected = self::DIGEST_ALGORITHMS[$algorithm] ?? '';
			if ($expected === '') {
				continue;
			}

			$checked++;
			if (!hash_equals(base64_encode(hash($expected, $data, true)), $sent)) {
				throw new SignatureException(
					'digest does not match the body -- algorithm: ' . $algorithm
					. ', sent: ' . self::excerpt($sent)
				);
			}
		}

		if ($checked === 0) {
			throw new SignatureException(
				'no digest algorithm we can compute: ' . self::excerpt(implode(', ', array_column($digests, 0)))
			);
		}
	}

	/**
	 * `algorithm=base64` entries of a Digest or Content-Digest header, as
	 * pairs of lowercased algorithm name and value, in the order sent.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function parseDigestHeader(string $header): array {
		$digests = [];
		foreach (explode(',', $header) as $entry) {
			$entry = trim($entry);
			$separator = strpos($entry, '=');
			if ($entry === '' || $separator === false || $separator === 0) {
				continue;
			}

			$algorithm = strtolower(substr($entry, 0, $separator));
			$value = substr($entry, $separator + 1);

			// RFC 9530 wraps the byte sequence in colons
			if (strlen($value) > 1 && $value[0] === ':' && substr($value, -1) === ':') {
				$value = substr($value, 1, -1);
			}

			if ($value !== '') {
				$digests[] = [$algorithm, $value];
			}
		}

		return $digests;
	}

	/**
	 * @param ACore $object
	 *
	 * @return bool
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 * @throws DateTimeException
	 * @throws UnauthorizedFediverseException
	 */
	public function checkObject(ACore $object): bool {
		try {
			$actorId = $object->getActorId();
			// this instance's own actors never reach its inbox, and their
			// signatures are copied around the network with every activity
			if ($this->isLocalActor($actorId)) {
				return false;
			}

			$signature = new LinkedDataSignature();
			$signature->import(json_decode($object->getSource(), true));
			// the key lives on the actor's host, and a host this instance does
			// not federate with is not asked for it
			$this->fediverseService->authorized($this->getKeyOrigin($actorId));
			$signature->setPublicKey($this->retrieveKey($actorId, false, true));

			if (!$signature->verify()) {
				$signature->setPublicKey($this->retrieveKey($actorId, true, true));
				if (!$signature->verify()) {
					return false;
				}
			}

			// an absent `created` is refused by the window check below
			$time = ($signature->getCreated() === '') ? 0 : self::parseObjectDate($signature->getCreated());
			if ($time === null) {
				throw new DateTimeException('signature created is not a date: ' . self::excerpt($signature->getCreated()));
			}

			// An LD signature stays valid forever on its own, so any instance
			// that ever saw the activity could re-POST it indefinitely. Bound it
			// in time and remember what was already accepted inside that window.
			if ($signature->getCreated() === '' || abs($this->now() - $time) > self::LD_WINDOW) {
				$this->logger->notice('LD signature outside its validity window', [
					'actorId' => $actorId, 'created' => $signature->getCreated(),
				]);

				return false;
			}

			// kept in `DurableCache`: in the distributed cache alone, an
			// instance with no memcache remembered nothing and accepted every
			// replay inside the window
			$seenKey = hash('sha256', (string)$signature->getSignatureBytes());
			if ($this->durableCache->get(self::LD_SEEN_NAMESPACE, $seenKey) !== null) {
				$this->logger->notice('LD signature replayed', ['actorId' => $actorId]);

				return false;
			}
			$this->durableCache->set(self::LD_SEEN_NAMESPACE, $seenKey, 1, self::LD_WINDOW * 2 + self::REPLAY_MARGIN);

			$this->getKeyOrigin($actorId);
			$object->setOrigin(
				ConfigService::authorityOf($actorId), SignatureService::ORIGIN_SIGNATURE, $time
			);

			return true;
		} catch (LinkedDataSignatureMissingException $e) {
		}

		return false;
	}

	/**
	 * @param Person $actor
	 * @param ACore $object
	 */
	public function signObject(Person $actor, ACore &$object) {
		$signature = new LinkedDataSignature();
		$signature->setPrivateKey($actor->getPrivateKey());
		$signature->setType('RsaSignature2017');
		$signature->setCreator($actor->getId() . '#main-key');
		$signature->setCreated($date = gmdate(self::DATE_OBJECT));
		$signature->setObject(json_decode(json_encode($object), true));

		try {
			$signature->sign();
			$object->setSignature($signature);
		} catch (Exception $e) {
		}
	}

	/**
	 * @param IRequest $request
	 *
	 * @param string $data
	 *
	 * @return string
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 * @throws SignatureException
	 */
	/**
	 * Who signed a GET, or '' for a request that carried no signature: the
	 * inbound half of authorized fetch, so followers-only objects can be
	 * served to a remote follower.
	 *
	 * The POST path's checks except the digest and content length, as a GET
	 * has no body. An unsigned request is the ordinary case and gets what an
	 * anonymous reader gets; a signature that is present and wrong is an error.
	 *
	 * @param string $signer set to the actor whose key signed, when one did
	 *
	 * @return string the instance the signature came from, or ''
	 *
	 * @throws SignatureException the signature is there and does not verify
	 * @throws DateTimeException
	 * @throws SignatureIsGoneException
	 */
	public function checkGetRequest(IRequest $request, string &$signer = ''): string {
		if ($request->getHeader('Signature') === ''
			&& $request->getHeader('Signature-Input') === '') {
			return '';
		}

		$messageSignature = $this->selectMessageSignature($request);

		if ($messageSignature === null) {
			$this->checkDateHeader($request);
		} else {
			$this->checkMessageSignatureTime($request, $messageSignature);
		}

		try {
			return $messageSignature === null
				? $this->checkRequestSignature($request, '', $signer, false)
				: $this->checkMessageSignature($request, '', $messageSignature, $signer);
		} catch (RequestContentException $e) {
			if ($e->getCode() === Http::STATUS_GONE) {
				throw new SignatureIsGoneException();
			}

			throw new SignatureException(
				'signing key could not be retrieved: ' . get_class($e) . ' ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * @param bool $requireDigest whether the signed set must cover a digest.
	 *                            False for a GET, which has no body for one to
	 *                            be computed over.
	 */
	private function checkRequestSignature(
		IRequest $request, string $data, string &$signer = '', bool $requireDigest = true,
	): string {
		$signatureHeader = $request->getHeader('Signature');

		$sign = $this->parseSignatureHeader($signatureHeader);

		$this->mustContains(['keyId', 'headers', 'signature'], $sign);

		$keyId = $sign['keyId'];
		$origin = $this->getKeyOrigin($keyId);
		// an instance this one does not federate with is refused before its
		// key is fetched, not after
		$this->fediverseService->authorized($origin);

		$headers = $sign['headers'];

		// The digest is checked against the body earlier, but that binds nothing unless
		// the digest itself is signed; and without host and date in the signed set, a
		// captured request can be replayed against another instance or with a swapped
		// body. Everything sending to the Fediverse signs at least these four.
		$signedHeaders = explode(' ', strtolower($headers));
		foreach (['(request-target)', 'host', 'date'] as $mandatory) {
			if (!in_array($mandatory, $signedHeaders, true)) {
				throw new SignatureException('header is not signed: ' . $mandatory);
			}
		}

		// whichever of the two digest headers the sender used, one of them has
		// to be inside the signature or the digest binds nothing
		if ($requireDigest
			&& !in_array('digest', $signedHeaders, true)
			&& !in_array('content-digest', $signedHeaders, true)) {
			throw new SignatureException('header is not signed: digest');
		}
		$signed = base64_decode($sign['signature'], true);
		if ($signed === false) {
			throw new SignatureException('signature is not base64');
		}
		$estimated = $this->generateEstimatedSignature($headers, $request);

		$this->verifyWithKey($keyId, function (string $publicKey) use ($sign, $estimated, $signed): void {
			$this->checkRequestSignatureUsingPublicKey($publicKey, $sign, $estimated, $signed);
		});
		$signer = $this->keyOwner($keyId);

		return $origin;
	}

	/**
	 * @param string $publicKey
	 * @param array $sign
	 * @param string $estimated
	 * @param string $signed
	 *
	 * @throws SignatureException
	 */
	private function checkRequestSignatureUsingPublicKey(
		string $publicKey, array $sign, string $estimated, string $signed,
	) {
		$algorithm = $this->getAlgorithmFromSignature($sign);
		if ($publicKey === ''
			|| openssl_verify($estimated, $signed, $publicKey, $algorithm) !== 1) {
			throw new SignatureException(
				'signature cannot be checked - keyId: ' . self::excerpt((string)($sign['keyId'] ?? ''))
				. ($publicKey === '' ? ' - the actor publishes no key' : '')
				. ' - algo: ' . $algorithm . ' - estimated: ' . self::excerpt($estimated, self::EXCERPT_BASE)
			);
		}
	}

	/**
	 * @param string $headers
	 * @param IRequest $request
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 * @throws SignatureException
	 */
	private function generateEstimatedSignature(string $headers, IRequest $request): string {
		$keys = explode(' ', $headers);

		if (!empty(array_diff(['(request-target)', 'date'], $keys))) {
			throw new SignatureException('missing elements in \'headers\'');
		}

		$target = '';
		try {
			$target = strtolower($request->getMethod()) . ' ' . $request->getRequestUri();
		} catch (Exception $e) {
		}

		$estimated = '';

		foreach ($keys as $key) {
			if ($key === '(request-target)') {
				$estimated .= '(request-target): ' . $target . "\n";
				continue;
			}

			$value = $request->getHeader($key);
			if ($key === 'host') {
				$value = $this->signedHost($value);
			}

			$estimated .= $key . ': ' . $value . "\n";
		}

		return trim($estimated, "\n");
	}

	/**
	 * @param $signatureHeader
	 *
	 * @return array
	 */
	private function parseSignatureHeader(string $signatureHeader) {
		$sign = [];

		$entries = explode(',', $signatureHeader);
		foreach ($entries as $entry) {
			if ($entry === '' || !strpos($entry, '=')) {
				continue;
			}

			[$k, $v] = explode('=', $entry, 2);
			preg_match('/"([^"]+)"/', $v, $varr);
			if (isset($varr[0])) {
				$v = trim($varr[0], '"');
			}
			$sign[$k] = $v;
		}

		return $sign;
	}

	/**
	 * @param string $keyId
	 *
	 * @param bool $refresh
	 *
	 * @return string
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	/**
	 * The public key a keyId names, fetched only when there is no other way:
	 * this runs before authentication, with a keyId anyone may write. A cached
	 * key is read from the database; a keyId that never resolved is not tried
	 * again for a while; a fetch or forced refresh is bounded in time and in
	 * how often the same keyId may ask.
	 *
	 * @param bool $isActorId `$keyId` is the actor's own id, as an LD signature's check passes it
	 * @throws SignatureException
	 * @throws Exception
	 */
	private function retrieveKey(string $keyId, bool $refresh = false, bool $isActorId = false): string {
		$id = $this->keyIdWithoutAnchor($keyId);
		$holder = $isActorId ? '' : $this->knownKeyHolder($keyId);

		if (!$refresh) {
			try {
				return $this->cacheActorsRequest->getFromId($holder !== '' ? $holder : $id)
					->getPublicKey();
			} catch (CacheActorDoesNotExistException $e) {
				// not known yet: fall through to the bounded fetch
			}
		}

		$attemptKey = ($refresh ? 'refresh.' : 'resolve.') . hash('sha256', $id);
		if ($this->keyAttempts->get($attemptKey) !== null) {
			// temporary by construction: the caller has to answer something that
			// asks the peer to deliver this again rather than to give up on it
			throw new SignatureException(
				'key retrieval for ' . self::excerpt($id) . ' was attempted too recently',
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		// while the fetch is in flight the entry stands for the fetch itself, so
		// a burst of deliveries naming the same unknown keyId costs one of them
		// and not one each. What it means afterwards depends on how it ended.
		$this->keyAttempts->set(
			$attemptKey,
			1,
			$refresh ? self::KEY_REFRESH_INTERVAL : self::UNKNOWN_KEY_TIMEOUT * 2
		);

		// bounded: an unauthenticated caller must not be able to decide how long
		// one of this instance's workers stays busy.
		try {
			$actor = $this->configService->withRequestTimeout(
				self::UNKNOWN_KEY_TIMEOUT,
				fn (): Person => match (true) {
					$holder !== '' => $this->cacheActorService->getFromId($holder, $refresh),
					$isActorId || str_contains($keyId, '#') => $this->cacheActorService->getFromId($id, $refresh),
					default => $this->cacheActorService->getFromKeyId($keyId, $refresh),
				},
				self::UNKNOWN_KEY_CONNECT_TIMEOUT
			);
		} catch (RequestNetworkException|RequestServerException $e) {
			$this->rememberFailedAttempt($attemptKey, self::KEY_UNREACHABLE_TTL, $refresh);

			throw $e;
		} catch (Exception $e) {
			// the peer answered, and the answer was not a usable actor: that is
			// a fact about the keyId, and worth remembering for a while
			$this->rememberFailedAttempt($attemptKey, self::KEY_FAILURE_TTL, $refresh);

			throw $e;
		}

		if ($actor->getId() !== $id) {
			$this->durableCache->set(self::KEY_HOLDER_NAMESPACE, $keyId, $actor->getId(), self::KEY_HOLDER_TTL);
		}

		if (!$refresh) {
			// resolved and stored, so the read above answers from now on; drop the
			// entry so a peer whose first delivery raced a slow answer is not held off
			$this->keyAttempts->remove($attemptKey);
		}

		return $actor->getPublicKey();
	}

	/**
	 * The actor a fragment-less keyId was found to belong to, or ''.
	 */
	private function knownKeyHolder(string $keyId): string {
		if (str_contains($keyId, '#')) {
			return '';
		}
		$holder = $this->durableCache->get(self::KEY_HOLDER_NAMESPACE, $keyId);

		return is_string($holder) ? $holder : '';
	}

	/**
	 * How long the next request naming this keyId is refused without touching
	 * the network.
	 *
	 * A forced refresh keeps the interval it was given: that entry is a ceiling
	 * on how often the same key may be re-fetched, not a record of a failure.
	 */
	private function rememberFailedAttempt(string $attemptKey, int $ttl, bool $refresh): void {
		if (!$refresh) {
			$this->keyAttempts->set($attemptKey, 1, $ttl);
		}
	}

	/**
	 * A keyId is an actor id with a fragment (`…/users/bob#main-key`); the
	 * fragment is not part of what is fetched or stored, and leaving it on would
	 * make every fragment a separate cache entry.
	 */
	private function keyIdWithoutAnchor(string $keyId): string {
		$posAnchor = strpos($keyId, '#');

		return $posAnchor === false ? $keyId : substr($keyId, 0, $posAnchor);
	}

	/**
	 * @param $id
	 *
	 * @return string
	 * @throws InvalidOriginException
	 */
	/**
	 * The actor a key belongs to: a keyId is that actor's id with the key named
	 * in the fragment (`…/users/alice#main-key`), or a URL of its own whose
	 * owner `retrieveKey()` found out (GoToSocial's `…/users/alice/main-key`).
	 */
	private function keyOwner(string $keyId): string {
		$holder = $this->knownKeyHolder($keyId);
		if ($holder !== '') {
			return $holder;
		}
		$owner = strtok($keyId, '#');

		return ($owner === false) ? '' : rtrim($owner, '/');
	}

	/**
	 * An activity may only speak for the actor whose key signed it, as in
	 * Mastodon: a host check alone lets anyone on a server act as anybody else
	 * there. A relayed or forwarded activity is covered by its Linked Data
	 * signature, which the caller checks first.
	 *
	 * @throws InvalidOriginException
	 */
	public function assertSignerSpeaksFor(string $signer, ACore $activity): void {
		$actor = rtrim($activity->getActorId(), '/');
		if ($actor === '' || $signer === '') {
			throw new InvalidOriginException('an activity with no actor cannot be attributed to a signer');
		}

		if (strtolower($signer) !== strtolower($actor)) {
			throw new InvalidOriginException(
				'the key that signed this request belongs to ' . self::excerpt($signer) . ', not to ' . self::excerpt($actor)
			);
		}
	}

	/**
	 * Refuses an inbound activity that names one of this instance's own
	 * actors: delivery never posts to this instance (ActivityService::isOurs),
	 * so such an activity can only be a forgery or a reflection.
	 *
	 * @throws InvalidOriginException
	 */
	public function assertRemoteActor(ACore $activity): void {
		if ($this->isLocalActor($activity->getActorId())) {
			throw new InvalidOriginException(
				'an inbound activity cannot act for a local actor: ' . self::excerpt($activity->getActorId())
			);
		}
	}

	/**
	 * Whether an actor id is on this instance: its `host[:port]` is the one of
	 * the cloud address or of the social URL, the two addresses local ids can
	 * carry.
	 */
	public function isLocalActor(string $actorId): bool {
		$authority = strtolower(ConfigService::authorityOf($actorId));
		if ($authority === '') {
			return false;
		}

		$local = [];
		try {
			$local[] = $this->configService->getCloudAuthority();
		} catch (SocialAppConfigException $e) {
		}
		try {
			$local[] = ConfigService::authorityOf($this->configService->getSocialUrl());
		} catch (SocialAppConfigException $e) {
		}

		foreach ($local as $candidate) {
			if ($candidate !== '' && strtolower($candidate) === $authority) {
				return true;
			}
		}

		return false;
	}

	private function getKeyOrigin(string $id) {
		$host = parse_url($id, PHP_URL_HOST);
		if (is_string($host) && ($host !== '')) {
			return $host;
		}

		throw new InvalidOriginException(
			'SignatureService::getKeyOrigin - id: ' . self::excerpt($id)
		);
	}

	/**
	 * @param array $sign
	 *
	 * @return string
	 */
	/**
	 * The hash a signature says it was computed with. `hs2019` and an absent
	 * parameter mean SHA-256 for the RSA keys ActivityPub actors publish;
	 * anything else is refused by name rather than failing as `rsa-sha256`.
	 *
	 * @throws SignatureException
	 */
	private function getAlgorithmFromSignature(array $sign): string {
		$algorithm = strtolower($this->get('algorithm', $sign, ''));
		switch ($algorithm) {
			case 'rsa-sha512':
				return 'sha512';
			case '':
			case 'hs2019':
			case 'rsa-sha256':
				return 'sha256';
			default:
				throw new SignatureException(
					'unsupported signature algorithm: ' . self::excerpt($algorithm)
				);
		}
	}

	/** Shipped copies of the only JSON-LD contexts signature normalisation may use. */
	public const LOCAL_CONTEXTS = [
		'https://www.w3.org/ns/activitystreams' => 'www.w3.org.ns.activitystreams.json',
		'https://w3id.org/security/v1' => 'w3id.org.security.v1.json',
		'https://w3id.org/identity/v1' => 'w3id.org.identity.v1.json',
	];

	/**
	 * Serves the JSON-LD contexts used during signature normalisation only from
	 * the copies shipped with the app: a remote `@context` would make this
	 * server open URLs a sender chose and could change the signed bytes. An
	 * unknown context fails normalisation, treated as an unverifiable signature.
	 *
	 * @throws JsonLdException
	 */
	public static function documentLoader($url): stdClass {
		$filename = self::LOCAL_CONTEXTS[$url] ?? '';
		if ($filename === '') {
			throw new JsonLdException('remote @context is not resolved: ' . $url, 'jsonld.LoadDocumentError');
		}

		$context = file_get_contents(__DIR__ . '/../../context/' . $filename);
		if (is_bool($context)) {
			throw new JsonLdException('shipped context cannot be read: ' . $filename, 'jsonld.LoadDocumentError');
		}

		return json_decode($context);
	}
}
