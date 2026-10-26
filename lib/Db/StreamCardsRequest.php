<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\CardNotFoundException;
use OCA\Social\Model\StreamCard;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Storage for link preview cards, one row per post that carries a link.
 */
class StreamCardsRequest extends StreamCardsRequestBuilder {
	/**
	 * Stores the card of a post, replacing any earlier one. Two requests
	 * racing on the same post is normal (a post can be handed to the queue
	 * more than once), so a duplicate insert falls back to an update.
	 */
	public function save(StreamCard $card): void {
		$qb = $this->getStreamCardsInsertSql();
		$qb->setValue('stream_id_prim', $qb->createNamedParameter($qb->prim($card->getStreamId())))
			->setValue('url', $qb->createNamedParameter($card->getUrl()))
			->setValue('title', $qb->createNamedParameter($card->getTitle()))
			->setValue('description', $qb->createNamedParameter($card->getDescription()))
			->setValue('image', $qb->createNamedParameter($card->getImage()))
			->setValue('provider_name', $qb->createNamedParameter($card->getProviderName()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$this->update($card);
		}
	}

	public function update(StreamCard $card): void {
		$qb = $this->getStreamCardsUpdateSql();
		$qb->set('url', $qb->createNamedParameter($card->getUrl()))
			->set('title', $qb->createNamedParameter($card->getTitle()))
			->set('description', $qb->createNamedParameter($card->getDescription()))
			->set('image', $qb->createNamedParameter($card->getImage()))
			->set('provider_name', $qb->createNamedParameter($card->getProviderName()))
			->set('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->andWhere(
			$qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($card->getStreamId())))
		);

		$qb->executeStatement();
	}

	/**
	 * @throws CardNotFoundException
	 */
	public function getByStreamId(string $streamId): StreamCard {
		$qb = $this->getStreamCardsSelectSql();
		$qb->andWhere(
			$qb->expr()->eq('sc.stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
		);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CardNotFoundException('no card for ' . $streamId);
		}

		$card = $this->parseStreamCardsSelectSql($data);
		$card->setStreamId($streamId);

		return $card;
	}

	/**
	 * The cards of a whole page of posts in one query.
	 *
	 * @param string[] $streamIds
	 *
	 * @return array<string, StreamCard> keyed by the prim of the stream id
	 */
	public function getByStreamIds(array $streamIds): array {
		return $this->getByStreamPrims(array_map(static fn (string $id): string => md5($id), $streamIds));
	}

	/**
	 * @param string[] $prims
	 * @return array<string, StreamCard> keyed by the prim of the stream id
	 */
	public function getByStreamPrims(array $prims): array {
		$prims = array_values(array_unique($prims));
		if ($prims === []) {
			return [];
		}

		$cards = [];
		$qb = $this->getStreamCardsSelectSql();
		$qb->andWhere(
			$qb->expr()->in('sc.stream_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
		);

		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$card = $this->parseStreamCardsSelectSql($data);
			$cards[$card->getStreamId()] = $card;
		}
		$cursor->closeCursor();

		return $cards;
	}

	public function deleteByStreamId(string $streamId): void {
		$qb = $this->getStreamCardsDeleteSql();
		$qb->andWhere(
			$qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
		);

		$qb->executeStatement();
	}

	/**
	 * @param string[] $prims the id_prim of the posts being deleted
	 */
	public function deleteByStreamPrims(array $prims): void {
		if ($prims === []) {
			return;
		}

		$qb = $this->getStreamCardsDeleteSql();
		$qb->andWhere(
			$qb->expr()->in('stream_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
		);

		$qb->executeStatement();
	}
}
