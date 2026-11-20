<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Where a post was taken: never inferred and never required. Nothing here
 * geocodes, so a place is one the instance has seen or one a client named
 * with coordinates it already had. Coordinates stay strings throughout, so
 * nothing rounds what somebody typed.
 */
class Place implements IQueryRow, JsonSerializable {
	use TArrayTools;

	public const NAME_MAX = 255;

	private int $id = 0;
	private string $name = '';
	private string $country = '';
	private string $lat = '';
	private string $lon = '';

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): self {
		$this->name = mb_substr(trim($name), 0, self::NAME_MAX);

		return $this;
	}

	/** The md5 of the lowercased name, which is what the row is keyed by. */
	public function getNamePrim(): string {
		return ($this->name === '') ? '' : md5(mb_strtolower($this->name));
	}

	public function getCountry(): string {
		return $this->country;
	}

	/**
	 * ISO 3166-1 alpha-2, upper-cased. Anything that is not two letters is
	 * dropped, so the column can group.
	 */
	public function setCountry(string $country): self {
		$country = strtoupper(trim($country));
		$this->country = preg_match('/^[A-Z]{2}$/', $country) ? $country : '';

		return $this;
	}

	public function getLat(): string {
		return $this->lat;
	}

	public function getLon(): string {
		return $this->lon;
	}

	/** Both or neither: half a coordinate points nowhere. */
	public function setCoordinates(string $lat, string $lon): self {
		$lat = trim($lat);
		$lon = trim($lon);

		if (!is_numeric($lat) || !is_numeric($lon)
			|| abs((float)$lat) > 90 || abs((float)$lon) > 180) {
			$this->lat = '';
			$this->lon = '';

			return $this;
		}

		$this->lat = $lat;
		$this->lon = $lon;

		return $this;
	}

	public function hasCoordinates(): bool {
		return $this->lat !== '' && $this->lon !== '';
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data));
		$this->setName($this->get('name', $data));
		$this->setCountry($this->get('country', $data));
		$this->setCoordinates($this->get('lat', $data), $this->get('lon', $data));
	}

	/** Pixelfed's shape: `long`, not `lon`, and every value a string. */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'name' => $this->getName(),
			'country' => $this->getCountry(),
			'lat' => $this->getLat(),
			'long' => $this->getLon(),
		];
	}
}
