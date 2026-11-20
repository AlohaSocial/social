<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\StreamCard;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class TrendsRequestBuilder
 *
 * Extends StreamRequestBuilder because a status trend answers with a page of
 * statuses, built from the same stream helpers as the timelines.
 *
 * @package OCA\Social\Db
 */
class TrendsRequestBuilder extends StreamRequestBuilder {
	use TArrayTools;

	/**
	 * The statuses that were interacted with inside a window, as a `nid`
	 * column and a count, aggregated from the like and boost rows in
	 * `social_action` over its (object, type) index, so a trend cannot drift
	 * from the counts a status reports.
	 */
	protected function getTrendingStatusNidsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('s.nid')
			->from(self::TABLE_STREAM, 's');

		$this->defaultSelectAlias = 's';
		$qb->setDefaultSelectAlias('s');

		return $qb;
	}

	/**
	 * The link previews attached to statuses inside a window, as a `url`
	 * column and a count.
	 */
	protected function getTrendingLinkUrlsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('sc.url')
			->from(self::TABLE_STREAM_CARDS, 'sc');

		$this->defaultSelectAlias = 'sc';
		$qb->setDefaultSelectAlias('sc');

		return $qb;
	}

	protected function getTrendingCardsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('sc.stream_id_prim', 'sc.url', 'sc.title', 'sc.description', 'sc.image', 'sc.provider_name', 'sc.creation')
			->from(self::TABLE_STREAM_CARDS, 'sc');

		$this->defaultSelectAlias = 'sc';
		$qb->setDefaultSelectAlias('sc');

		return $qb;
	}

	protected function parseTrendingCardSelectSql(array $data): StreamCard {
		$card = new StreamCard();
		$card->setStreamId($this->get('stream_id_prim', $data))
			->setUrl($this->get('url', $data))
			->setTitle($this->get('title', $data))
			->setDescription($this->get('description', $data))
			->setImage($this->get('image', $data))
			->setProviderName($this->get('provider_name', $data));

		return $card;
	}
}
