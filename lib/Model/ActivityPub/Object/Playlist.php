<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * PeerTube's `Playlist`: an ordered set of a channel's videos, stored as a
 * collection. Its `PlaylistElement`s are read from the raw document rather
 * than modelled.
 */
class Playlist extends ACore implements JsonSerializable {
	public const TYPE = 'Playlist';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * The document as it arrived, from which `PlaylistService` reads the
	 * elements.
	 *
	 * @return array<string, mixed>
	 */
	public function asWire(): array {
		$source = json_decode($this->getSource(), true);

		return is_array($source) ? $source : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
