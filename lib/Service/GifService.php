<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\GifRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\Gif;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The instance's own library of animated pictures.
 *
 * **Why this rather than Giphy or Tenor.** Those are the obvious way to get a
 * GIF picker, and both mean every composer on the instance talking to a third
 * party: the search terms people type are sent there, and every thumbnail is a
 * request from the reader's browser to a host the instance does not control.
 * That is a decision about a company's internal feed that this app is not
 * entitled to make on an administrator's behalf, and a setting to turn it on
 * would still be a setting that quietly sends what people are looking for to
 * somebody else's server.
 *
 * **Why not just Files.** The composer can already attach any file, this one
 * included. What this adds is a *shared* set: a picture an administrator put
 * here is in everybody's picker, under a name they can search for, without
 * anybody having to be given a folder or remember a path. That is what makes
 * it the same joke in the same team rather than one person's collection.
 *
 * Stored the way custom emoji are — a row and a file in appdata — because it
 * is the same shape of thing: a small curated set, served to everybody, which
 * must not break because a file moved in somebody's Files.
 */
class GifService {
	/** The appdata folder the pictures live in. */
	private const FOLDER = 'gif';

	/**
	 * What may be in the library.
	 *
	 * Animated formats and nothing else: this is a GIF picker, and a library
	 * of stills is what the Files picker next to it is already for.
	 */
	private const ALLOWED_TYPES = ['image/gif', 'image/webp', 'video/mp4'];

	/**
	 * How large one may be, in bytes.
	 *
	 * Generous next to an emoji's 256 KiB, because a second of animation is
	 * not a 32-pixel square, and far below an upload's ceiling, because
	 * everybody on the instance loads these in a grid.
	 */
	public const MAX_SIZE = 8 * 1024 * 1024;

	public function __construct(
		private GifPackService $pack,
		private GifRequest $gifRequest,
		private IAppData $appData,
		private IURLGenerator $urlGenerator,
		private ImageMetadataService $imageMetadataService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The whole library, each knowing its URL.
	 *
	 * @return Gif[]
	 */
	public function all(): array {
		return array_map(
			fn (Gif $gif): Gif => $gif->setUrl($this->urlOf($gif)),
			$this->gifRequest->all()
		);
	}

	/**
	 * The library as the picker is offered it: what this instance added, and
	 * then the animated emoji every instance has.
	 *
	 * The instance's own come first whatever the order asks: somebody put them
	 * there on purpose, and they are the ones nowhere else has.
	 *
	 * @return Gif[]
	 */
	public function offered(): array {
		return array_merge($this->all(), $this->pack->all());
	}

	/**
	 * The library, narrowed to what somebody typed.
	 *
	 * Matched on the title and the slug, case-insensitively, in PHP rather
	 * than in SQL: the set is tens of rows, one query returns all of them
	 * anyway, and `LIKE` with a leading wildcard is a scan on every database
	 * this app supports. What it buys is that the same search works the same
	 * on all three.
	 *
	 * @return Gif[]
	 */
	public function search(string $term): array {
		$term = trim($term);
		if ($term === '') {
			return $this->offered();
		}

		$needle = mb_strtolower($term);

		$own = array_values(array_filter(
			$this->all(),
			static fn (Gif $gif): bool => str_contains(mb_strtolower($gif->getTitle()), $needle)
				|| str_contains($gif->getSlug(), $needle)
		));

		return array_merge($own, $this->pack->search($term));
	}

	/** One by its slug, or null. */
	public function bySlug(string $slug): ?Gif {
		$slug = strtolower(trim($slug));

		// the pack first, and by name rather than by a scan: it is 881 of them
		// against the handful an instance adds, and its slugs are its own
		if ($this->pack->owns($slug)) {
			return $this->pack->bySlug($slug);
		}

		foreach ($this->all() as $gif) {
			if ($gif->getSlug() === $slug) {
				return $gif;
			}
		}

		return null;
	}

	/**
	 * Adds a picture to the library, replacing whatever the slug named before.
	 *
	 * @param string $slug what the URL will name it
	 * @param string $path a file on the server's own filesystem
	 * @param string $title what the picker searches
	 * @throws InvalidActionException when the slug or the file will not do
	 */
	public function add(string $slug, string $path, string $title = ''): Gif {
		$slug = strtolower(trim($slug));
		if (!Gif::isSlug($slug)) {
			throw new InvalidActionException(
				'a slug is 2 to 64 characters of a-z, 0-9, - and _'
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
				'the picture is larger than ' . intdiv(self::MAX_SIZE, 1024 * 1024) . ' MiB'
			);
		}

		// the bytes decide, not the extension: this is served to everybody on
		// the instance and ends up attached to posts that federate
		$mediaType = (string)@mime_content_type($path);
		if (!in_array($mediaType, self::ALLOWED_TYPES, true)) {
			throw new InvalidActionException('a library picture has to be a GIF, an animated WebP or an MP4');
		}

		$content = file_get_contents($path);
		if ($content === false) {
			throw new InvalidActionException('the picture could not be read');
		}

		// the same stripping every other upload gets; these are attached to
		// posts that leave this instance, so a guarantee that holds for a
		// photograph and not for this is not a guarantee
		$content = $this->imageMetadataService->strip($content, $mediaType);

		// named after the slug: one picture per slug, and replacing it
		// overwrites rather than leaving the old bytes behind
		$filename = $slug . '.' . $this->extensionOf($mediaType);
		$this->folder()->newFile($filename, $content);

		$gif = new Gif($slug, $filename, $mediaType, trim($title));
		$this->gifRequest->save($gif);

		return $gif->setUrl($this->urlOf($gif));
	}

	/**
	 * Removes one, picture and all.
	 *
	 * Posts that used it are untouched: an attachment is a copy taken when the
	 * post was written, so a post keeps the picture it was published with.
	 *
	 * @return bool whether there was one to remove
	 */
	public function remove(string $slug): bool {
		$filename = $this->gifRequest->delete(strtolower(trim($slug)));
		if ($filename === '') {
			return false;
		}

		try {
			$this->folder()->getFile($filename)->delete();
		} catch (\Exception $e) {
			// the row is gone, which is what decides whether it is in the
			// library; a file left behind is a file, not a broken entry
			$this->logger->notice('could not delete the bytes of a library picture', [
				'slug' => $slug, 'exception' => $e,
			]);
		}

		return true;
	}

	/**
	 * The bytes behind a slug.
	 *
	 * @throws NotFoundException
	 */
	public function file(string $slug): ISimpleFile {
		$slug = strtolower(trim($slug));
		// the pack keeps its own bytes, and fetches them the first time
		if ($this->pack->owns($slug)) {
			return $this->pack->file($slug);
		}

		$gif = $this->bySlug($slug);
		if ($gif === null) {
			throw new NotFoundException('no such picture');
		}

		return $this->folder()->getFile($gif->getFilename());
	}

	/** What the licence of the shipped pack requires to be said. */
	public function attribution(): string {
		return $this->pack->attribution();
	}

	private function urlOf(Gif $gif): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'social.InstanceApi.gifOpen', ['slug' => $gif->getSlug()]
		);
	}

	private function extensionOf(string $mediaType): string {
		return match ($mediaType) {
			'image/webp' => 'webp',
			'video/mp4' => 'mp4',
			default => 'gif',
		};
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException $e) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
