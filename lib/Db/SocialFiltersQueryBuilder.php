<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

class SocialFiltersQueryBuilder extends SocialLimitsQueryBuilder {
	/**
	 * Keep the viewer's own boosts out of their home timeline: everything that
	 * is not a boost (`filter_duplicate`, set on `Announce` by
	 * `AP::getItemFromType()`), plus every boost that is not the viewer's own.
	 */
	public function filterDuplicate() {
		if (!$this->hasViewer()) {
			return;
		}

		$expr = $this->expr();

		$this->andWhere(
			$expr->orX(
				$this->exprLimitToDBFieldInt('filter_duplicate', 0, 's'),
				$expr->neq(
					's.attributed_to_prim',
					$this->createNamedParameter($this->prim($this->getViewer()->getId()))
				)
			)
		);
	}
}
