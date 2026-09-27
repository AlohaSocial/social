<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\EmojiRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\CustomEmoji;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The emoji this instance publishes.
 *
 * `/api/v1/custom_emojis` answered `[]` unconditionally and outbound posts
 * carried no `Emoji` tags, so emoji from every other instance rendered here
 * and this one could publish none — the asymmetry a reader notices first,
 * because their own instance's emoji stop working the moment they move here.
 *
 * A shortcode is what goes between colons. What a post carries is the tag, not
 * the picture: `:blobcat:` stays in the content and an `Emoji` tag beside it
 * says where the picture is, which is how every fediverse server does it and
 * why an instance that has never heard of `blobcat` still renders the post.
 *
 * The picture lives in appdata under a name of this app's choosing and is
 * served from one route, so a local client and a remote server dereference the
 * same URL.
 */
class EmojiService {
	/** The appdata folder the pictures live in. */
	private const FOLDER = 'emoji';

	/** What an emoji may be: what a browser will render inline, and no more. */
	private const ALLOWED_TYPES = ['image/png', 'image/gif', 'image/webp', 'image/jpeg'];

	/** Mastodon's own ceiling for one, in bytes. */
	public const MAX_SIZE = 256 * 1024;

	/**
	 * What a post is scanned for.
	 *
	 * Bounded by a colon on each side, and — this is the part a plain
	 * `:(\w+):` gets wrong — neither colon may sit against a word character
	 * or another colon. Without the two guards, `12:30:45` carries an emoji
	 * called `30`, and `https://example.test` and `:-)` are each one colon
	 * away from the same thing. Mastodon's own pattern, with this app's
	 * shortcode alphabet.
	 *
	 * Case-insensitive, and the match is lowercased: a shortcode is stored in
	 * lower case, but Mastodon compares them without regard to case and people
	 * type `:BlobCat:`, which rendered as literal text here and on every peer
	 * this post reached.
	 */
	private const IN_TEXT = '/(?<![\w:]):([a-zA-Z0-9_]{2,64}):(?![\w:])/';

	/** @var array<string, CustomEmoji>|null the whole set, for this request */
	private ?array $cached = null;

	public function __construct(
		private EmojiRequest $emojiRequest,
		private IAppData $appData,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private ImageMetadataService $imageMetadataService,
	) {
	}

	/**
	 * Every emoji this instance has, by shortcode, each knowing its URL.
	 *
	 * @return array<string, CustomEmoji>
	 */
	public function all(): array {
		if ($this->cached === null) {
			$this->cached = array_map(
				fn (CustomEmoji $emoji): CustomEmoji => $emoji->setUrl($this->urlOf($emoji)),
				$this->emojiRequest->getAll()
			);
		}

		return $this->cached;
	}

	/**
	 * The ones a picker should offer, in the order `/api/v1/custom_emojis`
	 * sends them.
	 *
	 * @return CustomEmoji[]
	 */
	public function visible(): array {
		return array_values(array_filter(
			$this->all(), static fn (CustomEmoji $emoji): bool => $emoji->isVisible()
		));
	}

	public function byShortcode(string $shortcode): ?CustomEmoji {
		return $this->all()[strtolower(trim($shortcode))] ?? null;
	}

	/**
	 * The `Emoji` tags a piece of text needs, one per distinct shortcode in it
	 * that this instance has a picture for.
	 *
	 * Unknown shortcodes are left alone: `:shrug:` on an instance without one
	 * is text, here and everywhere it is delivered.
	 *
	 * @return array[] tags, ready for `ACore::addTag()`
	 */
	public function tagsFor(string $text): array {
		$tags = [];
		foreach ($this->shortcodesIn($text) as $shortcode) {
			$emoji = $this->byShortcode($shortcode);
			if ($emoji !== null) {
				$tags[] = $emoji->asTag();
			}
		}

		return $tags;
	}

	/**
	 * The distinct shortcodes written in a piece of text, in the order they
	 * appear.
	 *
	 * @return string[]
	 */
	public function shortcodesIn(string $text): array {
		if (preg_match_all(self::IN_TEXT, $text, $matches) === 0) {
			return [];
		}

		return array_values(array_unique(array_map('strtolower', $matches[1])));
	}

