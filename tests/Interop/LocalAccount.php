<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use CURLFile;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CountsService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Service\VideoDeliveryHold;
use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\Service\VideoTranscodingWorker;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use RuntimeException;

/**
 * A fresh account on this instance, driven through this app's own
 * Mastodon-compatible API over https, the way a client drives it.
 *
 * Fresh per test, under a name no earlier run used, so every test starts with
 * nobody following anybody and nothing posted; the user, the actor and the
 * bearer token are made in-process the way the OAuth flow makes them, and
 * everything after that goes over the wire to `https://nextcloud.test`.
 *
 * What it cannot do over the wire is wait for cron: `settle()` runs the two
 * queues a cron pass would, here and now.
 */
class LocalAccount {
	private const SCOPES = ['read', 'write', 'follow', 'push'];

	public function __construct(
		public readonly string $userId,
		public readonly Person $actor,
		private string $token,
		private string $apiBase,
	) {
	}

	/** A new user, its actor and a bearer token for it. */
	public static function create(string $prefix): self {
		$userId = $prefix . bin2hex(random_bytes(4));
		$user = Server::get(IUserManager::class)->createUser($userId, 'Interop-' . bin2hex(random_bytes(12)) . '!');
		if ($user === false) {
			throw new RuntimeException('could not create the user ' . $userId);
		}

		$actor = Server::get(AccountService::class)->getActorFromUserId($userId, true);
		// counts are hidden by default; these tests assert what was counted
		Server::get(CountsService::class)->setHides($userId, false);
		Here::acceptEverybody($userId);

		$clients = Server::get(ClientService::class);
		$client = new SocialClient();
		$client->setAppName('interop')
			->setAppWebsite('https://example.com')
			->setAppRedirectUris([ClientService::REDIRECT_URI_OOB])
			->setAppScopes(self::SCOPES);
		$clients->createApp($client);
		$client->setAuthScopes(self::SCOPES)
			->setAuthAccount($actor->getPreferredUsername())
			->setAuthUserId($userId)
			->setAuthRedirectUri(ClientService::REDIRECT_URI_OOB);
		$clients->authClient($client);
		$token = $clients->exchangeCode($client, $client->getAuthCode(), '', ClientService::REDIRECT_URI_OOB)->getToken();

		$socialUrl = rtrim(Server::get(ConfigService::class)->getSocialUrl(), '/');

		return new self($userId, $actor, $token, $socialUrl);
	}

	/** `@name@host`, as another server addresses this account. */
	public function handle(): string {
		return '@' . $this->actor->getPreferredUsername() . '@' . Server::get(ConfigService::class)->getCloudAuthority();
	}

	/** This account's id in this app's API. */
	public function accountId(): string {
		return (string)$this->get('/api/v1/accounts/verify_credentials')['id'];
	}

	/**
	 * This app's account id for a remote handle, fetching it if this is the
	 * first time anybody here asked.
	 */
	public function resolve(string $handle): string {
		$found = $this->get('/api/v2/search', ['q' => $handle, 'type' => 'accounts', 'resolve' => 'true']);
		$account = $found['accounts'][0] ?? null;
		if (!is_array($account) || ($account['id'] ?? '') === '') {
			throw new RuntimeException('could not resolve ' . $handle . ' here: ' . json_encode($found));
		}

		return (string)$account['id'];
	}

	/** @return array<string, mixed> */
	public function account(string $accountId): array {
		return $this->get('/api/v1/accounts/' . rawurlencode($accountId));
	}

	/** @return array<string, mixed> */
	public function follow(string $accountId): array {
		return $this->post('/api/v1/accounts/' . rawurlencode($accountId) . '/follow');
	}

	/** @return array<string, mixed> */
	public function unfollow(string $accountId): array {
		return $this->post('/api/v1/accounts/' . rawurlencode($accountId) . '/unfollow');
	}

	/** @return array<string, mixed> Mastodon's Relationship entity */
	public function relationship(string $accountId): array {
		$found = $this->get('/api/v1/accounts/relationships', ['id[]' => $accountId]);

		return (is_array($found[0] ?? null) ? $found[0] : []) + ['following' => false, 'followed_by' => false];
	}

