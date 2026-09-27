<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\Gif;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The 881 animated emoji every instance has in its picker from the day the app
 * is installed.
 *
 * **Why they are not in the app.** They are Google's Noto Animated Emoji, and
 * all of them together are over half a gigabyte: 746 KiB apiece at the only
 * size Google publishes. Even re-encoded small enough to be ugly they are
 * 42 MiB, against an app that is 66 MiB in total. So what ships is the *list*
 * — `data/noto-animated-emoji.json`, 70 KiB — and the pictures arrive one at a
 * time, the first time anybody on the instance asks for one, and are kept in
 * appdata for ever after.
 *
 * **What that means for the reader.** Nothing leaves their browser. The grid
 * points at this instance's own `/gif/{slug}`, the same route an administrator's
 * own pictures are served from, and it is the *server* that fetches the file it
 * does not have yet — by codepoint, from a fixed URL, once in the life of the
 * instance. No search term is sent anywhere, and no third party learns who
 * looked at what. That is the property `GifService` was written to protect and
 * the reason a Giphy or Tenor picker was refused; this keeps it.
 *
 * An administrator who wants nothing fetched at all turns the pack off with
 * `occ config:app:set social gif_pack --value=0`, and is left with whatever
 * they added themselves.
 */
class GifPackService {
	/** The appdata folder the fetched pictures are kept in. */
	private const FOLDER = 'gif-pack';

	/** The shipped list, relative to the app root. */
	private const MANIFEST = '/data/noto-animated-emoji.json';

	/**
	 * Where one is fetched from, by codepoint.
	 *
	 * WebP rather than GIF because Google serves both at 512 px and the WebP
	 * is about a third smaller for the same picture, and `GifService` already
	 * accepts an animated WebP as a library picture.
	 */
	private const SOURCE = 'https://fonts.gstatic.com/s/e/notoemoji/latest/%s/512.webp';

	/** What every slug in this pack starts with, so none can collide with an
	 *  administrator's own. */
	public const PREFIX = 'noto-';

	/** How long to wait for one, in seconds. A thumbnail is not worth a hang. */
	private const TIMEOUT = 10;

	/**
	 * The largest one of these may be.
	 *
	 * The biggest in the set is a little over 4 MiB at 512 px. This is a
	 * ceiling on what a fetch will store, not a claim about the set: the point
	 * is that a URL that starts answering with something else cannot fill the
	 * instance's appdata.
	 */
	private const MAX_SIZE = 8 * 1024 * 1024;

	/** @var array<string, array{c: string, t: string, k: string}>|null */
	private ?array $manifest = null;

	/** The credit the manifest carries, read with it. */
	private string $attribution = '';

