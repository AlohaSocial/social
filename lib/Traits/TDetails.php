<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Traits;

use OCA\Social\Model\ActivityPub\Item;

/**
 * The `details` JSON blob carried by streams, actors and notifications. Every
 * key is named in {@see \OCA\Social\Model\Details}: a misspelled key is no
 * error, just a value nothing reads.
 */
trait TDetails {
	/** @var array */
	private array $details = [];

	/** @var array<string, Item> handed along with the object, never stored */
	private array $detailItems = [];

	/**
	 * @return array
	 */
	public function getDetailsAll(): array {
		return $this->details;
	}

	/**
	 * @param array $details
	 */
	public function setDetailsAll(array $details) {
		$this->details = $details;
	}

	/**
	 * @param string $detail
	 * @param string $value
	 */
	public function setDetail(string $detail, string $value) {
		$this->details[$detail] = $value;
	}

	/**
	 * @param string $detail
	 * @param int $value
	 */
	public function setDetailInt(string $detail, int $value) {
		$this->details[$detail] = $value;
	}

	/**
	 * @param string $detail
	 * @param array $value
	 */
	public function setDetailArray(string $detail, array $value) {
		$this->details[$detail] = $value;
	}

	/**
	 * @param string $detail
	 * @param bool $value
	 */
	public function setDetailBool(string $detail, bool $value) {
		$this->details[$detail] = $value;
	}

	/**
	 * A whole object handed along with this one for the rest of the request,
	 * such as the post a notification is about, for the push and mail. Not a
	 * detail: `getDetailsAll()` leaves it out, so it is never stored or exported.
	 *
	 * @param string $detail
	 * @param Item $value
	 */
	public function setDetailItem(string $detail, Item $value) {
		$this->detailItems[$detail] = $value;
	}

	public function getDetailItem(string $detail): ?Item {
		return $this->detailItems[$detail] ?? null;
	}

	/**
	 * @param string $detail
	 *
	 * @return array
	 */
	public function getDetails(string $detail): array {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			return [];
		}

		return $this->details[$detail];
	}

	/**
	 * A details blob may have been written by anybody, so a count that comes
	 * back as a string is cast rather than allowed to fatal on the `int` return.
	 *
	 * @param string $detail one of the keys in {@see \OCA\Social\Model\Details}
	 */
	public function getDetailInt(string $detail, int $default = 0): int {
		$value = $this->details[$detail] ?? null;

		return is_numeric($value) ? (int)$value : $default;
	}

	/**
	 * @param string $detail
	 * @param string $value
	 */
	public function addDetail(string $detail, string $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			$this->details[$detail] = [];
		} elseif (in_array($value, $this->details[$detail], true)) {
			return;
		}

		$this->details[$detail][] = $value;
	}

	/**
	 * @param string $detail
	 * @param string $value
	 */
	public function removeDetail(string $detail, string $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			return;
		}

		$this->details[$detail] = array_diff($this->details[$detail], [$value]);
	}
}
