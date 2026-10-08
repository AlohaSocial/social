<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\ActorsRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Service-auth tokens for a signed-in Bluesky app (§6.3), and the one use
 * this server accepts them for itself.
 *
 * `com.atproto.server.getServiceAuth` hands an app a token signed with the
 * account's key, for one service and one method. Only the pairs a video
 * upload needs are granted: Bluesky's video service, to ask the account's
 * upload limits and take the video, and this server, for the video
 * service to store what it made with `uploadBlob` in the account's name.
 * Anything else would be the account's signature in the hands of a
 * service this server knows nothing of, so it is refused.
 */
class ServiceAuthGrant {
	public const UPLOAD = 'com.atproto.repo.uploadBlob';
	/** the longest a token is good for: a large video takes a while to upload */
	public const MAX_LIFETIME = 1800;
	/** what a clock may be off by, between this server and the one sending the token */
	private const SKEW = 60;
	private const VIDEO_METHODS = ['app.bsky.video.getUploadLimits', 'app.bsky.video.uploadVideo', 'app.bsky.video.getJobStatus'];

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private ServiceAuth $serviceAuth,
		private ActorsRequest $actors,
		private ITimeFactory $time,
	) {
	}

	/**
	 * `com.atproto.server.getServiceAuth`.
	 *
	 * @param int $exp when it expires (UNIX time), 0 for a minute from now
	 * @throws XrpcException
	 */
	public function grant(ClientSession $session, string $audience, string $method, int $exp = 0): array {
		if (!$this->allowed($audience, $method)) {
			throw XrpcException::invalidRequest('This server does not hand out tokens for that service and method');
		}
		$now = $this->time->getTime();
		$lifetime = $exp === 0 ? ServiceAuth::LIFETIME : $exp - $now;
		if ($lifetime <= 0 || $lifetime > self::MAX_LIFETIME) {
			throw new XrpcException(400, 'BadExpiration', 'The expiry must be within the next ' . (self::MAX_LIFETIME / 60) . ' minutes');
		}

		return ['token' => $this->serviceAuth->token($this->identities->signingKey($session->identity), $session->identity->did, $audience, $method, $lifetime)];
	}

	/**
	 * The account a service-auth token for `uploadBlob` speaks for: the
	 * video service storing a video, signed by the account's own key and
	 * addressed to this server. Null for any other kind of token, which is
	 * a session's and is checked as one.
	 *
	 * @throws XrpcException for a service-auth token that is not good
	 */
	public function uploader(string $authorization): ?ClientSession {
		if (preg_match('/^Bearer\s+([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', trim($authorization), $m) !== 1) {
			return null;
		}
		$header = json_decode(Encoding::base64UrlDecode($m[1]), true);
		$claims = json_decode(Encoding::base64UrlDecode($m[2]), true);
		if (!is_array($header) || !is_array($claims) || ($header['typ'] ?? '') === 'at+jwt' || !isset($claims['iss'])) {
			return null;
		}
		$did = (string)$claims['iss'];
		if (!Syntax::isDid($did) || !$this->isThisServer((string)($claims['aud'] ?? '')) || ($claims['lxm'] ?? '') !== self::UPLOAD) {
			throw new XrpcException(401, 'InvalidToken', 'This token is not for uploading here');
		}
		$now = $this->time->getTime();
		$exp = (int)($claims['exp'] ?? 0);
		if ($exp < $now || $exp > $now + self::MAX_LIFETIME + self::SKEW) {
			throw new XrpcException(400, 'ExpiredToken', 'Token has expired');
		}
		try {
			$identity = $this->identities->getByDid($did);
			$valid = $this->identities->signingKey($identity)->publicKey()->verify($m[1] . '.' . $m[2], Encoding::base64UrlDecode($m[3]));
			$userId = $this->actors->getFromId($identity->actorId)->getUserId();
		} catch (Throwable) {
			$valid = false;
		}
		if (!$valid || !isset($identity, $userId) || $userId === '') {
			throw new XrpcException(401, 'InvalidToken', 'Token could not be verified');
		}
		if (!$identity->isActive()) {
			throw new XrpcException(400, 'AccountDeactivated', 'This account is deactivated');
		}

		return new ClientSession($userId, $identity, '');
	}

	private function allowed(string $audience, string $method): bool {
		if ($this->isThisServer($audience)) {
			return $method === self::UPLOAD;
		}

		return $audience !== '' && $audience === $this->config->videoServiceDid() && in_array($method, self::VIDEO_METHODS, true);
	}

	/**
	 * This server's `did:web`, with a port written either way: an app makes
	 * the audience from the host of the PDS address, where the port is not
	 * encoded.
	 */
	private function isThisServer(string $audience): bool {
		return $audience !== '' && str_ireplace('%3A', ':', $audience) === str_ireplace('%3A', ':', $this->config->serviceDid());
	}
}