	public function __construct(
		private IAppData $appData,
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/** Whether the instance offers the pack at all. */
	public function enabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_GIF_PACK);
	}

	/**
	 * The whole pack, most asked for first.
	 *
	 * @return Gif[]
	 */
	public function all(): array {
		if (!$this->enabled()) {
			return [];
		}

		return array_map(
			fn (array $entry): Gif => $this->gifOf($entry),
			array_values($this->entries())
		);
	}

	/**
	 * The pack, narrowed to what somebody typed.
	 *
	 * Matched on the name and on the keywords the manifest carries — every
	 * other name Google gives it, and the category — so "sad", "food" or
	 * "flag" find what a person means rather than only what a picture is
	 * called.
	 *
	 * @return Gif[]
	 */
	public function search(string $term): array {
		if (!$this->enabled()) {
			return [];
		}

		$needle = mb_strtolower(trim($term));
		if ($needle === '') {
			return $this->all();
		}

		$found = [];
		foreach ($this->entries() as $entry) {
			if (str_contains($entry['t'], $needle) || str_contains($entry['k'], $needle)) {
				$found[] = $this->gifOf($entry);
			}
		}

		return $found;
	}

	/** Whether a slug belongs to this pack, whether or not the pack is on. */
	public function owns(string $slug): bool {
		return str_starts_with($slug, self::PREFIX);
	}

	/** One by its slug, or null when there is no such emoji or the pack is off. */
	public function bySlug(string $slug): ?Gif {
		if (!$this->enabled() || !$this->owns($slug)) {
			return null;
		}

		$entry = $this->entries()[substr($slug, strlen(self::PREFIX))] ?? null;

		return $entry === null ? null : $this->gifOf($entry);
	}

	/**
	 * The bytes behind a slug, fetching them if this instance has not got them
	 * yet.
	 *
	 * The fetch is the slow path exactly once per emoji per instance; every
	 * request after it is a read from appdata. A failure is not cached — the
	 * next reader tries again — because the alternative is one bad minute
	 * leaving a picture permanently missing.
	 *
	 * @throws NotFoundException when there is no such emoji, or it cannot be had
	 */
	public function file(string $slug): ISimpleFile {
		$gif = $this->bySlug($slug);
		if ($gif === null) {
			throw new NotFoundException('no such picture');
		}

		$folder = $this->folder();
		try {
			return $folder->getFile($gif->getFilename());
		} catch (NotFoundException) {
			// not here yet: this is the one request that pays for it
		}

		$content = $this->fetch($gif);
		if ($content === '') {
			throw new NotFoundException('the picture could not be fetched');
		}

		try {
			return $folder->newFile($gif->getFilename(), $content);
		} catch (Throwable $e) {
			// two readers can ask for the same new emoji at the same moment;
			// the loser of that race finds the file already written
			$this->logger->debug('could not store a pack picture', [
				'slug' => $slug, 'exception' => $e,
			]);

			return $folder->getFile($gif->getFilename());
		}
	}

	/**
	 * Whether this instance already holds the bytes for a slug.
	 *
	 * What the warm-up job asks, so it fetches only what is missing.
	 */
	public function isCached(string $slug): bool {
		$gif = $this->bySlug($slug);
		if ($gif === null) {
			return false;
		}

		try {
			$this->folder()->getFile($gif->getFilename());

			return true;
		} catch (NotFoundException) {
			return false;
		}
	}

	/**
	 * What the licence requires to be said, wherever the pack is shown.
	 *
	 * Read from the manifest rather than written here twice, so regenerating
	 * the list cannot leave the credit behind.
	 */
	public function attribution(): string {
		$this->load();

		return $this->attribution;
	}

	/**
	 * Fetches one, or answers '' and says why.
	 */
	private function fetch(Gif $gif): string {
		$url = sprintf(self::SOURCE, substr($gif->getSlug(), strlen(self::PREFIX)));

		try {
			$response = $this->clientService->newClient()->get($url, [
				'timeout' => self::TIMEOUT,
				'http_errors' => false,
			]);

			if ($response->getStatusCode() !== 200) {
				$this->logger->notice('the emoji pack source refused a picture', [
					'url' => $url, 'status' => $response->getStatusCode(),
				]);

				return '';
			}

			$content = (string)$response->getBody();
		} catch (Throwable $e) {
			$this->logger->notice('could not reach the emoji pack source', [
				'url' => $url, 'exception' => $e,
			]);

			return '';
		}

		if ($content === '' || strlen($content) > self::MAX_SIZE) {
			return '';
		}

		// the bytes decide, as they do for a picture an administrator adds:
		// this is served to everybody here and ends up attached to posts that
		// federate
		if (!str_starts_with($content, 'RIFF') || substr($content, 8, 4) !== 'WEBP') {
			$this->logger->notice('the emoji pack source answered with something that is not a WebP', [
				'url' => $url,
			]);

			return '';
		}

		return $content;
	}

	/**
	 * @param array{c: string, t: string, k: string} $entry
	 */
	private function gifOf(array $entry): Gif {
		$gif = new Gif(
			self::PREFIX . $entry['c'],
			$entry['c'] . '.webp',
			'image/webp',
			$entry['t'],
		);

		return $gif->setUrl(
			$this->urlGenerator->linkToRouteAbsolute('social.InstanceApi.gifOpen', ['slug' => $gif->getSlug()])
		);
	}

	/**
	 * The manifest, by codepoint, read once per request.
	 *
	 * @return array<string, array{c: string, t: string, k: string}>
	 */
	private function entries(): array {
		$this->load();

		return $this->manifest ?? [];
	}

	private function load(): void {
		if ($this->manifest !== null) {
			return;
		}

		$this->manifest = [];
		$path = dirname(__DIR__, 2) . self::MANIFEST;
		$raw = @file_get_contents($path);
		if ($raw === false) {
			$this->logger->error('the animated emoji manifest is missing', ['path' => $path]);

			return;
		}

		$data = json_decode($raw, true);
		if (!is_array($data) || !is_array($data['emoji'] ?? null)) {
			$this->logger->error('the animated emoji manifest could not be read', ['path' => $path]);

			return;
		}

		$this->attribution = (string)($data['attribution'] ?? '');
		foreach ($data['emoji'] as $entry) {
			$codepoint = (string)($entry['c'] ?? '');
			// the codepoint is half of a URL this server will fetch and half of
			// a filename it will write, so nothing but a codepoint will do.
			// Two digits, not four: (C) is `a9_fe0f` and (R) is `ae_fe0f`, and
			// a stricter rule silently dropped both
			if ($codepoint === '' || preg_match('/^[0-9a-f]{2,6}(_[0-9a-f]{2,6})*$/', $codepoint) !== 1) {
				continue;
			}

			$this->manifest[$codepoint] = [
				'c' => $codepoint,
				't' => mb_strtolower((string)($entry['t'] ?? $codepoint)),
				'k' => mb_strtolower((string)($entry['k'] ?? '')),
			];
		}
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
