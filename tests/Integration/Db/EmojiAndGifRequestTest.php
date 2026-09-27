<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\EmojiRequest;
use OCA\Social\Db\GifRequest;
use OCA\Social\Model\CustomEmoji;
use OCA\Social\Model\Gif;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The instance's custom emoji and its picture library: one row per name,
 * replaced by a second save, and deleting hands back the file to remove.
 */
class EmojiAndGifRequestTest extends TestCase {
	private const SHORTCODE = 'itest_blobcat';
	private const SLUG = 'itest-dancing-cat';

	private EmojiRequest $emoji;
	private GifRequest $gifs;

	protected function setUp(): void {
		parent::setUp();
		$this->emoji = Server::get(EmojiRequest::class);
		$this->gifs = Server::get(GifRequest::class);
		$this->emoji->delete(self::SHORTCODE);
		$this->gifs->delete(self::SLUG);
	}

	protected function tearDown(): void {
		$this->emoji->delete(self::SHORTCODE);
		$this->gifs->delete(self::SLUG);
		parent::tearDown();
	}

	public function testAnEmojiIsKeptAndSavingItAgainReplacesIt(): void {
		$this->emoji->save(new CustomEmoji(self::SHORTCODE, 'blobcat.png', 'image/png', 'Blobs', true));
		$this->emoji->save(new CustomEmoji(self::SHORTCODE, 'blobcat-2.webp', 'image/webp', 'Cats', false));

		$stored = $this->emoji->getByShortcode(self::SHORTCODE);
		$this->assertNotNull($stored);
		$this->assertSame('blobcat-2.webp', $stored->getFilename());
		$this->assertSame('image/webp', $stored->getMediaType());
		$this->assertSame('Cats', $stored->getCategory());
		$this->assertFalse($stored->isVisible());
		$this->assertArrayHasKey(self::SHORTCODE, $this->emoji->getAll());
	}

	public function testDeletingAnEmojiHandsBackItsFile(): void {
		$this->emoji->save(new CustomEmoji(self::SHORTCODE, 'blobcat.png', 'image/png'));

		$this->assertSame('blobcat.png', $this->emoji->delete(self::SHORTCODE));
		$this->assertNull($this->emoji->getByShortcode(self::SHORTCODE));
		$this->assertSame('', $this->emoji->delete(self::SHORTCODE), 'nothing left to remove');
	}

	public function testAPictureIsKeptOnceAndDeletingHandsBackItsFile(): void {
		$this->gifs->save(new Gif(self::SLUG, 'cat.gif', 'image/gif', 'A cat'));
		$this->gifs->save(new Gif(self::SLUG, 'cat.webp', 'image/webp', 'A dancing cat'));

		$mine = array_values(array_filter($this->gifs->all(), static fn (Gif $gif): bool => $gif->getSlug() === self::SLUG));
		$this->assertCount(1, $mine);
		$this->assertSame('A dancing cat', $mine[0]->getTitle());
		$this->assertSame('image/webp', $mine[0]->getMediaType());

		$this->assertSame('cat.webp', $this->gifs->delete(self::SLUG));
		$this->assertSame('', $this->gifs->delete(self::SLUG));
	}
}
