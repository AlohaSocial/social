<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ImageInterface extends DocumentInterface implements IActivityPubInterface {
	public function __construct(
		CacheDocumentsRequest $cacheDocumentsRequest,
		CacheDocumentService $cacheDocumentService,
		ConfigService $configService,
		LoggerInterface $logger = new NullLogger(),
	) {
		parent::__construct($cacheDocumentService, $cacheDocumentsRequest, $configService, $logger);
	}
}
