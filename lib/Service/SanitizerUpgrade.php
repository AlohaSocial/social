<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Security\HtmlSanitizer;
use OCA\Social\Tools\Nid;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Brings stored post bodies and cached bios up to the current
 * `HtmlSanitizer::version()`, so their reads can skip the sanitizer.
 *
 * A row under another stamp still reads correctly -- it is sanitized on
 * every read -- so this is only ever catching up. Each table is walked once
 * per stamp, in pages by `nid`, from a cursor kept in the app config: the
 * stamp it walks for and the last `nid` done, or `done` once the walk passed
 * the end. Rows written meanwhile carry the current stamp already, so a walk
 * that reached the end is finished until the stamp changes. A row that
 * cannot be rewritten is logged and passed over: it keeps its old stamp and
 * is sanitized on read, so it never holds the walk back.
 */
class SanitizerUpgrade {
	public const BATCH = 500;
	/** the cursor of each table's walk, by table */
	public const CURSORS = [
		CoreRequestBuilder::TABLE_STREAM => 'sanitizer_walk_stream',
		CoreRequestBuilder::TABLE_CACHE_ACTORS => 'sanitizer_walk_cache_actor',
	];
	private const DONE = 'done';

	public function __construct(
		private IDBConnection $connection,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Up to `$pages` pages of each table.
	 *
	 * @return int how many rows were rewritten
	 */
	public function run(int $pages = 10): int {
		$rewritten = 0;
		foreach (array_keys(self::CURSORS) as $table) {
			for ($page = 0; $page < $pages; $page++) {
				$done = $this->page($table);
				if ($done === null) {
					break;
				}
				$rewritten += $done;
			}
		}

		return $rewritten;
	}

	/**
	 * One page of a table's walk.
	 *
	 * @return ?int the rows rewritten, null when the walk is finished
	 */
	public function page(string $table): ?int {
		$version = HtmlSanitizer::version();
		$after = $this->cursor($table, $version);
		if ($after === null) {
			return null;
		}

		$stream = $table === CoreRequestBuilder::TABLE_STREAM;
		$text = $stream ? 'content' : 'summary';

		$select = $this->connection->getQueryBuilder();
		$select->select('nid', 'id', $text, 'sanitized')
			->from($table)
			->where($select->expr()->gt('nid', $select->createNamedParameter(Nid::fromStorage($after))))
			->orderBy('nid', 'ASC')
			->setMaxResults(self::BATCH);
		$cursor = $select->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		if ($rows === []) {
			$this->setCursor($table, $version, self::DONE);

			return null;
		}

		$rewritten = 0;
		foreach ($rows as $row) {
			$after = (string)$row['nid'];
			$stamp = (int)($row['sanitized'] ?? 0);
			if ($stamp === $version) {
				continue;
			}

			try {
				$clean = HtmlSanitizer::sanitize((string)($row[$text] ?? ''), (string)($row['id'] ?? ''));
				$update = $this->connection->getQueryBuilder();
				$update->update($table)
					->set($stream ? 'content' : 'description', $update->createNamedParameter($clean))
					->set('sanitized', $update->createNamedParameter($version, IQueryBuilder::PARAM_INT))
					->where($update->expr()->eq('nid', $update->createNamedParameter(Nid::fromStorage($after))))
					// a write since the read carries its own text and the current
					// stamp; this one must not put the older text back over it
					->andWhere($update->expr()->eq('sanitized', $update->createNamedParameter($stamp, IQueryBuilder::PARAM_INT)));
				$rewritten += $update->executeStatement();
			} catch (Throwable $e) {
				$this->logger->warning('cannot bring a stored text up to the current sanitizer', [
					'table' => $table, 'nid' => $after, 'exception' => $e,
				]);
			}
		}

		$this->setCursor($table, $version, (count($rows) < self::BATCH) ? self::DONE : $after);

		return $rewritten;
	}

	/** The last nid done for this stamp, '0' to start, null when finished. */
	private function cursor(string $table, int $version): ?string {
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::CURSORS[$table], '');
		$parts = explode(':', $stored, 2);
		if (count($parts) !== 2 || $parts[0] !== (string)$version) {
			return '0';
		}

		return ($parts[1] === self::DONE) ? null : $parts[1];
	}

	private function setCursor(string $table, int $version, string $position): void {
		$this->appConfig->setValueString(Application::APP_ID, self::CURSORS[$table], $version . ':' . $position);
	}
}