	/**
	 * Adds one, or replaces the picture behind a shortcode already in use.
	 *
	 * @param string $path a readable file holding the picture
	 *
	 * @throws InvalidActionException the shortcode or the picture is not one
	 */
	public function add(
		string $shortcode, string $path, string $category = '', bool $visible = true,
	): CustomEmoji {
		$shortcode = strtolower(trim($shortcode));
		if (!CustomEmoji::isShortcode($shortcode)) {
			throw new InvalidActionException(
				'a shortcode is 2 to 64 characters of a-z, 0-9 and _'
			);
		}

		if (!is_file($path) || !is_readable($path)) {
			throw new InvalidActionException('no picture found at ' . $path);
		}

		$size = filesize($path);
		if ($size === false || $size === 0) {
			throw new InvalidActionException('the picture is empty');
		}

		if ($size > self::MAX_SIZE) {
			throw new InvalidActionException(
				'the picture is larger than ' . intdiv(self::MAX_SIZE, 1024) . ' KiB'
			);
		}

		// the bytes decide, not the extension: this is served to every reader
		// of every post that uses it
		$mediaType = (string)@mime_content_type($path);
		if (!in_array($mediaType, self::ALLOWED_TYPES, true)) {
			throw new InvalidActionException('an emoji has to be a PNG, GIF, WebP or JPEG image');
		}

		$content = file_get_contents($path);
		if ($content === false) {
			throw new InvalidActionException('the picture could not be read');
		}

		// The same stripping every other upload gets. An emoji is usually a
		// drawing with nothing in it to remove, but it is uploaded from a
		// filesystem like anything else and is then served to every reader of
		// every post that uses it -- on this instance and on every peer that
		// mirrors it. A guarantee that holds for photographs and not for this
		// is not a guarantee, and the cost when there is nothing to strip is
		// that the bytes come back unchanged.
		$content = $this->imageMetadataService->strip($content, $mediaType);

		// named after the shortcode: one picture per shortcode, and replacing
		// it overwrites rather than leaving the old bytes behind
		$filename = $shortcode . '.' . $this->extensionOf($mediaType);
		$this->folder()->newFile($filename, $content);

		$emoji = new CustomEmoji($shortcode, $filename, $mediaType, trim($category), $visible);
		$this->emojiRequest->save($emoji);
		$this->cached = null;

		return $emoji->setUrl($this->urlOf($emoji));
	}

	/**
	 * Removes one, picture and all.
	 *
	 * Posts that used it keep the shortcode as text: what they carry is the
	 * tag they were federated with, and this instance no longer offering the
	 * picture does not rewrite what was already said.
	 *
	 * @return bool whether there was one to remove
	 */
	public function remove(string $shortcode): bool {
		$filename = $this->emojiRequest->delete(strtolower(trim($shortcode)));
		$this->cached = null;
		if ($filename === '') {
			return false;
		}

		try {
			$this->folder()->getFile($filename)->delete();
		} catch (\Exception $e) {
			// the row is gone, which is what decides whether the emoji exists;
			// a file left behind is a file, not a broken emoji
			$this->logger->notice('could not delete the picture of a custom emoji', [
				'shortcode' => $shortcode, 'exception' => $e,
			]);
		}

		return true;
	}

	/**
	 * The picture behind a shortcode.
	 *
	 * @throws NotFoundException
	 */
	public function picture(string $shortcode): ISimpleFile {
		$emoji = $this->byShortcode($shortcode);
		if ($emoji === null) {
			throw new NotFoundException('no such emoji');
		}

		return $this->folder()->getFile($emoji->getFilename());
	}

	/** The one address the picture has, for a client and for a peer alike. */
	private function urlOf(CustomEmoji $emoji): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'social.InstanceApi.emojiOpen', ['shortcode' => $emoji->getShortcode()]
		);
	}

	private function extensionOf(string $mediaType): string {
		return match ($mediaType) {
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			default => 'jpg',
		};
	}

	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException $e) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