	/**
	 * The accounts following this one, by their handles.
	 *
	 * @return string[]
	 */
	public function followerHandles(): array {
		return array_map(
			static fn (array $account): string => (string)($account['acct'] ?? ''),
			array_filter($this->get('/api/v1/accounts/' . rawurlencode($this->accountId()) . '/followers'), 'is_array')
		);
	}

	/**
	 * Uploads a video and publishes it as a public post, the two calls a
	 * client makes.
	 *
	 * @return array<string, mixed> the status
	 */
	public function postVideo(string $path, string $words): array {
		$media = $this->request('POST', '/api/v1/media', [
			'file' => new CURLFile($path, 'video/mp4', 'interop-' . bin2hex(random_bytes(4)) . '.mp4'),
			'description' => 'an interop test video',
		], true);
		if (($media['id'] ?? '') === '') {
			throw new RuntimeException('the upload made no attachment: ' . json_encode($media));
		}

		// an H.264 MP4 is recorded as needing no conversion while it is still
		// on disk; otherwise the post's delivery waits for the transcoder
		$document = Server::get(CacheDocumentsRequest::class)->getByNid((string)$media['id']);
		if ($document->getTranscoded() !== VideoTranscodingWorker::NOT_NEEDED) {
			$rows = Server::get(IDBConnection::class)->getQueryBuilder();
			$rows->select('nid', 'id', 'account', 'media_type', 'transcoded', 'local_copy', 'size')
				->from('social_cache_doc')
				->where($rows->expr()->eq('account', $rows->createNamedParameter($this->actor->getPreferredUsername())));
			throw new RuntimeException(
				'the upload left an H.264 MP4 marked ' . $document->getTranscoded() . ', not as needing no conversion,'
				. ' so its delivery is held for ' . VideoDeliveryHold::HOLD_SECONDS . ' seconds. Upload: '
				. json_encode($media) . ' Rows of the account: ' . json_encode($rows->executeQuery()->fetchAll())
				. ' Transcoder now: ' . json_encode([
					'enabled' => Server::get(VideoTranscodeService::class)->isEnabled(),
					'needs' => Server::get(VideoTranscodeService::class)->needsConversion('video/mp4', (string)getenv('LOOPS_VIDEO')),
				])
			);
		}

		return $this->post('/api/v1/statuses', [
			'status' => $words,
			'media_ids' => [(string)$media['id']],
			'visibility' => 'public',
		]);
	}

	/**
	 * Publishes a public post with one file, uploaded as the web client
	 * uploads it, with no expectation of the transcoder.
	 *
	 * @return array<string, mixed> the status
	 */
	public function postFile(string $path, string $mime, string $words): array {
		$media = $this->request('POST', '/api/v1/media', [
			'file' => new CURLFile($path, $mime, 'interop-' . bin2hex(random_bytes(4)) . '.' . pathinfo($path, PATHINFO_EXTENSION)),
			'description' => 'an interop test file',
		], true);
		if (($media['id'] ?? '') === '') {
			throw new RuntimeException('the upload made no attachment: ' . json_encode($media));
		}

		return $this->post('/api/v1/statuses', [
			'status' => $words,
			'media_ids' => [(string)$media['id']],
			'visibility' => 'public',
		]);
	}

	/**
	 * Publishes a post, or a reply when `$inReplyTo` names a status.
	 *
	 * @param array<string, mixed> $extra anything else the call takes
	 * @return array<string, mixed> the status
	 */
	public function postStatus(string $words, ?string $inReplyTo = null, array $extra = []): array {
		$body = ['status' => $words, 'visibility' => 'public'] + $extra;
		if ($inReplyTo !== null) {
			$body['in_reply_to_id'] = $inReplyTo;
		}

		return $this->post('/api/v1/statuses', $body);
	}

	/** @return array<string, mixed> */
	public function editStatus(string $statusId, string $words): array {
		return $this->request('PUT', '/api/v1/statuses/' . rawurlencode($statusId), ['status' => $words]);
	}

	public function deleteStatus(string $statusId): void {
		$this->request('DELETE', '/api/v1/statuses/' . rawurlencode($statusId));
	}

