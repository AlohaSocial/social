<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Actor;

use JsonSerializable;

class Group extends Person implements JsonSerializable {
	public const TYPE = 'Group';
}
