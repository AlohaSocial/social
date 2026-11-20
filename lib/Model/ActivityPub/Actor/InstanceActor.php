<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Actor;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * This instance's own actor, which signs outbound fetches, as Mastodon's
 * `/actor`: a person signing them would appear in every peer's logs, and a
 * block of them anywhere would fail every fetch there. Written out rather than
 * inherited from `Person`, as it has no outbox, followers, following, bio,
 * aliases or webfinger account, and naming collections no route serves would
 * make peers think it gone.
 */
class InstanceActor extends Application implements JsonSerializable {
	public function __construct() {
		parent::__construct();

		// Person's constructor sets `self::TYPE`, resolved at compile time
		// against Person: without this the document says `Person`.
		$this->setType(self::TYPE);
	}

	/**
	 * The `keyId` an outbound signature names, and the `publicKey.id` a peer
	 * has to find in this document for the two to be the same key.
	 */
	public function getKeyId(): string {
		return $this->getId() . '#main-key';
	}

	#[\Override]
	public function exportAsActivityPub(): array {
		return [
			'@context' => [
				ACore::CONTEXT_ACTIVITYSTREAMS,
				ACore::CONTEXT_SECURITY,
				ACore::CONTEXT_EXTENSIONS,
			],
			'id' => $this->getId(),
			'type' => self::TYPE,
			'preferredUsername' => $this->getPreferredUsername(),
			'name' => $this->getName(),
			'url' => $this->getId(),
			// Mastodon drops a fetched actor without an `inbox`, and could then not
			// check our signature; anything addressed to this actor is addressed to
			// the server, so the shared inbox is the right one.
			'inbox' => $this->getInbox(),
			'endpoints' => ['sharedInbox' => $this->getSharedInbox()],
			'publicKey' => [
				'id' => $this->getKeyId(),
				'owner' => $this->getId(),
				'publicKeyPem' => $this->getPublicKey(),
			],
			// Not a person: `manuallyApprovesFollowers` is the one way every
			// implementation reads to hide the Follow button, and no Follow
			// addressed here is ever answered.
			'manuallyApprovesFollowers' => true,
			'discoverable' => false,
			'indexable' => false,
		];
	}

	/**
	 * The instance actor has no client-facing form: it is not an account, and
	 * nothing in the Mastodon API should ever return it.
	 */
	#[\Override]
	public function exportAsLocal(): array {
		return [];
	}

	#[\Override]
	public function jsonSerialize(): array {
		return $this->exportAsActivityPub();
	}
}
