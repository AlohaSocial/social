<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Db;

use DateTime;
use Exception;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\IExtendedQueryBuilder;
use OCA\Social\Tools\IQueryRow;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ConflictResolutionMode;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\ILiteral;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;

/**
 * The app's query-builder helpers, wrapped around a query builder the server
 * handed us rather than extending one we built ourselves.
 *
 * This class used to extend `OC\DB\QueryBuilder\QueryBuilder`, which lives in
 * the server's `lib/private/` and carries no stability promise. Every one of
 * the 68 classes in `lib/Db/` descends from this one, so that edge put the
 * whole persistence layer on a private constructor signature: when core added
 * sharding parameters to it, the app got a fatal on `occ upgrade` with no
 * deprecation first, and when Nextcloud 35 added `forUpdate()` to the public
 * interface, the private class no longer satisfied the interface this class
 * claims to implement.
 *
 * Composition removes both failure modes. The inner builder comes from
 * `IDBConnection::getQueryBuilder()`, so the server constructs its own object
 * with whatever arguments it currently takes, and everything below is
 * delegation to the public `IQueryBuilder` contract. The fluent methods return
 * `$this` rather than the inner builder, so chains stay on the helper class.
 */
class ExtendedQueryBuilder implements IExtendedQueryBuilder {
	public string $defaultSelectAlias = '';

	public function __construct(
		protected IQueryBuilder $queryBuilder,
	) {
	}

	/**
	 * The query builder this one delegates to.
	 *
	 * Exposed for the rare caller that needs to hand the server its own object
	 * back; everything inside the app should use the delegating methods.
	 */
	public function getQueryBuilder(): IQueryBuilder {
		return $this->queryBuilder;
	}

	#[\Override]
	public function automaticTablePrefix($enabled) {
		$this->queryBuilder->automaticTablePrefix($enabled);

		return $this;
	}

	#[\Override]
	public function expr() {
		return $this->queryBuilder->expr();
	}

	#[\Override]
	public function func() {
		return $this->queryBuilder->func();
	}

	#[\Override]
	public function getType() {
		return $this->queryBuilder->getType();
	}

	#[\Override]
	public function getConnection() {
		return $this->queryBuilder->getConnection();
	}

	#[\Override]
	public function getState() {
		return $this->queryBuilder->getState();
	}

	#[\Override]
	public function executeQuery(?IDBConnection $connection = null): IResult {
		return $this->queryBuilder->executeQuery($connection);
	}

	#[\Override]
	public function executeStatement(?IDBConnection $connection = null): int {
		return $this->queryBuilder->executeStatement($connection);
	}

	#[\Override]
	public function getSQL() {
		return $this->queryBuilder->getSQL();
	}

	#[\Override]
	public function setParameter($key, $value, $type = null) {
		$this->queryBuilder->setParameter($key, $value, $type);

		return $this;
	}

	#[\Override]
	public function setParameters(array $params, array $types = [
	]) {
		$this->queryBuilder->setParameters($params, $types);

		return $this;
	}

	#[\Override]
	public function getParameters() {
		return $this->queryBuilder->getParameters();
	}

	#[\Override]
	public function getParameter($key) {
		return $this->queryBuilder->getParameter($key);
	}

	#[\Override]
	public function getParameterTypes() {
		return $this->queryBuilder->getParameterTypes();
	}

	#[\Override]
	public function getParameterType($key) {
		return $this->queryBuilder->getParameterType($key);
	}

	#[\Override]
	public function setFirstResult($firstResult) {
		$this->queryBuilder->setFirstResult($firstResult);

		return $this;
	}

	#[\Override]
	public function getFirstResult() {
		return $this->queryBuilder->getFirstResult();
	}

	#[\Override]
	public function setMaxResults($maxResults) {
		$this->queryBuilder->setMaxResults($maxResults);

		return $this;
	}

	#[\Override]
	public function getMaxResults() {
		return $this->queryBuilder->getMaxResults();
	}

	#[\Override]
	public function select(...$selects) {
		$this->queryBuilder->select(...$selects);

		return $this;
	}

	#[\Override]
	public function selectAlias($select, $alias): static {
		$this->queryBuilder->selectAlias($select, $alias);

		return $this;
	}

	#[\Override]
	public function selectDistinct($select) {
		$this->queryBuilder->selectDistinct($select);

		return $this;
	}

	#[\Override]
	public function addSelect(...$select) {
		$this->queryBuilder->addSelect(...$select);

		return $this;
	}

	#[\Override]
	public function delete($delete = null, $alias = null) {
		$this->queryBuilder->delete($delete, $alias);

		return $this;
	}

	#[\Override]
	public function update($update = null, $alias = null) {
		$this->queryBuilder->update($update, $alias);

		return $this;
	}

