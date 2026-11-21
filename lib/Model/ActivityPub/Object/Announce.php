<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use Exception;
use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;

class Announce extends Stream implements JsonSerializable {
	public const TYPE = 'Announce';

	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @param array $data
	 *
	 * @throws Exception
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		// Might be better to create 'actor_id' field in the 'server_streams' table.
		//		$this->setAttributedTo($this->getActorId());
	}

	/**
	 * The boost, as a client reads one: a status whose `reblog` is the status
	 * that was boosted. Only a Stream can stand in `reblog`; an `Announce` of
	 * anything else gets none rather than the wrong shape.
	 *
	 * @return array
	 */
	#[\Override]
	public function exportAsLocal(): array {
		$result = parent::exportAsLocal();

		$object = $this->getObject();
		if ($object instanceof Stream) {
			// the viewer's action read with a boost is their action on the
			// boosted status, which is where `reblogged` and `favourited` belong
			$action = $this->getAction();
			if (!$object->hasAction() && $action !== null && $action->getStreamId() === $object->getId()) {
				$object->setAction($action);
			}
			$result['reblog'] = $object->exportAsLocal();
		}

		return $result;
	}
}
