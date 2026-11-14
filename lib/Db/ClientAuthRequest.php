<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Who has authorized which app, and with what.
 *
 * `social_client` used to hold this in its own row — one code, one account,
 * one token — so an app registration belonged to exactly one person at a time
 * and the second person to sign in with a client signed the first one out.
 * Everything about *who authorized* lives here instead; the app registration
 * stays where it was.
 *
 * Every read joins the app row, so what comes back is still one `SocialClient`
 * carrying both halves: the app it is, and the account it is acting for.
 */
class ClientAuthRequest extends ClientRequestBuilder {
	/** How often replace() deletes and inserts before it gives up on a busy row. */
	private const REPLACE_ATTEMPTS = 3;

	public function __construct(
		IDBConnection $connection,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		ConfigService $configService,
		MiscService $miscService,
		SecretHasher $secretHasher,
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService, $secretHasher);
	}

	/**
	 * Records an authorization, replacing whatever that account had granted
	 * this app before.
	 *
	 * Replacing rather than adding: a second authorization by the same person
	 * against the same app is them saying "start again", and Mastodon reads it
	 * the same way. The token is emptied with the row — leaving the old one
	 * live would let a token granted under the old scopes act under the new
	 * ones.
	 *
	 * The PKCE challenge is stored as the client sent it: it is a digest
	 * already, and `exchangeCode()` compares the digest of the presented
	 * verifier against it. The `redirect_uri` the code was issued for is
	 * stored beside it, so the exchange can require the same one.
	 */
	public function authorize(
		int $clientId,
		string $userId,
		string $account,
		array $scopes,
		string $code,
		string $codeChallenge = '',
		string $codeChallengeMethod = '',
		string $redirectUri = '',
	): void {
		$this->replace($clientId, $userId, [
			'account' => $account,
			'scopes' => (string)json_encode($scopes),
			'code' => $this->secretHasher->hash($code),
			'code_challenge' => $codeChallenge,
			'code_challenge_method' => $codeChallengeMethod,
			'redirect_uri' => $redirectUri,
			'token' => '',
		]);

		// the app row's own last_update marks it as having been authorized at
		// least once, which keeps it out of ClientRequest::deleteNeverAuthorized();
		// a second on, so an app authorized the second it registered differs too
		$app = $this->getQueryBuilder();
		$app->update(self::TABLE_CLIENT)
			->set('last_update', $app->createNamedParameter(new DateTime('+1 second'), IQueryBuilder::PARAM_DATE))
			->where($app->expr()->eq('id', $app->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
		$app->executeStatement();
	}

	/**
	 * Records an app token: a row of the app's that names no account.
	 *
	 * Stored like an authorization, with an empty `user_id`, so that
	 * `getByToken()` reads it back as a `SocialClient` whose `auth_user_id`
	 * is empty -- which is what tells the routes it acts for nobody. One per
	 * app, replaced by the next (the (client, user) index is unique), and
	 * the app row is marked as used, as `authorize()` marks it, so a client
	 * that only ever took an app token is not swept as never authorized.
	 *
	 * @param string[] $scopes
	 */
	public function issueAppToken(int $clientId, array $scopes, string $token): void {
		$this->replace($clientId, '', [
			'account' => '',
			'scopes' => (string)json_encode($scopes),
			'code' => '',
			'code_challenge' => '',
			'code_challenge_method' => '',
			'redirect_uri' => '',
			'token' => $this->secretHasher->hash($token),
		]);

		$app = $this->getQueryBuilder();
		$app->update(self::TABLE_CLIENT)
			->set('last_update', $app->createNamedParameter(new DateTime('+1 second'), IQueryBuilder::PARAM_DATE))
			->where($app->expr()->eq('id', $app->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
		$app->executeStatement();
	}

	/**
	 * Exchanges a code for a token, on the authorization the code names.
	 *
	 * The code is spent here: it is emptied in the same statement that writes
	 * the token, so the same code cannot be exchanged twice even if two
	 * requests arrive together — the second updates no row and is told the
	 * code is unknown.
	 *
	 * @throws ClientNotFoundException the code names no live authorization
	 */
	public function exchange(int $clientId, string $code, string $token, string $refreshToken = ''): SocialClient {
		$auth = $this->getByCode($clientId, $code);
		$now = new DateTime('now');

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CLIENT_AUTH)
			->set('token', $qb->createNamedParameter($this->secretHasher->hash($token)))
			->set('refresh_token', $qb->createNamedParameter($this->secretHasher->hash($refreshToken)))
			->set('token_creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->set('refresh_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('code', $qb->createNamedParameter(''))
			->set('code_challenge', $qb->createNamedParameter(''))
			->set('last_update', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			// the authorization row, not the app row: a joined read carries both
			->where($qb->expr()->eq('id', $qb->createNamedParameter($auth->getAuthId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('code', $qb->createNamedParameter('')));

		if ($qb->executeStatement() === 0) {
			throw new ClientNotFoundException('unknown code');
		}

		return $auth->setToken($token)
			->setRefreshToken($refreshToken)
			->setTokenCreation($now->getTimestamp())
			->setRefreshCount(0);
	}

	/**
	 * Puts a new access and refresh token in place of the ones an
	 * authorization holds, if the refresh token presented is still its
	 * current one, and remembers the presented one as spent.
	 *
	 * The comparison is in the statement that writes, so of two requests
	 * presenting the same refresh token at once only one rotates; the other
	 * updates no row and is told so.
	 *
	 * @param string[] $scopes
	 * @return bool whether the authorization was rotated
	 */
	public function rotate(int $authId, string $presented, string $token, string $refreshToken, array $scopes, int $now): bool {
		$spent = $this->secretHasher->hash($presented);
		$at = new DateTime('@' . $now);

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CLIENT_AUTH)
			->set('token', $qb->createNamedParameter($this->secretHasher->hash($token)))
			->set('refresh_token', $qb->createNamedParameter($this->secretHasher->hash($refreshToken)))
			->set('scopes', $qb->createNamedParameter((string)json_encode(array_values($scopes))))
			->set('token_creation', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATE))
			->set('refresh_count', $qb->createFunction('refresh_count + 1'))
			->set('last_update', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($authId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('refresh_token', $qb->createNamedParameter($spent)));

		if ($qb->executeStatement() === 0) {
			return false;
		}

		$this->dbConnection->insertIgnoreConflict(self::TABLE_CLIENT_REFRESH_SPENT, [
			'auth_id' => $authId,
			'token' => $spent,
			'creation' => $at->format('Y-m-d H:i:s'),
		]);

		return true;
	}

	/**
	 * The authorization a refresh token names, with the app it was granted to.
	 *
	 * @throws ClientNotFoundException
	 */
	public function getByRefreshToken(string $refreshToken): SocialClient {
		$stored = $this->secretHasher->forLookup($refreshToken);
		if ($stored === null) {
			throw new ClientNotFoundException();
		}

		return $this->getOne('a.refresh_token', $stored);
	}

	/**
	 * The authorization a refresh token was spent on, or null for a token
	 * that was never exchanged here.
	 */
	public function spentOn(string $refreshToken): ?int {
		$stored = $this->secretHasher->forLookup($refreshToken);
		if ($stored === null) {
			return null;
		}

		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('auth_id')
			->from(self::TABLE_CLIENT_REFRESH_SPENT)
			->where($qb->expr()->eq('token', $qb->createNamedParameter($stored)));
		$result = $qb->executeQuery();
		$authId = $result->fetchOne();
		$result->closeCursor();

		return ($authId === false) ? null : (int)$authId;
	}

	/**
	 * One authorization by its row, with the app it was granted to.
	 *
	 * @throws ClientNotFoundException
	 */
	public function getByAuthId(int $authId): SocialClient {
		$qb = $this->joined();
		$qb->andWhere($qb->expr()->eq('a.id', $qb->createNamedParameter($authId, IQueryBuilder::PARAM_INT)));

		return $this->getClientFromRequest($qb);
	}

	/**
	 * The authorization a token names, with the app it was granted to.
	 *
	 * @throws ClientNotFoundException
	 */
	public function getByToken(string $token): SocialClient {
		$stored = $this->secretHasher->forLookup($token);
		if ($stored === null) {
			throw new ClientNotFoundException();
		}

		return $this->getOne('a.token', $stored);
	}

	/**
	 * The authorization a code names, on that app.
	 *
	 * @throws ClientNotFoundException
	 */
	public function getByCode(int $clientId, string $code): SocialClient {
		$stored = $this->secretHasher->forLookup($code);
		if ($stored === null) {
			throw new ClientNotFoundException('unknown code');
		}

		try {
			return $this->getOne('a.code', $stored, $clientId);
		} catch (ClientNotFoundException $e) {
			throw new ClientNotFoundException('unknown code');
		}
	}

	/**
	 * Keeps a token in use from ageing out, at most once a refresh window.
	 *
	 * @param int|null $now the moment of use; the wall clock when not given
	 */
	public function touch(int $id, ?int $now = null): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CLIENT_AUTH)
			->set('last_update', $qb->createNamedParameter(new DateTime('@' . ($now ?? time())), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/** Takes one authorization back. */
	public function revoke(int $id): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CLIENT_AUTH)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/** What one account has granted, newest first. @return SocialClient[] */
	public function getByUser(string $userId): array {
		$qb = $this->joined();
		$qb->andWhere($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
			->orderBy('a.id', 'desc');

		return $this->getClientsFromRequest($qb);
	}

	/**
	 * Everything an account leaves behind here when it is deleted. The app
	 * registrations are the instance's and stay.
	 */
	public function deleteRelatedId(string $userId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CLIENT_AUTH)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}

	/**
	 * The authorizations nobody has used since a moment.
	 *
	 * @return SocialClient[]
	 */
	public function getIdleSince(int $before): array {
		$qb = $this->joined();
		$qb->andWhere($qb->expr()->lt('a.last_update', $qb->createNamedParameter(new DateTime('@' . $before), IQueryBuilder::PARAM_DATE)));

		return $this->getClientsFromRequest($qb);
	}

	/**
	 * Drops authorizations nobody has used since a moment
	 * (`ClientService::idleSeconds()` before now), with their refresh tokens.
	 *
	 * The app registration is untouched, which is the other half of what the
	 * old sweep got wrong: it deleted the whole `social_client` row, so an
	 * idle token took the app's registration with it and the client had to
	 * register again.
	 */
	public function deprecate(int $before): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CLIENT_AUTH)
			->where($qb->expr()->lt('last_update', $qb->createNamedParameter(new DateTime('@' . $before), IQueryBuilder::PARAM_DATE)));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			$this->logger->warning('could not sweep expired authorizations', ['exception' => $e]);
		}
	}

	/**
	 * Forgets the spent refresh tokens of authorizations that are gone: a
	 * copy of one of those has nothing left to revoke.
	 */
	public function pruneSpentRefreshTokens(): void {
		$live = $this->dbConnection->getQueryBuilder();
		$live->select('id')->from(self::TABLE_CLIENT_AUTH);

		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_CLIENT_REFRESH_SPENT)
			->where($qb->expr()->notIn('auth_id', $qb->createFunction($live->getSQL())));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			$this->logger->warning('could not sweep spent refresh tokens', ['exception' => $e]);
		}
	}

	/**
	 * Puts one account's authorization of an app (or the app's own token, for
	 * an empty `$userId`) in place of whatever was there.
	 *
	 * Two requests can do this at once — a consent form sent twice, two
	 * workers of a bot asking for an app token together — and both delete
	 * before either inserts, so the second insert meets the unique
	 * (client, user) index. It is skipped rather than refused, and the
	 * request deletes and tries again: the last one to write wins, and
	 * neither fails.
	 *
	 * @param array<string, string> $values the row's columns but its key and dates
	 */
	private function replace(int $clientId, string $userId, array $values): void {
		$now = (new DateTime('now'))->format('Y-m-d H:i:s');
		$row = [
			'client_id' => $clientId,
			'user_id' => $userId,
		] + $values + [
			'creation' => $now,
			'last_update' => $now,
		];

		for ($attempt = 1; ; $attempt++) {
			$this->forget($clientId, $userId);
			if ($this->dbConnection->insertIgnoreConflict(self::TABLE_CLIENT_AUTH, $row) > 0) {
				return;
			}
			if ($attempt >= self::REPLACE_ATTEMPTS) {
				throw new RuntimeException('the authorization could not be stored');
			}
		}
	}

	private function forget(int $clientId, string $userId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CLIENT_AUTH)
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}

	/** @throws ClientNotFoundException */
	private function getOne(string $field, string $value, int $clientId = 0): SocialClient {
		$qb = $this->joined();
		$qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));

		if ($clientId > 0) {
			$qb->andWhere($qb->expr()->eq('a.client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
		}

		return $this->getClientFromRequest($qb);
	}

	/**
	 * An authorization and the app it is against, in the one shape the rest of
	 * the app already reads: a `SocialClient` whose `auth_*` fields are this
	 * account's rather than whoever authorized last.
	 */
	private function joined(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select(
			'cl.id', 'cl.app_name', 'cl.app_website', 'cl.app_redirect_uris', 'cl.app_client_id',
			'cl.app_client_secret', 'cl.app_scopes', 'cl.creation'
		)
			->selectAlias('a.id', 'auth_id')
			->selectAlias('a.scopes', 'auth_scopes')
			->selectAlias('a.account', 'auth_account')
			->selectAlias('a.user_id', 'auth_user_id')
			->selectAlias('a.code', 'auth_code')
			->selectAlias('a.code_challenge', 'auth_code_challenge')
			->selectAlias('a.code_challenge_method', 'auth_code_challenge_method')
			->selectAlias('a.redirect_uri', 'auth_redirect_uri')
			->selectAlias('a.token', 'token')
			->selectAlias('a.refresh_token', 'refresh_token')
			->selectAlias('a.token_creation', 'token_creation')
			->selectAlias('a.refresh_count', 'refresh_count')
			->selectAlias('a.last_update', 'last_update')
			// the authorization's own date, beside the app registration's: the
			// Authorized apps page wants to say when *this account* granted it
			->selectAlias('a.creation', 'auth_creation')
			->from(self::TABLE_CLIENT_AUTH, 'a')
			->innerJoin('a', self::TABLE_CLIENT, 'cl', $qb->expr()->eq('a.client_id', 'cl.id'));

		$this->defaultSelectAlias = 'a';
		$qb->setDefaultSelectAlias('a');

		return $qb;
	}
}
