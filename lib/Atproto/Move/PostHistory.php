<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\PostImportService;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The posts of a Bluesky account that moved here (§13.1, step 6) become
 * the account's posts in this app's timelines: through the import path,
 * dated when they were written, a reply under its parent when that is one
 * of them too, the pictures and the video from the blobs held here.
 * Nothing is published — not to the Fediverse, which the import path never
 * does, and not to Bluesky, where each post is already: every Social post
 * is tied to the record it came from, so likes and replies there reach it
 * here, and the publisher leaves an imported post's record as it came.
 *
 * A reply to somebody else's post hangs off it here too, and the account's
 * latest likes and reposts become likes and boosts here, each tied to its
 * record so taking it back here takes it back there. Somebody else's post
 * is fetched from the AppView when it is not here, for at most FETCHES of
 * them in one run; the rest of the replies stand alone, the rest of the
 * likes and reposts stay on Bluesky only.
 */
class PostHistory {
	private const PAGE = 100;
	/** how many likes, and how many reposts, become actions here: the latest */
	public const ACTIONS = 200;
	/** how many posts that are not here are fetched in one run */
	public const FETCHES = 300;
	/** the labels a Bluesky app sets on a post that should be behind a warning */
	private const SENSITIVE = ['porn', 'sexual', 'nudity', 'graphic-media'];

	public function __construct(
		private RepositoryService $repositories,
		private AtprotoRepoRequest $repoRequest,
		private AtprotoBlobRequest $blobs,
		private PictureService $pictures,
		private PostImportService $imports,
		private ImportedPostsRequest $imported,
		private StreamRequest $streams,
		private ITempManager $tempManager,
		private LocalRecordResolver $local,
		private PostStore $postStore,
		private LikeService $likes,
		private BoostService $boosts,
		private LoggerInterface $logger,
	) {
	}

	/** how many more posts this run may fetch */
	private int $fetchesLeft = self::FETCHES;

	/**
	 * Brings over every post of the repository that is not a Social post
	 * yet; running it again picks up only what is left.
	 *
	 * @return int how many posts are in the timeline now that were not before
	 */
	public function import(Person $actor, string $did): int {
		$this->fetchesLeft = self::FETCHES;
		$parsed = [];
		$rkeys = [];
		$files = [];
		$cursor = '';
		try {
			do {
				$page = $this->repositories->listRecords($did, RecordMapper::POST, self::PAGE, $cursor);
				foreach ($page as $record) {
					if ($record->localId !== '') {
						continue;
					}
					$source = 'at://' . $did . '/' . RecordMapper::POST . '/' . $record->rkey;
					$post = $this->parse($did, $source, $record->bytes, $files);
					if ($post !== null) {
						$parsed[] = $post;
						$rkeys[$source] = $record->rkey;
					}
				}
				$cursor = count($page) === self::PAGE ? end($page)->rkey : '';
			} while ($cursor !== '');

			$tally = $this->imports->importParsed($actor, $parsed);
		} finally {
			// a post that was here already never took its files
			foreach ($files as $file) {
				@unlink($file);
			}
		}

		foreach ($this->imported->knownAmong($actor->getId(), array_keys($rkeys)) as $source => $prim) {
			try {
				$this->repoRequest->setLocalId($did, RecordMapper::POST, $rkeys[$source], $this->streams->getStream($prim)->getId());
			} catch (Throwable $e) {
				$this->logger->warning('Imported post not tied to its record', ['did' => $did, 'source' => $source, 'exception' => $e]);
			}
		}
		if ($tally['failed'] > 0) {
			$this->logger->info('Some posts of a Bluesky account that moved here were not imported', ['did' => $did, 'failures' => $tally['failures']]);
		}

		return $tally['imported'];
	}

	/**
	 * The account's latest likes and reposts, as likes and boosts here.
	 *
	 * @return array{likes: int, reposts: int} how many there are here now that were not before
	 */
	public function importActions(Person $actor, string $did): array {
		return [
			'likes' => $this->actions($actor, $did, RecordMapper::LIKE),
			'reposts' => $this->actions($actor, $did, RecordMapper::REPOST),
		];
	}

	private function actions(Person $actor, string $did, string $collection): int {
		$made = 0;
		$seen = 0;
		$cursor = '';
		do {
			$page = $this->repositories->listRecords($did, $collection, self::PAGE, $cursor);
			foreach ($page as $record) {
				if (++$seen > self::ACTIONS) {
					return $made;
				}
				if ($record->localId !== '') {
					continue;
				}
				try {
					$value = DagCbor::decode($record->bytes);
					$subject = is_array($value) ? (string)($value['subject']['uri'] ?? '') : '';
					$postId = $subject === '' ? '' : $this->postHere($subject);
					if ($postId === '') {
						continue;
					}
					$when = (string)($value['createdAt'] ?? '');
					$when = strtotime($when) > 0 ? $when : gmdate('c');
					$action = $collection === RecordMapper::LIKE
						? $this->likes->recordWithoutSending($actor, $postId, $when)
						: $this->boosts->recordWithoutSending($actor, $postId, $when);
					$this->repoRequest->setLocalId($did, $collection, $record->rkey, $action->getId());
					$made++;
				} catch (Throwable $e) {
					$this->logger->info('Moved ' . $collection . ' not taken over', ['did' => $did, 'rkey' => $record->rkey, 'exception' => $e]);
				}
			}
			$cursor = count($page) === self::PAGE ? end($page)->rkey : '';
		} while ($cursor !== '');

		return $made;
	}

