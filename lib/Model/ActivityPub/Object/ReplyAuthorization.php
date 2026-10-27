<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

class ReplyAuthorization extends ACore implements JsonSerializable {
	public const TYPE = 'ReplyAuthorization';

	private string $attributedTo = '';
	private string $interactingObject = '';
	private string $interactionTarget = '';

	public function __construct($parent = null) {
		parent::__construct($parent);
		$this->setType(self::TYPE);
	}

	#[\Override]
	protected function activityPubContextExtensions(): array {
		$extensions = parent::activityPubContextExtensions();
		$extensions['interactingObject'] = ['@id' => 'gts:interactingObject', '@type' => '@id'];
		$extensions['interactionTarget'] = ['@id' => 'gts:interactionTarget', '@type' => '@id'];
		return $extensions;
	}

	public function setAttributedTo(string $attributedTo): self {
		$this->attributedTo = $attributedTo;
		return $this;
	}

	public function getAttributedTo(): string {
		return $this->attributedTo;
	}

	public function setInteractingObject(string $interactingObject): self {
		$this->interactingObject = $interactingObject;
		return $this;
	}

	public function getInteractingObject(): string {
		return $this->interactingObject;
	}

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
		$this->addEntry('attributedTo', $this->attributedTo);
		$this->addEntry('interactingObject', $this->interactingObject);
		$this->addEntry('interactionTarget', $this->interactionTarget);
		return parent::exportAsActivityPub();
	}

	#[\Override]
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
