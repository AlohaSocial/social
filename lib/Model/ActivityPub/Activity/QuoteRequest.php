<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * FEP-044f's `QuoteRequest`: the quoter's server asking the quoted author's
 * server for permission to quote a post, as Mastodon sends for every quote.
 *
 * `object` is the quoted post, `instrument` the quoting one, which must exist
 * first; the `Accept`'s `result` is the approval URI the quoting post carries
 * as `quoteAuthorization`.
 */
class QuoteRequest extends ACore implements JsonSerializable {
	public const TYPE = 'QuoteRequest';

	/** the post doing the quoting */
	private string $instrument = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getInstrument(): string {
		return $this->instrument;
	}

	public function setInstrument(string $instrument): self {
		$this->instrument = $instrument;

		return $this;
	}

	#[\Override]
	public function import(array $data) {
		parent::import($data);

		// Mastodon inlines the quoting post (`allow_post_inlining`), so the
		// instrument may be an object; only its id is trusted
		$instrument = $data['instrument'] ?? '';
		$this->setInstrument($this->validate(
			ACore::AS_ID, 'instrument', ['instrument' => is_array($instrument) ? ($instrument['id'] ?? '') : $instrument], ''
		));
	}

	/**
	 * Adds `instrument`, which the generic export does not know: without it there
	 * is nothing to approve.
	 */
	#[\Override]
	public function exportAsActivityPub(): array {
		if ($this->getInstrument() !== '') {
			$this->addEntry('instrument', $this->getInstrument());
		}

		return parent::exportAsActivityPub();
	}

	#[\Override]
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
