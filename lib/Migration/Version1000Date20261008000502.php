<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_stream_media`: which cached document each of this instance's own
 * posts carries, one row per (post, document).
 *
 * An upload is made before its post exists, so nothing on the document
 * names the post; when a video finished transcoding, the posts carrying it
 * were found by a `LIKE` on the `attachments` text of every local post with
 * a video. This table is that question answered by an index, both ways: the
 * posts of a document for the transcode, and the rows of a post for the
 * delete that clears them with it.
 *
 * The local posts that predate it are linked here from their stored
 * attachment copies, which are keyed by the document's nid.
 */
class Version1000Date20261008000502 extends SimpleMigrationStep {
	/** Rows read per page: each carries its attachment copies. */
	private const BATCH = 500;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_stream_media')) {
			return null;
		}

		$table = $schema->createTable('social_stream_media');
		$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
		$table->addColumn('doc_nid', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['doc_nid', 'stream_id_prim']);
		$table->addIndex(['stream_id_prim'], 'social_sm_stream');

		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$after = '0';
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `id_prim`, `attachments` FROM `' . $table . '`'
				. ' WHERE `local` = ? AND `nid` > ?'
				. ' ORDER BY `nid` ASC LIMIT ' . self::BATCH,
				['1', $after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			foreach ($page as $row) {
				$after = (string)$row['nid'];
				$prim = (string)($row['id_prim'] ?? '');
				if ($prim === '') {
					continue;
				}

				foreach (StreamRequest::documentNidsOf((string)($row['attachments'] ?? '')) as $nid) {
					// a second run finds the rows of the first, and keeps them
					$this->connection->insertIgnoreConflict(
						CoreRequestBuilder::TABLE_STREAM_MEDIA,
						['stream_id_prim' => $prim, 'doc_nid' => $nid]
					);
				}
			}

			if (count($page) < self::BATCH) {
				break;
			}
		}
	}
}
