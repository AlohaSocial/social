<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Model;

use JsonSerializable;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class SimpleDataStore
 *
 * @package OCA\Social\Tools\Model
 */
class SimpleDataStore implements JsonSerializable {
	use TArrayTools;

	private array $data;

	public function __construct(?array $data = []) {
		if (!is_array($data)) {
			$data = [];
		}

		$this->data = $data;
	}

	/**
	 * @param string $key
	 * @param string $value
	 *
	 * @return SimpleDataStore
	 */
	public function s(string $key, string $value): self {
		$this->data[$key] = $value;

		return $this;
	}

	/**
	 * @param string $key
	 *
	 * @return string
	 */
	public function g(string $key): string {
		return $this->get($key, $this->data);
	}

	/**
	 * @param string $key
	 * @param string $value
	 *
	 * @return SimpleDataStore
	 */
	public function a(string $key, string $value): self {
		if (!array_key_exists($key, $this->data)) {
			$this->data[$key] = [];
		}

		$this->data[$key][] = $value;

		return $this;
	}

	/**
	 * @param string $key
	 * @param int $value
	 *
	 * @return SimpleDataStore
	 */
	public function sInt(string $key, int $value): self {
		$this->data[$key] = $value;

		return $this;
	}

	/**
	 * @param string $key
	 * @param array $values
	 *
	 * @return SimpleDataStore
	 */
	public function sArray(string $key, array $values): self {
		$this->data[$key] = $values;

		return $this;
	}

	/**
	 * @param string $key
	 *
	 * @return array
	 */
	public function gArray(string $key): array {
		return $this->getArray($key, $this->data);
	}

	/**
	 * @param string $key
	 * @param array $values
	 *
	 * @return SimpleDataStore
	 */
	public function aArray(string $key, array $values): self {
		if (!array_key_exists($key, $this->data)) {
			$this->data[$key] = [];
		}

		$this->data[$key] = array_merge($this->data[$key], $values);

		return $this;
	}

	/**
	 * @param string $key
	 * @param JsonSerializable $value
	 *
	 * @return SimpleDataStore
	 */
	public function sObj(string $key, JsonSerializable $value): self {
		$this->data[$key] = $value;

		return $this;
	}

	/**
	 * @param string $key
	 * @param JsonSerializable $value
	 *
	 * @return SimpleDataStore
	 */
	public function aObj(string $key, JsonSerializable $value): self {
		if (!array_key_exists($key, $this->data)) {
			$this->data[$key] = [];
		}

		$this->data[$key][] = $value;

		return $this;
	}

	/**
	 * @return array
	 */
	public function gAll(): array {
		return $this->data;
	}

	public function keys(): array {
		return array_keys($this->data);
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public function hasKey(string $key): bool {
		return (array_key_exists($key, $this->data));
	}

	/**
	 * @param array $keys
	 *
	 * @param bool $must
	 *
	 * @return bool
	 * @throws MalformedArrayException
	 */
	public function hasKeys(array $keys, bool $must = false): bool {
		foreach ($keys as $key) {
			if (!$this->haveKey($key)) {
				if ($must) {
					throw new MalformedArrayException($key . ' missing in ' . json_encode($this->keys()));
				}

				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 * @deprecated
	 */
	public function haveKey(string $key): bool {
		return $this->hasKey($key);
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return $this->data;
	}
}
