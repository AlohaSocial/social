<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Tools\DbTime;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * What is recorded about an instance-wide block or silence: why, for the
 * moderators and for the published list, and since when.
 *
 * Whether a domain is blocked is decided by the two lists in app config
 * (`FediverseService`); a row here only describes an entry on one of them,
 * and goes when the domain leaves both.
 */
class ServerBlockRequest extends CoreRequestBuilder {
	/**
	 * Every row, keyed by domain.
	 *
	 * @return array<string, array{private_comment: ?string, public_comment: ?string, created: int}>
	 */
	public function all(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('domain', 'private_comment', 'public_comment', 'creation')
			->from(self::TABLE_SERVER_BLOCKS);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[(string)$data['domain']] = [
				'private_comment' => self::nullableText($data['private_comment'] ?? null),
				'public_comment' => self::nullableText($data['public_comment'] ?? null),
				'created' => DbTime::toTimestamp($data['creation'] ?? null),
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * Records the reasons for one domain.
	 *
	 * A null reason leaves what is stored alone, '' clears it. A domain with
	 * no row yet gets one, dated now.
	 */
	public function save(string $domain, ?string $privateComment, ?string $publicComment): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_SERVER_BLOCKS)
			->setValue('domain', $qb->createNamedParameter($domain))
			->setValue('private_comment', $qb->createNamedParameter(self::stored($privateComment)))
			->setValue('public_comment', $qb->createNamedParameter(self::stored($publicComment)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb) || ($privateComment === null && $publicComment === null)) {
			return;
		}

		$update = $this->getQueryBuilder();
		$update->update(self::TABLE_SERVER_BLOCKS);
		if ($privateComment !== null) {
			$update->set('private_comment', $update->createNamedParameter(self::stored($privateComment)));
		}
		if ($publicComment !== null) {
			$update->set('public_comment', $update->createNamedParameter(self::stored($publicComment)));
		}
		$update->where($update->expr()->eq('domain', $update->createNamedParameter($domain)));

		$update->executeStatement();
	}

	/**
	 * Removes the rows of domains that are on neither list any more.
	 *
	 * @param string[] $domains
	 */
	public function delete(array $domains): void {
		foreach (array_chunk(array_values(array_unique($domains)), 100) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->delete(self::TABLE_SERVER_BLOCKS)
				->where($qb->expr()->in('domain', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	private static function stored(?string $comment): ?string {
		return ($comment === null || $comment === '') ? null : $comment;
	}

	private static function nullableText(mixed $value): ?string {
		if (is_resource($value)) {
			$value = stream_get_contents($value);
		}

		return ($value === null || $value === false || $value === '') ? null : (string)$value;
	}
}
