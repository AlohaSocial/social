<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use GuzzleHttp\Psr7\DroppingStream;
use GuzzleHttp\Psr7\Utils;
use OCA\Social\AP;
use OCA\Social\Exceptions\HostMetaException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Security\AsyncRequestSigner;
use OCA\Social\Security\RemoteAddress;
use OCA\Social\Tools\Exceptions\ArrayNotFoundException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Http\BoundedSink;
use OCA\Social\Tools\Http\FetchedResponse;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TPathTools;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Outbound HTTP for federation, over the server's own client
 * (`OCP\Http\Client\IClientService`) with its CA bundle, proxy and
 * local-address checks. What is here is federation-specific: the WebFinger
 * protocol fallback, ActivityPub content negotiation, the signed fetch and
 * its unsigned retry, the download ceiling, and this app's request
 * exceptions.
 */
class CurlService {
	use TArrayTools;
	use TPathTools;

	public const ASYNC_REQUEST_TOKEN = '/async/request/{token}';
	public const USER_AGENT = 'Aloha Social';

	/** As many hops as the server's own HTTP client would have followed. */
	private const MAX_REDIRECTS = 5;

	private const REDIRECT_STATUSES = [
		Http::STATUS_MOVED_PERMANENTLY,
		Http::STATUS_FOUND,
		Http::STATUS_SEE_OTHER,
		Http::STATUS_TEMPORARY_REDIRECT,
		308,
	];

	private int $maxDownloadSize;

	public function __construct(
		private ConfigService $configService,
		private FediverseService $fediverseService,
		private IClientService $clientService,
		private HttpSignatureService $httpSignatureService,
		private LoggerInterface $logger,
		private AsyncRequestSigner $asyncRequestSigner,
	) {
		$this->maxDownloadSize = $this->configService->getAppValue(ConfigService::SOCIAL_MAX_SIZE) * 1048576;
	}

	/**
	 * @return array the JRD document
	 *
	 * @throws InvalidResourceException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function webfingerAccount(string &$account): array {
		$this->logger->debug('[CurlService] webfinger', ['account' => $account]);
		$account = $this->withoutBeginAt($account);

		$exploded = explode('@', $account);

		if (count($exploded) < 2) {
			throw new InvalidResourceException();
		}
		[, $host] = $exploded;
		// hostMeta() rewrites $host to whatever the host-meta names, so the
		// host that was asked is kept here
		$queried = $host;

		$protocols = ['https', 'http'];
		try {
			$path = $this->hostMeta($host, $protocols);
		} catch (HostMetaException $e) {
			$path = '/.well-known/webfinger';
		}

		$urls = $this->candidateUrls($protocols, $host, $path, ['resource' => 'acct:' . $account]);
		$result = $this->retrieveJsonFromFirstReachable($urls, ['json_headers' => false]);

		$this->logger->notice('webfingerAccount, request result', ['urls' => $urls]);

		// The JRD subject, when it is an acct: URI, names the handle's canonical
		// spelling, and may only name a handle on the host that was asked, or
		// `bob@evil.example` could claim to be `Gargron@mastodon.social`.
		$subject = $this->get('subject', $result, '');
		if (str_starts_with($subject, 'acct:')) {
			$claimed = substr($subject, strlen('acct:'));
			if ($this->handleHost($claimed) === strtolower($queried)) {
				$account = $claimed;
			} else {
				$this->logger->notice('a webfinger answer claims a handle on another host', [
					'queried' => $account, 'subject' => $subject,
				]);
			}
		}

		return $result;
	}

	/** The host half of a `name@host` handle, lowercased, or `''`. */
	private function handleHost(string $account): string {
		$at = strrpos($account, '@');
		if ($at === false || $at === 0 || $at === strlen($account) - 1) {
			return '';
		}

		return strtolower(substr($account, $at + 1));
	}

	/**
	 * Follows the host's own `.well-known/host-meta` to the WebFinger endpoint
	 * it names, rewriting $host and $protocols to point at it.
	 *
	 * @param string[] $protocols
	 *
	 * @return string the WebFinger path
	 *
	 * @throws HostMetaException
	 */
	public function hostMeta(string &$host, array &$protocols): string {
		$this->logger->debug('hostMeta', ['host' => $host, 'protocols' => $protocols]);

		$urls = $this->candidateUrls($protocols, $host, '/.well-known/host-meta');
		try {
			$result = $this->retrieveJsonFromFirstReachable($urls, ['json_headers' => false]);
		} catch (Exception $e) {
			$this->logger->notice('during hostMeta', ['urls' => $urls, 'exception' => $e]);

			throw new HostMetaException(get_class($e) . ' - ' . $e->getMessage());
		}

		$url = $this->get('Link.@attributes.template', $result, '');
		if ($url === '') {
			throw new HostMetaException('Failed to get URL');
		}
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (!self::isHttpScheme($scheme)) {
			throw new HostMetaException('Template is not an http(s) URL');
		}

		// parse_url() answers false or null for the parts it cannot find
		$host = parse_url($url, PHP_URL_HOST) ?: '';
		$protocols = [$scheme];

		return parse_url($url, PHP_URL_PATH) ?: '';
	}

