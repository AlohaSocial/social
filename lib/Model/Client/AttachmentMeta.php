<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

class AttachmentMeta implements JsonSerializable {
	use TArrayTools;

	private ?AttachmentMetaDim $original = null;
	private ?AttachmentMetaDim $small = null;
	private ?AttachmentMetaFocus $focus = null;

	private ?int $width = null;
	private ?int $height = null;
	private ?float $aspect = null;
	private string $size = '';
	private string $length = '';
	private ?float $duration = null;
	private ?float $fps = null;

	private string $audioEncode = '';
	private string $audioBitrate = '';
	private string $audioChannels = '';

	private string $description = '';
	private string $blurHash = '';

	public function setOriginal(AttachmentMetaDim $original): self {
		$this->original = $original;

		return $this;
	}

	public function getOriginal(): ?AttachmentMetaDim {
		return $this->original;
	}

	public function setSmall(AttachmentMetaDim $small): self {
		$this->small = $small;

		return $this;
	}

	public function getSmall(): ?AttachmentMetaDim {
		return $this->small;
	}

	public function setFocus(AttachmentMetaFocus $focus): self {
		$this->focus = $focus;

		return $this;
	}

	public function getFocus(): ?AttachmentMetaFocus {
		return $this->focus;
	}

	public function setWidth(int $width): self {
		$this->width = $width;

		return $this;
	}

	public function getWidth(): ?int {
		return $this->width;
	}

	public function setHeight(int $height): self {
		$this->height = $height;

		return $this;
	}

	public function getHeight(): ?int {
		return $this->height;
	}

	public function setAspect(float $aspect): self {
		$this->aspect = $aspect;

		return $this;
	}

	public function getAspect(): ?float {
		return $this->aspect;
	}

	public function setSize(string $size): self {
		$this->size = $size;

		return $this;
	}

	public function getSize(): string {
		return $this->size;
	}

	public function setLength(string $length): self {
		$this->length = $length;

		return $this;
	}

	public function getLength(): string {
		return $this->length;
	}

	public function setDuration(float $duration): self {
		$this->duration = $duration;

		return $this;
	}

	public function getDuration(): ?float {
		return $this->duration;
	}

	public function setFps(float $fps): self {
		$this->fps = $fps;

		return $this;
	}

	public function getFps(): ?float {
		return $this->fps;
	}

	public function setAudioEncode(string $audioEncode): self {
		$this->audioEncode = $audioEncode;

		return $this;
	}

	public function getAudioEncode(): string {
		return $this->audioEncode;
	}

	public function setAudioBitrate(string $audioBitrate): self {
		$this->audioBitrate = $audioBitrate;

		return $this;
	}

	public function getAudioBitrate(): string {
		return $this->audioBitrate;
	}

	public function setAudioChannels(string $audioChannels): self {
		$this->audioChannels = $audioChannels;

		return $this;
	}

	public function getAudioChannels(): string {
		return $this->audioChannels;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setBlurHash(string $blurHash): self {
		$this->blurHash = $blurHash;

		return $this;
	}

	public function getBlurHash(): ?string {
		return $this->blurHash;
	}

	public function import(array $data): self {
		if (array_key_exists('width', $data)) {
			$this->setWidth($this->getInt('width', $data));
		}
		if (array_key_exists('height', $data)) {
			$this->setHeight($this->getInt('height', $data));
		}
		if (array_key_exists('aspect', $data)) {
			$this->setAspect($this->getInt('aspect', $data));
		}
		if (array_key_exists('duration', $data)) {
			$this->setDuration($this->getInt('duration', $data));
		}

		$this->setSize($this->get('size', $data));
		$this->setLength($this->get('length', $data));
		$this->setFps($this->getFloat('fps', $data));
		$this->setAudioEncode($this->get('audio_encode', $data));
		$this->setAudioBitrate($this->get('audio_bitrate', $data));
		$this->setAudioChannels($this->get('audio_channels', $data));
		$this->setDescription($this->get('description', $data));
		$this->setBlurHash($this->get('blurhash', $data));

		$original = new AttachmentMetaDim();
		$original->import($this->getArray('original', $data));
		$this->setOriginal($original);

		$small = new AttachmentMetaDim();
		$small->import($this->getArray('small', $data));
		$this->setSmall($small);

		// Floats, not integers. A focal point is a fraction of the way from the
		// centre to each edge, so every value a client ever sends is between
		// -1 and 1 and `getInt()` rounded all of them to the centre.
		$focus = new AttachmentMetaFocus($this->getFloat('focus.x', $data), $this->getFloat('focus.y', $data));
		$this->setFocus($focus);

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return array_filter(
			[
				'width' => $this->getWidth(),
				'height' => $this->getHeight(),
				'aspect' => $this->getAspect(),
				'size' => $this->getSize(),
				'length' => $this->getLength(),
				'duration' => $this->getDuration(),
				'fps' => $this->getFps(),
				'original' => self::stated($this->getOriginal()),
				'small' => self::stated($this->getSmall()),
				'focus' => $this->getFocus(),
				'audio_encode' => $this->getAudioEncode(),
				'audio_bitrate' => $this->getAudioBitrate(),
				'audio_channels' => $this->getAudioChannels(),
				'description' => $this->getDescription(),
				'blurhash' => $this->getBlurHash()
			]
		);
	}

	/**
	 * A size that states nothing is left out: it would serialise as `[]`
	 * where clients expect an object, and they would drop the status.
	 */
	private static function stated(?AttachmentMetaDim $dimension): ?AttachmentMetaDim {
		return ($dimension === null || $dimension->jsonSerialize() === []) ? null : $dimension;
	}
}
