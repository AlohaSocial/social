<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * One authorization per (app, account): `social_client_auth`.
 *
 * `social_client` held the whole of an authorization in its own row — one
 * `auth_code`, one `auth_user_id`, one `token` — so an app row could belong to
 * exactly one person at a time. Elk, Phanpy and every other client that
 * registers one app per instance therefore signed the previous user out the
 * moment a second one signed in: `authClient()` blanked the token on purpose,
 * because leaving it would have let the old token act as the new user.
 *
 * The app registration stays where it is. What moves here is everything about
 * *who authorized it*: the code, the token, the scopes granted and the account
 * they were granted to. Unique on (client, account), so re-authorizing replaces
 * rather than accumulating, which is what Mastodon does and what keeps this
 * table the size of the number of people using the instance.
 *
 * The existing authorization on every client row is carried across, so a token
 * in use today goes on working.
 */
class Version1000Date20260912000010 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * The columns this step used to add are in `Version1000Date20221118000002`,
	 * which describes the whole schema and runs before this. What is left here
	 * is the half a schema cannot express: the rows.
	 */

	/**
	 * Carries the authorization already on each client row into a row of its
	 * own, so a token in use today goes on working.
	 *
	 * Only rows that have one: an app registered and never authorized has
	 * nothing to move, and a row whose token was already blanked has nothing
	 * worth keeping.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_client_auth') || !$schema->hasTable('social_client')) {
			return;
		}

		$read = $this->connection->getQueryBuilder();
		$read->select('id', 'auth_user_id', 'auth_account', 'auth_scopes', 'auth_code', 'token', 'creation', 'last_update')
			->from('social_client')
			->where($read->expr()->neq('auth_user_id', $read->createNamedParameter('')));

		$moved = 0;
		$cursor = $read->executeQuery();
		while ($row = $cursor->fetch()) {
			if ((string)($row['token'] ?? '') === '' && (string)($row['auth_code'] ?? '') === '') {
				// nothing to carry: no live token and no code to exchange
				continue;
			}

			$write = $this->connection->getQueryBuilder();
			$write->insert('social_client_auth')
				->setValue('client_id', $write->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT))
				->setValue('user_id', $write->createNamedParameter((string)$row['auth_user_id']))
				->setValue('account', $write->createNamedParameter((string)($row['auth_account'] ?? '')))
				->setValue('scopes', $write->createNamedParameter((string)($row['auth_scopes'] ?? '[]')))
				->setValue('code', $write->createNamedParameter((string)($row['auth_code'] ?? '')))
				->setValue('token', $write->createNamedParameter((string)($row['token'] ?? '')))
				->setValue('creation', $write->createNamedParameter($row['creation']))
				->setValue('last_update', $write->createNamedParameter($row['last_update']));

			try {
				$write->executeStatement();
				$moved++;
			} catch (\Throwable $e) {
				// a duplicate can only mean this step already ran
				$output->warning('could not carry an authorization across: ' . $e->getMessage());
			}
		}
		$cursor->closeCursor();

		$output->info($moved . ' OAuth authorization(s) moved to social_client_auth');
	}
}
