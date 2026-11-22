<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use Exception;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class ClientRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	public function __construct(
		IDBConnection $connection,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		ConfigService $configService,
		MiscService $miscService,
		protected SecretHasher $secretHasher,
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);
	}

	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CLIENT);

		return $qb;
	}

	/**
	 * An app registration, and nothing else: the legacy `auth_*` and `token`
	 * columns of `social_client` are stale, and authorizations are read through
	 * `ClientAuthRequest`.
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'cl.id', 'cl.app_name', 'cl.app_website', 'cl.app_redirect_uris', 'cl.app_client_id',
			'cl.app_client_secret', 'cl.app_scopes', 'cl.last_update', 'cl.creation'
		)
			->from(self::TABLE_CLIENT, 'cl');

		$this->defaultSelectAlias = 'cl';
		$qb->setDefaultSelectAlias('cl');

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getClientDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CLIENT);

		return $qb;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getClientFromRequest(SocialQueryBuilder $qb): SocialClient {
		/** @var SocialClient $result */
		try {
			$result = $qb->getRow([$this, 'parseClientSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new ClientNotFoundException($e->getMessage());
		}

		return $result;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return SocialClient[]
	 */
	public function getClientsFromRequest(SocialQueryBuilder $qb): array {
		/** @var SocialClient[] $result */
		$result = $qb->getRows([$this, 'parseClientSelectSql']);

		return $result;
	}

	/**
	 * @param array $data
	 *
	 * @return SocialClient
	 * @throws Exception
	 */
	public function parseClientSelectSql(array $data): SocialClient {
		$item = new SocialClient();
		$item->importFromDatabase($data);

		return $item;
	}
}