	/** @return array<string, mixed> */
	public function favourite(string $statusId): array {
		return $this->post('/api/v1/statuses/' . rawurlencode($statusId) . '/favourite');
	}

	/** @return array<string, mixed> */
	public function unfavourite(string $statusId): array {
		return $this->post('/api/v1/statuses/' . rawurlencode($statusId) . '/unfavourite');
	}

	/**
	 * One status, or null where this app answers that it has none.
	 *
	 * @return array<string, mixed>|null
	 */
	public function status(string $statusId): ?array {
		try {
			return $this->get('/api/v1/statuses/' . rawurlencode($statusId));
		} catch (RuntimeException $e) {
			if ($e->getCode() === 404) {
				return null;
			}

			throw $e;
		}
	}

	/** @return array{ancestors: array<int, array<string, mixed>>, descendants: array<int, array<string, mixed>>} */
	public function context(string $statusId): array {
		$context = $this->get('/api/v1/statuses/' . rawurlencode($statusId) . '/context');

		return [
			'ancestors' => array_values(array_filter($context['ancestors'] ?? [], 'is_array')),
			'descendants' => array_values(array_filter($context['descendants'] ?? [], 'is_array')),
		];
	}

	/**
	 * The home timeline narrowed to videos: what the Videos page and Shorts
	 * both read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function videos(): array {
		return array_values(array_filter($this->get('/api/v1/timelines/home', [
			'only_media' => 'true',
			'only_video' => 'true',
			'limit' => '40',
		]), 'is_array'));
	}

	/**
	 * The statuses of one account, as this app holds them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function statusesOf(string $accountId): array {
		return array_values(array_filter(
			$this->get('/api/v1/accounts/' . rawurlencode($accountId) . '/statuses', ['limit' => '40']),
			'is_array'
		));
	}

	/**
	 * This account's notifications, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function notifications(): array {
		return array_values(array_filter($this->get('/api/v1/notifications', ['limit' => '40']), 'is_array'));
	}

	/** @return array<string, mixed> */
	public function updateCredentials(string $displayName, string $note): array {
		return $this->request('PATCH', '/api/v1/accounts/update_credentials', [
			'display_name' => $displayName,
			'note' => $note,
		]);
	}

	/**
	 * Runs what a cron pass would: every delivery waiting in the request
	 * queue, then every fetch waiting in the stream queue.
	 */
	public static function settle(): void {
		$requests = Server::get(RequestQueueService::class);
		$activityService = Server::get(ActivityService::class);
		$activityService->manageInit();

		$total = 0;
		foreach ($requests->getRequestStandby($total) as $request) {
			$activityService->manageRequest($request);
		}

		$streams = Server::get(StreamQueueService::class);
		$total = 0;
		foreach ($streams->getRequestStandby($total) as $item) {
			$streams->manageStreamQueue($item);
		}
	}

	/**
	 * @param array<string, string> $query
	 * @return array<mixed>
	 */
	public function get(string $path, array $query = []): array {
		if ($query !== []) {
			$path .= '?' . http_build_query($query);
		}

		return $this->request('GET', $path);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function post(string $path, array $body = []): array {
		return $this->request('POST', $path, $body);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function patch(string $path, array $body = []): array {
		return $this->request('PATCH', $path, $body);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function put(string $path, array $body = []): array {
		return $this->request('PUT', $path, $body);
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<mixed>
	 */
	private function request(string $method, string $path, ?array $body = null, bool $multipart = false): array {
		$url = $this->apiBase . $path;
		$handle = curl_init($url);
		$headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];

		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, 120);
		if ($body !== null) {
			if ($multipart) {
				curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
			} else {
				$headers[] = 'Content-Type: application/json';
				curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
			}
		}
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

		$answer = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);

		if ($answer === false) {
			throw new RuntimeException('here: ' . $method . ' ' . $url . ' failed: ' . $error);
		}

		if ($status >= 400) {
			throw new RuntimeException(
				'here: ' . $method . ' ' . $url . ' answered ' . $status . ': ' . substr((string)$answer, 0, 500),
				$status
			);
		}

		$decoded = json_decode((string)$answer, true);

		return is_array($decoded) ? $decoded : [];
	}
}
