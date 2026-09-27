<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\EmojiRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\CustomEmoji;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\ImageMetadataService;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The emoji this instance publishes.
 *
 * `/api/v1/custom_emojis` answered `[]` unconditionally and outbound posts
 * carried no `Emoji` tags: emoji from every other instance rendered here and
 * this one could publish none. What is asserted is the half that decides
 * whether a post renders anywhere — which shortcodes are found in a piece of
 * text, and what tag each one becomes — and the half that decides what gets
 * served to every reader of every post that uses it.
 */
#[AllowMockObjectsWithoutExpectations]
class EmojiServiceTest extends TestCase {
	private EmojiRequest|Stub $emojiRequest;
	private IAppData|Stub $appData;
	private ISimpleFolder|Stub $folder;
	private EmojiService $service;

	/** @var array<string, CustomEmoji> what the instance has */
	private array $stored = [];
	/** @var array<string, string> the appdata files, by name */
	private array $files = [];
	/** @var string[] the files that were deleted */
	private array $deleted = [];

	protected function setUp(): void {
		$this->emojiRequest = $this->createStub(EmojiRequest::class);
		$this->appData = $this->createStub(IAppData::class);
		$this->folder = $this->createStub(ISimpleFolder::class);

		$this->emojiRequest->method('getAll')->willReturnCallback(fn (): array => $this->stored);
		$this->emojiRequest->method('save')->willReturnCallback(
			function (CustomEmoji $emoji): void {
				$this->stored[$emoji->getShortcode()] = $emoji;
			}
		);
		$this->emojiRequest->method('delete')->willReturnCallback(
			function (string $shortcode): string {
				$emoji = $this->stored[$shortcode] ?? null;
				unset($this->stored[$shortcode]);

				return $emoji === null ? '' : $emoji->getFilename();
			}
		);

		$this->appData->method('getFolder')->willReturn($this->folder);
		$this->folder->method('newFile')->willReturnCallback(
			function (string $name, $content): ISimpleFile {
				$this->files[$name] = (string)$content;

				return $this->file($name);
			}
		);
		$this->folder->method('getFile')->willReturnCallback(
			function (string $name): ISimpleFile {
				if (!isset($this->files[$name])) {
					throw new NotFoundException('no such file');
				}

				return $this->file($name);
			}
		);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string
				=> 'https://cloud.example/apps/social/emoji/' . $args['shortcode']
		);

