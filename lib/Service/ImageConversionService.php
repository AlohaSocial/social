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
 * Gets an uploaded picture into a state it can be stored and federated in:
 * decodable by a browser, the right way up, and carrying none of the metadata
 * the camera wrote into it.
 *
 * Two formats arrive that a browser cannot draw. HEIC is the default on an
 * iPhone, so refusing it -- which is what the app did -- meant a large share of
 * the people this is for could not post a photo at all without converting it
 * first. It has to be transcoded. AVIF a browser *can* draw, so it is kept as
 * AVIF and only rewritten if it actually carries metadata.
 *
 * The order of preference throughout is "do nothing": a JPEG that is upright and
 * carries no Exif is stored byte for byte as it was uploaded. Re-encoding costs
 * a generation of quality every time and the app should not be the reason
 * somebody's photos look worse here than on their phone. So the expensive path
 * is taken only when something actually requires it -- a format nothing can
 * draw, an orientation tag that is about to be dropped, or metadata in a
 * container this cannot edit in place.
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
	 * The bytes to store, and the mime they are now.
	 *
	 * The mime can change: a HEIC upload is stored as a JPEG, and everything
	 * downstream -- the allow-list the file is served under, the `mediaType`
	 * that goes out on the wire -- has to be told the new one rather than the
	 * one the upload claimed.
	 *
	 * @return array{0: string, 1: string} the content and its mime
	 *
	 * @throws CacheContentMimeTypeException when nothing here can read the format
	 * @throws CacheContentDecodeException when the picture will not decode
	 */
	public function prepareForStorage(string $content, string $mime): array {
		[$content, $mime] = $this->convert($content, $mime);

		// Last, and only when an administrator asked for it: everything above
		// is about storing what was uploaded correctly, and this is about
		// storing less of it.
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

		// The orientation tag is about to be removed with the rest of the Exif
		// block, and it is the only record that the picture is meant to be
		// turned. Turn the pixels instead, which means re-encoding.
		if ($this->metadataService->needsRotation($content, $mime)) {
			$orientation = $this->metadataService->orientation($content, $mime);
			$rotated = $this->rotate($content, $orientation);
			if ($rotated !== null) {
				return [$rotated, $mime];
			}

			// Rotating failed. Keeping the Exif block would keep the picture
			// upright, but it is the block with the coordinates in it, so the
			// privacy of the person posting wins over the orientation of their
			// photo.
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
	 * The picture, no larger than this instance asked for.
	 *
	 * Off unless `image_max_edge` is set, and deliberately so: this app's
	 * promise has been that nothing loses a generation of quality. An instance
	 * where storage costs money, or whose people post from a 48-megapixel
	 * phone, wants the other trade — and a picture that is 8000 pixels wide is
	 * not being looked at at 8000 pixels by anybody.
	 *
	 * A picture already inside the ceiling is returned untouched rather than
	 * re-encoded at the configured quality: shrinking nothing and losing a
	 * generation anyway is the worst of both.
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
			// a picture that cannot be resized is stored as it arrived: the
			// upload is the person's, and a setting about disk space is not a
			// reason to refuse it
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
	 * Decodes a HEIC and re-encodes it as a JPEG.
	 *
	 * Imagick only: GD has no HEIC decoder in any build, so on a server without
	 * Imagick and libheif this cannot work and says so rather than storing
	 * something no browser will draw.
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
	 * An AVIF with no metadata is returned untouched; one that has some is
	 * re-encoded, because its metadata lives in an ISO base-media `meta` box
	 * whose items are addressed by byte offset -- removing one means rewriting
	 * every offset after it, which is a great deal of risk for a case that a
	 * re-encode handles.
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

		// Nothing here can rewrite it. Storing it as it arrived is what happened
		// to every upload before any of this existed, and it is still better
		// than refusing the picture -- but say so, because it is the one path
		// that leaves metadata in place.
		$this->logger->warning('[ImageConversionService] storing an AVIF with its metadata: no encoder available to strip it');

		return $content;
	}

	/**
	 * Whether an ISO base-media file (AVIF, HEIC) declares an Exif or XMP item.
	 *
	 * The item types are listed in `infe` entries inside the `meta` box as plain
	 * four-character strings, so finding out whether there is anything to strip
	 * needs no offset arithmetic -- which is the part that makes *removing* them
	 * hard. Only the header is searched: past a few hundred kilobytes it is
	 * image data, and a false positive there would cost a needless re-encode.
	 */
	private function isoBmffHasMetadata(string $content): bool {
		$head = substr($content, 0, 65536);

		return str_contains($head, 'Exif') || str_contains($head, "\x00\x00Exif") || str_contains($head, 'mime\0');
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