	/**
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RetrieveAccountFormatException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveAccount(string &$account): Person {
		$this->logger->debug('[CurlService] retrieving an actor', ['account' => $account]);
		$result = $this->webfingerAccount($account);

		try {
			$link = $this->extractArray('rel', 'self', $this->getArray('links', $result));
		} catch (ArrayNotFoundException $e) {
			throw new RetrieveAccountFormatException();
		}

		$id = $this->get('href', $link, '');
		$data = $this->retrieveObject($id);

		$this->logger->debug(
			'retrieveAccount, details', ['link' => $link, 'data' => $data, 'account' => $account]
		);

		/** @var Person $actor */
		$actor = AP::instance()->getItemFromData($data);
		if (!AP::instance()->isActor($actor)) {
			throw new ItemUnknownException(json_encode($actor) . ' is not an Actor');
		}

		if (strtolower($actor->getId()) !== strtolower($id)) {
			throw new InvalidOriginException(
				'CurlService::retrieveAccount - id: ' . $id . ' - actorId: ' . $actor->getId()
			);
		}

		return $actor;
	}

	/**
	 * Fetches an ActivityPub document by its id, requested exactly as written,
	 * since it is the peer's own URL and what the signature covers.
	 *
	 * @throws MalformedArrayException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveObject(string $id, bool $acceptActivityJson = true, ?int $timeout = null): array {
		$this->logger->debug('retrieveObject id=' . $id);

		$parsed = parse_url($id);
		$this->mustContains(['path', 'host', 'scheme'], is_array($parsed) ? $parsed : []);

		$headers = $acceptActivityJson ? ['Accept' => 'application/activity+json'] : [];

		// An ActivityPub fetch is signed: peers with Mastodon's AUTHORIZED_FETCH
		// or GoToSocial's secure mode answer 401 to an unsigned one.
		$signature = $acceptActivityJson ? $this->httpSignatureService->signFetch($id) : [];

		$status = 0;
		$contentType = '';
		try {
			$options = ['headers' => $headers + $signature, 'same_host' => true];
			if ($timeout !== null) {
				$options['timeout'] = max(1, $timeout);
			}
			$result = $this->retrieveJson('get', $id, $options, $status, $contentType);
		} catch (RequestContentException $e) {
			if ($signature === [] || !$this->refusedTheSignature($e->getCode())) {
				throw $e;
			}

			// Some peers reject the signature (an unresolvable keyId, a strict
			// reverse proxy), so one unsigned retry follows a refused signed fetch.
			$this->logger->debug('a signed fetch was refused, retrying unsigned', [
				'id' => $id, 'status' => $e->getCode(),
			]);

			$options = ['headers' => $headers, 'same_host' => true];
			if ($timeout !== null) {
				$options['timeout'] = max(1, $timeout);
			}
			$result = $this->retrieveJson('get', $id, $options, $status, $contentType);
		}

		$result['_host'] = $parsed['host'];
		$result['_resultCode'] = $status;
		$result['_contentType'] = $contentType;

		return $result;
	}

	/**
	 * Whether a document was served as ActivityPub. Without the media type check
	 * any JSON URL on a host (a public share, a paste) could claim to be one of
	 * its actors. `application/ld+json` counts only with the ActivityStreams
	 * profile. Static, so a mocked CurlService still answers it.
	 */
	public static function isActivityPubMediaType(string $contentType): bool {
		$type = strtolower(trim(explode(';', $contentType, 2)[0]));
		if ($type === 'application/activity+json') {
			return true;
		}

		return $type === 'application/ld+json'
			&& str_contains(strtolower($contentType), 'https://www.w3.org/ns/activitystreams');
	}

	/**
	 * Whether a status is one a peer would answer to a signature it did not
	 * want. A 4xx that is about anything else (404, 410) is the answer, not a
	 * reason to ask again.
	 */
	private function refusedTheSignature(int $status): bool {
		return $status === Http::STATUS_UNAUTHORIZED || $status === Http::STATUS_FORBIDDEN;
	}

	/**
	 * Fires a request at this app's own `/async/request/{token}` route so that
	 * the rows left on standby are delivered without the caller waiting. The
	 * request is signed, since the route refuses one this server did not send.
	 *
	 * @param int $round the round of the token's drain this starts: 0 for the
	 *                   first, and `QueueController` counts the follow-ups
	 *
	 * @throws SocialAppConfigException
	 */
	public function asyncWithToken(string $token, int $round = 0): void {
		$address = $this->configService->getSocialUrl();

		$path = $this->withEndSlash((string)parse_url($address, PHP_URL_PATH));
		$path .= $this->withoutBeginSlash(self::ASYNC_REQUEST_TOKEN);
		$path = str_replace('{token}', $token, $path);

		$url = parse_url($address, PHP_URL_SCHEME) . '://' . $this->configService->getCloudHost() . $path;
		$headers = [AsyncRequestSigner::HEADER => $this->asyncRequestSigner->sign($token, $round)];
		if ($round > 0) {
			$headers[AsyncRequestSigner::ROUND_HEADER] = (string)$round;
		}

		try {
			// This instance's own address may resolve to a private or loopback address
			// behind a proxy or in a container, so the local-address guard is lifted
			// for this one call; it is the configured cloud host, not anything a
			// request can influence.
			$this->retrieveJson('post', $url, [
				'allow_local_address' => true,
				'headers' => $headers,
			]);
		} catch (RequestResultNotJsonException $e) {
		} catch (Exception $e) {
			$this->logger->error('Cannot initiate AsyncWithToken', ['token' => AsyncRequestSigner::fingerprint($token), 'exception' => $e]);
		}
	}

	/**
	 * Sends the request and reads the answer as JSON.
	 *
	 * @param array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool, allow_local_address?: bool, accept_errors?: bool, same_host?: bool} $options
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveJson(
		string $method,
		string $url,
		array $options = [],
		?int &$statusCode = null,
		?string &$contentType = null,
	): array {
		return $this->retrieveJsonFromFirstReachable([$url], $options, $method, $statusCode, $contentType);
	}

	/**
	 * Several ActivityPub documents at once, each signed for its own URL, as
	 * `retrieveObject()` but without the unsigned retry: a batch is a
	 * best-effort read.
	 *
	 * @param string[] $ids
	 * @param array{timeout?: int} $options
	 * @param ?array<string, int> $statuses set to id => the HTTP status of its answer, for the ids that were answered
	 * @param list<string>|null $failed set to the ids that got no answer, as `retrieveJsonMany()` says
	 *
	 * @return array<string, array<string, mixed>|null> id => the document, or null
	 */
	public function retrieveObjectsMany(array $ids, array $options = [], ?array &$statuses = null, ?array &$failed = null): array {
		$perUrl = [];
		foreach ($ids as $id) {
			$parsed = parse_url($id);
			if (!is_array($parsed) || !isset($parsed['host'], $parsed['scheme'], $parsed['path'])) {
				continue;
			}

			$perUrl[$id] = ['headers' => $this->httpSignatureService->signFetch($id)];
		}

		return $this->retrieveJsonMany(
			array_keys($perUrl),
			$this->mergeOptions($options, ['headers' => ['Accept' => 'application/activity+json'], 'same_host' => true]),
			$perUrl,
			$statuses,
			$failed
		);
	}

	/**
	 * Several documents at once, from several servers, in parallel, so the
	 * slowest peer does not set the pace for all.
	 *
	 * The federation and local-address checks run before anything is sent, and
	 * the size ceiling applies to each answer. A redirect is finished by the
	 * single-URL path, since each hop must be checked before it is followed.
	 * One bad answer is `null` in its place; `$failed` lists the URLs that got no
	 * answer, timed out, or answered 429 or 5xx (not those the federation policy
	 * refused).
	 *
	 * @param string[] $urls
	 * @param array{headers?: array<string, string>, timeout?: int, json_headers?: bool, allow_local_address?: bool, accept_errors?: bool, same_host?: bool} $options
	 * @param array<string, array<string, mixed>> $perUrl options for one URL, merged over the shared ones
	 * @param ?array<string, int> $statuses set to url => the HTTP status of its answer, for the URLs that were answered
	 * @param list<string>|null $failed set to the URLs that got no answer
	 * @param ?array<string, int> $retryAfters set to url => the requested wait in seconds, for answered URLs
	 *
	 * @return array<string, array<string, mixed>|null> url => the document, or null
	 */
	public function retrieveJsonMany(array $urls, array $options = [], array $perUrl = [], ?array &$statuses = null, ?array &$failed = null, ?array &$retryAfters = null): array {
		$statuses = [];
		$failed = [];
		$retryAfters = [];
		$urls = array_values(array_unique(array_filter($urls)));
		if ($urls === []) {
			return [];
		}

		$client = $this->parallelClient();

		$answers = array_fill_keys($urls, null);
		$promises = [];
		foreach ($urls as $url) {
			// a signed fetch signs its own URL, so the options are built per
			// request rather than once for the batch
			$clientOptions = $this->clientOptions('get', $this->mergeOptions($options, $perUrl[$url] ?? []));
			$clientOptions['allow_redirects'] = false;

			try {
				$clientOptions = $this->sunk($this->pinReachable($url, $clientOptions), $this->maxDownloadSize);
				$promises[$url] = $client->getAsync($url, $clientOptions);
			} catch (UnauthorizedFediverseException $e) {
				$this->logger->debug('[CurlService] not asking ' . $url, ['exception' => $e]);
			} catch (Throwable $e) {
				// a host with no public address, or a client that cannot do
				// this at all: nothing from there, and no answer either
				$failed[] = $url;
				$this->logger->debug('[CurlService] not asking ' . $url, ['exception' => $e]);
			}
		}

		foreach ($promises as $url => $promise) {
			try {
				$response = $this->settledResponse($promise->wait());
				if ($response !== null) {
					$statuses[$url] = $response->getStatusCode();
					$retryAfters[$url] = self::retryAfter($response);
				}
				if ($response === null || in_array($response->getStatusCode(), [408, 429], true) || $response->getStatusCode() >= 500) {
					$failed[] = $url;
				}
				$answers[$url] = ($response !== null)
					? $this->decodeOrFollow($url, $response, $this->mergeOptions($options, $perUrl[$url] ?? []))
					: null;
			} catch (Throwable $e) {
				$failed[] = $url;
				$this->logger->debug('[CurlService] no answer from ' . $url, ['exception' => $e]);
			}
		}

		return $answers;
	}

	/**
	 * How long an answer asks to be left alone, in seconds: `Retry-After` as
	 * a number of seconds or as an HTTP date (RFC 9110 §10.2.3), 0 where it
	 * says nothing usable. Capping is the breaker's business.
	 */
	public static function retryAfter(IResponse $response): int {
		$value = trim($response->getHeader('Retry-After'));
		if ($value === '') {
			return 0;
		}
		if (preg_match('/^\d{1,10}$/', $value) === 1) {
			return (int)$value;
		}
		$date = SignatureService::parseHttpDate($value);

		return ($date === null) ? 0 : max(0, $date - time());
	}

	/**
	 * A client whose `…Async()` requests are in flight together: Guzzle's
	 * `CurlHandler` would run each as it is made, so the multi handler is used
	 * where the server's HTTP stack has one. Nextcloud 34's `newClient()` takes
	 * no handler, so there the requests still run one after another.
	 */
	private function parallelClient(): IClient {
		$multi = 'GuzzleHttp\\Handler\\CurlMultiHandler';
		if (!class_exists($multi)) {
			return $this->clientService->newClient();
		}

		/** @var callable $handler */
		$handler = new $multi();

		return $this->clientService->newClient($handler);
	}

	/**
	 * What `IPromise::wait()` settles with, as an `IResponse`: `wait()` gives
	 * Guzzle's own response, not the wrapper `then()` hands its handlers, while a
	 * test double gives an `IResponse` directly.
	 *
	 * @see \OCA\Social\Tools\Http\FetchedResponse
	 */
	private function settledResponse(mixed $value): ?IResponse {
		if ($value instanceof IResponse) {
			return $value;
		}

		return ($value instanceof ResponseInterface) ? new FetchedResponse($value) : null;
	}

	/**
	 * Several requests at once, each settled as `retrieveJson()` settles one:
	 * what it would have thrown, or null. For the delivery queue, whose rows go
	 * to unrelated servers. The checks run before sending, the size ceiling per
	 * answer, and redirects go through the single-request path. A body that is
	 * not JSON is no failure: a delivery wants the status.
	 *
	 * @param array<array-key, array{method: string, url: string, options: array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool}}> $requests
	 *
	 * @return array<array-key, Throwable|null> the key of each request => its outcome
	 */
	public function sendMany(array $requests): array {
		$client = $this->parallelClient();

		$outcomes = [];
		$promises = [];
		$sinks = [];
		foreach ($requests as $key => $request) {
			$method = strtolower($request['method']);
			$clientOptions = $this->clientOptions($method, $request['options']);
			$clientOptions['allow_redirects'] = false;

			try {
				$clientOptions = $this->sunk($this->pinReachable($request['url'], $clientOptions), $this->maxDownloadSize);
				$sinks[$key] = $clientOptions['sink'];
				$promises[$key] = match ($method) {
					'post' => $client->postAsync($request['url'], $clientOptions),
					'put' => $client->putAsync($request['url'], $clientOptions),
					'delete' => $client->deleteAsync($request['url'], $clientOptions),
					default => $client->getAsync($request['url'], $clientOptions),
				};
			} catch (Throwable $e) {
				$outcomes[$key] = $e;
			}
		}

		foreach ($promises as $key => $promise) {
			$url = $requests[$key]['url'];
			try {
				$response = $this->settledResponse($promise->wait());
			} catch (Throwable $e) {
				$outcomes[$key] = $sinks[$key]->overflowed()
					? new RequestResultSizeException()
					: new RequestNetworkException($e->getMessage() . ' - ' . $url, (int)$e->getCode());

				continue;
			}

			$outcomes[$key] = ($response instanceof IResponse)
				? $this->settledOutcome($requests[$key], $response)
				: new RequestNetworkException('no response - ' . $url);
		}

		return $outcomes;
	}

	/**
	 * @param array{method: string, url: string, options: array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool}} $request
	 */
	private function settledOutcome(array $request, IResponse $response): ?Throwable {
		try {
			if ($this->redirectTarget($response, $request['url']) !== '') {
				$this->retrieveJson($request['method'], $request['url'], $request['options']);

				return null;
			}

			$this->body($response);
			if ($response->getStatusCode() >= 300) {
				return (new RequestContentException($request['url'], $response->getStatusCode()))
					->setRetryAfter(self::retryAfter($response));
			}

			return null;
		} catch (RequestResultNotJsonException $e) {
			return null;
		} catch (Throwable $e) {
			return $e;
		}
	}

	/**
	 * Several files at once, each written to its own path as it arrives, with
	 * `sendMany()`'s client and checks (the federation policy on behalf of the
	 * naming instance, the pinned local-address guard). A sink refuses the byte
	 * past `max + 1`, so an oversized answer costs no more disk than that and
	 * nothing is held in memory. Redirects are finished by `openStream()`.
	 *
	 * @param array<array-key, array{url: string, path: string, max: int, on_behalf_of?: string}> $downloads
	 *
	 * @return array<array-key, Throwable|null> the key of each download => its outcome; null when the
	 *                                          whole file is at its path
	 */
	public function downloadMany(array $downloads): array {
		$client = $this->parallelClient();
		$clientOptions = $this->clientOptions('get', ['json_headers' => false]);
		$clientOptions['allow_redirects'] = false;
		// a streamed response is handed back unread and never reaches the sink
		unset($clientOptions['stream']);

		$outcomes = [];
		$promises = [];
		$sinks = [];
		foreach ($downloads as $key => $download) {
			try {
				$options = $this->pinReachable($download['url'], $clientOptions, $download['on_behalf_of'] ?? '');
				$sinks[$key] = new DroppingStream(Utils::streamFor(Utils::tryFopen($download['path'], 'w')), $download['max'] + 1);
				$options['sink'] = $sinks[$key];
				$promises[$key] = $client->getAsync($download['url'], $options);
			} catch (Throwable $e) {
				$outcomes[$key] = $e;
			}
		}

		foreach ($promises as $key => $promise) {
			$outcomes[$key] = $this->settledDownload($downloads[$key], $promise, $sinks[$key]);
		}
		foreach ($sinks as $sink) {
			$sink->close();
		}

		return $outcomes;
	}

	/**
	 * @param array{url: string, path: string, max: int, on_behalf_of?: string} $download
	 */
	private function settledDownload(array $download, IPromise $promise, StreamInterface $sink): ?Throwable {
		$url = $download['url'];
		try {
			$response = $this->settledResponse($promise->wait());
		} catch (Throwable $e) {
			$sink->close();

			// the sink refusing the byte past the ceiling is what ended it
			return self::overflowed($download)
				? new RequestResultSizeException()
				: new RequestNetworkException($e->getMessage() . ' - ' . $url, (int)$e->getCode());
		}
		$sink->close();

		if ($response === null) {
			return new RequestNetworkException('no response - ' . $url);
		}

		try {
			if ($this->redirectTarget($response, $url) !== '') {
				$this->downloadOne($download);

				return null;
			}
			if ($response->getStatusCode() >= 300) {
				return (new RequestContentException($url, $response->getStatusCode()))
					->setRetryAfter(self::retryAfter($response));
			}
		} catch (Throwable $e) {
			return $e;
		}

		return self::overflowed($download) ? new RequestResultSizeException() : null;
	}

	/**
	 * One file, through `openStream()`, copied to its path a chunk at a time
	 * and cut off one byte past its ceiling.
	 *
	 * @param array{url: string, path: string, max: int, on_behalf_of?: string} $download
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	private function downloadOne(array $download): void {
		$opened = $this->openStream($download['url'], [], $download['on_behalf_of'] ?? '', max: $download['max']);
		$target = fopen($download['path'], 'w');
		if ($target === false) {
			fclose($opened['stream']);

			throw new RequestServerException('could not write the downloaded file');
		}

		try {
			$copied = stream_copy_to_stream($opened['stream'], $target, $download['max'] + 1);
		} finally {
			fclose($opened['stream']);
			fclose($target);
		}

		if ($copied === false) {
			throw new RequestServerException('could not read ' . $download['url']);
		}
		if ($copied > $download['max']) {
			throw new RequestResultSizeException();
		}
	}

	/**
	 * @param array{url: string, path: string, max: int, on_behalf_of?: string} $download
	 */
	private static function overflowed(array $download): bool {
		clearstatcache(true, $download['path']);

		return ((int)@filesize($download['path'])) > $download['max'];
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	private function mergeOptions(array $base, array $extra): array {
		$merged = array_merge($base, $extra);
		if (isset($base['headers']) || isset($extra['headers'])) {
			$merged['headers'] = $this->mergeHeaders($base['headers'] ?? [], $extra['headers'] ?? []);
		}

		return $merged;
	}

	/**
	 * One parallel answer as JSON, or the single-URL path where it redirected.
	 *
	 * @param array<string, mixed> $options
	 *
	 * @return array<string, mixed>|null
	 */
	private function decodeOrFollow(string $url, IResponse $response, array $options): ?array {
		if ($this->redirectTarget($response, $url) !== '') {
			// each hop is checked before it is followed, one request at a time
			try {
				return $this->retrieveJson('get', $url, $options);
			} catch (Throwable $e) {
				return null;
			}
		}

		if ($response->getStatusCode() >= 300) {
			return null;
		}

		$decoded = json_decode($this->body($response), true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * The same, over a list of URLs that differ only in their scheme — see
	 * doRequestOverUrls().
	 *
	 * @param string[] $urls
	 * @param array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool, allow_local_address?: bool, accept_errors?: bool, same_host?: bool} $options
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveJsonFromFirstReachable(
		array $urls,
		array $options = [],
		string $method = 'get',
		?int &$statusCode = null,
		?string &$contentType = null,
	): array {
		$contentType = '';
		$result = $this->doRequestOverUrls($method, $urls, $options, $contentType, $statusCode);

		// host-meta is served as XRD by most instances and as JRD by some
		if (str_starts_with($contentType, 'application/xrd')) {
			$result = (string)json_encode(simplexml_load_string($result), JSON_UNESCAPED_SLASHES);
		}

		$decoded = json_decode($result, true);
		if (is_array($decoded)) {
			return $decoded;
		}

		throw new RequestResultNotJsonException();
	}

	/**
	 * Opens a remote file, such as a federated video, for reading without reading
	 * it, handing back the open stream, status and headers.
	 *
	 * The client's `Range` is forwarded and the origin's `206` passed back, so
	 * seeking costs nothing extra. The federation and local-address checks apply
	 * to the URL and every redirect. Curl receives the whole answer before
	 * returning, so it is held to `$max` bytes, beyond which this throws
	 * `RequestResultSizeException`.
	 *
	 * @param array<string, string> $headers
	 * @param string $onBehalfOf the instance that named this url, where that is
	 *                           not the host serving it — see
	 *                           {@see FediverseService::authorized()}
	 * @param int|null $max the most bytes taken, the download ceiling when null
	 * @param bool $sameOrigin whether a redirect may only lead to the scheme,
	 *                         host and port of `$url`
	 *
	 * @return array{stream: resource, status: int, headers: array<string, string[]>}
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function openStream(
		string $url,
		array $headers = [],
		string $onBehalfOf = '',
		int $timeout = ConfigService::DEFAULT_REQUEST_TIMEOUT,
		?int $max = null,
		bool $sameOrigin = false,
	): array {
		$clientOptions = $this->clientOptions('get', ['json_headers' => false, 'headers' => $headers, 'timeout' => $timeout]);
		$clientOptions['allow_redirects'] = false;
		$client = $this->clientService->newClient();
		$requested = $url;

		$response = null;
		for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
			$hopOptions = $this->sunk($this->pinReachable($url, $clientOptions, $onBehalfOf), $max ?? $this->maxDownloadSize);

			try {
				$response = $client->get($url, $hopOptions);
			} catch (Exception $e) {
				if ($hopOptions['sink']->overflowed()) {
					throw new RequestResultSizeException();
				}

				throw new RequestNetworkException($e->getMessage() . ' - ' . $url, $e->getCode());
			}

			$location = $this->redirectTarget($response, $url);
			if ($location === '') {
				break;
			}
			if ($sameOrigin && !self::sameOrigin($requested, $location)) {
				throw new RequestServerException('redirect to another origin: ' . $location);
			}

			$clientOptions = self::forHop($clientOptions, $url, $location);
			$url = $location;
			$response = null;
		}

		if ($response === null) {
			throw new RequestServerException('too many redirects: ' . $url);
		}

		$status = $response->getStatusCode();
		if ($status >= 300) {
			throw (new RequestContentException($url, $status))->setRetryAfter(self::retryAfter($response));
		}

		$stream = $response->getBody();
		if (!is_resource($stream)) {
			// a client that does not stream -- a test double -- hands the body
			// over whole; it is still something to read from
			$whole = fopen('php://temp', 'r+');
			fwrite($whole, (string)$stream);
			rewind($whole);
			$stream = $whole;
		}

		return ['stream' => $stream, 'status' => $status, 'headers' => $response->getHeaders()];
	}

	/** Sends the request and returns the body. */
	public function doRequest(
		string $method,
		string $url,
		array $options = [],
		?string &$contentType = null,
		?int &$statusCode = null,
	): string {
		return $this->doRequestOverUrls($method, [$url], $options, $contentType, $statusCode);
	}

	/**
	 * Sends the request to the first of $urls that answers: a connection or TLS
	 * failure falls through to the next (an instance reachable over http only),
	 * an error status ends the attempt. The URLs differ only in their scheme.
	 *
	 * @param string[] $urls
	 * @param array{headers?: array<string, string>, body?: string|resource, timeout?: int, json_headers?: bool, allow_local_address?: bool, accept_errors?: bool, verify?: bool, max_redirects?: int, federation?: bool, same_host?: bool} $options
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function doRequestOverUrls(
		string $method,
		array $urls,
		array $options = [],
		?string &$contentType = null,
		?int &$statusCode = null,
	): string {
		if ($urls === []) {
			return '';
		}

		$clientOptions = $this->clientOptions($method, $options);
		$client = $this->clientService->newClient();

		$networkFailure = null;
		foreach ($urls as $url) {
			try {
				return $this->send($client, $method, $url, $clientOptions, $contentType, $statusCode);
			} catch (RequestNetworkException $e) {
				$networkFailure = $e;
			}
		}

		// the list is not empty, so the loop ran and this is the last failure
		throw $networkFailure;
	}

	/**
	 * The options the server's HTTP client is handed. The answer goes to a
	 * `BoundedSink` (`sunk()`), cut at `max_size`; `allow_local_address` is the
	 * instance's setting, except for this app's request to itself
	 * (`asyncWithToken()`).
	 *
	 * Redirects are followed by `send()`, so every hop is checked
	 * (`redirectTarget()`) and pinned. `max_redirects` caps them, 0 for none;
	 * `federation` false skips the instance access list for a URL this app ships;
	 * `same_host` refuses a redirect to another host.
	 *
	 * @param array{headers?: array<string, string>, body?: string|resource, timeout?: int, json_headers?: bool, allow_local_address?: bool, accept_errors?: bool, verify?: bool, max_redirects?: int, federation?: bool, same_host?: bool} $options
	 *
	 * @return array<string, mixed>
	 */
	private function clientOptions(string $method, array $options): array {
		$clientOptions = $this->configService->requestOptions(
			$options['timeout'] ?? ConfigService::DEFAULT_REQUEST_TIMEOUT
		);

		$headers = ['user-agent' => $this->userAgent()];
		$headers = $this->mergeHeaders($headers, $options['headers'] ?? []);
		if ($options['json_headers'] ?? true) {
			$headers = $this->mergeHeaders($headers, $this->configService->activityPubHeaders($method));
		}

		$clientOptions['headers'] = $headers;
		// the status code belongs to the caller, not to an exception
		$clientOptions['http_errors'] = false;
		$clientOptions['stream'] = true;

		if ($options['allow_local_address'] ?? false) {
			$clientOptions['nextcloud']['allow_local_address'] = true;
		}
		if ($options['accept_errors'] ?? false) {
			$clientOptions['social']['accept_errors'] = true;
		}
		if (isset($options['max_redirects'])) {
			$clientOptions['social']['max_redirects'] = $options['max_redirects'];
		}
		if (($options['federation'] ?? true) === false) {
			$clientOptions['social']['federation'] = false;
		}
		if ($options['same_host'] ?? false) {
			$clientOptions['social']['same_host'] = true;
		}
		if (isset($options['verify'])) {
			$clientOptions['verify'] = $options['verify'];
		}

		if (($options['body'] ?? '') !== '' && strtolower($method) !== 'get') {
			$clientOptions['body'] = $options['body'];
		}

		return $clientOptions;
	}

	/**
	 * Adds $extra to $headers, appending to a header already there rather than
	 * replacing it.
	 *
	 * @param array<string, string> $headers
	 * @param array<string, string> $extra
	 *
	 * @return array<string, string>
	 */
	private function mergeHeaders(array $headers, array $extra): array {
		foreach ($extra as $key => $value) {
			$headers[$key] = isset($headers[$key]) ? $headers[$key] . ', ' . $value : $value;
		}

		return $headers;
	}

	public function userAgent(): string {
		return self::USER_AGENT . ' ' . $this->configService->getAppValue('installed_version');
	}

	/**
	 * Verifies a WebSocket upgrade without reading from the open event stream.
	 * The normal address guard and pinned DNS result still apply to the endpoint.
	 */
	public function doWebSocketHandshake(string $url, int &$statusCode): bool {
		$nonce = base64_encode(random_bytes(16));
		$clientOptions = $this->clientOptions('get', [
			'timeout' => 5,
			'json_headers' => false,
			'headers' => [
				'Connection' => 'Upgrade',
				'Upgrade' => 'websocket',
				'Sec-WebSocket-Version' => '13',
				'Sec-WebSocket-Key' => $nonce,
			],
			'max_redirects' => 0,
			'federation' => false,
		]);
		$clientOptions['allow_redirects'] = false;
		$clientOptions['curl'][CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
		$client = $this->clientService->newClient();
		$clientOptions = $this->pinReachable($url, $clientOptions, '', false);
		$clientOptions['curl'][CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;

		try {
			$response = $client->request('get', $url, $clientOptions);
		} catch (Exception $e) {
			throw new RequestNetworkException($e->getMessage() . ' - ' . $url, $e->getCode());
		}

		$statusCode = $response->getStatusCode();
		$accept = base64_encode(sha1($nonce . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
		$body = $response->getBody();
		if (is_resource($body)) {
			fclose($body);
		}

		return $statusCode === 101
			&& strtolower($response->getHeader('Upgrade')) === 'websocket'
			&& preg_match('/(?:^|,)\s*upgrade\s*(?:,|$)/i', $response->getHeader('Connection')) === 1
			&& hash_equals($accept, $response->getHeader('Sec-WebSocket-Accept'));
	}

	/**
	 * @param array<string, mixed> $clientOptions
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	private function send(
		IClient $client,
		string $method,
		string $url,
		array $clientOptions,
		?string &$contentType,
		?int &$statusCode,
	): string {
		$clientOptions['allow_redirects'] = false;
		$acceptErrors = (bool)($clientOptions['social']['accept_errors'] ?? false);
		$maxRedirects = (int)($clientOptions['social']['max_redirects'] ?? self::MAX_REDIRECTS);
		$federation = (bool)($clientOptions['social']['federation'] ?? true);
		$sameHost = (bool)($clientOptions['social']['same_host'] ?? false);
		unset($clientOptions['social']);
		$requested = $url;

		for ($hop = 0; $hop <= $maxRedirects; $hop++) {
			$hopOptions = $this->sunk($this->pinReachable($url, $clientOptions, '', $federation), $this->maxDownloadSize);

			try {
				$response = $client->request(strtolower($method), $url, $hopOptions);
			} catch (Exception $e) {
				if ($hopOptions['sink']->overflowed()) {
					throw new RequestResultSizeException();
				}

				throw new RequestNetworkException($e->getMessage() . ' - ' . $url, $e->getCode());
			}

			$statusCode = $response->getStatusCode();
			$contentType = $response->getHeader('Content-Type');

			$this->logger->debug('[>>] ' . $url . ' result [' . $statusCode . ']');

			$body = $this->body($response);

			$location = $this->redirectTarget($response, $url);
			if ($location === '') {
				if ($statusCode >= 300 && !$acceptErrors) {
					throw (new RequestContentException($url, $statusCode))->setRetryAfter(self::retryAfter($response));
				}

				return $body;
			}

			if ($sameHost && strcasecmp((string)parse_url($requested, PHP_URL_HOST), (string)parse_url($location, PHP_URL_HOST)) !== 0) {
				throw new RequestServerException('redirect to another host: ' . $location);
			}

			// a 301/302/303 answers a POST by naming something to GET, which is
			// also what the server's own client does when it follows one
			if ($statusCode !== Http::STATUS_TEMPORARY_REDIRECT && $statusCode !== 308) {
				$method = 'get';
				unset($clientOptions['body']);
			} elseif (isset($clientOptions['body']) && !self::sameOrigin($url, $location)) {
				throw new RequestServerException('a request body is not sent on to another origin: ' . $location);
			}

			$clientOptions = self::forHop($clientOptions, $url, $location);
			$url = $location;
		}

		throw new RequestServerException('too many redirects: ' . $requested);
	}

	/**
	 * Where a response says to go next, or `''` when it does not. Followed here
	 * rather than by the client so every hop gets the federation checks: an
	 * allowed host could otherwise redirect to a blocked one.
	 *
	 * @throws RequestServerException
	 * @throws UnauthorizedFediverseException
	 * @throws SocialAppConfigException
	 */
	private function redirectTarget(IResponse $response, string $from): string {
		if (!in_array($response->getStatusCode(), self::REDIRECT_STATUSES, true)) {
			return '';
		}

		$location = trim($response->getHeader('Location'));
		if ($location === '') {
			return '';
		}

		$target = $this->absoluteUrl($from, $location);
		if (!self::isHttpScheme(strtolower((string)parse_url($target, PHP_URL_SCHEME)))) {
			throw new RequestServerException('redirect to a non-http location: ' . $target);
		}

		return $target;
	}

	/**
	 * The options of one hop, with a sink of its own that ends the transfer
	 * one byte past `$max`.
	 *
	 * @param array<string, mixed> $options
	 *
	 * @return array<string, mixed>
	 */
	private function sunk(array $options, int $max): array {
		$options['sink'] = BoundedSink::temporary($max);

		return $options;
	}

	/**
	 * The options for following a redirect from `$from` to `$to`: credentials
	 * are for the origin they were written for and are not sent to another.
	 *
	 * @param array<string, mixed> $options
	 *
	 * @return array<string, mixed>
	 */
	private static function forHop(array $options, string $from, string $to): array {
		if (self::sameOrigin($from, $to)) {
			return $options;
		}

		foreach (array_keys($options['headers'] ?? []) as $name) {
			if (in_array(strtolower((string)$name), ['authorization', 'cookie', 'proxy-authorization'], true)) {
				unset($options['headers'][$name]);
			}
		}

		return $options;
	}

	/** Whether two URLs share scheme, host and port. */
	private static function sameOrigin(string $one, string $other): bool {
		$a = parse_url($one);
		$b = parse_url($other);
		if (!is_array($a) || !is_array($b)) {
			return false;
		}

		$scheme = strtolower($a['scheme'] ?? '');
		$port = static fn (array $url, string $scheme): int => (int)($url['port'] ?? ($scheme === 'https' ? 443 : 80));

		return $scheme === strtolower($b['scheme'] ?? '')
			&& strcasecmp($a['host'] ?? '', $b['host'] ?? '') === 0
			&& $port($a, $scheme) === $port($b, $scheme);
	}

	private static function isHttpScheme(string $scheme): bool {
		return $scheme === 'http' || $scheme === 'https';
	}

	/**
	 * Refuses a URL that is not http(s), names an instance this one does not
	 * federate with, or resolves to this network, and pins the request to the
	 * addresses that passed (`CURLOPT_RESOLVE`), so the client cannot resolve the
	 * host differently. Each redirect hop is pinned afresh; a literal address
	 * needs no pin. The entry replaces curl's defaults, so the HTTP/2 preference
	 * is restated. Nothing is checked when the options allow a local address.
	 *
	 * @param array<string, mixed> $clientOptions
	 *
	 * @return array<string, mixed> the options the request is sent with
	 *
	 * @throws RequestServerException
	 * @throws UnauthorizedFediverseException
	 * @throws SocialAppConfigException
	 */
	private function pinReachable(string $url, array $clientOptions, string $onBehalfOf = '', bool $federation = true): array {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (!self::isHttpScheme($scheme)) {
			throw new RequestServerException('not an http(s) URL: ' . $url);
		}

		$host = (string)parse_url($url, PHP_URL_HOST);
		if ($federation) {
			$this->fediverseService->authorized($host, $onBehalfOf);
		}

		if ($clientOptions['nextcloud']['allow_local_address'] ?? false) {
			return $clientOptions;
		}

		$addresses = RemoteAddress::reachableAddresses($host);
		if ($addresses === []) {
			throw new RequestServerException('host does not resolve to a public address: ' . $host);
		}
		if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
			return $clientOptions;
		}

		$port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);
		$clientOptions['curl'] = [
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
			CURLOPT_RESOLVE => [$host . ':' . $port . ':' . implode(',', $addresses)],
		];

		return $clientOptions;
	}

	/**
	 * A `Location` resolved against the URL it came from, which is allowed to
	 * be absolute, protocol-relative, root-relative or relative.
	 */
	private function absoluteUrl(string $base, string $location): string {
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
			return $location;
		}

		$parsed = parse_url($base);
		$scheme = $parsed['scheme'] ?? 'https';
		if (str_starts_with($location, '//')) {
			return $scheme . ':' . $location;
		}

		$authority = ($parsed['host'] ?? '') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
		if (str_starts_with($location, '/')) {
			return $scheme . '://' . $authority . $location;
		}

		$path = $parsed['path'] ?? '/';

		return $scheme . '://' . $authority . substr($path, 0, (int)strrpos($path, '/') + 1) . $location;
	}

	/**
	 * `scheme://host/path?query` for every scheme in $protocols, which is how
	 * an instance reachable over http only is still found.
	 *
	 * @param string[] $protocols
	 * @param array<string, string> $params
	 *
	 * @return string[]
	 */
	private function candidateUrls(array $protocols, string $host, string $path, array $params = []): array {
		$query = ($params === []) ? '' : '?' . http_build_query($params);

		return array_map(
			static fn (string $protocol): string => $protocol . '://' . $host . $path . $query,
			$protocols
		);
	}

	/**
	 * @throws RequestResultSizeException
	 */
	private function body(IResponse $response): string {
		$body = self::readAtMost($response, $this->maxDownloadSize);
		if (strlen($body) > $this->maxDownloadSize) {
			throw new RequestResultSizeException();
		}

		return $body;
	}

	/**
	 * At most `$max + 1` bytes of a response body, so the caller can tell a body
	 * that fits from one that does not. The request must be made with
	 * `'stream' => true` and a `BoundedSink`, which keeps curl from receiving
	 * more.
	 */
	public static function readAtMost(IResponse $response, int $max): string {
		$stream = $response->getBody();
		if (!is_resource($stream)) {
			// a client that does not stream (a test double, say) hands over
			// the whole body at once
			return substr((string)$stream, 0, $max + 1);
		}

		try {
			return (string)stream_get_contents($stream, $max + 1);
		} finally {
			fclose($stream);
		}
	}
}
