<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Server;

/**
 * Class MuteExpiryRequestBuilder
 *
 * @package OCA\Social\Db
 */
class MuteExpiryRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/** The alias the expiry rows of a timeline query are joined under. */
	public const ALIAS = 'hd_x';

	/**
	 * The columns an expiry is looked up by: the row's author, and the author
	 * of the row it boosts, as either may be muted with a different expiry.
	 *
	 * @return string[]
	 */
	public static function authorColumns(string $alias, string $announceAlias): array {
		$columns = [$alias . '.attributed_to_prim'];
		if ($announceAlias !== '') {
			$columns[] = $announceAlias . '.attributed_to_prim';
		}

		return $columns;
	}

	/**
	 * Joins the viewer's expired mutes against the authors of the rows being
	 * read, for `unexpired()`. It must come before the anti-join of
	 * `filterHiddenActors()`, whose join condition names this alias. Nothing is
	 * filtered here.
	 *
	 * @param string $announceAlias the alias the boosted row is joined under; a
	 *                              boost's own author is the booster, and both
	 *                              can be muted. Empty to skip that half.
	 * @param int|null $now the moment the read happens; the server's clock
	 *                      when not given
	 */
	public static function joinExpiredMutes(
		SocialCoreQueryBuilder $qb, string $announceAlias = 'hd_o', ?int $now = null,
	): void {
		if (!$qb->hasViewer()) {
			return;
		}

		$expr = $qb->expr();
		$pf = $qb->getDefaultSelectAlias();

		$authors = [];
		foreach (self::authorColumns($pf, $announceAlias) as $author) {
			$authors[] = $expr->eq(self::ALIAS . '.object_id_prim', $author);
		}

		$expired = (new DateTime('@' . ($now ?? Server::get(ITimeFactory::class)->getTime())))
			->setTimezone(new DateTimeZone(date_default_timezone_get()));

		$qb->leftJoin(
			$pf, self::TABLE_MUTE_EXPIRY, self::ALIAS,
			$expr->andX(
				$expr->eq(
					self::ALIAS . '.actor_id_prim',
					$qb->createNamedParameter($qb->prim($qb->getViewer()->getId()))
				),
				$expr->orX(...$authors),
				$expr->lte(
					self::ALIAS . '.expires_at',
					$qb->createNamedParameter($expired, IQueryBuilder::PARAM_DATE)
				)
			)
		);
	}

	/**
	 * True of a mute row that still applies, compared on the muted account,
	 * as a boost brings two authors into one query. Expiry is a predicate of
	 * the read, not a cleanup job, so a mute stops applying the moment it
	 * expires even without cron.
	 */
	public static function unexpired(
		IExpressionBuilder $expr, string $relationAlias = 'hd_r', string $expiryAlias = self::ALIAS,
	): ICompositeExpression {
		return $expr->orX(
			$expr->isNull($expiryAlias . '.id'),
			$expr->neq($expiryAlias . '.object_id_prim', $relationAlias . '.object_id_prim')
		);
	}

	protected function getMuteExpiryInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_MUTE_EXPIRY);

		return $qb;
	}

	protected function getMuteExpiryUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_MUTE_EXPIRY);

		return $qb;
	}

	protected function getMuteExpirySelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('mx.id', 'mx.actor_id_prim', 'mx.object_id_prim', 'mx.expires_at', 'mx.creation')
			->from(self::TABLE_MUTE_EXPIRY, 'mx');

		$this->defaultSelectAlias = 'mx';
		$qb->setDefaultSelectAlias('mx');

		return $qb;
	}

	protected function getMuteExpiryDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MUTE_EXPIRY);

		return $qb;
	}
}