	#[\Override]
	public function insert($insert = null) {
		$this->queryBuilder->insert($insert);

		return $this;
	}

	#[\Override]
	public function from($from, $alias = null) {
		$this->queryBuilder->from($from, $alias);

		return $this;
	}

	#[\Override]
	public function join($fromAlias, $join, $alias, $condition = null) {
		$this->queryBuilder->join($fromAlias, $join, $alias, $condition);

		return $this;
	}

	#[\Override]
	public function innerJoin($fromAlias, $join, $alias, $condition = null) {
		$this->queryBuilder->innerJoin($fromAlias, $join, $alias, $condition);

		return $this;
	}

	#[\Override]
	public function leftJoin($fromAlias, $join, $alias, $condition = null) {
		$this->queryBuilder->leftJoin($fromAlias, $join, $alias, $condition);

		return $this;
	}

	#[\Override]
	public function rightJoin($fromAlias, $join, $alias, $condition = null) {
		$this->queryBuilder->rightJoin($fromAlias, $join, $alias, $condition);

		return $this;
	}

	#[\Override]
	public function set($key, $value) {
		$this->queryBuilder->set($key, $value);

		return $this;
	}

	#[\Override]
	public function where(...$predicates) {
		$this->queryBuilder->where(...$predicates);

		return $this;
	}

	#[\Override]
	public function andWhere(...$where) {
		$this->queryBuilder->andWhere(...$where);

		return $this;
	}

	#[\Override]
	public function orWhere(...$where) {
		$this->queryBuilder->orWhere(...$where);

		return $this;
	}

	#[\Override]
	public function groupBy(...$groupBys) {
		$this->queryBuilder->groupBy(...$groupBys);

		return $this;
	}

	#[\Override]
	public function addGroupBy(...$groupBy) {
		$this->queryBuilder->addGroupBy(...$groupBy);

		return $this;
	}

	#[\Override]
	public function setValue($column, $value) {
		$this->queryBuilder->setValue($column, $value);

		return $this;
	}

	#[\Override]
	public function values(array $values) {
		$this->queryBuilder->values($values);

		return $this;
	}

	#[\Override]
	public function having(...$having) {
		$this->queryBuilder->having(...$having);

		return $this;
	}

	#[\Override]
	public function andHaving(...$having) {
		$this->queryBuilder->andHaving(...$having);

		return $this;
	}

	#[\Override]
	public function orHaving(...$having) {
		$this->queryBuilder->orHaving(...$having);

		return $this;
	}

	#[\Override]
	/**
	 * Untyped on purpose, and it has to stay that way. The `nextcloud/ocp`
	 * stub this app is analysed against declares
	 * `orderBy(string|ILiteral|IParameter|IQueryFunction $sort, …)`, but the
	 * interface a real server ships is still `orderBy($sort, $order = null)`.
	 * PHP lets an override widen a parameter type and not narrow one, so the
	 * stub's signature here is a fatal error the moment this class is loaded
	 * on any Nextcloud this app supports — and the unit suite cannot see it,
	 * because it loads the stub.
	 */
	public function orderBy($sort, $order = null): static {
		$this->queryBuilder->orderBy($sort, $order);

		return $this;
	}

	#[\Override]
	/**
	 * Untyped on purpose, and it has to stay that way. The `nextcloud/ocp`
	 * stub this app is analysed against declares
	 * `orderBy(string|ILiteral|IParameter|IQueryFunction $sort, …)`, but the
	 * interface a real server ships is still `orderBy($sort, $order = null)`.
	 * PHP lets an override widen a parameter type and not narrow one, so the
	 * stub's signature here is a fatal error the moment this class is loaded
	 * on any Nextcloud this app supports — and the unit suite cannot see it,
	 * because it loads the stub.
	 */
	public function addOrderBy($sort, $order = null): static {
		$this->queryBuilder->addOrderBy($sort, $order);

		return $this;
	}

	#[\Override]
	public function getQueryPart($queryPartName) {
		return $this->queryBuilder->getQueryPart($queryPartName);
	}

	#[\Override]
	public function getQueryParts() {
		return $this->queryBuilder->getQueryParts();
	}

	#[\Override]
	public function resetQueryParts($queryPartNames = null) {
		$this->queryBuilder->resetQueryParts($queryPartNames);

		return $this;
	}

	#[\Override]
	public function resetQueryPart($queryPartName) {
		$this->queryBuilder->resetQueryPart($queryPartName);

		return $this;
	}

	#[\Override]
	public function createNamedParameter($value, $type = IQueryBuilder::PARAM_STR, $placeHolder = null) {
		return $this->queryBuilder->createNamedParameter($value, $type, $placeHolder);
	}

