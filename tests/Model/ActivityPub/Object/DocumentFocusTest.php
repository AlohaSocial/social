<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaFocus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The focal point is what keeps a face in frame when a square crop is taken --
 * the profile grid takes one of every picture, so the difference between having
 * this and not having it is visible on every profile.
 */
class DocumentFocusTest extends TestCase {
	public function testAFreshDocumentPointsAtTheCentre(): void {
		$document = new Document();

		$this->assertSame(0.0, $document->getFocusX());
		$this->assertSame(0.0, $document->getFocusY());
		$this->assertFalse($document->hasFocus());
	}

	public function testAFocusIsKeptAsTheFractionItIs(): void {
		$document = (new Document())->setFocus(-0.25, 0.75);

		$this->assertSame(-0.25, $document->getFocusX());
		$this->assertSame(0.75, $document->getFocusY());
		$this->assertTrue($document->hasFocus());
	}

	/** Outside -1..1 there is no picture left to point at. */
	public function testAFocusOffThePictureIsPulledBackOntoIt(): void {
		$document = (new Document())->setFocus(-4.5, 12.0);

		$this->assertSame(-1.0, $document->getFocusX());
		$this->assertSame(1.0, $document->getFocusY());
	}

	public static function focusStringProvider(): array {
		return [
			'a pair' => ['0.5,-0.25', [0.5, -0.25]],
			'with spaces' => [' 0.5 , -0.25 ', [0.5, -0.25]],
			'whole numbers' => ['1,-1', [1.0, -1.0]],
			'the centre' => ['0,0', [0.0, 0.0]],
			'one value' => ['0.5', null],
			'three values' => ['0.5,0.5,0.5', null],
			'not numbers' => ['left,top', null],
			'empty' => ['', null],
			'injection attempt' => ['0.5,0.5; DROP TABLE', null],
		];
	}

	#[DataProvider('focusStringProvider')]
	public function testTheApiFormatIsParsedOrRefused(string $input, ?array $expected): void {
		$this->assertSame($expected, Document::parseFocus($input));
	}

	public function testTheAttachmentStatesItsFocalPointOnTheWire(): void {
		$document = (new Document())->setFocus(0.4, -0.6);
		$document->setMediaType('image/jpeg');
		$document->setUrl('https://cloud.example.org/media/1');

		$meta = new AttachmentMeta();
		$meta->setFocus(new AttachmentMetaFocus(0.4, -0.6));

		$attachment = $document->convertToMediaAttachment();
		$attachment->setMeta($meta);

		$wire = $attachment->asDocument();

		$this->assertArrayHasKey('focalPoint', $wire);
		$this->assertSame([0.4, -0.6], $wire['focalPoint']);
	}

	/**
	 * A centred picture says nothing, because a peer that sees no `focalPoint`
	 * already assumes the centre. Sending it would cost a field on every
	 * attachment of every post for no information at all.
	 */
	public function testACentredAttachmentSaysNothingOnTheWire(): void {
		$document = new Document();
		$document->setMediaType('image/jpeg');
		$document->setUrl('https://cloud.example.org/media/1');

		$meta = new AttachmentMeta();
		$meta->setFocus(new AttachmentMetaFocus(0, 0));

		$attachment = $document->convertToMediaAttachment();
		$attachment->setMeta($meta);

		$this->assertArrayNotHasKey('focalPoint', $attachment->asDocument());
	}

	public function testAFocalPointFromAPeerIsRead(): void {
		$document = new Document();
		$document->import([
			'id' => 'https://remote.example/media/7',
			'type' => 'Document',
			'mediaType' => 'image/jpeg',
			'url' => 'https://remote.example/media/7.jpg',
			'focalPoint' => [0.8, -0.2],
		]);

		$this->assertSame(0.8, $document->getFocusX());
		$this->assertSame(-0.2, $document->getFocusY());
	}

	public function testRubbishFromAPeerLeavesThePictureCentred(): void {
		foreach ([[0.5], ['left', 'top'], [], [1, 2, 3], 'nope'] as $focalPoint) {
			$document = new Document();
			$document->import([
				'id' => 'https://remote.example/media/7',
				'type' => 'Document',
				'mediaType' => 'image/jpeg',
				'url' => 'https://remote.example/media/7.jpg',
				'focalPoint' => $focalPoint,
			]);

			$this->assertFalse($document->hasFocus(), 'a malformed focalPoint moved the picture');
		}
	}

	/** The stored `meta` blob is where it lives, so it has to survive that. */
	public function testTheFocalPointSurvivesTheDatabase(): void {
		$document = new Document();
		$document->importFromDatabase([
			'id' => 'https://cloud.example.org/documents/local/1',
			'type' => 'Document',
			'media_type' => 'image/jpeg',
			'meta' => json_encode(['focus' => ['x' => -0.35, 'y' => 0.9]]),
		]);

		$this->assertSame(-0.35, $document->getFocusX());
		$this->assertSame(0.9, $document->getFocusY());
	}

	/**
	 * The bug this pins: `AttachmentMeta::import()` read the pair with
	 * `getInt()`, so every focal point a client set rounded to the centre and
	 * the feature silently did nothing.
	 */
	public function testTheStoredFocusIsNotRoundedToWholeNumbers(): void {
		$meta = (new AttachmentMeta())->import(['focus' => ['x' => 0.75, 'y' => -0.5]]);

		$this->assertSame(0.75, $meta->getFocus()?->getX());
		$this->assertSame(-0.5, $meta->getFocus()?->getY());
	}
}
