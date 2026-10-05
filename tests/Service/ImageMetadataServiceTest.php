<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ImageMetadataService;
use PHPUnit\Framework\TestCase;

/**
 * The pictures are built here rather than loaded from fixtures, because what is
 * being asserted is byte-level: that a named segment is gone, that the ones
 * around it survived, and that the result still decodes. A fixture would hide
 * which of those failed.
 */
class ImageMetadataServiceTest extends TestCase {
	private ImageMetadataService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new ImageMetadataService();
	}

	/** A JPEG segment: FF, marker, big-endian length including the length. */
	private function segment(int $marker, string $payload): string {
		return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
	}

	/** A little-endian Exif payload carrying GPS latitude and an orientation. */
	private function exifPayload(int $orientation = 1): string {
		$tags = [0x0112 => $orientation, 0x0001 => 4]; // orientation, GPS ref
		$ifd = pack('v', count($tags));
		foreach ($tags as $tag => $value) {
			$ifd .= pack('v', $tag) . pack('v', 3) . pack('V', 1) . pack('v', $value) . "\x00\x00";
		}
		$ifd .= pack('V', 0);

		return "Exif\x00\x00" . 'II' . "\x2A\x00" . pack('V', 8) . $ifd;
	}

	private function jpegWithMetadata(int $orientation = 1): string {
		return "\xFF\xD8"
			. $this->segment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")   // kept
			. $this->segment(0xE1, $this->exifPayload($orientation))                  // dropped
			. $this->segment(0xE1, "http://ns.adobe.com/xap/1.0/\x00<x:xmpmeta/>")    // dropped
			. $this->segment(0xED, "Photoshop 3.0\x00IPTC")                           // dropped
			. $this->segment(0xFE, 'a comment')                                       // dropped
			. $this->segment(0xE2, "ICC_PROFILE\x00" . str_repeat('c', 16))           // kept
			. $this->segment(0xDB, str_repeat("\x01", 64))                            // kept
			. "\xFF\xDA" . "\x00\x0C" . str_repeat("\x11", 10)
			. str_repeat("\x7F", 32)
			. "\xFF\xD9";
	}

	public function testTheExifBlockIsGoneAndTheColourProfileIsNot(): void {
		$stripped = $this->service->strip($this->jpegWithMetadata(), 'image/jpeg');

		$this->assertStringNotContainsString("Exif\x00\x00", $stripped, 'the Exif block survived');
		$this->assertStringNotContainsString('ns.adobe.com/xap', $stripped, 'the XMP block survived');
		$this->assertStringNotContainsString('Photoshop 3.0', $stripped, 'the IPTC block survived');
		$this->assertStringNotContainsString('a comment', $stripped, 'the comment survived');

		$this->assertStringContainsString("ICC_PROFILE\x00", $stripped, 'the colour profile was dropped');
		$this->assertStringContainsString('JFIF', $stripped, 'the JFIF header was dropped');
	}

	public function testTheImageDataItselfIsUntouched(): void {
		$stripped = $this->service->strip($this->jpegWithMetadata(), 'image/jpeg');

		$this->assertStringStartsWith("\xFF\xD8", $stripped);
		$this->assertStringEndsWith("\xFF\xD9", $stripped);
		// the quantisation table and the whole scan, byte for byte
		$this->assertStringContainsString(str_repeat("\x01", 64), $stripped);
		$this->assertStringContainsString(str_repeat("\x7F", 32), $stripped);
		$this->assertLessThan(strlen($this->jpegWithMetadata()), strlen($stripped));
	}

	public function testAPhotoWithNoMetadataIsNotRewritten(): void {
		$plain = "\xFF\xD8"
			. $this->segment(0xDB, str_repeat("\x02", 64))
			. "\xFF\xDA" . "\x00\x0C" . str_repeat("\x11", 10) . str_repeat("\x40", 8) . "\xFF\xD9";

		$this->assertSame($plain, $this->service->strip($plain, 'image/jpeg'));
	}

	public function testOrientationIsReadBeforeItIsStripped(): void {
		$rotated = $this->jpegWithMetadata(6);

		$this->assertSame(6, $this->service->orientation($rotated, 'image/jpeg'));
		$this->assertTrue($this->service->needsRotation($rotated, 'image/jpeg'));

		// and an upright photo asks for nothing
		$upright = $this->jpegWithMetadata(1);
		$this->assertSame(1, $this->service->orientation($upright, 'image/jpeg'));
		$this->assertFalse($this->service->needsRotation($upright, 'image/jpeg'));
	}

	public function testOrientationOfAPhotoThatDoesNotSayIsUpright(): void {
		$this->assertSame(
			ImageMetadataService::ORIENTATION_NORMAL,
			$this->service->orientation("\xFF\xD8\xFF\xD9", 'image/jpeg')
		);
		// and a format whose orientation this does not read
		$this->assertFalse($this->service->needsRotation('whatever', 'image/png'));
	}

	private function pngChunk(string $type, string $data): string {
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	}

	public function testPngTextAndExifChunksGoAndThePixelsStay(): void {
		$png = "\x89PNG\r\n\x1a\n"
			. $this->pngChunk('IHDR', str_repeat("\x00", 13))
			. $this->pngChunk('iCCP', 'profile')
			. $this->pngChunk('eXIf', 'II' . "\x2A\x00" . 'gps here')
			. $this->pngChunk('tEXt', "Comment\x00taken at home")
			. $this->pngChunk('acTL', str_repeat("\x01", 8))
			. $this->pngChunk('IDAT', str_repeat("\x05", 20))
			. $this->pngChunk('IEND', '');

		$stripped = $this->service->strip($png, 'image/png');

		$this->assertStringNotContainsString('eXIf', $stripped);
		$this->assertStringNotContainsString('taken at home', $stripped);
		$this->assertStringContainsString('iCCP', $stripped, 'the colour profile was dropped');
		$this->assertStringContainsString('acTL', $stripped, 'the animation control chunk was dropped');
		$this->assertStringContainsString(str_repeat("\x05", 20), $stripped, 'the pixels were touched');
		$this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $stripped);
		$this->assertStringEndsWith($this->pngChunk('IEND', ''), $stripped);
	}

	private function riffChunk(string $type, string $data): string {
		$out = $type . pack('V', strlen($data)) . $data;

		return (strlen($data) % 2 === 1) ? $out . "\x00" : $out;
	}

	public function testWebpExifChunkGoesAndTheHeaderIsMadeHonest(): void {
		// VP8X with the Exif (bit 3) and XMP (bit 2) flags set
		$vp8x = $this->riffChunk('VP8X', chr(0b00001100) . str_repeat("\x00", 9));
		$body = $vp8x
			. $this->riffChunk('ICCP', 'profile')
			. $this->riffChunk('ANMF', str_repeat("\x03", 12))
			. $this->riffChunk('EXIF', 'II' . "\x2A\x00" . 'coordinates')
			. $this->riffChunk('XMP ', '<x:xmpmeta/>');
		$webp = 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;

		$stripped = $this->service->strip($webp, 'image/webp');

		$this->assertStringNotContainsString('coordinates', $stripped);
		$this->assertStringNotContainsString('x:xmpmeta', $stripped);
		$this->assertStringContainsString('ICCP', $stripped, 'the colour profile was dropped');
		$this->assertStringContainsString('ANMF', $stripped, 'the animation frame was dropped');

		// the RIFF length counts what actually follows it now
		$this->assertSame(strlen($stripped) - 8, unpack('V', substr($stripped, 4, 4))[1]);
		// and the flags no longer announce blocks that are not there
		$this->assertSame(0, ord(substr($stripped, 20, 1)) & 0b00001100);
	}

	public function testAGifIsLeftExactlyAsItArrived(): void {
		$gif = 'GIF89a' . str_repeat("\x09", 40);

		$this->assertSame($gif, $this->service->strip($gif, 'image/gif'));
	}

	/**
	 * Nothing here may throw. A picture that cannot be parsed is stored as it
	 * arrived, which is what happened to every upload before this existed --
	 * refusing it instead would turn a privacy improvement into an outage.
	 */
	public function testRubbishIsReturnedRatherThanRaised(): void {
		foreach (['', "\xFF\xD8", 'not an image at all', "\x89PNG\r\n\x1a\n" . 'truncated', 'RIFF'] as $junk) {
			foreach (['image/jpeg', 'image/png', 'image/webp', 'application/octet-stream'] as $mime) {
				$this->assertIsString($this->service->strip($junk, $mime));
				$this->assertIsInt($this->service->orientation($junk, $mime));
			}
		}
	}

	public function testARealGdJpegStillDecodesAfterStripping(): void {
		$image = imagecreatetruecolor(24, 16);
		imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 220));
		ob_start();
		imagejpeg($image, null, 90);
		$jpeg = (string)ob_get_clean();

		$stripped = $this->service->strip($jpeg, 'image/jpeg');
		$decoded = @imagecreatefromstring($stripped);

		$this->assertNotFalse($decoded, 'a stripped JPEG no longer decodes');
		$this->assertSame(24, imagesx($decoded));
		$this->assertSame(16, imagesy($decoded));
	}

	public function testARealGdPngStillDecodesAfterStripping(): void {
		$image = imagecreatetruecolor(12, 9);
		imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
		ob_start();
		imagepng($image);
		$png = (string)ob_get_clean();

		$decoded = @imagecreatefromstring($this->service->strip($png, 'image/png'));

		$this->assertNotFalse($decoded, 'a stripped PNG no longer decodes');
		$this->assertSame(12, imagesx($decoded));
	}

	// provenance: what a picture says about where it came from

	private const CV = 'http://cv.iptc.org/newscodes/digitalsourcetype/';

	/** A JPEG whose APP1 XMP packet carries the given body inside its rdf:Description. */
	private function jpegWithXmp(string $description, string $attributes = ''): string {
		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"' . $attributes . '>'
			. $description . '</rdf:Description></rdf:RDF></x:xmpmeta>';

		return "\xFF\xD8"
			. $this->segment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
			. $this->segment(0xE1, "http://ns.adobe.com/xap/1.0/\x00" . $xmp)
			. $this->segment(0xDB, str_repeat("\x01", 64))
			. "\xFF\xDA" . "\x00\x0C" . str_repeat("\x11", 10)
			. str_repeat("\x7F", 32)
			. "\xFF\xD9";
	}

	public function testTheSourceTypeIsReadFromAnXmpAttribute(): void {
		$jpeg = $this->jpegWithXmp('', ' Iptc4xmpExt:DigitalSourceType="' . self::CV . 'trainedAlgorithmicMedia"');

		$this->assertSame(ImageMetadataService::SOURCE_TRAINED, $this->service->digitalSourceType($jpeg, 'image/jpeg'));
	}

	public function testTheSourceTypeIsReadFromAnXmpElement(): void {
		$jpeg = $this->jpegWithXmp(
			'<Iptc4xmpExt:DigitalSourceType>' . self::CV . 'compositeWithTrainedAlgorithmicMedia</Iptc4xmpExt:DigitalSourceType>'
		);

		$this->assertSame(ImageMetadataService::SOURCE_COMPOSITE, $this->service->digitalSourceType($jpeg, 'image/jpeg'));
	}

	public function testTheSourceTypeIsReadFromAResourceElement(): void {
		$jpeg = $this->jpegWithXmp('<Iptc4xmpExt:DigitalSourceType rdf:resource="' . self::CV . 'trainedAlgorithmicMedia"/>');

		$this->assertSame(ImageMetadataService::SOURCE_TRAINED, $this->service->digitalSourceType($jpeg, 'image/jpeg'));
	}

	public function testTheBareTermCountsAndSoDoesAnUnprefixedProperty(): void {
		// some writers state the term rather than the NewsCodes URL, and some
		// leave the namespace prefix off
		$jpeg = $this->jpegWithXmp('<DigitalSourceType>TrainedAlgorithmicMedia</DigitalSourceType>');

		$this->assertSame(ImageMetadataService::SOURCE_TRAINED, $this->service->digitalSourceType($jpeg, 'image/jpeg'));
		$this->assertSame(
			ImageMetadataService::SOURCE_COMPOSITE,
			$this->service->digitalSourceType($this->jpegWithXmp('', ' DigitalSourceType="compositewithtrainedalgorithmicmedia"'), 'image/jpeg')
		);
	}

	public function testAnotherSourceTypeIsNotAGeneratedOne(): void {
		// IPTC has a dozen terms; a scan of a painting or a photo off a camera
		// are stated too, and must not be read as a model's output
		foreach (['digitalCapture', 'negativeFilm', 'digitalCreation', 'algorithmicMedia'] as $term) {
			$jpeg = $this->jpegWithXmp('', ' Iptc4xmpExt:DigitalSourceType="' . self::CV . $term . '"');

			$this->assertSame('', $this->service->digitalSourceType($jpeg, 'image/jpeg'), $term);
		}
	}

	/**
	 * A C2PA manifest rides in APP11 (JUMBF) segments and is CBOR, which is
	 * not parsed here; what is looked for is the term's path as bytes, which
	 * the `c2pa.actions` assertion carries verbatim.
	 */
	public function testTheSourceTypeIsFoundInsideAC2paManifest(): void {
		$manifest = "\x00\x00\x00\x1Cjumb" . random_bytes(40)
			. "\xA2\x67actions\x81\xA2\x66action\x6Cc2pa.created\x71digitalSourceType\x78\x3A"
			. 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia'
			. random_bytes(20);
		$jpeg = "\xFF\xD8"
			. $this->segment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
			. $this->segment(0xEB, "JP\x00\x01" . $manifest)
			. $this->segment(0xDB, str_repeat("\x01", 64))
			. "\xFF\xDA" . "\x00\x0C" . str_repeat("\x11", 10)
			. "\xFF\xD9";

		$this->assertSame(ImageMetadataService::SOURCE_TRAINED, $this->service->digitalSourceType($jpeg, 'image/jpeg'));
	}

	public function testTheSourceTypeIsReadFromAPngTextChunk(): void {
		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/">'
			. '<Iptc4xmpExt:DigitalSourceType>' . self::CV . 'trainedAlgorithmicMedia</Iptc4xmpExt:DigitalSourceType>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>';
		$png = "\x89PNG\r\n\x1a\n"
			. $this->pngChunk('IHDR', str_repeat("\x00", 13))
			. $this->pngChunk('iTXt', "XML:com.adobe.xmp\x00\x00\x00\x00\x00" . $xmp)
			. $this->pngChunk('IDAT', str_repeat("\x55", 16))
			. $this->pngChunk('IEND', '');

		$this->assertSame(ImageMetadataService::SOURCE_TRAINED, $this->service->digitalSourceType($png, 'image/png'));
	}

	public function testAPictureThatStatesNothingAnswersNothing(): void {
		$this->assertSame('', $this->service->digitalSourceType($this->jpegWithMetadata(), 'image/jpeg'));
		$this->assertSame('', $this->service->digitalSourceType($this->jpegWithXmp(''), 'image/jpeg'));
		$this->assertSame('', $this->service->digitalSourceType('', 'image/jpeg'));
		$this->assertSame('', $this->service->digitalSourceType('not a picture at all', 'image/gif'));
	}

	public function testOnlyAPictureIsAskedAboutItsProvenance(): void {
		// a video or a PDF can carry the same string; this reader is for images
		$bytes = 'DigitalSourceType="' . self::CV . 'trainedAlgorithmicMedia"';

		$this->assertSame('', $this->service->digitalSourceType($bytes, 'video/mp4'));
		$this->assertSame('', $this->service->digitalSourceType($bytes, 'application/pdf'));
		$this->assertSame(ImageMetadataService::SOURCE_TRAINED, $this->service->digitalSourceType($bytes, 'image/webp'));
	}

	public function testTheSourceTypeHasToBeReadBeforeStripping(): void {
		$jpeg = $this->jpegWithXmp('', ' Iptc4xmpExt:DigitalSourceType="' . self::CV . 'trainedAlgorithmicMedia"');

		$this->assertSame(ImageMetadataService::SOURCE_TRAINED, $this->service->digitalSourceType($jpeg, 'image/jpeg'));

		$stripped = $this->service->strip($jpeg, 'image/jpeg');

		// the XMP went with the rest of the metadata, and the statement with it
		$this->assertSame('', $this->service->digitalSourceType($stripped, 'image/jpeg'));
	}

	public function testTheWindowAfterThePropertyNameIsBounded(): void {
		// a property whose value is further away than the window is not found
		// by the XMP reader; only the path search, which is linear, still can
		$far = '<DigitalSourceType>' . str_repeat(' ', 600) . 'trainedAlgorithmicMedia</DigitalSourceType>';

		$this->assertSame('', $this->service->digitalSourceType($this->jpegWithXmp($far), 'image/jpeg'));
	}
}
