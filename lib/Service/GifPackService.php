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
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The 881 animated emoji every instance has in its picker from the day the app
 * is installed: Google's Noto Animated Emoji.
 *
 * They are too large to ship, so only the list ships
 * (`data/noto-animated-emoji.json`) and each picture is fetched by the
 * server the first time anybody asks for it, by codepoint from a fixed URL,
 * and kept in appdata. The browser only talks to this instance's `/gif/{slug}`,
 * so no search term or viewer reaches a third party, as `GifService` requires.
 *
 * The pack can be turned off on the Sections card or with
 * `occ config:app:set social gif_pack --value=0`; a server with
 * `has_internet_connection => false` offers no pack and fetches nothing.
 */
class GifPackService {
	/** The appdata folder the fetched pictures are kept in. */
	private const FOLDER = 'gif-pack';

	/** The shipped list, relative to the app root. */
	private const MANIFEST = '/data/noto-animated-emoji.json';

	/**
	 * Where one is fetched from, by codepoint. WebP, about a third smaller than
	 * the GIF at 512 px, and accepted by `GifService` as an animated picture.
	 */
	private const SOURCE = 'https://fonts.gstatic.com/s/e/notoemoji/latest/%s/512.webp';

	/** What every slug in this pack starts with, so none can collide with an
	 *  administrator's own. */
	public const PREFIX = 'noto-';

	/** How long to wait for one, in seconds. A thumbnail is not worth a hang. */
	private const TIMEOUT = 10;

	/**
	 * The largest one of these may be: a ceiling well above the biggest in the
	 * set (a little over 4 MiB), so a URL answering with something else cannot
	 * fill appdata.
	 */
	private const MAX_SIZE = 8 * 1024 * 1024;

	/** @var array<string, array{c: string, t: string, k: string}>|null */
	private ?array $manifest = null;

	/** The credit the manifest carries, read with it. */
	private string $attribution = '';

	public function __construct(
		private IAppData $appData,
		private CurlService $curl,
		private IURLGenerator $urlGenerator,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the instance offers the pack at all: switched on, and on a
	 * server allowed to fetch the pictures.
	 */
	public function enabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_GIF_PACK)
			&& $this->configService->hasInternetConnection();
	}

	/** The host the pictures are fetched from, for the administrator to see. */
	public static function sourceHost(): string {
		return (string)parse_url(self::SOURCE, PHP_URL_HOST);
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
	 * The pack, narrowed to what somebody typed, matched on the name and the
	 * manifest's keywords (other names and the category).
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
	 * The bytes behind a slug, fetched once per emoji per instance if missing.
	 * A failure is not cached, so the next reader tries again.
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
	 * Whether this instance already holds the bytes for a slug, for the
	 * warm-up job.
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
	 * What the licence requires to be said, wherever the pack is shown; read
	 * from the manifest so the credit travels with the list.
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
			$status = 0;
			$content = $this->curl->doRequest('get', $url, [
				'timeout' => self::TIMEOUT,
				'json_headers' => false,
				'accept_errors' => true,
				// a URL this app ships, not one subject to the instance access list
				'federation' => false,
			], $contentType, $status);

			if ($status !== 200) {
				$this->logger->notice('the emoji pack source refused a picture', [
					'url' => $url, 'status' => $status,
				]);

				return '';
			}
		} catch (Throwable $e) {
			$this->logger->notice('could not reach the emoji pack source', [
				'url' => $url, 'exception' => $e,
			]);

			return '';
		}

		if ($content === '' || strlen($content) > self::MAX_SIZE) {
			return '';
		}

		// the bytes decide, as for an administrator's own picture, since this
		// is served to everybody and federates attached to posts
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
			// the codepoint becomes part of a URL and a filename, so only a
			// codepoint will do; two digits minimum for (C) `a9_fe0f` and (R) `ae_fe0f`
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
