<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/**
 * Removes the metadata a camera writes into an image, such as the GPS
 * position in Exif, before it is stored or federated.
 *
 * The container is rewritten without the metadata segments rather than the
 * pixels re-encoded, so it is lossless, for JPEG, PNG and WebP. The exception
 * is an Exif `Orientation` other than upright, which only the tag records:
 * the caller rotates, which re-encodes (see `orientation()`).
 *
 * Kept: the ICC colour profile and everything structural (animation,
 * transparency, palette). Removed: Exif, XMP, IPTC/Photoshop resources, JPEG
 * comments, PNG text chunks and timestamps, and anything after a JPEG's
 * primary image.
 */
class ImageMetadataService {
	/** Exif orientation meaning "as shot, no rotation needed". */
	public const ORIENTATION_NORMAL = 1;

	/** The quality a JPEG whose structure cannot be followed is re-encoded at. */
	private const REENCODE_QUALITY = 90;

	/**
	 * JPEG APPn/COM segments that carry metadata rather than image data.
	 *
	 * APP0 (JFIF) and APP2 (ICC) are not here on purpose: the first describes
	 * the pixel density and the second is the colour profile.
	 */
	private const JPEG_DROP_MARKERS = [
		0xE1, // APP1  -- Exif, and XMP, which uses the same marker
		0xED, // APP13 -- Photoshop IRB, which is where IPTC lives
		0xEE, // APP14 -- Adobe, carries no image data a decoder needs
		0xFE, // COM   -- free-text comment
	];

	/**
	 * PNG ancillary chunks that carry metadata.
	 *
	 * Everything else is copied, which keeps `iCCP`/`sRGB`/`gAMA` (colour) and
	 * `acTL`/`fcTL`/`fdAT` (APNG animation) intact.
	 */
	private const PNG_DROP_CHUNKS = ['eXIf', 'tEXt', 'iTXt', 'zTXt', 'tIME'];

	/** RIFF chunks inside a WebP that carry metadata. */
	private const WEBP_DROP_CHUNKS = ['EXIF', 'XMP '];

	/** IPTC's two `DigitalSourceType` terms for an image a model produced. */
	public const SOURCE_TRAINED = 'trainedAlgorithmicMedia';
	public const SOURCE_COMPOSITE = 'compositeWithTrainedAlgorithmicMedia';

	/** The terms, lower-cased for comparison, and the spelling answered for each. */
	private const SOURCE_TERMS = [
		'compositewithtrainedalgorithmicmedia' => self::SOURCE_COMPOSITE,
		'trainedalgorithmicmedia' => self::SOURCE_TRAINED,
	];

	/** The property name the terms are stated under, in XMP and in a C2PA assertion alike. */
	private const SOURCE_PROPERTY = 'DigitalSourceType';

	/** How far past a `DigitalSourceType` the value is looked for. */
	private const SOURCE_WINDOW = 512;

	/** How many `DigitalSourceType` occurrences are examined before giving up. */
	private const SOURCE_OCCURRENCES = 16;

	/**
	 * The image with its metadata removed, or the bytes unchanged when the
	 * format is not one handled here. Never throws: an image that cannot be
	 * parsed is stored as it arrived; the mime allow-list decides which
	 * formats may be stored.
	 */
	public function strip(string $content, string $mime): string {
		return match ($mime) {
			'image/jpeg' => $this->stripJpeg($content),
			'image/png' => $this->stripPng($content),
			'image/webp' => $this->stripWebp($content),
			// GIF has no Exif block to carry coordinates, and re-writing one
			// risks its animation for nothing.
			default => $content,
		};
	}

	/**
	 * The Exif orientation of a JPEG, or `ORIENTATION_NORMAL` when it says
	 * nothing. Read before stripping removes it: a value other than 1 means
	 * the caller has to turn the pixels upright first.
	 */
	public function orientation(string $content, string $mime): int {
		if ($mime !== 'image/jpeg') {
			return self::ORIENTATION_NORMAL;
		}

		$exif = $this->jpegExifSegment($content);
		if ($exif === null) {
			return self::ORIENTATION_NORMAL;
		}

		return $this->orientationFromExif($exif);
	}

	/** Whether a stripped copy would still need its pixels turned. */
	public function needsRotation(string $content, string $mime): bool {
		return $this->orientation($content, $mime) > self::ORIENTATION_NORMAL;
	}

	/**
	 * The machine-generation provenance an image states about itself:
	 * `SOURCE_TRAINED`, `SOURCE_COMPOSITE`, or '' when it states none. Read
	 * before stripping, like the orientation.
	 *
	 * Looked for as IPTC `DigitalSourceType` in XMP (attribute or element,
	 * with or without the `Iptc4xmpExt:` prefix, URL or bare term), and as the
	 * term under its `digitalsourcetype/` path anywhere in the bytes, which is
	 * how a C2PA manifest carries it without parsing CBOR. Case-insensitive,
	 * and bounded: linear passes, with the XMP value looked for only in a
	 * short window after the first few occurrences of the property name.
	 */
	public function digitalSourceType(string $content, string $mime): string {
		if (!str_starts_with($mime, 'image/')) {
			return '';
		}

		$offset = 0;
		for ($seen = 0; $seen < self::SOURCE_OCCURRENCES; $seen++) {
			$at = stripos($content, self::SOURCE_PROPERTY, $offset);
			if ($at === false) {
				break;
			}

			$offset = $at + strlen(self::SOURCE_PROPERTY);
			$term = $this->sourceTermIn(substr($content, $offset, self::SOURCE_WINDOW));
			if ($term !== '') {
				return $term;
			}
		}

		foreach (self::SOURCE_TERMS as $lower => $term) {
			if (stripos($content, 'digitalsourcetype/' . $lower) !== false) {
				return $term;
			}
		}

		return '';
	}

