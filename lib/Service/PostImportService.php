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
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\ITempManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Bringing an account's own posts over from the server it wrote them on.
 *
 * What moving to another Fediverse server has never carried is the posts. A
 * `Move` takes the followers; an export takes a file. This reads that file and
 * writes the posts here — as **new local posts of the importing account**,
 * dated when they were written, with their pictures.
 *
 * Three rules decide everything else, and each of them is load-bearing:
 *
 * **Nothing is federated.** Not one delivery is queued. Re-publishing somebody's
 * five years of posts would put five years of posts into the timeline of every
 * person who follows them, on every server, in one afternoon. The rows are
 * written and the queue is never touched — the same write-the-row,
 * federate-nothing discipline `SocialMigrator::importRelations()` uses for the
 * follow graph.
 *
 * **The ids are ours.** A post's id belongs to the server it was written on: an
 * id from somewhere else served from here would be a second server claiming
 * another's URI, and nothing would resolve it. So each post is minted locally
 * and the original id is remembered in `social_import_post` — which is what
 * makes a second run of the same archive a no-op, and what lets a reply find
 * the post it answers.
 *
 * **Only what the account wrote.** Boosts are somebody else's post and are
 * skipped. Direct messages are skipped too: their recipients are accounts on
 * the old server, and a direct message with nobody in it is a post nobody —
 * the author included — can find.
 *
 * Four shapes are read, because four are what the networks hand out:
 *
 *  - this app's own archive (`social/outbox.json` in the zip, with the files
 *    under `media_attachments/`),
 *  - Mastodon's and GoToSocial's (`outbox.json` at the root of the zip, same
 *    layout — theirs is the layout ours copied),
 *  - an `outbox.json` on its own, and
 *  - Pixelfed's `pixelfed-statuses.json`, which is its API's shape and carries
 *    **URLs rather than files**: those pictures are fetched from the old
 *    server, which therefore has to still be running.
 *
 * And a fifth that *is* a Fediverse export but is not a timeline: **PeerTube's**
 * (`/api/v1/users/me/exports`), read from `peertube/videos.json`. Its own JSON
 * rather than the `activity-pub/outbox.json` sitting beside it, because the
 * ActivityPub half names each video's file by its address on the old server
 * while the PeerTube half names the copy that is **inside the archive** — so
 * one import needs the old server to still be running and the other does not.
 * It also states the privacy as a number rather than leaving it to be read out
 * of an audience, and carries the title, the tags, the category and the
 * licence, which is most of what a video is.
 *
 * And a sixth that is not a Fediverse export at all: **Instagram's** "Download
 * your information" archive, which is the way most people arrive at Pixelfed.
 * It is read from `content/posts_*.json` and the reels beside them, with the
 * pictures out of the archive's own `media/` folder — nothing is fetched,
 * because there is no server left that would answer us for them.
 *
 * Instagram's archive is missing three things every other export has, and each
 * is answered rather than guessed at:
 *
 *  - **No ids.** One is derived from the file a post carries and the moment it
 *    was posted, so a second run of the same archive is still a no-op.
 *  - **No visibility.** An Instagram post was visible to whoever could see the
 *    account, which is not a sentence this app can translate. The importing
 *    account's **own default** is used — what their next post would get — and
 *    the person is told that is what happened.
 *  - **No hashtags as data.** They are words in the caption, so they are read
 *    out of it, which is what makes them findable here.
 *
 * Only the JSON export can be read. Instagram also offers HTML, which is a
 * rendering of the same data with the data taken out.
 */
class PostImportService {
	/** How many posts one run writes at most. */
	public const MAX_POSTS = 2000;

	/** The two kinds of export, which are parsed by two different readers. */
	private const FORMAT_ACTIVITYPUB = 'activitypub';
	private const FORMAT_INSTAGRAM = 'instagram';
	private const FORMAT_PEERTUBE = 'peertube';

	/** Where an archive keeps the posts, ours first. */
	private const OUTBOX_PATHS = ['social/outbox.json', 'outbox.json'];

	/**
	 * Where PeerTube keeps the videos, and the key it wraps them in.
	 *
	 * Not `activity-pub/outbox.json`, which is in the same archive: see the
	 * class comment for why the richer half is the one worth reading.
	 */
	private const PEERTUBE_PATH = 'peertube/videos.json';

