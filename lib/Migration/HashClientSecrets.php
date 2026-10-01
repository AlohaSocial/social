<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Service\ConfigService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Rewrites plaintext OAuth client secrets, authorization codes and access
 * tokens into their sha256 form. New values are written hashed from the
 * start; this only exists for rows created before that.
 *
 * The marker is what keeps it affordable. Coming back empty still meant running
 * the query, on every `occ upgrade` for the life of the instance, with the
 * instance in maintenance mode. It is only set once a run finishes with nothing
 * left behind, so a row that could not be rewritten is still retried on the
 * next upgrade.
 *
 * A row that cannot be rewritten is reported and left alone rather than
 * allowed to end the upgrade: the plaintext form is still understood on
 * lookup, so the client keeps working and the next upgrade tries again.
 */
class HashClientSecrets implements IRepairStep {
	/**
	 * The secrets each table holds, by table.
	 *
	 * `social_client` is the app registration and keeps its own secret; the
	 * code and the token moved to `social_client_auth` when an app row stopped
	 * holding one person's authorization. A legacy plaintext token carried
	 * across by that migration is still plaintext, and is still understood on
	 * lookup, so it is this step that has to find it — reading only the first
	 * table would leave it in the clear for ever.
	 */
	private const TABLES = [
		CoreRequestBuilder::TABLE_CLIENT => ['app_client_secret', 'auth_code', 'token'],
		CoreRequestBuilder::TABLE_CLIENT_AUTH => ['code', 'token'],
	];

	private const MARKER = 'migration_client_secrets_hashed';

	public function __construct(
		private IDBConnection $connection,
		private SecretHasher $secretHasher,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Hash the stored Social OAuth client secrets and tokens';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) === 1) {
			return;
		}

		$converted = 0;
		$failed = [];
		foreach (self::TABLES as $table => $columns) {
			foreach ($this->unhashedRows($table, $columns) as $row) {
				// one row that cannot be written must not end the upgrade with
				// the instance in maintenance mode. A row left behind still
				// works — the plaintext form is understood on lookup — and the
				// next upgrade picks it up again.
				try {
					$converted += $this->hashRow($table, $columns, $row);
				} catch (Throwable $t) {
					$failed[] = $table . '#' . $row['id'];
					$output->warning(
						'could not hash the credentials of the Social OAuth client ' . $row['id']
						. ': ' . $t->getMessage()
					);
				}
			}
		}

		if ($converted > 0) {
			$output->info('Hashed the credentials of ' . $converted . ' Aloha Social OAuth client(s)');
		}

		if ($failed === []) {
			$this->configService->setAppValue(self::MARKER, '1');
		} else {
			$output->warning(
				'The credentials of ' . count($failed) . ' Aloha Social OAuth client(s) are still stored '
				. 'in plaintext: ' . implode(', ', $failed) . '. They keep working, and the next '
				. 'upgrade will try again.'
			);
		}
	}

	/**
	 * @param string[] $columns
	 * @param array<string, mixed> $row
	 *
	 * @return int 1 if the row was rewritten
	 */
	private function hashRow(string $table, array $columns, array $row): int {
		$update = $this->connection->getQueryBuilder();
		$update->update($table);

		$dirty = false;
		foreach ($columns as $column) {
			$value = (string)($row[$column] ?? '');
			if ($value === '' || $this->secretHasher->isHashed($value)) {
				continue;
			}
			$update->set($column, $update->createNamedParameter($this->secretHasher->hash($value)));
			$dirty = true;
		}

		if (!$dirty) {
			return 0;
		}

		$update->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])));
		$update->executeStatement();

		return 1;
	}

	/**
	 * The rows that still hold something in plaintext.
	 *
	 * Selected by the database rather than by reading the table and deciding
	 * here: this runs on every single upgrade, and on all but the first one
	 * there is nothing left to convert — which should cost one query that
	 * returns no rows, not the whole `social_client` table hydrated into PHP.
	 *
	 * @return array<array<string, mixed>>
	 */
	private function unhashedRows(string $table, array $columns): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', ...$columns)
			->from($table);

		$prefix = $this->hashedPrefix();
		if ($prefix !== '') {
			$pattern = $qb->createNamedParameter(
				$this->connection->escapeLikeParameter($prefix) . '%'
			);

			$plaintext = [];
			foreach ($columns as $column) {
				$plaintext[] = $qb->expr()->andX(
					$qb->expr()->nonEmptyString($column),
					$qb->expr()->notLike($column, $pattern)
				);
			}
			$qb->where($qb->expr()->orX(...$plaintext));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The marker a hashed value carries, asked of the hasher itself so the two
	 * cannot drift apart. Empty when the hasher no longer uses one, in which
	 * case every row is read and the decision is made here, as it used to be.
	 */
	private function hashedPrefix(): string {
		$hashed = $this->secretHasher->hash('probe');
		$separator = strpos($hashed, ':');
		if ($separator === false) {
			return '';
		}

		$prefix = substr($hashed, 0, $separator + 1);

		return $this->secretHasher->isHashed($prefix) ? $prefix : '';
	}
}
