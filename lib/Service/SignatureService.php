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
	 * how far ahead of this server's clock. Mastodon's own bounds: a peer
	 * whose clock is six minutes off, or whose queue delivers a request it
	 * signed a while ago, used to lose every delivery here with a final 401.
	 * A request inside the window is taken in once — see `isReplayed()`.
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

	/** The `DurableCache` namespace of the LD signatures already accepted. */
	public const LD_SEEN_NAMESPACE = 'social.ldsig';

	/** The `DurableCache` namespace of the signed requests already taken in. */
	public const REQUEST_SEEN_NAMESPACE = 'social.httpsig';

	/**
	 * How long a request is allowed to take when it is fetching the signing key
	 * of a keyId this instance has never seen.
	 *
	 * Everything checked before that fetch is attacker-controlled and free to
	 * satisfy, so an unauthenticated POST to the inbox can name any URL and hold
	 * a PHP worker for as long as that URL takes to answer. At the default
	 * federation timeout a few dozen concurrent requests exhaust the worker pool
	 * and the whole Nextcloud instance stops answering — so pre-authentication
	 * work gets a fraction of it. A key this instance already knows is read from
	 * the database and needs no fetch at all.
	 *
	 * Three seconds was not that fraction, it was less than the work takes: DNS,
	 * TCP, TLS and the peer rendering its actor JSON all have to fit inside it
	 * on first contact, which a small self-hosted instance does not manage —
	 * and the failure then poisoned the negative cache below, so a follow to a
	 * slow-but-honest peer never completed at all. These match what Mastodon
	 * allows a peer of its own. Note `CurlService::retrieveObject()` retries
	 * unsigned after a 401/403, so a worker can be held for roughly twice the
	 * read budget.
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
	 * How long a key retrieval that never got an answer — the host was
	 * unreachable, or slower than the budget above — is remembered.
	 *
	 * Much shorter, because it is not a fact about the keyId: it says the peer
	 * was having a bad minute, and holding that against it for five would keep
	 * an instance that is merely slow permanently unable to federate here. Long
	 * enough that repeating the same unreachable keyId still costs far fewer
	 * fetches than requests.
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

			// The signing key could not be retrieved. Failing here, rather than
			// returning an empty origin for a later check to reject, keeps this method
			// the single place that decides whether a request is authenticated.
			throw new SignatureException(
				'signing key could not be retrieved: ' . get_class($e) . ' ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * Whether this exact signed request was already taken in.
	 *
	 * A signature is only as fresh as its `Date`, and the window above is
	 * twelve hours wide: without this, a captured delivery could be sent
	 * again for that long — a Follow after its Undo, say. A peer's own retry
	 * signs again with a new `Date`, so it is never mistaken for a repeat.
	 * The request is remembered only once it has been taken in
	 * (`rememberRequest()`), so identical bytes resent after a failure are
	 * still processed. Kept in `DurableCache`, so the guard holds on an
	 * instance with no memory cache too.
	 */
	public function isReplayed(IRequest $request): bool {
		return $this->durableCache->get(self::REQUEST_SEEN_NAMESPACE, $this->requestKey($request)) !== null;
	}

	/**
	 * Records a signed request as taken in, for as long as its date would
	 * still be accepted.
	 */
	public function rememberRequest(IRequest $request): void {
		$this->durableCache->set(self::REQUEST_SEEN_NAMESPACE, $this->requestKey($request), 1, self::DATE_PAST + self::DATE_FUTURE);
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

		if ($time < (time() - self::DATE_PAST)) {
			throw new SignatureException('object is too old');
		}

		if ($time > (time() + self::DATE_FUTURE)) {
			// without an upper bound, a request stamped into the far future
			// stays replayable until that date is finally "too old"
			throw new SignatureException('object is from the future');
		}

		return $time;
	}

	/**
	 * The timestamp an HTTP `Date` header names, or null when it is not one.
	 *
	 * Only RFC 7231's IMF-fixdate (`Sun, 06 Nov 1994 08:49:37 GMT`) is read,
	 * which is what Mastodon, Pixelfed, PeerTube, Misskey, GoToSocial and
	 * this app send, with `UTC` taken for `GMT` and a day without its leading
	 * zero. A free-form parse such as `new DateTime()` accepts `now` or
	 * `+1 hour`, and a request signed over a relative date never ages out of
	 * the replay window.
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
	 * not an RFC 3339 date-time.
	 *
	 * Held to the same standard as the `Date` header, for the same reason: a
	 * relative `created` would keep a signature inside its window for good.
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
	 * A value the sender wrote, made fit for an exception message: control
	 * characters and bytes outside printable ASCII escaped, and cut at `$max`
	 * bytes with the length that was left out. These messages reach the log,
	 * and what goes into them is chosen by whoever sent the request.
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
	 * the request carries no Signature-Input and is a draft-cavage one.
	 *
	 * A sender may sign under several labels. One is enough: the first whose
	 * signature value is present, whose keyid names a key, and whose algorithm
	 * this instance implements is the one verified — so a peer that also signs
	 * with an Ed25519 key is not refused for it. Nothing here touches the
	 * network; the key is fetched once, later, for the label chosen.
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
	 * The replay window of the draft-cavage path, applied to what RFC 9421
	 * carries: a `created` parameter inside the signed set, an optional
	 * `expires`, and a Date header that a 9421 sender may or may not send.
	 * Whatever is there has to hold; that one of `date` and `created` is there
	 * at all is the coverage check's business.
	 *
	 * @param array{params: array<string, mixed>, ...} $signature
	 * @return int the request time: `created` when present, else the Date header
	 * @throws DateTimeException
	 * @throws SignatureException
	 */
	private function checkMessageSignatureTime(IRequest $request, array $signature): int {
		$params = $signature['params'];
		$now = time();
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
	 * Verifies the RFC 9421 signature chosen by selectMessageSignature().
	 *
	 * Same steps as the draft-cavage path, in the same order: the key's
	 * origin, the mandatory covered set, the signature base, then the key —
	 * fetched through the same bounded retrieval and refreshed once when the
	 * signature does not verify against it.
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
		$signer = $this->keyOwner($keyId);
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
	 * with its port when the cloud URL names a non-default one.
	 *
	 * The signed host is what the sender addressed; substituting the
	 * configured one is what stops a captured request being replayed
	 * against another instance. But on a deployment whose configured host is
	 * not the one peers reach (a second domain, a non-default port, a proxy
	 * that rewrites Host), that substitution makes *every* inbound delivery
	 * fail verification — with nothing in the log to say why.
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
	 * fetched copy if the first does not verify.
	 *
	 * A retrieval failure is not a reason to retrieve again: only a key that
	 * was fetched and did not verify is worth refreshing, because only then
	 * might the peer have rotated it. Retrying on any failure meant a keyId
	 * that cannot be resolved cost two fetches per request instead of none.
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
	 * A declared body length has to match the body.
	 *
	 * The header is optional, though: a sender using chunked transfer encoding
	 * sends no Content-Length at all, and comparing the body against an absent
	 * header's `(int)''` rejected every one of them.
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
	 * The body has to match a digest the sender computed over it.
	 *
	 * What is on the wire is wider than one fixed string. RFC 3230's `Digest`
	 * carries `algorithm=base64`, with an algorithm name that is
	 * case-insensitive and a list that may hold several entries; RFC 9530's
	 * `Content-Digest` carries `sha-256=:base64:` and is what newer
	 * implementations are moving to. Comparing bytes against exactly
	 * `SHA-256=…` refused `sha-256=…`, refused
	 * `Digest: SHA-256=…,SHA-512=…`, and refused a sender that offers only
	 * `Content-Digest` — in every case reading as a forged body.
	 *
	 * At least one digest this server knows how to compute has to be present
	 * and has to match: an unrecognised algorithm on its own is not a pass,
	 * because then nothing binds the body to the signature.
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
		foreach ($digests as $algorithm => $sent) {
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
				'no digest algorithm we can compute: ' . self::excerpt(implode(', ', array_keys($digests)))
			);
		}
	}

	/**
	 * `algorithm=base64` entries of a Digest or Content-Digest header, keyed by
	 * lowercased algorithm name.
	 *
	 * @return array<string, string>
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
				$digests[$algorithm] = $value;
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

			$signature = new LinkedDataSignature();
			$signature->import(json_decode($object->getSource(), true));
			// the key lives on the actor's host, and a host this instance does
			// not federate with is not asked for it
			$this->fediverseService->authorized($this->getKeyOrigin($actorId));
			$signature->setPublicKey($this->retrieveKey($actorId));

			if (!$signature->verify()) {
				$signature->setPublicKey($this->retrieveKey($actorId, true));
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
			if ($signature->getCreated() === '' || abs(time() - $time) > self::LD_WINDOW) {
				$this->logger->notice('LD signature outside its validity window', [
					'actorId' => $actorId, 'created' => $signature->getCreated(),
				]);

				return false;
			}

			// kept in `DurableCache`: in the distributed cache alone, an
			// instance with no memcache remembered nothing and accepted every
			// replay inside the window
			$seenKey = hash('sha256', $signature->getSignatureValue());
			if ($this->durableCache->get(self::LD_SEEN_NAMESPACE, $seenKey) !== null) {
				$this->logger->notice('LD signature replayed', ['actorId' => $actorId]);

				return false;
			}
			$this->durableCache->set(self::LD_SEEN_NAMESPACE, $seenKey, 1, self::LD_WINDOW * 2);

			$object->setOrigin(
				$this->getKeyOrigin($actorId), SignatureService::ORIGIN_SIGNATURE, $time
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
	 * Who signed a **GET**, or '' for a request that carried no signature.
	 *
	 * Authorized fetch, the inbound half. Signature verification ran on inbox
	 * POSTs only, so this instance could not tell one remote reader from
	 * another and had nothing to serve a followers-only object to — it failed
	 * closed, which is safe and is also why a follower on another server saw
	 * an empty profile.
	 *
	 * A GET has no body, so the two checks that bind one — the digest and the
	 * content length — have nothing to bind and are not asked for. Everything
	 * else is the POST path's: the signature has to cover `(request-target)`,
	 * `host` and `date`, the date has to be inside the replay window, and the
	 * key is fetched from the actor the `keyId` names.
	 *
	 * An unsigned request is not an error here. It is the ordinary case —
	 * every crawler, every link preview, every fediverse server not running
	 * authorized fetch — and what it gets is what an anonymous reader gets.
	 * A signature that is *present and wrong* is an error, because the sender
	 * is claiming to be somebody.
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
		$signer = $this->keyOwner($keyId);
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
	 * The public key a keyId names, fetching it only when there is no other way
	 * and never for free.
	 *
	 * This runs before the request is authenticated, on a route anyone on the
	 * internet can POST to, with a keyId the sender wrote. Three things bound
	 * what that can cost:
	 *
	 *  - a key already in the actor cache is read from the database, untouched
	 *    by any of the limits below;
	 *  - a keyId that has never resolved is not retried for a while, so
	 *    repeating the same unresolvable id costs one fetch, not one per
	 *    request;
	 *  - the fetch itself, and a forced refresh, are bounded — in time and in
	 *    how often the same keyId may ask for one.
	 *
	 * @throws SignatureException
	 * @throws Exception
	 */
	private function retrieveKey(string $keyId, bool $refresh = false): string {
		$id = $this->keyIdWithoutAnchor($keyId);

		if (!$refresh) {
			try {
				return $this->cacheActorsRequest->getFromId($id)
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
				fn (): Person => $this->cacheActorService->getFromId($id, $refresh),
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

		if (!$refresh) {
			// resolved and stored, so the read above will answer from now on;
			// drop the entry rather than hold off a genuine peer whose first
			// delivery merely raced a slow answer
			$this->keyAttempts->remove($attemptKey);
		}

		return $actor->getPublicKey();
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
	 * in the fragment (`…/users/alice#main-key`).
	 */
	private function keyOwner(string $keyId): string {
		$owner = strtok($keyId, '#');

		return ($owner === false) ? '' : rtrim($owner, '/');
	}

	/**
	 * An activity may only speak for the actor whose key signed it.
	 *
	 * Checking the host alone lets anyone with an account on a server act as
	 * anybody else on that server: same origin, different person. The one
	 * legitimate case for a mismatch is a relayed or forwarded activity, and
	 * that is what the Linked Data signature on the object is for — the caller
	 * checks it first and only asks this when there was none. Mastodon draws
	 * the line in the same place.
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
	 * The hash a signature says it was computed with.
	 *
	 * `hs2019` deliberately names no hash: the key type decides, and for the
	 * RSA keys ActivityPub actors publish that is SHA-256 — so it maps there,
	 * and so does an absent parameter (the draft's own default). Anything else
	 * is refused rather than silently treated as SHA-256: an Ed25519 or ECDSA
	 * signature verified as `rsa-sha256` fails with "signature cannot be
	 * checked", which says nothing about what actually happened.
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
	 * Serves the JSON-LD contexts used during signature normalisation, exclusively
	 * from the copies shipped with the app.
	 *
	 * A document's `@context` is remote input. Resolving it over the network would
	 * hand every signing instance a URL this server then opens — with
	 * `file_get_contents()`, that means any PHP stream wrapper — and a substituted
	 * context would change the bytes a signature is computed over. An unknown context
	 * therefore fails normalisation, which callers treat as an unverifiable
	 * signature rather than an error.
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
