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
 * A post keeps `:shortcode:` in its content and carries an `Emoji` tag saying
 * where the image is, as every fediverse server does. The image lives in
 * appdata and is served from one route, the same URL for local clients and
 * remote servers.
 */
class EmojiService {
	/** The appdata folder the images live in. */
	private const FOLDER = 'emoji';

	/** What an emoji may be: what a browser will render inline, and no more. */
	private const ALLOWED_TYPES = ['image/png', 'image/gif', 'image/webp', 'image/jpeg'];

	/** Mastodon's own ceiling for one, in bytes. */
	public const MAX_SIZE = 256 * 1024;

	/**
	 * What a post is scanned for: Mastodon's pattern with this app's shortcode
	 * alphabet. Neither colon may touch a word character or another colon, so
	 * `12:30:45` and URLs do not match. Case-insensitive; matches are lowercased,
	 * as shortcodes are stored.
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
	 * The `Emoji` tags a piece of text needs, one per distinct shortcode this
	 * instance has an image for; unknown shortcodes stay text.
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
	 * Adds one, or replaces the image behind a shortcode already in use.
	 *
	 * @param string $path a readable file holding the image
	 *
	 * @throws InvalidActionException the shortcode or the image is not one
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

		// The bytes decide the type, not the extension.
		$mediaType = (string)@mime_content_type($path);
		if (!in_array($mediaType, self::ALLOWED_TYPES, true)) {
			throw new InvalidActionException('an emoji has to be a PNG, GIF, WebP or JPEG image');
		}

		$content = file_get_contents($path);
		if ($content === false) {
			throw new InvalidActionException('the picture could not be read');
		}

		// Metadata is stripped as for every other upload.
		$content = $this->imageMetadataService->strip($content, $mediaType);

		// Named after the shortcode, so replacing it overwrites the old bytes.
		$filename = $shortcode . '.' . $this->extensionOf($mediaType);
		$this->folder()->newFile($filename, $content);

		$emoji = new CustomEmoji($shortcode, $filename, $mediaType, trim($category), $visible);
		$this->emojiRequest->save($emoji);
		$this->cached = null;

		return $emoji->setUrl($this->urlOf($emoji));
	}

	/**
	 * Removes one, image and all. Posts that used it keep the shortcode as text.
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
			// The row decides whether the emoji exists; a leftover file is harmless.
			$this->logger->notice('could not delete the picture of a custom emoji', [
				'shortcode' => $shortcode, 'exception' => $e,
			]);
		}

		return true;
	}

	/**
	 * The image behind a shortcode.
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

	/** The one address of the image, for clients and peers alike. */
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