	#[\Override]
	public function createPositionalParameter($value, $type = IQueryBuilder::PARAM_STR) {
		return $this->queryBuilder->createPositionalParameter($value, $type);
	}

	#[\Override]
	public function createParameter($name) {
		return $this->queryBuilder->createParameter($name);
	}

	#[\Override]
	public function createFunction($call) {
		return $this->queryBuilder->createFunction($call);
	}

	#[\Override]
	public function getLastInsertId(): int {
		return $this->queryBuilder->getLastInsertId();
	}

	#[\Override]
	public function getTableName($table) {
		return $this->queryBuilder->getTableName($table);
	}

	#[\Override]
	public function prefixTableName(string $table): string {
		return $this->queryBuilder->prefixTableName($table);
	}

	#[\Override]
	public function getColumnName($column, $tableAlias = '') {
		return $this->queryBuilder->getColumnName($column, $tableAlias);
	}

	#[\Override]
	public function hintShardKey(string $column, mixed $value, bool $overwrite = false): static {
		$this->queryBuilder->hintShardKey($column, $value, $overwrite);

		return $this;
	}

	#[\Override]
	public function runAcrossAllShards(): static {
		$this->queryBuilder->runAcrossAllShards();

		return $this;
	}

	#[\Override]
	public function getOutputColumns(): array {
		return $this->queryBuilder->getOutputColumns();
	}

	#[\Override]
	public function forUpdate(ConflictResolutionMode $conflictResolutionMode = ConflictResolutionMode::Ordinary): static {
		$this->queryBuilder->forUpdate($conflictResolutionMode);

		return $this;
	}

	#[\Override]
	public function setDefaultSelectAlias(string $alias): IExtendedQueryBuilder {
		$this->defaultSelectAlias = $alias;

		return $this;
	}

	/**
	 * @return string
	 */
	#[\Override]
	public function getDefaultSelectAlias(): string {
		return $this->defaultSelectAlias;
	}

	/**
	 * Limit the request to the Id
	 *
	 * @param int $id
	 *
	 * @return ExtendedQueryBuilder
	 */
	#[\Override]
	public function limitToId(int $id): IExtendedQueryBuilder {
		$this->limitToDBFieldInt('id', $id);

		return $this;
	}

	/**
	 * Limit to a stream nid without narrowing BIGINTs to PHP's int range.
	 *
	 * @param int|string $id
	 */
	public function limitToNid(int|string $id): void {
		if (is_int($id)) {
			$this->limitToDBFieldInt('nid', $id);
			return;
		}

		$this->limitToDBField('nid', \OCA\Social\Tools\Nid::normalize($id));
	}

	/**
	 * Limit the request to the Id (string)
	 *
	 * @param string $id
	 *
	 * @return ExtendedQueryBuilder
	 */
	#[\Override]
	public function limitToIdString(string $id): IExtendedQueryBuilder {
		$this->limitToDBField('id', $id, false);

		return $this;
	}

	/**
	 * Limit the request to the UserId
	 *
	 * @param string $userId
	 *
	 * @return ExtendedQueryBuilder
	 */
	#[\Override]
	public function limitToUserId(string $userId): IExtendedQueryBuilder {
		$this->limitToDBField('user_id', $userId, false);

		return $this;
	}

	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	#[\Override]
	public function limitToDBField(string $field, string $value, bool $cs = true, string $alias = ''): void {
		$expr = $this->exprLimitToDBField($field, $value, true, $cs, $alias);

		$this->andWhere($expr);
	}

	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	#[\Override]
	public function filterDBField(string $field, string $value, bool $cs = true, string $alias = '',
	): void {
		$expr = $this->exprLimitToDBField($field, $value, false, $cs, $alias);
		$this->andWhere($expr);
	}

	#[\Override]
	public function exprLimitToDBField(
		string $field, string $value, bool $eq = true, bool $cs = true, string $alias = '',
	): string {
		$expr = $this->expr();

		$pf = '';
		if ($this->getType() === self::SELECT) {
			$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.';
		}
		$field = $pf . $field;

		$comp = 'eq';
		if ($eq === false) {
			$comp = 'neq';
		}

		if ($cs) {
			return $expr->$comp($field, $this->createNamedParameter($value));
		} else {
			$func = $this->func();

			return $expr->$comp(
				$func->lower($field), $func->lower($this->createNamedParameter($value))
			);
		}
	}

