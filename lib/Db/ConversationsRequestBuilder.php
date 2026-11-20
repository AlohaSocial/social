<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Statements for conversations: threads, derived from `social_stream` and
 * stored nowhere, and what one account did with a thread (read, dismissed),
 * which has rows of its own.
 *
 * The thread statements select four columns and join nothing: walking a
 * thread needs only parents and nids, not whole posts.
 */
class ConversationsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getConversationStateInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CONVERSATION_STATE);

		return $qb;
	}

	protected function getConversationStateSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select(
			'cs.id', 'cs.actor_id', 'cs.actor_id_prim', 'cs.root_id', 'cs.root_id_prim',
			'cs.read_nid', 'cs.hidden_nid', 'cs.muted'
		)
			->from(self::TABLE_CONVERSATION_STATE, 'cs');

		$this->defaultSelectAlias = 'cs';
		$qb->setDefaultSelectAlias('cs');

		return $qb;
	}

	protected function getConversationStateUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CONVERSATION_STATE);

		return $qb;
	}

	protected function getConversationStateDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CONVERSATION_STATE);

		return $qb;
	}

	/**
	 * A post reduced to its place in a thread: who it answers, and the two ids
	 * it is addressed by.
	 */
	protected function getThreadLinkSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('s.id', 's.id_prim', 's.nid', 's.in_reply_to')
			->from(self::TABLE_STREAM, 's');

		$this->defaultSelectAlias = 's';
		$qb->setDefaultSelectAlias('s');

		return $qb;
	}

	/**
	 * The same reduction, narrowed to the posts of a thread that were addressed
	 * to, or sent by, one account as a direct message: a `dm` row in
	 * `social_stream_dest`, which `StreamRequest::getTimelineDirect()` reads too
	 * and which carries the author beside the recipients.
	 */
	protected function getThreadLinkForDestSql(string $actorId): SocialQueryBuilder {
		$qb = $this->getThreadLinkSelectSql();
		$expr = $qb->expr();

		$qb->innerJoin(
			's', self::TABLE_STREAM_DEST, 'sd',
			$expr->andX(
				$expr->eq('sd.stream_id', 's.id_prim'),
				$expr->eq('sd.actor_id', $qb->createNamedParameter($qb->prim($actorId))),
				$expr->eq('sd.type', $qb->createNamedParameter('dm'))
			)
		);

		return $qb;
	}

	/**
	 * @return array{id: string, idPrim: string, nid: int|string, inReplyTo: string}
	 */
	protected function parseThreadLinkSelectSql(array $data): array {
		return [
			'id' => $this->get('id', $data),
			'idPrim' => $this->get('id_prim', $data),
			'nid' => \OCA\Social\Tools\Nid::fromStorage($this->get('nid', $data, '0')),
			'inReplyTo' => $this->get('in_reply_to', $data),
		];
	}

	/**
	 * @return array{rootId: string, readNid: int|string, hiddenNid: int|string}
	 */
	protected function parseConversationStateSelectSql(array $data): array {
		return [
			'rootId' => $this->get('root_id', $data),
			'readNid' => \OCA\Social\Tools\Nid::fromStorage($this->get('read_nid', $data, '0')),
			'hiddenNid' => \OCA\Social\Tools\Nid::fromStorage($this->get('hidden_nid', $data, '0')),
		];
	}
}
