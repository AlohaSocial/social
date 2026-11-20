<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\RequestQueue;
use Psr\Log\LoggerInterface;

/**
 * Signs outbound HTTP requests (the draft-cavage scheme ActivityPub
 * implementations speak). Verification is SignatureService's; signing is
 * here because CurlService signs GETs and SignatureService fetches keys
 * through CurlService.
 */
class HttpSignatureService {
	public const DATE_HEADER = 'D, d M Y H:i:s T';

	/**
	 * The headers a delivery POST signs. `digest` and `content-length` bind the
	 * body; `host` and `date` stop the request being replayed elsewhere or
	 * later.
	 */
	private const DELIVERY_HEADERS = ['(request-target)', 'content-length', 'date', 'host', 'digest'];

	/**
	 * The headers a fetch GET signs: no body, so no digest or content-length.
	 * The set Mastodon, GoToSocial and Pleroma produce and verify.
	 */
	private const FETCH_HEADERS = ['(request-target)', 'host', 'date'];

	public function __construct(
		private ActorsRequest $actorsRequest,
		private InstanceActorService $instanceActorService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The delivery signature: signs a POST to a peer's inbox as the local actor
	 * the activity belongs to.
	 *
	 * @param string $url the URL the request is actually sent to; the signature
	 *                    covers the path and query of that very URL, so the two
	 *                    cannot drift apart
	 * @param string $body the bytes that go on the wire, which is what `digest`
	 *                     and `content-length` have to describe
	 *
	 * @return array<string, string> the headers to send, in the order they are
	 *                               signed, with `Signature` last
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SignatureException
	 * @throws SocialAppConfigException
	 */
	public function signDelivery(string $url, string $body, RequestQueue $queue): array {
		$localActor = $this->actorsRequest->getFromId($queue->getAuthor());

		return $this->sign(
			$localActor,
			self::DELIVERY_HEADERS,
			[
				// a queued delivery is a POST: every InstancePath that reaches the queue
				// is an inbox or a shared inbox
				'(request-target)' => $this->requestTarget('post', $url),
				'date' => gmdate(self::DATE_HEADER),
				'host' => $this->authority($url),
				'digest' => $this->digest($body),
				'content-length' => (string)strlen($body),
			]
		);
	}

	/**
	 * The delivery signature for an activity the instance sends as itself (a
	 * forwarded report, a relay subscription). `signDelivery()` resolves the key
	 * from `oc_social_actor`, where the instance actor is not
	 * (`InstanceActorService`), so these go out inline with these headers.
	 *
	 * @param string $url the inbox the request is sent to; the signature covers
	 *                    the path of that very URL
	 * @param string $body the bytes that go on the wire, which is what `digest`
	 *                     and `content-length` describe
	 *
	 * @return array<string, string> the headers to send
	 *
	 * @throws SignatureException there is no instance actor, or its key is
	 *                            unusable: the activity must fail rather than
	 *                            go out unsigned
	 */
	public function signAsInstance(string $url, string $body): array {
		$actor = $this->instanceActorService->getSigningActor();
		if ($actor === null) {
			throw new SignatureException('this instance has no actor of its own to sign as');
		}

		return $this->sign(
			$actor,
			self::DELIVERY_HEADERS,
			[
				'(request-target)' => $this->requestTarget('post', $url),
				'date' => gmdate(self::DATE_HEADER),
				'host' => $this->authority($url),
				'digest' => $this->digest($body),
				'content-length' => (string)strlen($body),
			]
		);
	}

	/**
	 * Signs an outbound ActivityPub GET, so that peers running Mastodon's
	 * `AUTHORIZED_FETCH` or GoToSocial's secure mode answer it.
	 *
	 * Signed with the instance's own `Application` actor, never a person: not the
	 * one who triggered the fetch, which would tell peers who reads what, and not
	 * a fixed account, whose block anywhere would stop every signed fetch.
	 *
	 * @return array<string, string> the headers to add; empty means the request
	 *                               goes out unsigned, which a peer that does
	 *                               not demand a signature still answers
	 */
	public function signFetch(string $url): array {
		$actor = $this->instanceActorService->getSigningActor();
		if ($actor === null) {
			return [];
		}

		try {
			return $this->sign(
				$actor,
				self::FETCH_HEADERS,
				[
					'(request-target)' => $this->requestTarget('get', $url),
					'host' => $this->authority($url),
					'date' => gmdate(self::DATE_HEADER),
				]
			);
		} catch (SignatureException $e) {
			// an unsignable key must not stop the fetch: unsigned still works against
			// most peers
			$this->logger->warning('cannot sign an outgoing fetch', [
				'actor' => $actor->getId(), 'exception' => $e,
			]);

			return [];
		}
	}

	/**
	 * What the signature says the request is: the method and the path, with any
	 * query string, of the URL it is sent to, so a peer rebuilds the same bytes.
	 */
	private function requestTarget(string $method, string $url): string {
		$parts = parse_url($url);
		$target = $parts['path'] ?? '';
		if (($parts['query'] ?? '') !== '') {
			$target .= '?' . $parts['query'];
		}

		return strtolower($method) . ' ' . $target;
	}

	/**
	 * The `host` a peer will compare against the one it serves: the hostname,
	 * plus the port when the URL names one that is not the scheme's default.
	 */
	private function authority(string $url): string {
		return ConfigService::authorityOf($url);
	}

	/**
	 * @param string[] $elements the header names the signature covers, in the
	 *                           order they are signed in
	 * @param array<string, string> $values
	 *
	 * @return array<string, string>
	 *
	 * @throws SignatureException
	 */
	private function sign(Person $actor, array $elements, array $values): array {
		$signing = [];
		$headers = [];
		foreach ($elements as $element) {
			$signing[] = $element . ': ' . $values[$element];
			if ($element !== '(request-target)') {
				$headers[$element] = (string)$values[$element];
			}
		}

		// the warning a bad key raises is handled right here, as an exception
		if (!@openssl_sign(implode("\n", $signing), $signed, $actor->getPrivateKey(), OPENSSL_ALGO_SHA256)) {
			// an empty or undecryptable private key must fail loudly, not send
			// base64('') as the signature
			throw new SignatureException(
				'cannot sign request for ' . $actor->getId() . ': ' . openssl_error_string()
			);
		}

		$headers['Signature'] = implode(',', [
			'keyId="' . $actor->getId() . '#main-key"',
			'algorithm="rsa-sha256"',
			'headers="' . implode(' ', $elements) . '"',
			'signature="' . base64_encode($signed) . '"',
		]);

		return $headers;
	}

	public function digest(string $data): string {
		return 'SHA-256=' . base64_encode(hash('sha256', $data, true));
	}
}
