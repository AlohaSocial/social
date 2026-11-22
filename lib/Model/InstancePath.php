<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

class InstancePath implements JsonSerializable {
	use TArrayTools;

	public const TYPE_PUBLIC = 0;
	public const TYPE_INBOX = 1;
	public const TYPE_GLOBAL = 2;
	public const TYPE_FOLLOWERS = 3;
	public const TYPE_ALL = 4;

	public const PRIORITY_NONE = 0;
	public const PRIORITY_LOW = 1;
	public const PRIORITY_MEDIUM = 2;
	public const PRIORITY_HIGH = 3;
	public const PRIORITY_TOP = 4;

	private string $uri = '';
	private int $type = 0;
	private int $priority = 0;

	public function __construct(string $uri = '', int $type = 0, int $priority = 0) {
		$this->uri = $uri;
		$this->type = $type;
		$this->priority = $priority;
	}

	/**
	 * @param string $uri
	 *
	 * @return InstancePath
	 */
	public function setUri(string $uri): InstancePath {
		$this->uri = $uri;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getUri(): string {
		return $this->uri;
	}

	/**
	 * @param int $type
	 *
	 * @return InstancePath
	 */
	public function setType(int $type): InstancePath {
		$this->type = $type;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getType(): int {
		return $this->type;
	}

	/**
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}

	/**
	 * @param int $priority
	 *
	 * @return InstancePath
	 */
	public function setPriority(int $priority): InstancePath {
		$this->priority = $priority;

		return $this;
	}

	public function getProtocol(): string {
		$info = parse_url($this->getUri());

		return $this->get('scheme', $info, '');
	}

	/**
	 * @return string
	 */
	public function getAddress(): string {
		$info = parse_url($this->getUri());

		return $this->get('host', $info, '');
	}

	/**
	 * @return string
	 */
	public function getPath(): string {
		$info = parse_url($this->getUri());

		return $this->get('path', $info, '');
	}

	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$this->setUri($this->get('uri', $data, ''));
		$this->setType($this->getInt('type', $data, 0));
		$this->setPriority($this->getInt('priority', $data, 0));
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'uri' => $this->getUri(),
			'type' => $this->getType(),
			'priority' => $this->getPriority()
		];
	}
}
