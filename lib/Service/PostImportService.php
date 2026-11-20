<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Imports an account's own posts from the server it wrote them on, as new
 * local posts of the importing account, dated when written, with their media.
 *
 * Nothing is federated (re-publishing years of posts would flood every
 * follower), the same discipline as `SocialMigrator::importRelations()`. Ids
 * are minted here; the original is remembered in `social_import_post`, which
 * makes re-runs no-ops and lets replies find their parent. Only what the
 * account wrote: boosts and direct messages are skipped.
 *
 * Formats read:
 *
 *  - this app's archive (`social/outbox.json`, files under `media_attachments/`),
 *  - Mastodon's and GoToSocial's (`outbox.json` at the zip root),
 *  - a bare `outbox.json`,
 *  - Pixelfed's `pixelfed-statuses.json`, whose media are URLs fetched from
 *    the old server,
 *  - PeerTube's export, from `peertube/videos.json`, which names the video
 *    files inside the archive and carries title, tags, category and licence,
 *  - Instagram's JSON "Download your information" archive (`posts_*.json`
 *    and reels, media from its `media/` folder). It has no ids (derived from
 *    the file and time), no visibility (the account's default is used and the
 *    person told) and no hashtag data (read from the caption).
 */
class PostImportService {
	/** How many posts one run writes at most. */
	public const MAX_POSTS = 2000;
	/**
	 * How many failures are kept by name in a tally, so the person knows what
	 * failed and why.
	 */
	public const REPORT_FAILURES = 20;

	/** The two kinds of export, which are parsed by two different readers. */
	private const FORMAT_ACTIVITYPUB = 'activitypub';
	private const FORMAT_INSTAGRAM = 'instagram';
	private const FORMAT_PEERTUBE = 'peertube';

	/** Where an archive keeps the posts, ours first. */
	private const OUTBOX_PATHS = ['social/outbox.json', 'outbox.json'];

	/**
	 * Where PeerTube keeps the videos, and the key it wraps them in; not
	 * `activity-pub/outbox.json`, which names the files on the old server.
	 */
	private const PEERTUBE_PATH = 'peertube/videos.json';

	/**
	 * PeerTube's `VideoPrivacy`, as far as it maps onto an audience here.
	 * Private, internal and password-protected (3, 4, 5) are absent: their
	 * authors chose not to publish them.
	 */
	private const PEERTUBE_PRIVACY = [
		1 => Stream::TYPE_PUBLIC,
		2 => Stream::TYPE_UNLISTED,
	];

	/**
	 * Where Instagram keeps them: `your_instagram_activity/content/` in
	 * archives since 2023, `content/` before. The export splits by size, so
	 * all of `posts_1.json`, `posts_2.json` … are read.
	 */
	private const INSTAGRAM_DIRS = ['your_instagram_activity/content/', 'content/'];

	/**
	 * The files in those folders that hold posts, and the key each wraps them
	 * in (`posts_*.json` is a bare array). Stories, archived posts and recently
	 * deleted content are left out on purpose: their authors took them down.
	 */
	private const INSTAGRAM_FILES = [
		'reels.json' => 'ig_reels_media',
		'igtv_videos.json' => 'ig_igtv_media',
		'other_content.json' => 'ig_other_media',
	];

	/** How large a file inside an archive may be before it is left alone. */
	private const MAX_FILE = 200 * 1024 * 1024;

	/**
	 * The largest list of posts read, whatever memory there is: a list is
	 * read and decoded whole, and decoded JSON takes several times the room
	 * of its text.
	 */
	private const MAX_JSON = 256 * 1024 * 1024;
	/** How many bytes of memory decoding one byte of JSON is reckoned to take. */
	private const JSON_MEMORY_FACTOR = 10;

	/**
	 * Every file in the archive being read, built the first time an attachment
	 * cannot be found where the export said it would be. Null until then.
	 *
	 * @var string[]|null
	 */
	private ?array $archiveIndex = null;

	public function __construct(
		private ImportedPostsRequest $importedPostsRequest,
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private DocumentService $documentService,
		private CacheDocumentService $cacheDocumentService,
		private LinkifyService $linkifyService,
		private AccountService $accountService,
		private ITempManager $tempManager,
		private IURLGenerator $urlGenerator,
		private CurlService $curlService,
		private PeerTubeService $peerTubeService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Imports one video by its address, for somebody leaving an instance (or
	 * without access to their account there) who wants a single video here.
	 * The same rules as an archive import: nothing federated, the id ours with
	 * the original remembered, a new local post of the importing account.
	 *
	 * Refused: a video hosted here, a document that is not a video, and one
	 * that does not name the address it was fetched from (the evidence
	 * `SearchService::resolveStatus()` requires). Ownership cannot be checked,
	 * as with archives; it is one deliberate, rate-limited act producing an
	 * ordinary, reportable post.
	 *
	 * @return array<string, mixed> the same tally an archive import answers with
	 * @throws MoveErrorException with a sentence saying what was wrong, and its code
	 */
	public function importVideo(Person $actor, string $url, bool $fetchMedia = true): array {
		$url = trim($url);
		if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
			throw new MoveErrorException('that is not a video address', 'video-address-invalid');
		}

		$cloud = rtrim($this->configService->getCloudUrl(), '/');
		if ($cloud !== '' && str_starts_with($url, $cloud)) {
			throw new MoveErrorException(
				'that video is already on this server — an import is for bringing one over from another',
				'video-here-already'
			);
		}

		try {
			$data = $this->curlService->retrieveObject($url);
		} catch (Throwable $e) {
			throw new MoveErrorException('that address could not be read: ' . $e->getMessage(), 'video-unreachable', $e);
		}

		$post = $this->parseFetchedVideo($data, $url);
		if ($post === null) {
			throw new MoveErrorException(
				'that address is not a video this app can bring over', 'video-unsupported'
			);
		}

		$tally = [
			'imported' => 0, 'skipped' => 0, 'already' => 0,
			'media' => 0, 'failed' => 0, 'failures' => [], 'total' => 1, 'capped' => false,
		];

		$known = $this->importedPostsRequest->knownAmong($actor->getId(), [$post['source']]);
		if (isset($known[$post['source']])) {
			$tally['already'] = 1;

			return $tally;
		}

		$written = $this->write($actor, $post, $known, null, $fetchMedia, $tally);
		$this->importedPostsRequest->remember($actor->getId(), $post['source'], $written->getId());
		$tally['imported'] = 1;
		$this->accountService->cacheLocalActorDetailCount($actor);

		return $tally;
	}

