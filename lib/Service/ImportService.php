<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;

class ImportService {
	use TArrayTools;
	use TStringTools;

	public function __construct(
		private MiscService $miscService,
		private ModerationService $moderationService,
		private RelayService $relayService,
	) {
	}

	/**
	 * @param string $json
	 *
	 * @return ACore
	 * @throws ActivityPubFormatException
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 */
	public function importFromJson(string $json): ACore {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new ActivityPubFormatException();
		}

		// the models are strictly typed, and a document that puts a string
		// where an object belongs trips that deep inside one of them. It is the
		// sender's bytes that are wrong, which is a 400 — not a 500 that makes
		// the peer send the same bytes again for two days
		try {
			return AP::instance()->getItemFromData($data);
		} catch (\TypeError $e) {
			throw new ActivityPubFormatException('malformed activity: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * @param ACore $activity
	 *
	 * @throws ItemUnknownException
	 * @throws InvalidOriginException
	 */
	public function parseIncomingRequest(ACore $activity) {
		$activity->checkOrigin($activity->getId());

		// a suspended account is not merely hidden here: nothing further it
		// sends is taken in, or the suspension would undo itself the next time
		// it posted
		$author = $activity->getActorId() !== '' ? $activity->getActorId() : $activity->getId();
		if ($this->moderationService->isSuspended($author)) {
			$this->miscService->log('Refusing ' . $activity->getType() . ' from a suspended account');

			return;
		}

		$activity->setRequestToken($this->uuid());

		// A relay speaks a dialect of its own: its `Announce` is a pointer at
		// somebody else's post rather than a boost of it, and its `Accept`
		// answers a Follow no local actor sent. Both would be mishandled by
		// the ordinary interfaces — the Announce as a boost by an actor nobody
		// follows, the Accept as a follow that does not exist. Scoped to
		// actors this instance holds a subscription row for, so a server that
		// merely calls itself a relay is still handled as what it is.
		if ($this->relayService->handleIncoming($activity)) {
			return;
		}

		$interface = AP::instance()->getInterfaceForItem($activity);
		try {
			$interface->processIncomingRequest($activity);
		} catch (InvalidResourceException|ItemNotFoundException|RedundancyLimitException $e) {
			// The activity is understood but there is nothing to do with it (an
			// unresolvable resource, a missing target, a too-deep object). Tolerated,
			// like the ItemUnknownException the inbox already ignores.
			$this->miscService->log(
				'Ignoring ' . $activity->getType() . ': ' . get_class($e) . ' ' . $e->getMessage()
			);
		}
		// Anything else — a database error, an unexpected failure while storing the
		// activity — propagates, so the inbox answers 5xx and the sender retries
		// rather than the activity being lost behind a 200.
	}
}
