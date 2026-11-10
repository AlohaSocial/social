<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

/**
 * Message identifiers retained for the lifetime of their local direct
 * message, readable both ways: the Bluesky message of a local one, and the
 * local message of a Bluesky id (`message_prim`, the md5 of `message_id`).
 */
class AtprotoChatMessageRequest extends CoreRequestBuilder {
	public function remember(string $did, string $noteId, string $convoId, string $messageId): void {
		$this->dbConnection->insertIgnoreConflict(self::TABLE_ATPROTO_CHAT_MESSAGE, [
			'stream_id_prim' => md5($noteId), 'did' => $did,
			'convo_id' => $convoId, 'message_id' => $messageId,
			'message_prim' => md5($messageId),
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

	/**
	 * The ids of the local messages an account has under a Bluesky message
	 * id, read from the stream rows that still exist.
	 *
	 * @return list<string>
	 */
	public function noteIds(string $did, string $messageId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('s.id', 'm.message_id')
			->from(self::TABLE_ATPROTO_CHAT_MESSAGE, 'm')
			->innerJoin('m', self::TABLE_STREAM, 's', $qb->expr()->eq('s.id_prim', 'm.stream_id_prim'))
			->where($qb->expr()->eq('m.did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('m.message_prim', $qb->createNamedParameter(md5($messageId))))
			->setMaxResults(10);
		$ids = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			if ((string)$row['message_id'] === $messageId) {
				$ids[] = (string)$row['id'];
			}
		}
		$result->closeCursor();

		return $ids;
	}

	/** The Bluesky conversation of a local message for any account; '' for none. */
	public function convoOf(string $noteId): string {
		$qb = $this->getQueryBuilder();
		$qb->select('convo_id')->from(self::TABLE_ATPROTO_CHAT_MESSAGE)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5($noteId))))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$convo = $result->fetchOne();
		$result->closeCursor();

		return is_string($convo) ? $convo : '';
	}
}