	/**
	 * The term stated in the bytes that follow a `DigitalSourceType`, in any
	 * of the shapes XMP writes a property in, or '' when none is.
	 */
	private function sourceTermIn(string $window): string {
		$value = '';
		if (preg_match('/^\s*=\s*["\']([^"\']*)["\']/', $window, $found) === 1) {
			// an attribute: DigitalSourceType="…"
			$value = $found[1];
		} elseif (preg_match('/^[^>]*\brdf:resource\s*=\s*["\']([^"\']*)["\']/', $window, $found) === 1) {
			// an element whose value is a resource: <… rdf:resource="…"/>
			$value = $found[1];
		} elseif (preg_match('/^[^>\/]*>\s*(?:<rdf:(?:Bag|Seq|Alt)[^>]*>\s*<rdf:li[^>]*>\s*)?([^<]+)</', $window, $found) === 1) {
			// an element with its value as text: <…>…</…>
			$value = $found[1];
		}

		return $this->sourceTerm($value);
	}

	/** A stated value — the NewsCodes URL or the bare term — as the term it names. */
	private function sourceTerm(string $value): string {
		$value = trim($value);
		$slash = strrpos($value, '/');
		if ($slash !== false) {
			$value = substr($value, $slash + 1);
		}

		return self::SOURCE_TERMS[strtolower($value)] ?? '';
	}

	/**
	 * Walks the JPEG segment list and copies everything but the metadata.
	 * Each `SOS` is followed by entropy-coded data up to the next marker that
	 * is not a stuffed `FF 00` or a restart, and nothing after `EOI` is copied:
	 * that is where MPF secondary images and Motion Photo videos go, each with
	 * metadata of its own. A file whose structure cannot be followed is
	 * re-encoded, as the unreadable part may hold metadata.
	 */
	private function stripJpeg(string $content): string {
		if (strlen($content) < 4 || substr($content, 0, 2) !== "\xFF\xD8") {
			return $content;
		}

		$out = "\xFF\xD8";
		$i = 2;
		$len = strlen($content);

		while ($i + 1 < $len) {
			if ($content[$i] !== "\xFF") {
				return $this->reencodeJpeg($content, $out);
			}

			$marker = ord($content[$i + 1]);

			// padding, and the standalone markers that carry no length
			if ($marker === 0xFF) {
				$out .= "\xFF";
				$i++;
				continue;
			}
			if ($marker === 0xD9) {
				return $out . "\xFF\xD9";
			}
			if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
				$out .= substr($content, $i, 2);
				$i += 2;
				continue;
			}

			if ($i + 3 >= $len) {
				return $this->reencodeJpeg($content, $out);
			}

			$size = (ord($content[$i + 2]) << 8) | ord($content[$i + 3]);
			if ($size < 2 || $i + 2 + $size > $len) {
				return $this->reencodeJpeg($content, $out);
			}

			if ($marker === 0xDA) {
				$out .= substr($content, $i, 2 + $size);
				$i += 2 + $size;
				$end = self::entropyEnd($content, $i);
				$out .= substr($content, $i, $end - $i);
				$i = $end;
				continue;
			}

			if (!in_array($marker, self::JPEG_DROP_MARKERS, true)
				&& !($marker === 0xE2 && substr($content, $i + 4, 4) === "MPF\0")) {
				$out .= substr($content, $i, 2 + $size);
			}

			$i += 2 + $size;
		}

