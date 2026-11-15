<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/**
 * Removes the metadata a camera writes into a picture, before the picture is
 * stored or federated.
 *
 * A photo off a phone carries an Exif block, and that block routinely carries
 * the coordinates the photo was taken at. Until this existed the app wrote the
 * uploaded bytes through untouched and served them as the attachment's `url`,
 * so posting a picture published where it was taken to every follower and every
 * peer that mirrors it. Nothing warned anybody, and nothing could be undone
 * afterwards -- the copies are already out.
 *
 * The work is done on the container, not on the pixels. Re-encoding a JPEG to
 * launder it costs a generation of quality on every upload and would make the
 * app the reason people's pictures look worse than they did on their phone; the
 * metadata lives in identifiable, skippable segments, so it is cheaper and
 * lossless to walk the file and not copy them. Three formats are handled that
 * way (JPEG, PNG, WebP). The one case that cannot be lossless is an Exif
 * `Orientation` other than "upright": the tag is the only record that the
 * picture is meant to be rotated, so dropping it silently turns every portrait
 * photo on its side. There the caller is told to rotate, which means a re-encode
 * -- see `orientation()`.
 *
 * What is deliberately kept: the ICC colour profile (dropping it shifts colour
 * on a wide-gamut photo), and everything structural -- animation, transparency,
 * the palette. What goes: Exif in all its spellings, XMP, IPTC/Photoshop
 * resources, JPEG comments, PNG text chunks and timestamps, and whatever a
 * JPEG carries after the end of its primary image.
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

	/** IPTC's two `DigitalSourceType` terms for a picture a model produced. */
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
	 * The picture with its metadata removed, or the bytes unchanged when the
	 * format is not one handled here.
	 *
	 * Never throws: a picture that cannot be parsed is a picture that is stored
	 * as it arrived, which is what happened to every upload before this
	 * existed. The caller decides whether an unknown format may be stored at
	 * all -- that is the mime allow-list's job, not this one's.
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
	 * nothing.
	 *
	 * Read before stripping, because stripping is what removes it. A value
	 * other than 1 means the pixels are stored rotated and only the tag says
	 * so, so the caller has to turn the pixels the right way up before the tag
	 * is dropped.
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
	 * The machine-generation provenance a picture states about itself:
	 * `SOURCE_TRAINED`, `SOURCE_COMPOSITE`, or '' when it states none.
	 *
	 * Read before stripping, like the orientation, because XMP is one of the
	 * blocks `strip()` removes. Two places are looked in. The IPTC property
	 * `DigitalSourceType` in XMP, written by the generators that label their
	 * output (as an attribute or an element, prefixed `Iptc4xmpExt:` or not,
	 * valued with the IPTC NewsCodes URL or the bare term). And the same term
	 * anywhere in the bytes under its `digitalsourcetype/` path, which is how
	 * a C2PA manifest in a JUMBF segment carries it: the manifest is CBOR and
	 * parsing it properly would be a library for one string, so the string is
	 * searched for as bytes instead. Case never matters for the term.
	 *
	 * Bounded on purpose: the two searches are linear passes over bytes that
	 * are already in memory, and the XMP value is only looked for in a short
	 * window after each of the first few occurrences of the property name,
	 * so no pattern ever runs over the whole file.
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
	 *
	 * A JPEG is a run of `FF <marker> <big-endian length> <payload>` segments.
	 * Each `SOS` (start of scan) is followed by entropy coded data, which ends
	 * at the next marker that is not a stuffed `FF 00` or a restart; a
	 * progressive JPEG has several scans with segments between them. The
	 * primary image ends at `EOI`, and nothing after it is copied: that is
	 * where a camera appends the secondary images an `MPF` segment points at
	 * and a phone appends a Motion Photo's video, each with metadata of its
	 * own.
	 *
	 * A file whose structure cannot be followed is re-encoded rather than
	 * copied, since the part that could not be read may hold the metadata.
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
	 * The pixels of a JPEG written out afresh, which carries no metadata at
	 * all, or `$fallback` -- what the walk copied before it lost its way, which
	 * carries none either -- when the picture does not decode.
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
	 * Copies the PNG chunk stream, dropping the chunks that hold metadata.
	 *
	 * A PNG is an 8-byte signature and then `<length> <type> <data> <crc>`
	 * chunks. Dropping a whole chunk needs no checksum work: each carries its
	 * own CRC and the ones that are kept are copied with it.
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
	 * Rewrites the RIFF chunk list of a WebP without its metadata chunks.
	 *
	 * Two things have to be kept honest afterwards: the RIFF size in the header
	 * counts the bytes that follow it, and the extended header `VP8X` carries
	 * flag bits announcing that an Exif or XMP chunk is present. A viewer that
	 * trusts those flags and finds no chunk is entitled to complain, so they are
	 * cleared.
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
	 * Reads tag 0x0112 out of IFD0 of a TIFF header.
	 *
	 * Hand-parsed rather than handed to `exif_read_data()`: that needs the file
	 * on disk and the ext/exif extension, and this runs on bytes that are still
	 * in memory. Only one tag is wanted and it is always in the first IFD.
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
