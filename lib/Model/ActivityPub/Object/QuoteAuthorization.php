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
 * FEP-044f's stamp of approval, as a document a peer can fetch. Mastodon
 * fetches it before rendering a quote inline and requires it to name, from
 * the quoted author's server, who granted it, the quoting post and the
 * quoted post.
 */
class QuoteAuthorization extends ACore implements JsonSerializable {
	public const TYPE = 'QuoteAuthorization';

	private string $attributedTo = '';
	private string $interactingObject = '';
	private string $interactionTarget = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/** the author of the quoted post: the one whose permission this is */
	public function setAttributedTo(string $attributedTo): self {
		$this->attributedTo = $attributedTo;

		return $this;
	}

	public function getAttributedTo(): string {
		return $this->attributedTo;
	}

	/** the post doing the quoting */
	public function setInteractingObject(string $interactingObject): self {
		$this->interactingObject = $interactingObject;

		return $this;
	}

	public function getInteractingObject(): string {
		return $this->interactingObject;
	}

	/** the post being quoted */
	public function setInteractionTarget(string $interactionTarget): self {
		$this->interactionTarget = $interactionTarget;

		return $this;
	}

	public function getInteractionTarget(): string {
		return $this->interactionTarget;
	}

	#[\Override]
	public function import(array $data) {
		parent::import($data);
		$this->setAttributedTo($this->validate(ACore::AS_ID, 'attributedTo', $data, ''));
		$this->setInteractingObject($this->validate(ACore::AS_ID, 'interactingObject', $data, ''));
		$this->setInteractionTarget($this->validate(ACore::AS_ID, 'interactionTarget', $data, ''));
	}

	#[\Override]
	public function exportAsActivityPub(): array {
		$this->addEntry('attributedTo', $this->getAttributedTo());
		$this->addEntry('interactingObject', $this->getInteractingObject());
		$this->addEntry('interactionTarget', $this->getInteractionTarget());

		return parent::exportAsActivityPub();
	}

	#[\Override]
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
