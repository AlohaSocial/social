<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

use DateTime;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Interface IExtendedQueryBuilder
 *
 * @deprecated
 * @package OCA\Social\Tools
 */
interface IExtendedQueryBuilder extends IQueryBuilder {
	/**
	 * The statement types `IQueryBuilder::getType()` reports.
	 *
	 * The public interface returns the number but never names it, so the app
	 * used to import `Doctrine\DBAL\Query\QueryBuilder` — a third-party class
	 * it does not depend on — in four files purely to read these four
	 * constants. `QueryBuilderTypeConstantsTest` holds them to the values
	 * Doctrine assigns.
	 */
	public const SELECT = 0;
	public const DELETE = 1;
	public const UPDATE = 2;
	public const INSERT = 3;

	/**
	 * @param string $alias
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function setDefaultSelectAlias(string $alias): IExtendedQueryBuilder;

	/**
	 * @return string
	 */
	public function getDefaultSelectAlias(): string;

	/**
	 * Limit the request to the Id
	 *
	 * @param int $id
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function limitToId(int $id): IExtendedQueryBuilder;

	/**
	 * Limit the request to the Id (string)
	 *
	 * @param string $id
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function limitToIdString(string $id): IExtendedQueryBuilder;

	/**
	 * Limit the request to the UserId
	 *
	 * @param string $userId
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function limitToUserId(string $userId): IExtendedQueryBuilder;

	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs
	 * @param string $alias
	 */
	public function limitToDBField(string $field, string $value, bool $cs = true, string $alias = '',
	): void;

	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs
	 * @param string $alias
	 *
	 * @return mixed
	 */
	public function filterDBField(string $field, string $value, bool $cs = true, string $alias = '',
	): void;

	public function exprLimitToDBField(
		string $field, string $value, bool $eq = true, bool $cs = true, string $alias = '',
	): string;

	public function limitToDBFieldArray(
		string $field, array $values, bool $cs = true, string $alias = '',
	): void;

	/**
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	public function exprLimitToDBFieldArray(
		string $field, array $values, bool $eq = true, bool $cs = true, string $alias = '',
	): ICompositeExpression;

	public function limitToDBFieldInt(string $field, int $value, string $alias = ''): void;

	/**
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	public function exprLimitToDBFieldInt(string $field, int $value, string $alias = ''): string;

	/**
	 * @param string $field
	 */
	public function limitToDBFieldEmpty(string $field): void;

	/**
	 * @param string $field
	 * @param DateTime $date
	 * @param bool $orNull
	 */
	public function limitToDBFieldDateTime(string $field, DateTime $date, bool $orNull = false,
	): void;

	/**
	 * @param string $field
	 * @param string $value
	 *
	 * @return mixed
	 */
	public function searchInDBField(string $field, string $value): void;
}