	/**
	 * PeerTube's `VideoPrivacy`, as far as it maps onto an audience here.
	 *
	 * 3 (private), 4 (internal) and 5 (password-protected) are deliberately
	 * absent: each is a video its author decided not to publish, and an
	 * importer that quietly made one public would be undoing that decision.
	 * Unlisted becomes unlisted, which is the same promise on both sides.
	 */
	private const PEERTUBE_PRIVACY = [
		1 => Stream::TYPE_PUBLIC,
		2 => Stream::TYPE_UNLISTED,
	];

	/**
	 * Where Instagram keeps them.
	 *
	 * Two layouts, because Instagram moved the folder and did not move the
	 * archives people already had: the files sit under
	 * `your_instagram_activity/content/` in an archive taken since 2023 and
	 * under `content/` in one taken before that. The export splits at a size
	 * rather than at a date, so `posts_1.json`, `posts_2.json` and the rest are
	 * all read.
	 */
	private const INSTAGRAM_DIRS = ['your_instagram_activity/content/', 'content/'];

	/**
	 * The files in those folders that hold posts, and the key each wraps them
	 * in — `posts_*.json` is a bare array, the others an object with one key.
	 *
	 * Deliberately not here: `stories.json`, which is a day of somebody's life
	 * that was meant to end; `archived_posts.json`, which is what they took
	 * down on purpose; and `recently_deleted_content.json`, which they deleted.
	 * An importer that quietly republished any of the three would be worse than
	 * one that imported nothing.
	 */
	private const INSTAGRAM_FILES = [
		'reels.json' => 'ig_reels_media',
		'igtv_videos.json' => 'ig_igtv_media',
		'other_content.json' => 'ig_other_media',
	];

	/** How large a file inside an archive may be before it is left alone. */
	private const MAX_FILE = 200 * 1024 * 1024;

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
	 * Brings **one** video over, by its address.
	 *
	 * PeerTube has this and people use it: you have a video on an instance you
	 * are leaving, or on one you only ever used to publish, and you want it
	 * here. An export is the right tool for a channel and a heavy one for a
	 * single video — and somebody who has lost their account on the old server
	 * cannot take an export at all, while the video is still there to be
	 * fetched.
	 *
	 * The same three rules as an archive import: **nothing is federated**, the
	 * **id is ours** with the original remembered in `social_import_post` so a
	 * second attempt is a no-op, and it is written as a **new local post of the
	 * importing account**.
	 *
	 * What it will not do:
	 *
	 * - **A video hosted here.** Bringing a neighbour's post over as your own
	 *   is not an import, and this is the one case the server can actually
	 *   tell.
	 * - **A document that is not a video.** This is not a way to copy a post.
	 * - **A video whose address it was not given.** The document has to name
	 *   the address it was fetched from, the same evidence
	 *   `SearchService::resolveStatus()` requires, so a redirect cannot
	 *   substitute one video for another.
	 *
	 * What it cannot check is whether the video is **yours**. Neither can
	 * PeerTube's own importer, and neither can the archive import above — an
	 * archive is a file somebody uploaded. What stands in for it is the same
	 * thing that stands in for it there: it is one deliberate act, it is rate
	 * limited, and what it produces is an ordinary post of the account that
	 * asked for it, which moderation and reporting reach like any other.
	 *
	 * @return array<string, mixed> the same tally an archive import answers with
	 * @throws InvalidResourceException with a sentence saying what was wrong
	 */
	public function importVideo(Person $actor, string $url, bool $fetchMedia = true): array {
		$url = trim($url);
		if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
			throw new InvalidResourceException('that is not a video address');
		}

		$cloud = rtrim($this->configService->getCloudUrl(), '/');
		if ($cloud !== '' && str_starts_with($url, $cloud)) {
			throw new InvalidResourceException(
				'that video is already on this server — an import is for bringing one over from another'
			);
		}

		try {
			$data = $this->curlService->retrieveObject($url);
		} catch (Throwable $e) {
			throw new InvalidResourceException('that address could not be read: ' . $e->getMessage());
		}

		$post = $this->parseFetchedVideo($data, $url);
		if ($post === null) {
			throw new InvalidResourceException(
				'that address is not a video this app can bring over'
			);
		}

