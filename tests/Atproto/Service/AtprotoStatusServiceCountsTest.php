<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Service;

use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\AtprotoStatusService;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** The numbers of the admin section's Bluesky card. */
class AtprotoStatusServiceCountsTest extends TestCase {
	/**
	 * Every identity is counted, and apart from that the ones their owners
	 * switched off for Bluesky, so the count says how many are there.
	 */
	public function testTheIdentitiesAreCountedWithTheSwitchedOffApart(): void {
		$identities = $this->createStub(IdentityService::class);
		$identities->method('count')->willReturn(12);
		$identities->method('countDeactivated')->willReturn(2);

		$status = (new AtprotoStatusService(
			$this->createStub(AtprotoConfig::class), $this->createStub(ConfigService::class), $identities,
			$this->createStub(InstanceKeyService::class), $this->createStub(EventService::class), $this->createStub(FirehoseDaemon::class),
			$this->createStub(AtprotoRepoRequest::class), $this->createStub(CurlService::class), $this->createStub(IConfig::class),
			$this->createStub(ICacheFactory::class), $this->createStub(ITimeFactory::class),
		))->current()['status'];

		$this->assertSame([12, 2], [$status['identities'], $status['identities_off']]);
	}
}