		$this->service = new EmojiService(
			$this->emojiRequest, $this->appData, $urlGenerator, new NullLogger(),
			new ImageMetadataService()
		);
	}

	private function file(string $name): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($this->files[$name] ?? '');
		$file->method('delete')->willReturnCallback(function () use ($name): void {
			$this->deleted[] = $name;
			unset($this->files[$name]);
		});

		return $file;
	}

	/** A real PNG, small enough that the size ceiling is not what refuses it. */
	private function picture(string $extension = 'png'): string {
		$path = tempnam(sys_get_temp_dir(), 'emoji') . '.' . $extension;
		$image = imagecreatetruecolor(8, 8);
		match ($extension) {
			'gif' => imagegif($image, $path),
			'jpg' => imagejpeg($image, $path),
			default => imagepng($image, $path),
		};

		return $path;
	}

	private function have(string $shortcode, string $category = '', bool $visible = true): void {
		$this->stored[$shortcode] = new CustomEmoji(
			$shortcode, $shortcode . '.png', 'image/png', $category, $visible
		);
	}

	// what a post is scanned for

	#[DataProvider('provideTextsAndShortcodes')]
	public function testTheShortcodesWrittenInAPieceOfText(string $text, array $expected): void {
		$this->assertSame($expected, $this->service->shortcodesIn($text));
	}

	public static function provideTextsAndShortcodes(): iterable {
		yield 'one' => ['hello :blobcat:', ['blobcat']];
		yield 'several' => [':a1: and :b2:', ['a1', 'b2']];
		yield 'the same one twice is one tag' => [':blobcat: :blobcat:', ['blobcat']];
		yield 'nothing' => ['hello', []];
		// a shortcode is bounded by colons on both sides, which is what keeps
		// these out: a time of day, a URL scheme, and a smiley
		yield 'a time of day' => ['at 12:30:45 today', []];
		yield 'a url' => ['see https://example.test/a', []];
		yield 'a smiley' => [':) and :-(', []];
		yield 'a colon against a word' => ['a:bc: d', []];
		yield 'in brackets' => ['(:blobcat:)', ['blobcat']];
		yield 'at the very start and end' => [':blobcat:', ['blobcat']];
		yield 'a port number' => ['host:8080: x', []];
		yield 'too short' => [':a:', []];
		// Mastodon compares shortcodes without regard to case, and people type
		// them that way; the picture is stored under the lower-case name
		yield 'uppercase names the lower-case shortcode' => [':BlobCat:', ['blobcat']];
		yield 'the same one in two spellings is one tag' => [':BlobCat: :blobcat:', ['blobcat']];
		yield 'a hyphen is not' => [':blob-cat:', []];
	}

	/**
	 * The shortcode stays in the content as text and the tag beside it says
	 * where the picture is — which is why an instance that has never heard of
	 * `:blobcat:` still renders the post.
	 */
	public function testEachKnownShortcodeBecomesATagCarryingItsPicture(): void {
		$this->have('blobcat');

		$tags = $this->service->tagsFor('hello :blobcat:');

		$this->assertSame([[
			'type' => 'Emoji',
			'name' => ':blobcat:',
			'icon' => [
				'type' => 'Image',
				'mediaType' => 'image/png',
				'url' => 'https://cloud.example/apps/social/emoji/blobcat',
			],
		]], $tags);
	}

	/** `:shrug:` on an instance without one is text, here and everywhere. */
	public function testAShortcodeThisInstanceHasNoPictureForIsLeftAlone(): void {
		$this->have('blobcat');

		$this->assertSame([], $this->service->tagsFor('hello :shrug:'));
	}

	public function testATagIsCarriedForEveryDistinctShortcode(): void {
		$this->have('one');
		$this->have('two');

		$tags = $this->service->tagsFor(':one: :two: :one:');

		$this->assertSame([':one:', ':two:'], array_column($tags, 'name'));
	}

	// what a picker is offered

	public function testOnlyTheVisibleOnesAreOffered(): void {
		$this->have('offered');
		$this->have('byname', '', false);

		$visible = $this->service->visible();

		$this->assertCount(1, $visible);
		$this->assertSame('offered', $visible[0]->getShortcode());
		// hidden is not gone: it still renders wherever it is written
		$this->assertNotNull($this->service->byShortcode('byname'));
	}

	public function testEveryEmojiKnowsWhereItIsServedFrom(): void {
		$this->have('blobcat');

		$this->assertSame(
			'https://cloud.example/apps/social/emoji/blobcat',
			$this->service->byShortcode('blobcat')->getUrl()
		);
	}

	public function testACategoryIsSentOnlyWhenThereIsOne(): void {
		$this->have('grouped', 'blobs');
		$this->have('loose');

		$this->assertArrayHasKey('category', $this->service->byShortcode('grouped')->jsonSerialize());
		$this->assertArrayNotHasKey('category', $this->service->byShortcode('loose')->jsonSerialize());
	}

	// adding one

	public function testAddingOneStoresThePictureAndTheRow(): void {
		$emoji = $this->service->add('blobcat', $this->picture(), 'blobs');

		$this->assertSame('blobcat', $emoji->getShortcode());
		$this->assertSame('image/png', $emoji->getMediaType());
		$this->assertSame('blobs', $emoji->getCategory());
		$this->assertArrayHasKey('blobcat.png', $this->files);
		$this->assertSame(
			'https://cloud.example/apps/social/emoji/blobcat', $emoji->getUrl()
		);
	}

	/**
	 * An emoji is usually a drawing with nothing in it to remove, but it is
	 * uploaded from a filesystem like anything else and is then served to every
	 * reader of every post that uses it, here and on every peer that mirrors
	 * it. A stripping guarantee that holds for photographs and not for this is
	 * not a guarantee.
	 */
	public function testThePictureIsStrippedLikeAnyOtherUpload(): void {
		$path = tempnam(sys_get_temp_dir(), 'emoji') . '.jpg';
		$image = imagecreatetruecolor(8, 8);
		imagejpeg($image, $path);

		// a JPEG with an Exif block carrying a coordinate, straight after SOI
		$payload = "Exif\x00\x00" . 'II' . "\x2A\x00" . pack('V', 8)
			. pack('v', 1) . pack('v', 0x0001) . pack('v', 3) . pack('V', 1) . pack('v', 4) . "\x00\x00"
			. pack('V', 0);
		$jpeg = file_get_contents($path);
		file_put_contents(
			$path,
			substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2)
		);
		$this->assertStringContainsString("Exif\x00\x00", (string)file_get_contents($path));

		$this->service->add('blobcat', $path);

		$this->assertArrayHasKey('blobcat.jpg', $this->files);
		$this->assertStringNotContainsString(
			"Exif\x00\x00", $this->files['blobcat.jpg'], 'the emoji kept its Exif block'
		);
		$this->assertNotFalse(
			@imagecreatefromstring($this->files['blobcat.jpg']), 'the stripped emoji no longer decodes'
		);
	}

	public function testAShortcodeIsReadTheWayItIsWritten(): void {
		$emoji = $this->service->add('  BlobCat  ', $this->picture());

		$this->assertSame('blobcat', $emoji->getShortcode());
	}

	#[DataProvider('provideThingsThatAreNotShortcodes')]
	public function testWhatCannotBeAShortcodeIsRefused(string $shortcode): void {
		$this->expectException(InvalidActionException::class);

		$this->service->add($shortcode, $this->picture());
	}

	public static function provideThingsThatAreNotShortcodes(): iterable {
		yield 'empty' => [''];
		yield 'one character' => ['a'];
		yield 'a hyphen' => ['blob-cat'];
		yield 'a colon' => [':blobcat:'];
		yield 'a space' => ['blob cat'];
		yield 'a slash' => ['../escape'];
		yield 'too long' => [str_repeat('a', 65)];
	}

	/** The bytes decide: this is served to every reader of every post using it. */
	public function testWhatIsNotAPictureIsRefused(): void {
		$path = tempnam(sys_get_temp_dir(), 'emoji');
		file_put_contents($path, "<?php echo 'not a picture';");

		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('PNG, GIF, WebP or JPEG');

		$this->service->add('blobcat', $path);
	}

	public function testAPictureThatIsNotThereIsRefused(): void {
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('no picture found');

		$this->service->add('blobcat', '/nowhere/blobcat.png');
	}

	public function testAPictureTooLargeToSendWithEveryPostIsRefused(): void {
		$path = tempnam(sys_get_temp_dir(), 'emoji') . '.png';
		$image = imagecreatetruecolor(1200, 1200);
		for ($x = 0; $x < 1200; $x += 3) {
			imageline($image, $x, 0, 1199 - $x, 1199, imagecolorallocate($image, $x % 255, 40, 200));
		}
		imagepng($image, $path, 0);
		$this->assertGreaterThan(EmojiService::MAX_SIZE, filesize($path), 'the fixture is big enough');

		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('larger than');

		$this->service->add('blobcat', $path);
	}

	/** One picture a shortcode: re-adding means replacing, not a second row. */
	public function testAddingTheSameShortcodeAgainReplacesThePicture(): void {
		$this->service->add('blobcat', $this->picture());
		$this->service->add('blobcat', $this->picture('gif'), 'blobs');

		$this->assertCount(1, $this->service->all());
		$this->assertSame('image/gif', $this->service->byShortcode('blobcat')->getMediaType());
		$this->assertSame('blobs', $this->service->byShortcode('blobcat')->getCategory());
	}

	public function testTheSetIsReReadAfterItChanges(): void {
		$this->assertSame([], $this->service->all());

		$this->service->add('blobcat', $this->picture());

		$this->assertCount(1, $this->service->all(), 'the cached set outlived the change');
	}

	// removing one

	public function testRemovingOneTakesThePictureWithIt(): void {
		$this->service->add('blobcat', $this->picture());

		$this->assertTrue($this->service->remove('blobcat'));
		$this->assertSame([], $this->service->all());
		$this->assertSame(['blobcat.png'], $this->deleted);
	}

	public function testRemovingSomethingThatIsNotThereSaysSo(): void {
		$this->assertFalse($this->service->remove('blobcat'));
	}

	/**
	 * The row is what decides whether the emoji exists; a file left behind is
	 * a file, not a broken emoji.
	 */
	public function testRemovalStandsEvenWhenThePictureCannotBeDeleted(): void {
		$this->service->add('blobcat', $this->picture());
		unset($this->files['blobcat.png']);

		$this->assertTrue($this->service->remove('blobcat'));
		$this->assertNull($this->service->byShortcode('blobcat'));
	}

	// serving one

	public function testThePictureIsServedFromWhereItWasStored(): void {
		$this->service->add('blobcat', $this->picture());

		$this->assertSame('blobcat.png', $this->service->picture('blobcat')->getName());
	}

	public function testAskingForAPictureNobodyPublishedIsNotFound(): void {
		$this->expectException(NotFoundException::class);

		$this->service->picture('blobcat');
	}
}
