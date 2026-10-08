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
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

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
		$this->forget($clientId, $userId);

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CLIENT_AUTH)
			->setValue('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT))
			->setValue('user_id', $qb->createNamedParameter($userId))
			->setValue('account', $qb->createNamedParameter($account))
			->setValue('scopes', $qb->createNamedParameter((string)json_encode($scopes)))
			->setValue('code', $qb->createNamedParameter($this->secretHasher->hash($code)))
			->setValue('code_challenge', $qb->createNamedParameter($codeChallenge))
			->setValue('code_challenge_method', $qb->createNamedParameter($codeChallengeMethod))
			->setValue('redirect_uri', $qb->createNamedParameter($redirectUri))
			->setValue('token', $qb->createNamedParameter(''))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->setValue('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();

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
	 * Exchanges a code for a token, on the authorization the code names.
	 *
	 * The code is spent here: it is emptied in the same statement that writes
	 * the token, so the same code cannot be exchanged twice even if two
	 * requests arrive together — the second updates no row and is told the
	 * code is unknown.
	 *
	 * @throws ClientNotFoundException the code names no live authorization
	 */
	public function exchange(int $clientId, string $code, string $token): SocialClient {
		$auth = $this->getByCode($clientId, $code);

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CLIENT_AUTH)
			->set('token', $qb->createNamedParameter($this->secretHasher->hash($token)))
			->set('code', $qb->createNamedParameter(''))
			->set('code_challenge', $qb->createNamedParameter(''))
			->set('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			// the authorization row, not the app row: a joined read carries both
			->where($qb->expr()->eq('id', $qb->createNamedParameter($auth->getAuthId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('code', $qb->createNamedParameter('')));

		if ($qb->executeStatement() === 0) {
			throw new ClientNotFoundException('unknown code');
		}

		return $auth->setToken($token);
	}

	/**
	 * The authorization a token names, with the app it was granted to.
	 *
	 * @throws ClientNotFoundException
	 */
	public function getByToken(string $token): SocialClient {
		// tokens are stored hashed; rows from before hashing hold the bare value
		foreach ($this->secretHasher->forLookup($token) as $stored) {
			if ($stored === '') {
				continue;
			}

			try {
				return $this->getOne('a.token', $stored);
			} catch (ClientNotFoundException $e) {
			}
		}

		throw new ClientNotFoundException();
	}

	/**
	 * The authorization a code names, on that app.
	 *
	 * @throws ClientNotFoundException
	 */
	public function getByCode(int $clientId, string $code): SocialClient {
		foreach ($this->secretHasher->forLookup($code) as $stored) {
			if ($stored === '') {
				continue;
			}

			try {
				return $this->getOne('a.code', $stored, $clientId);
			} catch (ClientNotFoundException $e) {
			}
		}

		throw new ClientNotFoundException('unknown code');
	}

	/** Keeps a token in use from ageing out, at most once a refresh window. */
	public function touch(int $id): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CLIENT_AUTH)
			->set('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
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
	 * Drops authorizations nobody has used inside the TTL.
	 *
	 * The app registration is untouched, which is the other half of what the
	 * old sweep got wrong: it deleted the whole `social_client` row, so an
	 * idle token took the app's registration with it and the client had to
	 * register again.
	 */
	public function deprecate(): void {
		$qb = $this->getQueryBuilder();
		$date = new DateTime();
		$date->setTimestamp(time() - ClientService::TIME_TOKEN_TTL);

		$qb->delete(self::TABLE_CLIENT_AUTH)
			->where($qb->expr()->lt('last_update', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			$this->logger->warning('could not sweep expired authorizations', ['exception' => $e]);
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
