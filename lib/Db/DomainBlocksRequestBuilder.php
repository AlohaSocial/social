<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class DomainBlocksRequestBuilder
 *
 * @package OCA\Social\Db
 */
class DomainBlocksRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * The schemes an actor id is written with. Each pattern is anchored at one
	 * of them and closed by the `/` that ends the host, so a blocked
	 * `good.example` cannot be matched by `good.example.attacker.test` and a
	 * host cannot be matched half way through.
	 */
	public const SCHEMES = ['https://', 'http://'];

	/**
	 * The width of `social_stream.author_host`: what MySQL can index whole in
	 * utf8mb4. A longer host is stored cut to it, and every host it is
	 * compared with is cut the same way (`hostsToMatch()`), so the two still
	 * meet.
	 */
	public const AUTHOR_HOST_LENGTH = 191;

	/**
	 * The host of an actor id as `social_stream.author_host` stores it:
	 * lower case, without scheme or port, at most `AUTHOR_HOST_LENGTH` long.
	 * `''` for an id with no host, or one that is not plain ASCII — an actor
	 * id spells an internationalised host in punycode, and a domain block is
	 * stored that way too.
	 */
	public static function authorHostOf(string $actorId): string {
		$host = parse_url($actorId, PHP_URL_HOST);
		if (!is_string($host) || $host === '' || preg_match('/[^\x21-\x7e]/', $host) === 1) {
			return '';
		}

		return substr(strtolower($host), 0, self::AUTHOR_HOST_LENGTH);
	}

	/**
	 * Domains as `author_host` would hold them, each once.
	 *
	 * @param string[] $domains
	 * @return string[]
	 */
	public static function hostsToMatch(array $domains): array {
		$hosts = [];
		foreach ($domains as $domain) {
			$host = substr(strtolower(trim($domain)), 0, self::AUTHOR_HOST_LENGTH);
			if ($host !== '') {
				$hosts[$host] = true;
			}
		}

		return array_keys($hosts);
	}

	/**
	 * The columns a domain block is applied to: the author of the row, and the
	 * author of the row it boosts.
	 *
	 * A boost's own author is the booster, so without the second one a blocked
	 * instance still reaches the viewer through anybody who boosts it.
	 *
	 * @return string[]
	 */
	public static function authorColumns(string $alias, string $announceAlias): array {
		$columns = [$alias . '.attributed_to'];
		if ($announceAlias !== '') {
			$columns[] = $announceAlias . '.attributed_to';
		}

		return $columns;
	}

	/**
	 * Hides every post whose author is on an instance the viewer has blocked.
	 *
	 * Called from `filterHiddenActors()`, so that a domain block reaches every
	 * timeline, thread and notification list a per-account block reaches, and
	 * reaches all of them at once.
	 *
	 * A domain is matched against `author_host`, the host of the author's
	 * actor id that `StreamRequest::saveStream()` writes beside it: one
	 * `NOT IN` against a short indexed column, however many instances the
	 * viewer has blocked. It used to be two `LIKE` patterns per domain —
	 * anchored at the scheme and closed by the `/` that ends the host — on
	 * `LOWER(attributed_to)`, an unindexed text column, so an imported
	 * blocklist of a hundred domains put four hundred of them on every
	 * candidate row of every timeline read.
	 *
	 * Those patterns are still what a row is matched by while
	 * `Cron\StreamAuthorHosts` has not reached it (its `author_host` is
	 * NULL until then); once the job has filled in every row it sets a flag,
	 * and from then on the patterns are not built at all.
	 *
	 * The domains are read once and compared as **constants**, rather than
	 * joined as a table, and through a per-request cache, so several filtered
	 * queries in one request — a timeline and its thread, say — read them
	 * once. An account that has blocked nothing adds no clause at all.
	 *
	 * @param string $announceAlias the alias the boosted row is joined under —
	 *                              a boost's own author is the booster, so
	 *                              without it a blocked instance still reaches
	 *                              the viewer through anybody who boosts it.
	 *                              Empty to skip that half.
	 */
	public static function filterDomainBlocked(
		SocialCoreQueryBuilder $qb, string $announceAlias = 'hd_o',
	): void {
		if (!$qb->hasViewer()) {
			return;
		}

		$domains = $qb->blockedDomains();
		if ($domains === []) {
			return;
		}

		$expr = $qb->expr();
		$pf = $qb->getDefaultSelectAlias();
		$hosts = $qb->createNamedParameter(self::hostsToMatch($domains), IQueryBuilder::PARAM_STR_ARRAY);
		$filled = $qb->authorHostsAreFilled();

		foreach (self::authorColumns($pf, $announceAlias) as $author) {
			$alias = substr($author, 0, (int)strrpos($author, '.'));
			$host = $alias . '.author_host';
			$notOnIt = $expr->notIn($host, $hosts);

			// the boosted row is joined LEFT, so its author is NULL on every
			// post that is not a boost. `NULL NOT IN (…)` is NULL, which would
			// drop those rows, so a missing author is explicitly allowed
			$nullable = $author !== $pf . '.attributed_to';
			if ($filled) {
				$qb->andWhere($nullable ? $expr->orX($expr->isNull($host), $notOnIt) : $notOnIt);

				continue;
			}

			$byPattern = self::notOnDomainsByPattern($qb, $author, $domains, $nullable);
			$qb->andWhere($expr->orX(
				$expr->andX($expr->isNotNull($host), $notOnIt),
				($byPattern === null) ? $expr->isNull($host) : $expr->andX($expr->isNull($host), $byPattern)
			));
		}
	}

	/**
	 * The pattern form of the domain block, for a row whose `author_host` has
	 * not been filled in yet: two `NOT LIKE`s per domain on the actor id.
	 * Null when no domain could be made a pattern, which matches nothing.
	 *
	 * @param string[] $domains
	 */
	private static function notOnDomainsByPattern(
		SocialCoreQueryBuilder $qb, string $author, array $domains, bool $nullable,
	): ICompositeExpression|string|null {
		$expr = $qb->expr();
		$clauses = [];
		foreach ($domains as $domain) {
			try {
				$patterns = self::domainPatterns($domain);
			} catch (InvalidResourceException) {
				// a row that cannot be turned into a pattern is one this
				// filter cannot honour; leaving it out would quietly widen
				// the timeline, so nothing is matched by it and the block
				// simply does not apply to this read
				continue;
			}

			foreach ($patterns as $pattern) {
				$clauses[] = $expr->notLike($qb->func()->lower($author), $qb->createNamedParameter($pattern));
			}
		}

		if ($clauses === []) {
			return null;
		}

		$all = $expr->andX(...$clauses);

		return $nullable ? $expr->orX($expr->isNull($author), $all) : $all;
	}

	/**
	 * Matches a column of actor ids against one domain.
	 *
	 * The purge side of a domain block: `filterDomainBlocked()` above compares
	 * a column against the *rows* of the block table, while this compares it
	 * against one domain the caller already has. Same two patterns, each
	 * anchored at a scheme and closed by the `/` that ends the host, so
	 * `good.example` cannot be matched by `good.example.attacker.test`.
	 *
	 * @param string $domain must already have been through
	 *                       `DomainBlockService::normalise()`. A `%` or a `_`
	 *                       reaching a LIKE pattern would widen it to other
	 *                       instances, and this one is used to *delete* — so
	 *                       it is refused here as well rather than trusted.
	 *
	 * @throws InvalidResourceException
	 */
	public static function onDomain(SocialQueryBuilder $qb, string $column, string $domain): ICompositeExpression {
		$patterns = [];
		foreach (self::domainPatterns($domain) as $pattern) {
			$patterns[] = $qb->expr()->like(
				$qb->func()->lower($column), $qb->createNamedParameter($pattern)
			);
		}

		return $qb->expr()->orX(...$patterns);
	}

	/**
	 * The LIKE patterns an actor id on one domain matches, one per scheme.
	 *
	 * Separate from the expression above so that what the patterns do — and
	 * refuse to do — can be read and tested without a database.
	 *
	 * @return string[]
	 *
	 * @throws InvalidResourceException
	 */
	public static function domainPatterns(string $domain): array {
		if ($domain === '' || strpbrk($domain, '%_\\') !== false) {
			throw new InvalidResourceException("'" . $domain . "' is not a domain");
		}

		$patterns = [];
		foreach (self::SCHEMES as $scheme) {
			$patterns[] = $scheme . strtolower($domain) . '/%';
		}

		return $patterns;
	}

	protected function getDomainBlocksInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_DOMAIN_BLOCKS);

		return $qb;
	}

	protected function getDomainBlocksSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('db.id', 'db.actor_id_prim', 'db.domain', 'db.creation')
			->from(self::TABLE_DOMAIN_BLOCKS, 'db');

		$this->defaultSelectAlias = 'db';
		$qb->setDefaultSelectAlias('db');

		return $qb;
	}

	protected function getDomainBlocksDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_DOMAIN_BLOCKS);

		return $qb;
	}
}