		// a file cut off before its end: what was copied carries no metadata
		return $out;
	}

	/**
	 * Where the entropy coded data starting at `$i` ends: the next `FF` that
	 * is neither a stuffed `FF 00`, a restart marker nor fill, or the end of
	 * the file.
	 */
	private static function entropyEnd(string $content, int $i): int {
		$len = strlen($content);
		while (($i = strpos($content, "\xFF", $i)) !== false && $i + 1 < $len) {
			$next = ord($content[$i + 1]);
			if ($next === 0xFF) {
				$i++;
				continue;
			}
			if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
				$i += 2;
				continue;
			}

			return $i;
		}

		return $len;
	}

	/**
	 * The pixels of a JPEG written out afresh, without metadata, or
	 * `$fallback` (what the walk copied, which has none either) when the
	 * image does not decode.
	 */
	private function reencodeJpeg(string $content, string $fallback): string {
		$image = @imagecreatefromstring($content);
		if ($image === false) {
			return $fallback;
		}

		ob_start();
		$ok = @imagejpeg($image, null, self::REENCODE_QUALITY);
		$out = (string)ob_get_clean();

		return ($ok && $out !== '') ? $out : $fallback;
	}

	/**
	 * Copies the PNG chunk stream, dropping the chunks that hold metadata;
	 * each chunk carries its own CRC, so nothing needs recomputing.
	 */
	private function stripPng(string $content): string {
		$signature = "\x89PNG\r\n\x1a\n";
		if (strlen($content) < 8 || substr($content, 0, 8) !== $signature) {
			return $content;
		}

		$out = $signature;
		$i = 8;
		$len = strlen($content);

		while ($i + 8 <= $len) {
			$size = unpack('N', substr($content, $i, 4))[1];
			$type = substr($content, $i + 4, 4);
			$total = 12 + $size;

			if ($size < 0 || $i + $total > $len) {
				return $out . substr($content, $i);
			}

			if (!in_array($type, self::PNG_DROP_CHUNKS, true)) {
				$out .= substr($content, $i, $total);
			}

			$i += $total;

			if ($type === 'IEND') {
				return $out;
			}
		}

		return $out . substr($content, $i);
	}

	/**
	 * Rewrites the RIFF chunk list of a WebP without its metadata chunks,
	 * fixing the RIFF size in the header and clearing the `VP8X` flags that
	 * announce Exif or XMP chunks.
	 */
	private function stripWebp(string $content): string {
		if (strlen($content) < 12
			|| substr($content, 0, 4) !== 'RIFF'
			|| substr($content, 8, 4) !== 'WEBP') {
			return $content;
		}

		$body = '';
		$i = 12;
		$len = strlen($content);
		$dropped = false;

		while ($i + 8 <= $len) {
			$type = substr($content, $i, 4);
			$size = unpack('V', substr($content, $i + 4, 4))[1];
			// chunks are padded to an even length
			$total = 8 + $size + ($size % 2);

			if ($i + $total > $len) {
				$body .= substr($content, $i);
				break;
			}

			if (in_array($type, self::WEBP_DROP_CHUNKS, true)) {
				$dropped = true;
			} else {
				$chunk = substr($content, $i, $total);
				if ($type === 'VP8X' && $size >= 4) {
					// bit 3 is XMP, bit 4 is Exif, counting from the low end
					$flags = ord($chunk[8]);
					$chunk[8] = chr($flags & ~0b00001100);
				}
				$body .= $chunk;
			}

			$i += $total;
		}

		if (!$dropped) {
			return $content;
		}

		return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
	}

	/** The raw Exif payload of a JPEG, without its `Exif\0\0` introducer. */
	private function jpegExifSegment(string $content): ?string {
		if (strlen($content) < 4 || substr($content, 0, 2) !== "\xFF\xD8") {
			return null;
		}

		$i = 2;
		$len = strlen($content);

		while ($i + 3 < $len) {
			if ($content[$i] !== "\xFF") {
				return null;
			}

			$marker = ord($content[$i + 1]);
			if ($marker === 0xFF) {
				$i++;
				continue;
			}
			if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
				$i += 2;
				continue;
			}
			if ($marker === 0xDA || $marker === 0xD9) {
				return null;
			}

			$size = (ord($content[$i + 2]) << 8) | ord($content[$i + 3]);
			if ($size < 2 || $i + 2 + $size > $len) {
				return null;
			}

			if ($marker === 0xE1) {
				$payload = substr($content, $i + 4, $size - 2);
				if (str_starts_with($payload, "Exif\x00\x00")) {
					return substr($payload, 6);
				}
			}

			$i += 2 + $size;
		}

		return null;
	}

	/**
	 * Reads tag 0x0112 out of IFD0 of a TIFF header, by hand, as
	 * `exif_read_data()` needs a file on disk and ext/exif.
	 */
	private function orientationFromExif(string $exif): int {
		if (strlen($exif) < 8) {
			return self::ORIENTATION_NORMAL;
		}

		$byteOrder = substr($exif, 0, 2);
		if ($byteOrder === 'II') {
			$short = 'v';
			$long = 'V';
		} elseif ($byteOrder === 'MM') {
			$short = 'n';
			$long = 'N';
		} else {
			return self::ORIENTATION_NORMAL;
		}

		$ifdOffset = unpack($long, substr($exif, 4, 4))[1];
		if ($ifdOffset + 2 > strlen($exif)) {
			return self::ORIENTATION_NORMAL;
		}

		$count = unpack($short, substr($exif, $ifdOffset, 2))[1];
		for ($n = 0; $n < $count; $n++) {
			$entry = $ifdOffset + 2 + ($n * 12);
			if ($entry + 12 > strlen($exif)) {
				return self::ORIENTATION_NORMAL;
			}

			if (unpack($short, substr($exif, $entry, 2))[1] !== 0x0112) {
				continue;
			}

			$value = unpack($short, substr($exif, $entry + 8, 2))[1];

			return ($value >= 1 && $value <= 8) ? $value : self::ORIENTATION_NORMAL;
		}

		return self::ORIENTATION_NORMAL;
	}
}
