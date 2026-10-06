<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

/**
 * The Mastodon on the other side, read through its own client API.
 *
 * Its API rather than its database, for two reasons. It is the same answer a
 * Mastodon *user* would get, which is what "did it arrive" means — a row in
 * `statuses` that its own serialiser refuses to render has not arrived. And it
 * works over a network, so the same suite runs against the container in CI and
 * against a Mastodon somebody has standing.
 *
 * One instance of this per Mastodon account: the token decides who is asking.
 */
class Mastodon extends ClientApi {
	public function __construct(
		string $baseUrl,
		private string $token,
		private string $username = '',
	) {
		parent::__construct($baseUrl);
	}

	/**
	 * Whether this suite has a Mastodon to talk to at all, as the account the
	 * workflow made a token for in `$tokenVariable`.
	 *
	 * `MASTODON_TOKEN` is `interop`, the account most tests act as. The others
	 * are there for what one account cannot show: `stranger` follows nobody
	 * here, and `guarded` is locked.
	 */
	public static function fromEnvironment(string $tokenVariable = 'MASTODON_TOKEN', string $username = 'interop'): ?self {
		$base = (string)getenv('MASTODON_BASE_URL');
		$token = (string)getenv($tokenVariable);
		if ($base === '' || $token === '') {
			return null;
		}

		return new self($base, $token, $username);
	}

	/** The Mastodon host, as it appears in a handle. */
	public static function host(): string {
		$host = (string)getenv('MASTODON_HOST');

		return ($host !== '') ? $host : (string)parse_url((string)getenv('MASTODON_BASE_URL'), PHP_URL_HOST);
	}

	/** The handle of the account this client acts as, `user@host`. */
	public function handle(): string {
		return $this->username . '@' . self::host();
	}

	/** The ActivityPub id of the account this client acts as. */
	public function actorUri(): string {
		return 'https://' . self::host() . '/users/' . $this->username;
	}

	/** Mastodon's id for one of its own accounts, by username. */
	public function accountId(string $username): string {
		$account = $this->get('/api/v1/accounts/lookup', ['acct' => $username]);
		$id = (string)($account['id'] ?? '');
		if ($id === '') {
			throw new \RuntimeException('Mastodon has no account ' . $username . ': ' . json_encode($account));
		}

		return $id;
	}

	/** Mastodon's id of the account this client acts as. */
	public function ownId(): string {
		return (string)($this->get('/api/v1/accounts/verify_credentials')['id'] ?? '');
	}

	#[\Override]
	public function name(): string {
		return 'Mastodon (' . $this->username . ')';
	}

	#[\Override]
	protected function authorization(): string {
		return 'Bearer ' . $this->token;
	}
}
