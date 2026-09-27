<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Instance;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class InstancesRequest
 *
 * @package OCA\Social\Db
 */
class InstancesRequest extends InstancesRequestBuilder {
	use TArrayTools;

	/**
	 * @param Instance $instance the instance to write a row for
	 */
	public function save(Instance $instance) {
		$qb = $this->getInstanceInsertSql();
		$qb->setValue('uri', $qb->createNamedParameter($instance->getUri()))
			->setValue('local', $qb->createNamedParameter($instance->isLocal() ? 1 : 0, IQueryBuilder::PARAM_INT))
			->setValue('title', $qb->createNamedParameter($instance->getTitle()))
			->setValue('version', $qb->createNamedParameter($instance->getVersion()))
			->setValue('short_description', $qb->createNamedParameter($instance->getShortDescription()))
			->setValue('description', $qb->createNamedParameter($instance->getDescription()))
			->setValue('email', $qb->createNamedParameter($instance->getEmail()))
			->setValue('urls', $qb->createNamedParameter(json_encode($instance->getUrls())))
			->setValue('stats', $qb->createNamedParameter(json_encode($instance->getStats())))
			->setValue('usage', $qb->createNamedParameter(json_encode($instance->getUsage())))
			->setValue('image', $qb->createNamedParameter($instance->getImage()))
			->setValue('languages', $qb->createNamedParameter(json_encode($instance->getLanguages())))
			->setValue('account_prim', $qb->createNamedParameter($instance->getAccountPrim() ? $qb->prim($instance->getAccountPrim()) : null));
		$qb->executeStatement();
	}

	/**
	 * @param int $format
	 *
	 * @return Instance
	 * @throws InstanceDoesNotExistException
	 */
	public function getLocal(int $format = ACore::FORMAT_ACTIVITYPUB): Instance {
		$qb = $this->getInstanceSelectSql($format);
		$qb->linkToCacheActors('ca', 'account_prim', false);
		$qb->limitToDBFieldInt('local', 1);
		$qb->leftJoinCacheDocuments('icon_id', 'ca');

		return $this->getInstanceFromRequest($qb);
	}
}
