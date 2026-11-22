<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use GdImage;
use kornrunner\Blurhash\Blurhash;

class BlurService {
	/**
	 * The longest side of the copy the hash is taken from, in pixels. A 4×3
	 * blurhash only needs the broadest shapes, and the encoder holds a PHP
	 * array per pixel, so 32 px costs milliseconds where 800 px cost seconds.
	 */
	public const SAMPLE_SIZE = 32;

	public function __construct() {
	}

	public function generateBlurHash(GdImage $image): string {
		$image = $this->sample($image);
		$width = imagesx($image);
		$height = imagesy($image);
		$trueColor = imageistruecolor($image);

		$pixels = [];
		for ($y = 0; $y < $height; ++$y) {
			$row = [];
			for ($x = 0; $x < $width; ++$x) {
				$index = imagecolorat($image, $x, $y);
				if ($trueColor) {
					$row[] = [($index >> 16) & 0xFF, ($index >> 8) & 0xFF, $index & 0xFF];
					continue;
				}
				$colors = imagecolorsforindex($image, $index);

				$row[] = [$colors['red'], $colors['green'], $colors['blue']];
			}
			$pixels[] = $row;
		}

		return Blurhash::encode($pixels, 4, 3);
	}

	/** The image scaled so its longest side is `SAMPLE_SIZE`; a smaller one as it is. */
	private function sample(GdImage $image): GdImage {
		$width = imagesx($image);
		$height = imagesy($image);
		if ($width <= self::SAMPLE_SIZE && $height <= self::SAMPLE_SIZE) {
			return $image;
		}

		if ($width >= $height) {
			$sampled = imagescale($image, self::SAMPLE_SIZE, max(1, (int)round($height * self::SAMPLE_SIZE / $width)));
		} else {
			$sampled = imagescale($image, max(1, (int)round($width * self::SAMPLE_SIZE / $height)), self::SAMPLE_SIZE);
		}

		return ($sampled === false) ? $image : $sampled;
	}
}
