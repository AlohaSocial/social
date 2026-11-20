<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Gumlet\ImageResize;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gets an uploaded picture into a state to store and federate: decodable by
 * a browser, upright, and without the camera's metadata.
 *
 * HEIC (an iPhone's default) is transcoded to JPEG; AVIF is kept and only
 * rewritten when it carries metadata. Otherwise nothing is re-encoded: an
 * upright JPEG without Exif is stored byte for byte, since every re-encode
 * costs quality.
 *
 * @see ImageMetadataService for the lossless path
 */
class ImageConversionService {
	/** What a transcoded HEIC becomes. */
	public const TRANSCODE_TARGET = 'image/jpeg';
	private const JPEG_QUALITY = 90;
	private const AVIF_QUALITY = 80;

	/**
	 * The formats a resize is worth doing on. A GIF may be animated and would
	 * come out as one frame; an AVIF is already small and the encoder is not
	 * always there.
	 */
	private const SHRINKABLE = ['image/jpeg', 'image/png', 'image/webp'];

	/** Uploaded as these, stored as something else. */
	private const TRANSCODE_FROM = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

	/** Handled in place by the byte-level stripper. */
	private const LOSSLESS_STRIP = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

	public function __construct(
		private ImageMetadataService $metadataService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The bytes to store, and their mime, which can change (a HEIC is stored as
	 * a JPEG) and must be used downstream instead of the claimed one.
	 *
	 * @return array{0: string, 1: string} the content and its mime
	 *
	 * @throws CacheContentMimeTypeException when nothing here can read the format
	 * @throws CacheContentDecodeException when the picture will not decode
	 */
	public function prepareForStorage(string $content, string $mime): array {
		[$content, $mime] = $this->convert($content, $mime);

		// last, and only when an administrator asked for it: storing less of
		// what was uploaded
		return $this->shrink($content, $mime);
	}

	/**
	 * Everything that has to happen to a picture before it is stored, whatever
	 * the instance's settings are.
	 *
	 * @return array{0: string, 1: string} the content and what it now is
	 */
	private function convert(string $content, string $mime): array {
		if (in_array($mime, self::TRANSCODE_FROM, true)) {
			return [$this->transcodeToJpeg($content), self::TRANSCODE_TARGET];
		}

		// The orientation tag goes with the Exif block, so turn the pixels
		// instead, which means re-encoding.
		if ($this->metadataService->needsRotation($content, $mime)) {
			$orientation = $this->metadataService->orientation($content, $mime);
			$rotated = $this->rotate($content, $orientation);
			if ($rotated !== null) {
				return [$rotated, $mime];
			}

			// Rotating failed. The Exif block is dropped anyway, since it holds the
			// coordinates: privacy wins over orientation.
			$this->logger->warning('[ImageConversionService] could not rotate an upload; storing it stripped and unrotated');
		}

		if (in_array($mime, self::LOSSLESS_STRIP, true)) {
			return [$this->metadataService->strip($content, $mime), $mime];
		}

		if ($mime === 'image/avif') {
			return [$this->stripAvif($content), $mime];
		}

		return [$content, $mime];
	}

	/**
	 * The picture, no larger than `image_max_edge`, when that is set. A picture
	 * already within it is returned untouched rather than re-encoded.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function shrink(string $content, string $mime): array {
		$edge = $this->configService->getAppValueInt(ConfigService::SOCIAL_IMAGE_MAX_EDGE);
		if ($edge <= 0 || !in_array($mime, self::SHRINKABLE, true)) {
			return [$content, $mime];
		}

		try {
			$image = ImageResize::createFromString($content);
			if ($image->getSourceWidth() <= $edge && $image->getSourceHeight() <= $edge) {
				return [$content, $mime];
			}

			$quality = max(40, min(100, $this->configService->getAppValueInt(ConfigService::SOCIAL_IMAGE_QUALITY)));
			$image->quality_jpg = $quality;
			$image->quality_webp = $quality;
			$image->resizeToBestFit($edge, $edge);
			$smaller = $image->getImageAsString();
		} catch (Throwable $e) {
			// a picture that cannot be resized is stored as it arrived rather than
			// refused
			$this->logger->notice('[ImageConversionService] could not shrink an upload', [
				'mime' => $mime, 'exception' => $e->getMessage(),
			]);

			return [$content, $mime];
		}

		return ($smaller !== '') ? [$smaller, $mime] : [$content, $mime];
	}

	/** Whether this installation can read the formats a phone camera produces. */
	public function canDecodeHeic(): bool {
		return $this->imagickSupports('HEIC');
	}

	/**
	 * Decodes a HEIC and re-encodes it as a JPEG, with Imagick and libheif only,
	 * since GD has no HEIC decoder.
	 *
	 * @throws CacheContentMimeTypeException when there is no decoder
	 * @throws CacheContentDecodeException when there is one and it fails
	 */
	private function transcodeToJpeg(string $content): string {
		if (!$this->canDecodeHeic()) {
			throw new CacheContentMimeTypeException(
				'this server cannot read HEIC images; it needs the imagick extension built with libheif'
			);
		}

		$this->assertHeicWithinPixelLimit($content);

		try {
			$image = new \Imagick();
			$image->readImageBlob($content);
			// a HEIC can hold a burst; the still everything else means is the first
			$image = $image->coalesceImages();
			$image->setIteratorIndex(0);
			$image->autoOrient();
			$image->setImageFormat('jpeg');
			$image->setImageCompressionQuality(self::JPEG_QUALITY);
			// autoOrient() has already applied it, so nothing is lost by this
			$image->stripImage();

			$out = $image->getImageBlob();
			$image->clear();
		} catch (Throwable $e) {
			throw new CacheContentDecodeException('cannot read this HEIC image: ' . $e->getMessage());
		}

		if ($out === '') {
			throw new CacheContentDecodeException('cannot read this HEIC image');
		}

		return $out;
	}

	/**
	 * `CacheDocumentService::MAX_PIXELS` for a HEIC, whose header
	 * `getimagesizefromstring()` cannot read: Imagick's ping reads the
	 * dimensions without decoding a pixel.
	 *
	 * @throws CacheContentDecodeException
	 */
	private function assertHeicWithinPixelLimit(string $content): void {
		try {
			$ping = new \Imagick();
			$ping->pingImageBlob($content);
			$width = $ping->getImageWidth();
			$height = $ping->getImageHeight();
			$ping->clear();
		} catch (Throwable $e) {
			throw new CacheContentDecodeException('cannot read this HEIC image: ' . $e->getMessage());
		}

		if ($width * $height > CacheDocumentService::MAX_PIXELS) {
			throw new CacheContentDecodeException('image is too large to decode: ' . $width . 'x' . $height);
		}
	}

	/**
	 * Turns the pixels according to an Exif orientation, returning null when
	 * the picture cannot be decoded.
	 *
	 * The eight orientations are the four rotations, each optionally mirrored.
	 */
	private function rotate(string $content, int $orientation): ?string {
		$image = @imagecreatefromstring($content);
		if ($image === false) {
			return null;
		}

		try {
			// mirrored orientations come in pairs with their plain counterpart
			if (in_array($orientation, [2, 4, 5, 7], true)) {
				imageflip($image, IMG_FLIP_HORIZONTAL);
			}

			$degrees = match ($orientation) {
				3, 4 => 180,
				5, 6 => 270,
				7, 8 => 90,
				default => 0,
			};

			if ($degrees !== 0) {
				$rotated = imagerotate($image, $degrees, 0);
				if ($rotated === false) {
					return null;
				}
				$image = $rotated;
			}

			ob_start();
			$ok = imagejpeg($image, null, self::JPEG_QUALITY);
			$out = (string)ob_get_clean();

			return ($ok && $out !== '') ? $out : null;
		} catch (Throwable $e) {
			$this->logger->warning('[ImageConversionService] rotating an upload failed', ['exception' => $e]);

			return null;
		}
	}

	/**
	 * An AVIF with no metadata is returned untouched; one with some is
	 * re-encoded, since removing an item from its `meta` box means rewriting
	 * every byte offset after it.
	 *
	 * @throws CacheContentMimeTypeException when it has metadata and nothing
	 *                                       here can re-encode it
	 */
	private function stripAvif(string $content): string {
		if (!$this->isoBmffHasMetadata($content)) {
			return $content;
		}

		if ($this->imagickSupports('AVIF')) {
			try {
				$image = new \Imagick();
				$image->readImageBlob($content);
				$image->autoOrient();
				$image->stripImage();
				$image->setImageFormat('avif');
				$image->setImageCompressionQuality(self::AVIF_QUALITY);
				$out = $image->getImageBlob();
				$image->clear();

				if ($out !== '') {
					return $out;
				}
			} catch (Throwable $e) {
				$this->logger->warning('[ImageConversionService] could not re-encode an AVIF', ['exception' => $e]);
			}
		}

		if (function_exists('imageavif')) {
			$image = @imagecreatefromstring($content);
			if ($image !== false) {
				ob_start();
				$ok = @imageavif($image, null, self::AVIF_QUALITY);
				$out = (string)ob_get_clean();
				if ($ok && $out !== '') {
					return $out;
				}
			}
		}

		// The metadata may hold where the picture was taken and would be
		// federated, so the picture is refused, as a HEIC without a decoder is.
		throw new CacheContentMimeTypeException(
			'this server cannot remove the metadata from AVIF images; it needs the imagick extension with AVIF support, or GD built with AVIF'
		);
	}

	/**
	 * Whether an ISO base-media file (AVIF, HEIC) declares an Exif or XMP item,
	 * as four-character types in the `meta` box's `infe` entries. Only the header
	 * is searched, as image data could give a false positive.
	 */
	private function isoBmffHasMetadata(string $content): bool {
		$head = substr($content, 0, 65536);

		return str_contains($head, 'Exif') || str_contains($head, "mime\0");
	}

	/** Whether Imagick is here and was built with a delegate for this format. */
	private function imagickSupports(string $format): bool {
		if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
			return false;
		}

		try {
			return in_array(strtoupper($format), \Imagick::queryFormats(strtoupper($format)), true);
		} catch (Throwable $e) {
			return false;
		}
	}
}
