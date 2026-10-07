<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CountsService;
use OCP\Server;

/**
 * This app, as a client app sees it: its Mastodon-compatible API, over HTTP,
 * at the address the other servers know it by.
 *
 * The token is minted the way `/oauth/token` mints one — an app
 * registration, an authorization for one account and the exchange of its
 * code — so every request is authenticated exactly as a phone app's is.
 */
class Here extends ClientApi {
	private const SCOPES = ['read', 'write', 'follow'];

	/** @var array<string, string> a token per local user, minted once */
	private static array $tokens = [];

	public function __construct(
		string $baseUrl,
		private string $token,
		private string $userId,
	) {
		parent::__construct($baseUrl);
	}

	/** A client acting as one local user. */
	public static function forUser(string $userId): self {
		$base = rtrim(Server::get(ConfigService::class)->getSocialUrl(), '/');
		// counts are hidden by default; these tests assert what was counted
		Server::get(CountsService::class)->setHides($userId, false);

		return new self($base, self::$tokens[$userId] ??= self::mint($userId), $userId);
	}

	#[\Override]
	public function name(): string {
		return 'this app (' . $this->userId . ')';
	}

	#[\Override]
	protected function authorization(): string {
		return 'Bearer ' . $this->token;
	}

	private static function mint(string $userId): string {
		$clientService = Server::get(ClientService::class);
		$actor = Server::get(AccountService::class)->getActorFromUserId($userId, true);

		$client = new SocialClient();
		$client->setAppName('interop')
			->setAppWebsite('https://example.com')
			->setAppRedirectUris([ClientService::REDIRECT_URI_OOB])
			->setAppScopes(self::SCOPES);
		$clientService->createApp($client);

		$client->setAuthScopes(self::SCOPES)
			->setAuthAccount($actor->getPreferredUsername())
			->setAuthUserId($userId)
			->setAuthRedirectUri(ClientService::REDIRECT_URI_OOB);
		$clientService->authClient($client);

		return $clientService->exchangeCode(
			$client, $client->getAuthCode(), '', ClientService::REDIRECT_URI_OOB
		)->getToken();
	}
}
