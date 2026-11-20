<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Security\SecretHasher;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Throwable;

/**
 * Hashes the last OAuth credentials still stored in plaintext, or deletes
 * them when they cannot be hashed (the client signs in again). Lookups only
 * accept `SecretHasher` digests, so a plaintext row would be unusable. A
 * migration, so it runs once before any request is answered.
 */
class Version1000Date20261008000100 extends SimpleMigrationStep {
	/**
	 * The secrets each table holds, by table. `social_client` keeps the app's
	 * own secret, plus `auth_code` and `token` columns that nothing reads but
	 * that are still credentials at rest.
	 */
	private const TABLES = [
		CoreRequestBuilder::TABLE_CLIENT => ['app_client_secret', 'auth_code', 'token'],
		CoreRequestBuilder::TABLE_CLIENT_AUTH => ['code', 'token'],
	];

	public function __construct(
		private IDBConnection $connection,
		private SecretHasher $secretHasher,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$hashed = 0;
		$revoked = 0;
		foreach (self::TABLES as $table => $columns) {
			foreach ($this->unhashedRows($table, $columns) as $row) {
				try {
					$this->hashRow($table, $columns, $row);
					$hashed++;
				} catch (Throwable $t) {
					$output->warning(
						'could not hash the credentials of ' . $table . '#' . $row['id']
						. ', taking them back instead: ' . $t->getMessage()
					);
					$this->revoke($table, $row['id'], $output);
					$revoked++;
				}
			}
		}

		if ($hashed > 0) {
			$output->info('Hashed ' . $hashed . ' Aloha Social OAuth credential row(s)');
		}
		if ($revoked > 0) {
			$output->warning(
				'Took back ' . $revoked . ' Aloha Social OAuth credential row(s) that could not be '
				. 'hashed; the apps concerned will ask their users to sign in again.'
			);
		}
	}

	/**
	 * @param string[] $columns
	 * @param array<string, mixed> $row
	 */
	private function hashRow(string $table, array $columns, array $row): void {
		$update = $this->connection->getQueryBuilder();
		$update->update($table);

		foreach ($columns as $column) {
			$value = (string)($row[$column] ?? '');
			if ($value === '' || $this->secretHasher->isHashed($value)) {
				continue;
			}
			$update->set($column, $update->createNamedParameter($this->secretHasher->hash($value)));
		}

		$update->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])));
		$update->executeStatement();
	}

	/**
	 * Deletes a row whose credentials could not be hashed, and for an app
	 * registration every authorization against it, whose tokens would name an
	 * app that is gone.
	 */
	private function revoke(string $table, mixed $id, IOutput $output): void {
		try {
			if ($table === CoreRequestBuilder::TABLE_CLIENT) {
				$auth = $this->connection->getQueryBuilder();
				$auth->delete(CoreRequestBuilder::TABLE_CLIENT_AUTH)
					->where($auth->expr()->eq('client_id', $auth->createNamedParameter($id)));
				$auth->executeStatement();
			}

			$delete = $this->connection->getQueryBuilder();
			$delete->delete($table)
				->where($delete->expr()->eq('id', $delete->createNamedParameter($id)));
			$delete->executeStatement();
		} catch (Throwable $t) {
			// left in plaintext, which no lookup accepts: dead, and not worth
			// ending the upgrade over
			$output->warning('could not delete ' . $table . '#' . $id . ' either: ' . $t->getMessage());
		}
	}

	/**
	 * The rows that still hold something in plaintext, picked out by the
	 * database rather than by reading the table here.
	 *
	 * @param string[] $columns
	 *
	 * @return array<array<string, mixed>>
	 */
	private function unhashedRows(string $table, array $columns): array {
		$qb = $this->connection->getQueryBuilder();
		$pattern = $qb->createNamedParameter(
			$this->connection->escapeLikeParameter(SecretHasher::PREFIX) . '%'
		);

		$plaintext = [];
		foreach ($columns as $column) {
			$plaintext[] = $qb->expr()->andX(
				$qb->expr()->nonEmptyString($column),
				$qb->expr()->notLike($column, $pattern)
			);
		}

		$qb->select('id', ...$columns)
			->from($table)
			->where($qb->expr()->orX(...$plaintext));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}
}
