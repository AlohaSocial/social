<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Actor;

use OCA\Social\Interfaces\Actor\GroupInterface;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

require_once __DIR__ . '/ActorInterfaceTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class GroupInterfaceTest extends ActorInterfaceTestCase {
	protected function createHandler(): PersonInterface {
		return new GroupInterface(
			$this->cacheActorsRequest,
			$this->streamRequest,
			$this->streamDestRequest,
			$this->actorService,
			$this->actorCascadeService,
			$this->jobList,
		);
	}

	protected function createActor(): Person {
		return new Group();
	}
}
