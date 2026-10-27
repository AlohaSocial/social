<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

class ReplyRequest extends ACore implements JsonSerializable {
	public const TYPE = 'ReplyRequest';

	private string $instrument = '';
	private array $instrumentObject = [];

	public function __construct($parent = null) {
		parent::__construct($parent);
		$this->setType(self::TYPE);
	}

	#[\Override]
	protected function activityPubContextExtensions(): array {
		$extensions = parent::activityPubContextExtensions();
		$extensions['ReplyRequest'] = 'gts:ReplyRequest';

		return $extensions;
	}

	public function getInstrument(): string {
		return $this->instrument;
	}

	public function getInstrumentObject(): array {
		return $this->instrumentObject;
	}

	#[\Override]
	public function import(array $data) {
		parent::import($data);
		$instrument = $data['instrument'] ?? '';
		$this->instrumentObject = is_array($instrument) ? $instrument : [];
		$id = is_array($instrument) ? ($instrument['id'] ?? '') : $instrument;
		$this->instrument = is_string($id) ? $id : '';
	}

	#[\Override]
	public function exportAsActivityPub(): array {
		if ($this->instrument !== '') {
			$this->addEntry('instrument', $this->instrument);
		}

		return parent::exportAsActivityPub();
	}

	#[\Override]
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