	/**
	 * One fetched `Video` document, in the shape every other reader produces;
	 * the file is the playable absolute link in `url`, fetched from its server.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private function parseFetchedVideo(array $data, string $url): ?array {
		if ((string)($data['type'] ?? '') !== 'Video') {
			return null;
		}

		$id = (string)($data['id'] ?? '');
		if ($id === '' || (!$this->claimsAddress($data, $url) && $id !== $url)) {
			// the same evidence `SearchService::resolveStatus()` requires: a
			// PeerTube watch page is not the object's id, but the object names
			// it among its own `url` links
			return null;
		}

		$file = $this->playableLink($data);
		if ($file === '') {
			return null;
		}

		$title = trim((string)($data['name'] ?? ''));
		$description = trim(strip_tags((string)($data['content'] ?? '')));
		$text = trim($title . (($description === '') ? '' : "\n\n" . $description));
		if ($text === '') {
			return null;
		}

		$published = strtotime((string)($data['published'] ?? ''));

		return [
			'source' => $id,
			'text' => $text,
			'published' => ($published === false || $published <= 0) ? time() : $published,
			// the object's own audience is read first, and only public and
			// unlisted are kept, so an unlisted video does not become public
			'visibility' => $this->visibility($data) === Stream::TYPE_UNLISTED
				? Stream::TYPE_UNLISTED : Stream::TYPE_PUBLIC,
			'sensitive' => (bool)($data['sensitive'] ?? false),
			'spoiler' => '',
			'language' => $this->language($data),
			'replyTo' => '',
			'attachments' => [['url' => $file, 'name' => $title]],
			'hashtags' => $this->hashtags($data),
			'video' => $this->peerTubeService->videoMeta($data),
		];
	}

	/** Whether a document names the address it was fetched from. */
	private function claimsAddress(array $data, string $url): bool {
		foreach ($this->listOf($data, 'url') as $link) {
			$href = is_array($link) ? (string)($link['href'] ?? '') : (string)$link;
			if ($href === $url) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The best file link a `Video` offers. HLS playlists are skipped: they
	 * list remote segments, not a file to store.
	 *
	 * @param array<string, mixed> $data
	 */
	private function playableLink(array $data): string {
		$best = '';
		$bestHeight = -1;

		foreach ($this->listOf($data, 'url') as $link) {
			if (!is_array($link)) {
				continue;
			}

			$type = strtolower((string)($link['mediaType'] ?? ''));
			$href = (string)($link['href'] ?? '');
			if ($href === '' || !str_starts_with($type, 'video/')) {
				continue;
			}

			$height = (int)($link['height'] ?? 0);
			if ($height > $bestHeight) {
				$best = $href;
				$bestHeight = $height;
			}
		}

		return $best;
	}

	/**
	 * Reads an export and writes what is in it.
	 *
	 * @param bool $fetchMedia whether a picture named only by a URL may be
	 *                         fetched from the server it is still on
	 * @return array{imported: int, skipped: int, already: int, media: int, failed: int, failures: array<string, string>, total: int, capped: bool}
	 * @psalm-suppress InvalidReturnType the tally is built by reference through
	 *                 the writers, which psalm cannot follow back to its shape
	 * @throws InvalidResourceException when the file is not an export this can read
	 */
	public function import(
		Person $actor, string $path, bool $fetchMedia = true, int $limit = self::MAX_POSTS, ?callable $progress = null,
	): array {
		// 0 is no cap at all, for a run with no request to keep within a time
		// limit; anything else stays within MAX_POSTS
		$limit = ($limit <= 0) ? PHP_INT_MAX : max(1, min(self::MAX_POSTS, $limit));
		$zip = $this->openArchive($path);

		try {
			$read = $this->items($path, $zip);
			$items = $read['items'];
			$tally = [
				'imported' => 0, 'skipped' => 0, 'already' => 0,
				'media' => 0, 'failed' => 0, 'failures' => [], 'total' => count($items), 'capped' => false,
			];

			// an Instagram post carries no audience, so one is decided once, for
			// the whole run: the account's own default, which is what their next
			// post would be posted with
			$instagram = ($read['format'] === self::FORMAT_INSTAGRAM);
			$peertube = ($read['format'] === self::FORMAT_PEERTUBE);
			$visibility = $instagram
				? $this->accountService->getDefaultPrivacy($actor->getUserId()) : '';

			$parsed = [];
			foreach ($items as $item) {
				if (!is_array($item)) {
					$tally['skipped']++;
					continue;
				}

				$post = match (true) {
					$instagram => $this->parseInstagram($item, $visibility),
					$peertube => $this->parsePeerTube($item),
					default => $this->parse($item),
				};
				if ($post === null) {
					$tally['skipped']++;
					continue;
				}

				$parsed[] = $post;
			}

			return $this->writeParsed($actor, $parsed, $zip, $fetchMedia, $limit, $progress, $tally);
		} finally {
			$zip?->close();
		}
	}

	/**
	 * Writes the posts of an ActivityPub collection already fetched (an
	 * account's outbox on a server it is still on), with the same parsing and
	 * writes as `import()`. Uncapped, as it runs in the background.
	 *
	 * @param array<int, mixed> $items the activities or objects, as the collection lists them
	 * @param callable(int, int): void|null $progress
	 * @return array{imported: int, skipped: int, already: int, media: int, failed: int, failures: array<string, string>, total: int, capped: bool}
	 * @psalm-suppress InvalidReturnType the tally is built by reference through the writers
	 */
	public function importItems(Person $actor, array $items, bool $fetchMedia = true, ?callable $progress = null): array {
		$tally = [
			'imported' => 0, 'skipped' => 0, 'already' => 0,
			'media' => 0, 'failed' => 0, 'failures' => [], 'total' => count($items), 'capped' => false,
		];
		$parsed = [];
		foreach ($items as $item) {
			$post = is_array($item) ? $this->parse($item) : null;
			if ($post === null) {
				$tally['skipped']++;
				continue;
			}
			$parsed[] = $post;
		}

		/** @psalm-suppress InvalidReturnStatement the shape is the one declared above */
		return $this->writeParsed($actor, $parsed, null, $fetchMedia, PHP_INT_MAX, $progress, $tally);
	}

	/**
	 * Writes posts another reader made into the shape `parse()` makes — a
	 * Bluesky repository's, which this server already holds — with the same
	 * rules and writes as every import. An attachment may name a `path`, a
	 * temporary file of its bytes, which is used instead of its `url` and
	 * removed once stored.
	 *
	 * @param array<int, array<string, mixed>> $parsed
	 * @return array{imported: int, skipped: int, already: int, media: int, failed: int, failures: array<string, string>, total: int, capped: bool}
	 * @psalm-suppress InvalidReturnType the tally is built by reference through the writers
	 */
	public function importParsed(Person $actor, array $parsed): array {
		$tally = [
			'imported' => 0, 'skipped' => 0, 'already' => 0,
			'media' => 0, 'failed' => 0, 'failures' => [], 'total' => count($parsed), 'capped' => false,
		];

		/** @psalm-suppress InvalidReturnStatement the shape is the one declared above */
		return $this->writeParsed($actor, $parsed, null, false, PHP_INT_MAX, null, $tally);
	}

	/**
	 * Writes the parsed posts, oldest first, skipping what is here already.
	 *
	 * @param array<int, array<string, mixed>> $parsed
	 * @param callable(int, int): void|null $progress
	 * @param array<string, mixed> $tally
	 * @return array<string, mixed> the tally, filled in
	 */
	private function writeParsed(
		Person $actor, array $parsed, ?ZipArchive $zip, bool $fetchMedia, int $limit, ?callable $progress, array $tally,
	): array {
		// oldest first, so a reply is written after the post it answers and
		// can be hung off it
		usort($parsed, static fn (array $a, array $b): int => $a['published'] <=> $b['published']);

		$known = $this->importedPostsRequest->knownAmong(
			$actor->getId(), array_column($parsed, 'source')
		);

		$handled = 0;
		foreach ($parsed as $post) {
			if ($progress !== null) {
				$progress($handled++, count($parsed));
			}
			if (isset($known[$post['source']])) {
				$tally['already']++;
				continue;
			}

			if ($tally['imported'] >= $limit) {
				$tally['capped'] = true;
				break;
			}

			try {
				$written = $this->write($actor, $post, $known, $zip, $fetchMedia, $tally);
			} catch (Throwable $e) {
				$this->logger->warning('could not import a post', [
					'actor' => $actor->getId(), 'source' => $post['source'], 'exception' => $e,
				]);
				$tally['failed']++;
				self::noteFailure($tally, $post['source'], $e->getMessage());
				continue;
			}

			$known[$post['source']] = md5($written->getId());
			$this->importedPostsRequest->remember($actor->getId(), $post['source'], $written->getId());
			$tally['imported']++;
		}

		if ($progress !== null) {
			$progress($handled, count($parsed));
		}
		if ($tally['imported'] > 0) {
			$this->accountService->cacheLocalActorDetailCount($actor);
		}

		return $tally;
	}

	/**
	 * The archive, or null where the file is a bare JSON export.
	 */
	private function openArchive(string $path): ?ZipArchive {
		if (!is_file($path)) {
			throw new MoveErrorException('there is no such file', 'export-unreadable');
		}

		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			return null;
		}

		return $zip;
	}

	/**
	 * The posts an export names, whichever shape it is in, and which shape that
	 * was — the Instagram entries are not activities and are read by their own
	 * parser.
	 *
	 * @return array{format: string, items: array<int, mixed>}
	 * @throws InvalidResourceException
	 */
	private function items(string $path, ?ZipArchive $zip): array {
		$contents = null;

		if ($zip !== null) {
			// PeerTube first: its archive also holds an `activity-pub/outbox.json`
			// the loop below would find, which names the files on the old server
			$peertube = $this->peerTubeItems($zip);
			if ($peertube !== []) {
				// an export without the video files is only a catalogue, which
				// would import nothing, so it is reported as the wrong export
				if ($this->peerTubeHasNoFiles($zip)) {
					throw new MoveErrorException(
						'this PeerTube export was taken without its video files, so there is'
						. ' nothing to bring over — ask for the export again with the videos included',
						'export-peertube-no-videos'
					);
				}

				return ['format' => self::FORMAT_PEERTUBE, 'items' => $peertube];
			}

			foreach (self::OUTBOX_PATHS as $candidate) {
				$found = $this->readJson($zip, $candidate);
				if ($found !== null) {
					$contents = $found;
					break;
				}
			}

			if ($contents === null) {
				// not a Fediverse export; it may still be an Instagram one
				$instagram = $this->instagramItems($zip);
				if ($instagram !== []) {
					return ['format' => self::FORMAT_INSTAGRAM, 'items' => $instagram];
				}

				throw $this->whyNotAnExport($zip);
			}
		} else {
			$this->assertJsonFits((int)@filesize($path), basename($path));
			$contents = file_get_contents($path);
			if ($contents === false) {
				throw new MoveErrorException('the file could not be read', 'export-unreadable');
			}
		}

		$decoded = json_decode($contents, true);
		if (!is_array($decoded)) {
			throw new MoveErrorException('this file is not the JSON an export is written in', 'export-unreadable');
		}

		// an OrderedCollection, ours and Mastodon's; a bare array, Pixelfed's
		$items = $decoded['orderedItems'] ?? $decoded['items'] ?? $decoded['data'] ?? $decoded;
		if (!is_array($items)) {
			throw new MoveErrorException('this export names no posts', 'export-unreadable');
		}

		return ['format' => self::FORMAT_ACTIVITYPUB, 'items' => array_values($items)];
	}

	/**
	 * One list of posts out of an archive, or null when it is not there. Its
	 * declared size is checked before reading, as a list too large to decode
	 * would end the run with a fatal error rather than a refusal.
	 *
	 * @throws MoveErrorException file-too-large
	 */
	private function readJson(ZipArchive $zip, string $name): ?string {
		$stat = $zip->statName($name);
		if ($stat === false) {
			return null;
		}
		$this->assertJsonFits((int)($stat['size'] ?? 0), $name);

		$contents = $zip->getFromName($name);

		return ($contents === false) ? null : $contents;
	}

	/** @throws MoveErrorException file-too-large */
	private function assertJsonFits(int $size, string $name): void {
		$ceiling = $this->jsonCeiling();
		if ($size > $ceiling) {
			throw new MoveErrorException(
				$name . ' is larger than the ' . intdiv($ceiling, 1024 * 1024) . ' MB this server can read in one go',
				'file-too-large'
			);
		}
	}

	/** The largest list of posts this process has the memory to decode. */
	private function jsonCeiling(): int {
		$limit = Util::computerFileSize((string)ini_get('memory_limit'));
		if ($limit === false || $limit <= 0) {
			return self::MAX_JSON;
		}

		return max(0, min(self::MAX_JSON, intdiv((int)$limit - memory_get_usage(), self::JSON_MEMORY_FACTOR)));
	}

	/**
	 * The videos a PeerTube export names, or `[]` when this is not one. Found
	 * by suffix, like every archive file, since re-packed zips nest under a
	 * folder.
	 *
	 * @return array<int, mixed>
	 */
	private function peerTubeItems(ZipArchive $zip): array {
		$name = $zip->statName(self::PEERTUBE_PATH) !== false
			? self::PEERTUBE_PATH
			: $this->endingWith($zip, self::PEERTUBE_PATH);
		if ($name === null) {
			return [];
		}

		$contents = $this->readJson($zip, $name);
		if ($contents === null) {
			return [];
		}

		$decoded = json_decode($contents, true);
		$videos = is_array($decoded) ? ($decoded['videos'] ?? null) : null;

		return is_array($videos) ? array_values($videos) : [];
	}

	/** Whether a PeerTube archive carries no video file at all. */
	private function peerTubeHasNoFiles(ZipArchive $zip): bool {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			if (str_contains((string)$zip->getNameIndex($i), '/video-files/')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Everything an Instagram archive holds that is a post: every
	 * `posts_N.json`, plus the reels and IGTV files, which share the shape.
	 *
	 * @return array<int, mixed>
	 */
	private function instagramItems(ZipArchive $zip): array {
		$items = [];

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$wrapper = $this->instagramWrapper($name);
			if ($wrapper === false) {
				continue;
			}

			$contents = $this->readJson($zip, $name);
			if ($contents === null) {
				continue;
			}

			$decoded = json_decode($contents, true);
			if (!is_array($decoded)) {
				continue;
			}

			// `posts_*.json` is a bare array; the others wrap theirs in one key
			$entries = ($wrapper === null) ? $decoded : ($decoded[$wrapper] ?? []);
			if (is_array($entries)) {
				foreach ($entries as $entry) {
					$items[] = $entry;
				}
			}
		}

		return $items;
	}

	/**
	 * Whether a path inside the archive is one of Instagram's post files, and
	 * the key its entries are under — `null` for the bare arrays, `false` when
	 * the file is not one of them at all.
	 */
	private function instagramWrapper(string $name): string|null|false {
		foreach (self::INSTAGRAM_DIRS as $dir) {
			// at the root, or under the folder a browser unpacked it into, which
			// is named for the account and date
			$at = str_starts_with($name, $dir) ? 0 : strpos($name, '/' . $dir);
			if ($at === false) {
				continue;
			}

			$file = substr($name, (($at === 0) ? 0 : $at + 1) + strlen($dir));
			if (str_starts_with($file, 'posts_') && str_ends_with($file, '.json')) {
				return null;
			}

			if (array_key_exists($file, self::INSTAGRAM_FILES)) {
				return self::INSTAGRAM_FILES[$file];
			}
		}

		return false;
	}

	/**
	 * Why an archive with no outbox and no Instagram posts is not readable.
	 * Instagram's HTML download has the same layout with `.html` files, so the
	 * person is told to ask for JSON instead.
	 */
	private function whyNotAnExport(ZipArchive $zip): MoveErrorException {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (str_contains($name, 'content/posts_') && str_ends_with($name, '.html')) {
				return new MoveErrorException(
					'this is Instagram\'s HTML download, which holds the pages and not the posts'
					. ' — ask Instagram for the same export in JSON and import that',
					'export-instagram-html'
				);
			}
		}

		return new MoveErrorException('this archive has no outbox.json — it is not an export of an account\'s posts', 'export-not-posts');
	}

	/**
	 * One item of an export, as much as this app can use of it.
	 *
	 * Null for anything that is not a post of the account's own to bring over:
	 * a boost, a direct message, a deletion, an empty item.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>|null
	 */
	private function parse(array $item): ?array {
		// an outbox is activities; ours writes the objects themselves
		$type = (string)($item['type'] ?? '');
		if ($type === 'Create' && is_array($item['object'] ?? null)) {
			$item = $item['object'];
			$type = (string)($item['type'] ?? '');
		}

		// a boost is somebody else's post, and the `reblog` of an API export
		// says the same thing
		if (in_array($type, ['Announce', 'Delete', 'Undo', 'Like'], true) || ($item['reblog'] ?? null) !== null) {
			return null;
		}

		$source = (string)($item['id'] ?? $item['uri'] ?? $item['url'] ?? '');
		if ($source === '') {
			return null;
		}

		$visibility = $this->visibility($item);
		if ($visibility === '' || $visibility === Stream::TYPE_DIRECT) {
			// a direct message's recipients are on the old server, so a copy
			// here would be addressed to nobody
			return null;
		}

		$text = $this->text((string)($item['content'] ?? ''));
		$attachments = $this->attachments($item);
		if ($text === '' && $attachments === []) {
			return null;
		}

		$published = strtotime((string)($item['published'] ?? $item['created_at'] ?? ''));

		return [
			'source' => $source,
			'text' => $text,
			'published' => ($published === false || $published <= 0) ? time() : $published,
			'visibility' => $visibility,
			'sensitive' => (bool)($item['sensitive'] ?? false),
			'spoiler' => trim(strip_tags((string)($item['summary'] ?? $item['spoiler_text'] ?? ''))),
			'language' => $this->language($item),
			'replyTo' => (string)($item['inReplyTo'] ?? $item['in_reply_to_id'] ?? ''),
			'attachments' => $attachments,
			'hashtags' => $this->hashtags($item),
		];
	}

	/**
	 * One video out of a PeerTube export, with an extra `video` key for title,
	 * duration, category and licence.
	 *
	 * Refused: private, internal or password-protected videos (their authors
	 * chose not to publish), lives (a saved replay is exported as its own
	 * video), and videos without a file in the archive (an export taken
	 * without `withVideoFiles`).
	 *
	 * @param array<string, mixed> $item one entry of `peertube/videos.json`
	 * @return array<string, mixed>|null
	 */
	private function parsePeerTube(array $item): ?array {
		$visibility = self::PEERTUBE_PRIVACY[(int)($item['privacy'] ?? 0)] ?? '';
		if ($visibility === '' || ($item['isLive'] ?? false) === true) {
			return null;
		}

		$file = $this->peerTubeFile($item);
		if ($file === '') {
			return null;
		}

		$source = (string)($item['url'] ?? '');
		if ($source === '') {
			// an export from an instance that did not state it: the uuid is
			// what the archive keys everything else on, and is what makes a
			// second run of the same archive a no-op
			$uuid = (string)($item['uuid'] ?? '');
			if ($uuid === '') {
				return null;
			}
			$source = 'urn:peertube:' . $uuid;
		}

		$title = trim((string)($item['name'] ?? ''));
		$description = trim((string)($item['description'] ?? ''));

		// the title leads, because that is where this app keeps a video's name
		// and what `PeerTubeService` reads back out when it publishes one
		$text = trim($title . (($description === '') ? '' : "\n\n" . $description));
		if ($text === '') {
			$text = $title;
		}

		$published = strtotime(
			(string)($item['originallyPublishedAt'] ?? '') ?: (string)($item['publishedAt'] ?? '')
		);

		return [
			'source' => $source,
			'text' => $text,
			'published' => ($published === false || $published <= 0) ? time() : $published,
			'visibility' => $visibility,
			'sensitive' => (bool)($item['nsfw'] ?? false),
			'spoiler' => '',
			'language' => $this->peerTubeLabel($item['language'] ?? null),
			'replyTo' => '',
			'attachments' => [['url' => $file, 'name' => $title]],
			'hashtags' => $this->peerTubeTags($item),
			'video' => $this->peerTubeMeta($item, $title),
		];
	}

	/**
	 * Where the video's own file is inside the archive.
	 * `archiveFiles.videoFile` is relative to `peertube/`
	 * (`../files/videos/video-files/<uuid>.mp4`), so the `..` is resolved
	 * before the suffix match.
	 *
	 * @param array<string, mixed> $item
	 */
	private function peerTubeFile(array $item): string {
		$files = $item['archiveFiles'] ?? null;
		$path = is_array($files) ? trim((string)($files['videoFile'] ?? '')) : '';
		if ($path === '') {
			return '';
		}

		$parts = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				array_pop($parts);
				continue;
			}
			$parts[] = $segment;
		}

