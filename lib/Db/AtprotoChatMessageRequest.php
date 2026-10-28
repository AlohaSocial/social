<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

/** Message identifiers retained for the lifetime of their local direct message. */
class AtprotoChatMessageRequest extends CoreRequestBuilder {
	public function remember(string $did, string $noteId, string $convoId, string $messageId): void {
		$this->dbConnection->insertIgnoreConflict(self::TABLE_ATPROTO_CHAT_MESSAGE, [
			'stream_id_prim' => md5($noteId), 'did' => $did,
			'convo_id' => $convoId, 'message_id' => $messageId,
		]);
	}

	/** @return array{convoId: string, messageId: string}|null */
	public function location(string $did, string $noteId): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('convo_id', 'message_id')->from(self::TABLE_ATPROTO_CHAT_MESSAGE)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5($noteId))))
			->andWhere($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : ['convoId' => (string)$row['convo_id'], 'messageId' => (string)$row['message_id']];
	}
}