	/**
	 * The post here a Bluesky post URI names: one of this server's, or a
	 * Bluesky post, fetched from the AppView while the run may; '' when it
	 * cannot be had.
	 */
	private function postHere(string $uri): string {
		$postId = $this->local->postId($uri);
		if ($postId === '' || !BlueskyIds::isPostId($postId) || $this->postStore->isKnown($postId)) {
			return $postId;
		}
		if ($this->fetchesLeft <= 0) {
			return '';
		}
		$this->fetchesLeft--;

		return $this->postStore->storeByUri($uri) && $this->postStore->isKnown($postId) ? $postId : '';
	}

	/**
	 * One post record in the shape the import path writes, or null for one
	 * with nothing to show.
	 *
	 * @param string[] $files the temporary files made, for the caller to remove
	 * @return array<string, mixed>|null
	 */
	private function parse(string $did, string $source, string $bytes, array &$files): ?array {
		try {
			$record = DagCbor::decode($bytes);
		} catch (Throwable) {
			return null;
		}
		if (!is_array($record)) {
			return null;
		}
		$text = WriteService::fullText((string)($record['text'] ?? ''), $record['facets'] ?? []);
		$attachments = $this->attachments($did, is_array($record['embed'] ?? null) ? $record['embed'] : [], $files);
		if (trim($text) === '' && $attachments === []) {
			return null;
		}
		$published = strtotime((string)($record['createdAt'] ?? ''));
		$langs = $record['langs'] ?? [];
		$labels = is_array($record['labels']['values'] ?? null) ? array_column($record['labels']['values'], 'val') : [];
		$parent = (string)($record['reply']['parent']['uri'] ?? '');

		return [
			'source' => $source,
			'text' => $text,
			'published' => ($published === false || $published <= 0) ? time() : $published,
			'visibility' => Stream::TYPE_PUBLIC,
			'sensitive' => array_intersect($labels, self::SENSITIVE) !== [],
			'spoiler' => '',
			'language' => is_array($langs) && is_string($langs[0] ?? null) && Syntax::isLanguage($langs[0]) ? $langs[0] : '',
			// a reply to one of the account's own posts hangs off it as it is
			// imported; to anybody else's, off that post, fetched when needed
			'replyTo' => str_starts_with($parent, 'at://' . $did . '/') ? $parent : '',
			'replyToId' => $parent !== '' && !str_starts_with($parent, 'at://' . $did . '/') ? $this->postHere($parent) : '',
			'attachments' => $attachments,
			'hashtags' => self::hashtags($record['facets'] ?? []),
		];
	}

	/**
	 * The pictures, or the video, a post embeds, each as a temporary file of
	 * the blob held here; a blob that is not here is left out.
	 *
	 * @param string[] $files
	 * @return list<array{url: string, name: string, path: string}>
	 */
	private function attachments(string $did, array $embed, array &$files): array {
		if (($embed['$type'] ?? '') === 'app.bsky.embed.recordWithMedia') {
			$embed = is_array($embed['media'] ?? null) ? $embed['media'] : [];
		}
		$media = match ((string)($embed['$type'] ?? '')) {
			'app.bsky.embed.images' => array_map(static fn ($image): array => [is_array($image) ? ($image['image'] ?? null) : null, is_array($image) ? (string)($image['alt'] ?? '') : ''], is_array($embed['images'] ?? null) ? $embed['images'] : []),
			'app.bsky.embed.video' => [[$embed['video'] ?? null, (string)($embed['alt'] ?? '')]],
			default => [],
		};
		$attachments = [];
		foreach ($media as [$blob, $alt]) {
			$cid = is_array($blob) && ($blob['ref'] ?? null) instanceof Cid ? $blob['ref']->toString() : '';
			$stored = $cid === '' ? null : $this->blobs->get($did, $cid);
			if ($stored === null) {
				continue;
			}
			try {
				$bytes = $this->pictures->read($stored);
			} catch (Throwable $e) {
				$this->logger->info('Blob of a moved post not read', ['did' => $did, 'cid' => $cid, 'exception' => $e]);
				continue;
			}
			$path = $this->tempManager->getTemporaryFile();
			if ($path === false || file_put_contents($path, $bytes) === false) {
				continue;
			}
			$files[] = $path;
			$attachments[] = ['url' => 'at://' . $did . '/blob/' . $cid, 'name' => $alt, 'path' => $path];
		}

		return $attachments;
	}

	/**
	 * @return list<string> the hashtags the facets name
	 */
	private static function hashtags(mixed $facets): array {
		$names = [];
		foreach (is_array($facets) ? $facets : [] as $facet) {
			foreach (is_array($facet['features'] ?? null) ? $facet['features'] : [] as $feature) {
				if (($feature['$type'] ?? '') === 'app.bsky.richtext.facet#tag' && is_string($feature['tag'] ?? null) && $feature['tag'] !== '') {
					$names[strtolower($feature['tag'])] = $feature['tag'];
				}
			}
		}

		return array_values($names);
	}
}
