<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Mock;

use OCA\Social\Service\CacheActorService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * A CacheActorService whose lookups are all stubs except `resolve()`, which
 * runs for real on top of them. Controllers name accounts through
 * `resolve()`; a test stubs the lookup it expects (`getFromNids`,
 * `getFromId`, `getFromAccount`, `getCachedFromIds`) and so tests the rule
 * the controller relies on rather than a stub of it.
 */
trait TCacheActorServiceMock {
	/** @return CacheActorService&MockObject */
	private function cacheActorServiceMock(): CacheActorService {
		return $this->getMockBuilder(CacheActorService::class)
			->disableOriginalConstructor()
			->onlyMethods(array_values(array_diff(
				get_class_methods(CacheActorService::class),
				['__construct', 'resolve']
			)))
			->getMock();
	}
}
