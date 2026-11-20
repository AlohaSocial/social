<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ConfigService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Rewrites the actor private keys stored as bare PEM into their encrypted
 * form; new keys are written encrypted. A marker, set only once nothing is
 * left behind, skips the scan on later upgrades. A row that cannot be
 * encrypted is reported and left readable for the next upgrade.
 */
class EncryptPrivateKeys implements IRepairStep {
	private const MARKER = 'migration_actor_keys_encrypted';

	public function __construct(
		private IDBConnection $connection,
		private PrivateKeyCipher $keyCipher,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Encrypt the stored Aloha Social actor private keys';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) === 1) {
			return;
		}

		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'private_key')
			->from(CoreRequestBuilder::TABLE_ACTORS)
			->where($select->expr()->like(
				'private_key',
				$select->createNamedParameter(
					$this->connection->escapeLikeParameter('-----BEGIN') . '%'
				)
			));

		$plain = [];
		$result = $select->executeQuery();
		while ($row = $result->fetch()) {
			$plain[] = $row;
		}
		$result->closeCursor();

		if ($plain === []) {
			$this->configService->setAppValue(self::MARKER, '1');

			return;
		}

		$encrypted = 0;
		$failed = [];
		$output->startProgress(count($plain));
		foreach ($plain as $row) {
			// One unusable row must not fail the upgrade: seal() throws when the
			// instance secret is missing or changed. The key stays readable for the
			// next run.
			try {
				$update = $this->connection->getQueryBuilder();
				$update->update(CoreRequestBuilder::TABLE_ACTORS)
					->set('private_key', $update->createNamedParameter(
						$this->keyCipher->seal((string)$row['private_key'])
					))
					->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])));
				$update->executeStatement();
				$encrypted++;
			} catch (Throwable $t) {
				$failed[] = (string)$row['id'];
				$output->warning(
					'could not encrypt the private key of the Social actor ' . $row['id']
					. ': ' . $t->getMessage()
				);
			}
			$output->advance();
		}
		$output->finishProgress();

		if ($encrypted > 0) {
			$output->info('Encrypted the private key of ' . $encrypted . ' Aloha Social actor(s)');
		}

		if ($failed === []) {
			$this->configService->setAppValue(self::MARKER, '1');
		} else {
			$output->warning(
				'The private key of ' . count($failed) . ' Aloha Social actor(s) is still stored in '
				. 'plaintext: ' . implode(', ', $failed) . '. They keep working; check that the '
				. 'instance secret is readable, and the next upgrade will try again.'
			);
		}
	}
}