	/**
	 * @param string $field
	 * @param array $values
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	#[\Override]
	public function limitToDBFieldArray(
		string $field, array $values, bool $cs = true, string $alias = '',
	): void {
		$expr = $this->exprLimitToDBFieldArray($field, $values, true, $cs, $alias);
		$this->andWhere($expr);
	}

	/**
	 * @param string $field
	 * @param array $values
	 * @param bool $eq
	 * @param bool $cs
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	#[\Override]
	public function exprLimitToDBFieldArray(
		string $field, array $values, bool $eq = true, bool $cs = true, string $alias = '',
	): ICompositeExpression {
		$expr = $this->expr();

		$pf = '';
		if ($this->getType() === self::SELECT) {
			$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.';
		}
		$field = $pf . $field;

		$func = $this->func();
		$conditions = [];

		foreach ($values as $value) {
			$comp = $eq ? 'eq' : 'neq';
			if ($cs) {
				$conditions[] = $expr->$comp($field, $this->createNamedParameter($value));
			} else {
				$conditions[] = $expr->$comp(
					$func->lower($field), $func->lower($this->createNamedParameter($value))
				);
			}
		}

		if ($eq === false) {
			$junc = $expr->andX(...$conditions);
		} else {
			$junc = $expr->orX(...$conditions);
		}

		return $junc;
	}

	/**
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	#[\Override]
	public function limitToDBFieldInt(string $field, int $value, string $alias = ''): void {
		$expr = $this->exprLimitToDBFieldInt($field, $value, $alias, true);
		$this->andWhere($expr);
	}

	#[\Override]
	public function exprLimitToDBFieldInt(string $field, int $value, string $alias = '', bool $eq = true,
	): string {
		$expr = $this->expr();

		$pf = '';
		if ($this->getType() === self::SELECT) {
			$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.';
		}
		$field = $pf . $field;

		$comp = 'eq';
		if ($eq === false) {
			$comp = 'neq';
		}

		return $expr->$comp($field, $this->createNamedParameter($value, IQueryBuilder::PARAM_INT));
	}

	/**
	 * @param string $field
	 */
	#[\Override]
	public function limitToDBFieldEmpty(string $field): void {
		$expr = $this->expr();
		$pf
			= ($this->getType() === self::SELECT) ? $this->getDefaultSelectAlias()
															  . '.' : '';
		$field = $pf . $field;

		$this->andWhere($expr->eq($field, $this->createNamedParameter('')));
	}

	/**
	 * @param string $field
	 * @param DateTime $date
	 * @param bool $orNull
	 */
	#[\Override]
	public function limitToDBFieldDateTime(string $field, DateTime $date, bool $orNull = false): void {
		$expr = $this->expr();
		$pf
			= ($this->getType() === self::SELECT) ? $this->getDefaultSelectAlias()
															  . '.' : '';
		$field = $pf . $field;

		$conditions = [
			$expr->lte($field, $this->createNamedParameter($date, IQueryBuilder::PARAM_DATE))
		];

		if ($orNull === true) {
			$conditions[] = $expr->isNull($field);
		}

		$this->andWhere($expr->orX(...$conditions));
	}

	/**
	 * @param string $field
	 * @param string $value
	 */
	#[\Override]
	public function searchInDBField(string $field, string $value): void {
		$expr = $this->expr();

		$pf = ($this->getType() === self::SELECT) ? $this->getDefaultSelectAlias()
																. '.' : '';
		$field = $pf . $field;

		$this->andWhere($expr->iLike($field, $this->createNamedParameter($value)));
	}

	/**
	 * @param callable $method
	 *
	 * @return IQueryRow
	 * @throws RowNotFoundException
	 */
	public function getRow(callable $method): IQueryRow {
		$cursor = $this->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new RowNotFoundException();
		}

		return $method($data, $this);
	}

	/**
	 * @param callable $method
	 *
	 * @return IQueryRow[]
	 */
	public function getRows(callable $method): array {
		$rows = [];
		$cursor = $this->executeQuery();
		while ($data = $cursor->fetch()) {
			try {
				// a row the model cannot be built from is left out rather than
				// taking the whole page with it; the parsers already absorb a
				// missing joined actor themselves, so what reaches here is a
				// row this instance has no reading of
				$rows[] = $method($data, $this);
			} catch (Exception $e) {
			}
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * @param string $field
	 * @param array $value
	 * @param string $alias
	 */
	public function limitInArray(string $field, array $value, string $alias = ''): void {
		$this->andWhere($this->exprLimitInArray($field, $value, $alias));
	}

	/**
	 * @param string $field
	 * @param array $values
	 * @param string $alias
	 *
	 * @return string
	 */
	public function exprLimitInArray(string $field, array $values, string $alias = ''): string {
		if ($this->getType() === self::SELECT) {
			$field = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.' . $field;
		}

		$expr = $this->expr();

		return $expr->in($field, $this->createNamedParameter($values, IQueryBuilder::PARAM_STR_ARRAY));
	}
}
