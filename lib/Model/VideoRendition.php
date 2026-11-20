<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One rung of a video's ladder: the same video, smaller. A fragmented MP4
 * (ffmpeg `-hls_flags single_file`) and a playlist addressing its segments
 * as byte ranges, stored with the file name as `{uri}`, since the route
 * serving it is not known at encode time.
 */
class VideoRendition implements JsonSerializable {
	/** What the media filename becomes in the stored playlist. */
	public const URI_PLACEHOLDER = '{uri}';

	private int $id = 0;
	private int|string $docNid = 0;
	private int $height = 0;
	private int $bandwidth = 0;
	private int $size = 0;
	private string $localCopy = '';
	private string $playlist = '';

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getDocNid(): int|string {
		return $this->docNid;
	}

	public function setDocNid(int|string $docNid): self {
		$this->docNid = $docNid;

		return $this;
	}

	public function getHeight(): int {
		return $this->height;
	}

	public function setHeight(int $height): self {
		$this->height = $height;

		return $this;
	}

	public function getBandwidth(): int {
		return $this->bandwidth;
	}

	public function setBandwidth(int $bandwidth): self {
		$this->bandwidth = $bandwidth;

		return $this;
	}

	public function getSize(): int {
		return $this->size;
	}

	public function setSize(int $size): self {
		$this->size = $size;

		return $this;
	}

	public function getLocalCopy(): string {
		return $this->localCopy;
	}

	public function setLocalCopy(string $localCopy): self {
		$this->localCopy = $localCopy;

		return $this;
	}

	public function getPlaylist(): string {
		return $this->playlist;
	}

	public function setPlaylist(string $playlist): self {
		$this->playlist = $playlist;

		return $this;
	}

	/**
	 * The stored playlist with the media URI put back in; the one place the
	 * placeholder is resolved.
	 */
	public function playlistFor(string $uri): string {
		return str_replace(self::URI_PLACEHOLDER, $uri, $this->playlist);
	}

	/**
	 * What the master playlist says about this rung. No `RESOLUTION`: the width
	 * is not stored (scale=-2 derives it), and a wrong value is worse than none.
	 * `BANDWIDTH` is what players choose on.
	 */
	public function masterEntry(string $uri): string {
		return '#EXT-X-STREAM-INF:BANDWIDTH=' . $this->bandwidth
			. ',NAME="' . $this->height . 'p"' . "\n" . $uri;
	}

	/**
	 * @return array<string, int|string>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'height' => $this->height,
			'bandwidth' => $this->bandwidth,
			'size' => $this->size,
		];
	}
}
