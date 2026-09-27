<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Actor;

use OCA\Social\Interfaces\Actor\OrganizationInterface;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Actor\Organization;
use OCA\Social\Model\ActivityPub\Actor\Person;

require_once __DIR__ . '/ActorInterfaceTestCase.php';

class OrganizationInterfaceTest extends ActorInterfaceTestCase {
	protected function createHandler(): PersonInterface {
		return new OrganizationInterface(
			$this->cacheActorsRequest,
			$this->streamRequest,
			$this->streamDestRequest,
			$this->actorService,
			$this->actorCascadeService,
			$this->jobList,
		);
	}

	protected function createActor(): Person {
		return new Organization();
	}
}