		$tally = [
			'imported' => 0, 'skipped' => 0, 'already' => 0,
			'media' => 0, 'failed' => 0, 'total' => 1, 'capped' => false,
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
	 * One fetched `Video` document, in the shape every other reader produces.
	 *
	 * The file is the playable link out of `url`, which is an **absolute**
	 * address — so it is fetched from the server that holds it, the same way
	 * an export that named only addresses is. There is no archive here to take
	 * it out of.
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
			// whatever its audience was there, it is this account's own post
			// here and is published as the thing everybody can see. An
			// unlisted video brought over as public would be a decision the
			// person did not make, so the *object's* own audience is read
			// first and only the two that mean something here are kept.
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
	 * The best file link a `Video` offers.
	 *
	 * An HLS playlist is deliberately passed over: what this wants is a file
	 * to store, and a playlist is a list of a few hundred segments on somebody
	 * else's server — importing it would store a text file and call it a
	 * video.
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
	 * @return array{imported: int, skipped: int, already: int, media: int, failed: int, total: int, capped: bool}
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
				'media' => 0, 'failed' => 0, 'total' => count($items), 'capped' => false,
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
	 * Writes the posts of an ActivityPub collection already in hand: the pages
	 * of an account's outbox, fetched from the server it is still on, rather
	 * than an archive it exported. The same parsing and the same writes as
	 * `import()`, so what is brought over and what is left out is one rule.
	 * Uncapped, because what calls this is a background run.
	 *
	 * @param array<int, mixed> $items the activities or objects, as the collection lists them
	 * @param callable(int, int): void|null $progress
	 * @return array{imported: int, skipped: int, already: int, media: int, failed: int, total: int, capped: bool}
	 * @psalm-suppress InvalidReturnType the tally is built by reference through the writers
	 */
	public function importItems(Person $actor, array $items, bool $fetchMedia = true, ?callable $progress = null): array {
		$tally = [
			'imported' => 0, 'skipped' => 0, 'already' => 0,
			'media' => 0, 'failed' => 0, 'total' => count($items), 'capped' => false,
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
			throw new InvalidResourceException('there is no such file');
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
			// PeerTube first, because its archive also holds an
			// `activity-pub/outbox.json` that the loop below would find — and
			// that half names each video's file on the old server, where this
			// one names the copy inside the archive
			$peertube = $this->peerTubeItems($zip);
			if ($peertube !== []) {
				// PeerTube's export is offered with and without the video
				// files. Without them the JSON is a catalogue: every entry
				// names a file that is not in the archive, so the run would
				// report "nothing imported" about an archive that is perfectly
				// valid and simply not the one to ask for.
				if ($this->peerTubeHasNoFiles($zip)) {
					throw new InvalidResourceException(
						'this PeerTube export was taken without its video files, so there is'
						. ' nothing to bring over — ask for the export again with the videos included'
					);
				}

				return ['format' => self::FORMAT_PEERTUBE, 'items' => $peertube];
			}

			foreach (self::OUTBOX_PATHS as $candidate) {
				$found = $zip->getFromName($candidate);
				if ($found !== false) {
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

				throw new InvalidResourceException($this->whyNotAnExport($zip));
			}
		} else {
			$contents = file_get_contents($path);
			if ($contents === false) {
				throw new InvalidResourceException('the file could not be read');
			}
		}

		$decoded = json_decode($contents, true);
		if (!is_array($decoded)) {
			throw new InvalidResourceException('this file is not the JSON an export is written in');
		}

		// an OrderedCollection, ours and Mastodon's; a bare array, Pixelfed's
		$items = $decoded['orderedItems'] ?? $decoded['items'] ?? $decoded['data'] ?? $decoded;
		if (!is_array($items)) {
			throw new InvalidResourceException('this export names no posts');
		}

		return ['format' => self::FORMAT_ACTIVITYPUB, 'items' => array_values($items)];
	}

	/**
	 * The videos a PeerTube export names, or `[]` when this is not one.
	 *
	 * Looked for by suffix rather than by exact name, like every other file in
	 * an archive here: a zip saved by a browser and re-packed by a person is
	 * nested under a folder of its own, and nothing above it would match.
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

		$contents = $zip->getFromName($name);
		if ($contents === false) {
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
	 * Everything an Instagram archive holds that is a post.
	 *
	 * Read whole rather than by a known list of names: the export splits
	 * `posts_1.json`, `posts_2.json` … at a size nobody can predict, and an
	 * archive of ten years has a dozen of them. The entries of the reels and
	 * IGTV files are the same shape and go in the same list.
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

			$contents = $zip->getFromIndex($i);
			if ($contents === false) {
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
			// at the root, or under the folder a browser unpacked the archive
			// into — which is named for the account and the day and is
			// therefore not something that can be listed here
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
	 * Why an archive with no outbox and no Instagram posts in it is not an
	 * export this can read.
	 *
	 * Instagram's HTML download has the same folders and the same file names
	 * with `.html` on the end, and it is the option people pick because it is
	 * the one they can open. Being told "ask for JSON" is the difference
	 * between a two-minute fix and giving up.
	 */
	private function whyNotAnExport(ZipArchive $zip): string {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (str_contains($name, 'content/posts_') && str_ends_with($name, '.html')) {
				return 'this is Instagram\'s HTML download, which holds the pages and not the posts'
					. ' — ask Instagram for the same export in JSON and import that';
			}
		}

		return 'this archive has no outbox.json — it is not an export of an account\'s posts';
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
			// a direct message's recipients are accounts on the old server: a
			// copy here would be addressed to nobody, and a post addressed to
			// nobody is one even its author cannot find
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
	 * One video out of a PeerTube export.
	 *
	 * The shape is the same one every other reader produces, plus a `video`
	 * key: a video has a title, a running time, a category and a licence, and
	 * an import that dropped all of that would turn a video page into a post
	 * with a rectangle in it.
	 *
	 * Three things are refused rather than translated:
	 *
	 * - **A private, internal or password-protected video.** Each is one its
	 *   author decided not to publish; an importer that made it public would
	 *   be undoing that decision, and there is no audience here that means
	 *   "the people who had the password".
	 * - **A live.** There is no recording to bring over unless one was saved,
	 *   in which case the saved replay is a video of its own and is exported
	 *   as one.
	 * - **A video with no file in the archive.** The export is asked for
	 *   `withVideoFiles`, and one taken without them is a catalogue: importing
	 *   it would write a page per video with nothing to play, which is worse
	 *   than importing nothing.
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
	 *
	 * `archiveFiles.videoFile` is written relative to `peertube/`, so it
	 * arrives as `../files/videos/video-files/<uuid>.mp4`; the `..` is
	 * resolved here rather than left for the suffix match, which would
	 * otherwise be looking for a path that begins with a segment no entry has.
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
	 * What this app keeps about a video, out of what PeerTube exported.
	 *
	 * The same shape `PeerTubeService::videoMeta()` builds from the wire, so
	 * an imported video and a federated one are the same kind of thing to
	 * everything downstream — including the publisher, which reads it back to
	 * make the `Video` this instance sends out.
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

		// the counters are **not** carried over. They are numbers about the
		// old instance's readers, and a post here that arrived with four
		// thousand views would be claiming four thousand people had watched it
		// on this server.
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
	 * What language a post was written in, as the export says.
	 *
	 * ActivityPub carries it as the key of `contentMap` and Mastodon's API as
	 * a `language`; a post that says neither is left to the account's own
	 * default, which is what `Stream::normalizeLanguage()` returning nothing
	 * means downstream.
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
	 * What audience an exported post went out to.
	 *
	 * An ActivityPub post says so by who it is addressed to; an API export
	 * says so in a word. Anything this app cannot place is treated as direct —
	 * the most restrictive reading — and therefore left alone, rather than
	 * guessed into the public timeline.
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
	 * here produces.
	 *
	 * The caption is in one of two places and the archive does not say which:
	 * a post of several pictures carries it at the top, a post of one carries
	 * it on the picture. Both are read, the top one first, because a carousel
	 * whose first picture happens to have a title would otherwise be published
	 * under the wrong words.
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
	 * An id for a post that has none.
	 *
	 * It has to be the same on a second run of the same archive — that is what
	 * stops a re-import writing everything twice — and different for two posts
	 * that happen to share a caption. The file a post carries is what
	 * distinguishes it; a caption-only post has only its words and the second
	 * it was posted, which together are enough.
	 *
	 * Not a URL, and deliberately not one: nothing resolves it, and an id that
	 * looked like an address would invite something to try.
	 *
	 * @param array<int, array{url: string, name: string}> $attachments
	 */
	private function instagramId(array $attachments, string $caption, int $published): string {
		$seed = ($attachments === [])
			? $caption . '|' . $published : implode('|', array_column($attachments, 'url'));

		return 'instagram:' . hash('sha256', $seed);
	}

	/**
	 * Instagram's text, in the encoding it was written in.
	 *
	 * Every caption in the archive is mojibake: the exporter writes UTF-8 and
	 * escapes each **byte** as if it were a character, so `né` arrives as
	 * `nÃ©` and an emoji as four accented letters. Reading each character back
	 * as the byte it stood for undoes it exactly.
	 *
	 * Only when the result is valid UTF-8 and the input was the kind of string
	 * that can be one: a caption somebody genuinely wrote with a `Ã` in it is
	 * left alone, because converting it would be the same bug pointed the
	 * other way.
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
	 * The hashtags a caption spells.
	 *
	 * On Instagram a hashtag is a word in the text and nothing else, so unless
	 * they are read out of it an imported post is not findable by any of the
	 * tags its author chose. The same set the linkifier would find, so the
	 * stored tags and the rendered links agree.
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
	 * A post's words, from the markup the other server wrote them in.
	 *
	 * The markup is not kept: this app renders its own from the text, and a
	 * post is stored as what was typed. Paragraphs and line breaks are the
	 * only formatting a post here has, so they are the only ones carried.
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
	 * Writes one post, and nothing else.
	 *
	 * `StreamRequest::save()` rather than the delivery path: it writes the
	 * post, its recipients and its tags in one transaction and queues no
	 * request. That is the whole difference between importing a post and
	 * publishing one.
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

		// when it was written, not when it was imported: an archive that
		// arrived as today's posts would be a timeline of somebody's whole
		// history, in one minute, in the wrong order
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $post['published']));
		$note->convertPublished();

		// a reply keeps its parent where the archive holds both: the thread is
		// the only structure in an export, and it is cheap to keep
		$parent = $known[$post['replyTo']] ?? '';
		if ($post['replyTo'] !== '' && $parent !== '') {
			$note->setInReplyTo($this->streamIdOf($parent));
		}

		$this->streamService->addHashtags($note, $post['hashtags']);
		$note->setContent($this->linkifyService->toHtml($post['text'], $note->getTags()));

		// what the author said the video is: its title, how long it runs, its
		// category and its licence. Kept because it is most of what a video
		// *is*, and because this app reads it back out when it publishes one —
		// an imported video therefore leaves here as the same `Video` it
		// arrived as, rather than as a post with a rectangle in it.
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
	 * The entry of the archive whose path ends with this one, or null.
	 *
	 * The index is walked rather than guessed at because the prefix is
	 * whatever folder the archive was packed under, and it is built once per
	 * import: an archive of ten years of pictures is a few thousand entries
	 * and this would otherwise be a scan per attachment.
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
	 * The files of one post: out of the archive where it has them, off the old
	 * server where the export only wrote their addresses.
	 *
	 * Every file goes through the ordinary upload path, so an imported picture
	 * is stripped of its metadata, converted where a browser could not draw
	 * it, and held to the same size and type this instance accepts from its
	 * own people.
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
			$temp = $this->fetch($attachment['url'], $zip, $fetchMedia);
			if ($temp === null) {
				$tally['failed']++;
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
			} finally {
				@unlink($temp);
			}
		}

		return $stored;
	}

	/**
	 * One file, as a temporary path, or null where it cannot be had.
	 *
	 * A relative address is a file inside the archive — which is how both this
	 * app's export and Mastodon's name theirs. An absolute one is on the
	 * server the account is leaving, and is only fetched if the person asked
	 * for that: it means telling the old server that the import is happening,
	 * and it only works while that server is still up.
	 */
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
	 *
	 * The entry is read by name and its size is checked first: a zip says how
	 * large a member is before it is expanded, and an archive that claims a
	 * small file and expands to a large one is the oldest trick there is.
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
