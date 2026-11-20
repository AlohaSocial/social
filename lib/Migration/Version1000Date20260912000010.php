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
 * One authorization per (app, account): `social_client_auth`, so several
 * people can sign in through the same app registration (Elk, Phanpy).
 * Unique on (client, account), so re-authorizing replaces, as in Mastodon.
 */
class Version1000Date20260912000010 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * Carries the authorization already on each client row into a row of its
	 * own, so a token in use keeps working. Rows never authorized or with a
	 * blanked token are skipped.
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
