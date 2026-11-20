<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TArrayTools;

class StreamDetails implements JsonSerializable {
	use TArrayTools;

	private Stream $stream;

	/** @var Person[] */
	private array $homeViewers = [];

	/** @var Person[] */
	private array $directViewers = [];
	private bool $public = false;
	private bool $federated = false;

	public function __construct(?Stream $stream = null) {
		if ($stream !== null) {
			$this->stream = $stream;
		}
	}

	public function getStream(): Stream {
		return $this->stream;
	}

	public function setStream(Stream $stream): self {
		$this->stream = $stream;

		return $this;
	}

	/** @return Person[] */
	public function getHomeViewers(): array {
		return $this->homeViewers;
	}

	/** @param Person[] $viewers */
	public function setHomeViewers(array $viewers): self {
		$this->homeViewers = $viewers;

		return $this;
	}

	public function addHomeViewer(Person $viewer): self {
		$this->homeViewers[] = $viewer;

		return $this;
	}

	public function getDirectViewers(): array {
		return $this->directViewers;
	}

	/**
	 * @param Person[] $viewers
	 */
	public function setDirectViewers(array $viewers): self {
		$this->directViewers = $viewers;

		return $this;
	}

	public function addDirectViewer(Person $viewer): self {
		$this->directViewers[] = $viewer;

		return $this;
	}

	public function isPublic(): bool {
		return $this->public;
	}

	public function setPublic(bool $public): self {
		$this->public = $public;

		return $this;
	}

	public function isFederated(): bool {
		return $this->federated;
	}

	public function setFederated(bool $federated): self {
		$this->federated = $federated;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'stream' => $this->getStream(),
			'homeViewers' => $this->getHomeViewers(),
			'directViewers' => $this->getDirectViewers(),
			'public' => $this->isPublic(),
			'federated' => $this->isFederated()
		];
	}
}