		return implode('/', $parts);
	}

	/**
	 * What this app keeps about a video, in the shape
	 * `PeerTubeService::videoMeta()` builds from the wire, so imported and
	 * federated videos are alike downstream, publishing included.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function peerTubeMeta(array $item, string $title): array {
		$meta = array_filter([
			'title' => $title,
			'category' => $this->peerTubeLabel($item['category'] ?? null),
			'licence' => $this->peerTubeLabel($item['licence'] ?? null),
			'language' => $this->peerTubeLabel($item['language'] ?? null),
			'support' => trim((string)($item['support'] ?? '')),
			'originally_published_at' => trim((string)($item['originallyPublishedAt'] ?? '')),
		], static fn (string $value): bool => $value !== '');

		$duration = (int)($item['duration'] ?? 0);
		if ($duration > 0) {
			$meta['duration'] = $duration;
		}

		// counters are not carried over: they count the old instance's viewers
		if (($item['downloadEnabled'] ?? true) === false) {
			$meta['download'] = false;
		}

		return $meta;
	}

	/**
	 * PeerTube states a category, a licence and a language as `{id, label}` in
	 * its API and as a bare id in parts of its export. The label is the only
	 * half that means anything off its own instance.
	 */
	private function peerTubeLabel(mixed $value): string {
		if (is_array($value)) {
			return trim((string)($value['label'] ?? ''));
		}

		return is_string($value) ? trim($value) : '';
	}

	/**
	 * @param array<string, mixed> $item
	 * @return string[]
	 */
	private function peerTubeTags(array $item): array {
		$tags = [];
		foreach ($this->listOf($item, 'tags') as $tag) {
			$tag = trim((string)$tag);
			if ($tag !== '') {
				$tags[] = $tag;
			}
		}

		return $tags;
	}

	/**
	 * The language a post was written in: the `contentMap` key, or Mastodon's
	 * `language`; otherwise the account's default applies.
	 *
	 * @param array<string, mixed> $item
	 */
	private function language(array $item): string {
		$language = (string)($item['language'] ?? '');
		if ($language !== '') {
			return $language;
		}

		$map = $item['contentMap'] ?? null;

		return (is_array($map) && $map !== []) ? (string)array_key_first($map) : '';
	}

	/**
	 * The audience an exported post went out to, from its addressing or the
	 * API's word. Anything unplaceable is direct, and therefore skipped.
	 *
	 * @param array<string, mixed> $item
	 */
	private function visibility(array $item): string {
		$word = (string)($item['visibility'] ?? '');
		if ($word !== '') {
			return Stream::visibilityFromClient($word);
		}

		$to = $this->addresses($item['to'] ?? []);
		$cc = $this->addresses($item['cc'] ?? []);

		if (in_array(ACore::CONTEXT_PUBLIC, $to, true)) {
			return Stream::TYPE_PUBLIC;
		}
		if (in_array(ACore::CONTEXT_PUBLIC, $cc, true)) {
			return Stream::TYPE_UNLISTED;
		}
		// addressed to a followers collection — whoever's, since the only
		// account being imported is the one importing
		foreach (array_merge($to, $cc) as $address) {
			if (str_ends_with($address, '/followers')) {
				return Stream::TYPE_FOLLOWERS;
			}
		}

		return Stream::TYPE_DIRECT;
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	private function addresses(mixed $value): array {
		if (is_string($value)) {
			return [$value];
		}
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_filter(array_map(
			static fn ($entry): string => is_string($entry) ? $entry : '',
			$value
		)));
	}

	/**
	 * One entry of an Instagram archive, as the same post every other reader
	 * produces. The caption is at the top for a carousel and on the media for
	 * a single item; the top one is read first.
	 *
	 * @param array<string, mixed> $item
	 * @param string $visibility the importing account's own default
	 * @return array<string, mixed>|null
	 */
	private function parseInstagram(array $item, string $visibility): ?array {
		$media = $this->listOf($item, 'media');
		$attachments = [];
		$oldest = 0;

		foreach ($media as $one) {
			if (!is_array($one)) {
				continue;
			}

			$uri = trim((string)($one['uri'] ?? ''));
			if ($uri === '') {
				continue;
			}

			$taken = (int)($one['creation_timestamp'] ?? 0);
			if ($taken > 0 && ($oldest === 0 || $taken < $oldest)) {
				$oldest = $taken;
			}

			$attachments[] = [
				'url' => $uri,
				// Instagram has no alt text of its own in the export; the
				// title on a single-picture post is the caption, not a
				// description of the picture, so nothing is claimed here
				'name' => '',
			];

			if (count($attachments) >= Stream::MAX_ATTACHMENTS) {
				break;
			}
		}

		$caption = $this->instagramText((string)($item['title'] ?? ''));
		if ($caption === '' && count($media) === 1 && is_array($media[0])) {
			$caption = $this->instagramText((string)($media[0]['title'] ?? ''));
		}

		if ($attachments === [] && $caption === '') {
			return null;
		}

		$published = (int)($item['creation_timestamp'] ?? 0);
		if ($published <= 0) {
			$published = $oldest;
		}

		return [
			'source' => $this->instagramId($attachments, $caption, $published),
			'text' => mb_substr($caption, 0, InstanceService::MAX_CHARACTERS),
			'published' => ($published > 0) ? $published : time(),
			'visibility' => $visibility,
			'sensitive' => false,
			'spoiler' => '',
			'language' => '',
			'replyTo' => '',
			'attachments' => $attachments,
			'hashtags' => $this->hashtagsIn($caption),
		];
	}

	/**
	 * An id for a post that has none, stable across re-runs of the same
	 * archive: derived from the post's files, or for a text-only post from
	 * its words and time. Deliberately not a URL, as nothing resolves it.
	 *
	 * @param array<int, array{url: string, name: string}> $attachments
	 */
	private function instagramId(array $attachments, string $caption, int $published): string {
		$seed = ($attachments === [])
			? $caption . '|' . $published : implode('|', array_column($attachments, 'url'));

		return 'instagram:' . hash('sha256', $seed);
	}

	/**
	 * Instagram's text, in the encoding it was written in. The exporter
	 * escapes each UTF-8 byte as a character (`né` becomes `nÃ©`), so each
	 * character is read back as its byte; only when the result is valid UTF-8,
	 * so a genuine `Ã` is left alone.
	 */
	private function instagramText(string $text): string {
		$text = trim($text);
		if ($text === '' || !preg_match('/[\x{0080}-\x{00FF}]/u', $text)) {
			return $text;
		}

		$bytes = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
		if (!is_string($bytes) || $bytes === '' || !mb_check_encoding($bytes, 'UTF-8')) {
			return $text;
		}

		// the conversion is only right if it turned something: a string of
		// plain letters converts to itself, and one that was already correct
		// UTF-8 comes back as bytes that are not
		return ($bytes === $text || mb_strlen($bytes, 'UTF-8') === 0) ? $text : $bytes;
	}

	/**
	 * The hashtags a caption spells, found as the linkifier finds them, since
	 * on Instagram they are only words in the text.
	 *
	 * @return string[]
	 */
	private function hashtagsIn(string $text): array {
		$names = [];
		if (preg_match_all('/(?:^|[^\w\/&])#([\w\x{00C0}-\x{024F}]{1,100})/u', $text, $matches) > 0) {
			foreach ($matches[1] as $name) {
				// a tag of digits alone is a number somebody wrote after a hash
				if ($name !== '' && !ctype_digit($name)) {
					$names[mb_strtolower($name)] = $name;
				}
			}
		}

		return array_values($names);
	}

	/**
	 * A post's words from the other server's markup. Only paragraphs and line
	 * breaks are kept, as posts here are stored as typed text.
	 */
	private function text(string $html): string {
		if ($html === '') {
			return '';
		}

		$text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
		$text = preg_replace('#</p\s*>#i', "\n\n", $text) ?? $text;
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

		return trim($text);
	}

	/**
	 * The hashtags an export names, so they are indexed here as they were
	 * there — a tag the text spells is found by the linkifier anyway, and a
	 * tag only the metadata knows would otherwise be lost.
	 *
	 * @param array<string, mixed> $item
	 * @return string[]
	 */
	private function hashtags(array $item): array {
		$names = [];
		foreach (array_merge($this->listOf($item, 'tag'), $this->listOf($item, 'tags')) as $tag) {
			if (!is_array($tag)) {
				continue;
			}
			$type = strtolower((string)($tag['type'] ?? 'hashtag'));
			if ($type !== 'hashtag') {
				continue;
			}
			$name = ltrim(trim((string)($tag['name'] ?? '')), '#');
			if ($name !== '') {
				$names[strtolower($name)] = $name;
			}
		}

		return array_values($names);
	}

	/**
	 * The pictures and videos an exported post carries.
	 *
	 * @param array<string, mixed> $item
	 * @return array<int, array{url: string, name: string}>
	 */
	private function attachments(array $item): array {
		$attachments = [];
		foreach (array_merge($this->listOf($item, 'attachment'), $this->listOf($item, 'media_attachments')) as $attachment) {
			if (!is_array($attachment)) {
				continue;
			}

			$url = (string)($attachment['url'] ?? $attachment['remote_url'] ?? '');
			if ($url === '') {
				continue;
			}

			$attachments[] = [
				'url' => $url,
				'name' => (string)($attachment['name'] ?? $attachment['description'] ?? ''),
			];

			if (count($attachments) >= Stream::MAX_ATTACHMENTS) {
				break;
			}
		}

		return $attachments;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<int, mixed>
	 */
	private function listOf(array $item, string $key): array {
		$value = $item[$key] ?? [];

		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * Writes one post via `StreamRequest::save()`, which stores the post, its
	 * recipients and tags in one transaction and queues no delivery.
	 *
	 * @param array<string, mixed> $post
	 * @param array<string, string> $known original id => local id_prim, for a reply's parent
	 * @param array<string, mixed> $tally
	 */
	private function write(
		Person $actor,
		array $post,
		array $known,
		?ZipArchive $zip,
		bool $fetchMedia,
		array &$tally,
	): Note {
		$note = new Note();
		$this->streamService->assignItem($note, $actor, $post['visibility']);
		$note->setAttributedTo($actor->getId());
		$note->setSpoilerText($post['spoiler']);
		$note->setSensitive($post['sensitive'] || $post['spoiler'] !== '');
		$note->setVisibility($post['visibility']);
		$note->setLanguage(Stream::normalizeLanguage($post['language']));

		// dated when written, not when imported, but never in the future, as
		// the date is the post's place in every timeline
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', min($post['published'], time())));
		$note->convertPublished();

		// a reply keeps its parent where the archive holds both: the thread is
		// the only structure in an export, and it is cheap to keep
		$parent = $known[$post['replyTo']] ?? '';
		if ($post['replyTo'] !== '' && $parent !== '') {
			$note->setInReplyTo($this->streamIdOf($parent));
		} elseif (($post['replyToId'] ?? '') !== '') {
			// a parent the reader found here already, somebody else's post
			$note->setInReplyTo((string)$post['replyToId']);
		}

		$this->streamService->addHashtags($note, $post['hashtags']);
		$note->setContent($this->linkifyService->toHtml($post['text'], $note->getTags()));

		// the video's title, duration, category and licence, read back when
		// it is published, so it leaves as the same `Video` it arrived as
		if (($post['video'] ?? []) !== []) {
			$note->setVideoMeta($post['video']);
		}

		$note->setSource(json_encode($note, JSON_UNESCAPED_SLASHES));

		$attachments = $this->store($actor, $note, $post, $zip, $fetchMedia, $tally);
		if ($attachments !== []) {
			$note->setAttachments($attachments);
		}

		$this->streamRequest->save($note);

		return $note;
	}

	/**
	 * The archive entry whose path ends with this one, or null. The index of
	 * entries is built once per import, since the prefix is whatever folder
	 * the archive was packed under.
	 */
	private function endingWith(ZipArchive $zip, string $path): ?string {
		if ($this->archiveIndex === null) {
			$this->archiveIndex = [];
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				if ($name !== '' && !str_ends_with($name, '/')) {
					$this->archiveIndex[] = $name;
				}
			}
		}

		$needle = '/' . ltrim($path, '/');
		foreach ($this->archiveIndex as $name) {
			if (str_ends_with($name, $needle)) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * The post an imported id names, as this app's own id.
	 */
	private function streamIdOf(string $prim): string {
		try {
			return $this->streamRequest->getStream($prim)->getId();
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * The files of one post, from the archive or fetched from the old server.
	 * Each goes through the ordinary upload path: metadata stripped, converted
	 * where needed, and held to this instance's size and type limits.
	 *
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $tally
	 * @return MediaAttachment[]
	 */
	private function store(
		Person $actor,
		Note $note,
		array $post,
		?ZipArchive $zip,
		bool $fetchMedia,
		array &$tally,
	): array {
		$public = in_array($post['visibility'], [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);
		$stored = [];

		foreach ($post['attachments'] as $attachment) {
			$temp = isset($attachment['path']) ? (string)$attachment['path'] : $this->fetch($attachment['url'], $zip, $fetchMedia);
			if ($temp === null) {
				// a picture that was asked for and could not be had failed; one
				// the reader declined to fetch was simply not fetched
				if ($this->wanted($attachment['url'], $zip, $fetchMedia)) {
					$tally['failed']++;
					self::noteFailure($tally, $post['source'], 'could not fetch ' . $attachment['url']);
				}
				continue;
			}

			try {
				$document = $this->documentService->storeLocalAttachment(
					$actor, $temp, $note->getId(), $attachment['name'], $public
				);
				$stored[] = $document->convertToMediaAttachment($this->urlGenerator, ACore::FORMAT_LOCAL);
				$tally['media']++;
			} catch (Throwable $e) {
				$this->logger->notice('could not store an imported attachment', [
					'url' => $attachment['url'], 'exception' => $e,
				]);
				$tally['failed']++;
				self::noteFailure($tally, $post['source'], $e->getMessage());
			} finally {
				@unlink($temp);
			}
		}

		return $stored;
	}

	/**
	 * One file, as a temporary path, or null where it cannot be had. A
	 * relative address is inside the archive; an absolute one is on the old
	 * server and fetched only if the person asked for that.
	 */
	/**
	 * Keeps a failure by the post it concerns, up to REPORT_FAILURES of them.
	 *
	 * @param array<string, mixed> $tally
	 */
	private static function noteFailure(array &$tally, string $source, string $reason): void {
		if (count($tally['failures']) < self::REPORT_FAILURES && !isset($tally['failures'][$source])) {
			$tally['failures'][$source] = $reason;
		}
	}

	/** Whether fetch() was going to try at all for this attachment. */
	private function wanted(string $url, ?ZipArchive $zip, bool $fetchMedia): bool {
		$isRemote = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');

		return $isRemote ? $fetchMedia : $zip !== null;
	}

	private function fetch(string $url, ?ZipArchive $zip, bool $fetchMedia): ?string {
		$isRemote = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');

		if (!$isRemote) {
			if ($zip === null) {
				return null;
			}

			return $this->fromArchive($zip, $url);
		}

		if (!$fetchMedia) {
			return null;
		}

		try {
			$content = $this->cacheDocumentService->retrieveContent($url);
		} catch (Throwable $e) {
			$this->logger->notice('could not fetch an imported attachment', [
				'url' => $url, 'exception' => $e->getMessage(),
			]);

			return null;
		}

		if ($content === '') {
			return null;
		}

		$temp = $this->tempManager->getTemporaryFile();
		if ($temp === false || file_put_contents($temp, $content) === false) {
			return null;
		}

		return $temp;
	}

	/**
	 * A file out of the archive, written where the upload path can read it.
	 * Its declared size is checked before it is expanded, against zip bombs.
	 */
	private function fromArchive(ZipArchive $zip, string $path): ?string {
		$candidates = [$path, 'social/' . $path, ltrim($path, '/')];

		// an archive unpacked into a folder of its own — which is how a
		// browser saves Instagram's, under the account name and the date —
		// names every file below it, and nothing above would match
		$nested = $this->endingWith($zip, $path);
		if ($nested !== null) {
			$candidates[] = $nested;
		}

		foreach ($candidates as $candidate) {
			$stat = $zip->statName($candidate);
			if ($stat === false) {
				continue;
			}

			if (($stat['size'] ?? 0) > self::MAX_FILE) {
				$this->logger->notice('an archived attachment is past the ceiling', ['path' => $candidate]);

				return null;
			}

			$content = $zip->getFromName($candidate);
			if ($content === false) {
				return null;
			}

			$temp = $this->tempManager->getTemporaryFile();
			if ($temp === false || file_put_contents($temp, $content) === false) {
				return null;
			}

			return $temp;
		}

		return null;
	}
}
