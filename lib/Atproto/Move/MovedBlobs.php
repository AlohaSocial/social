<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Service\DocumentService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The blobs of a repository that moves here: which ones its records name,
 * which of those are not here yet, and copying one from the old PDS, byte
 * for byte. A move goes on past a missing one only when the person says so
 * (§13.1).
 */
class MovedBlobs {
	/** how many missing CIDs a move keeps to show */
	public const SHOWN = 50;
	/** how many of the records that lose a missing blob a move names to the person */
	public const NAMED = 20;
	/** how much of a post's text names it */
	private const EXCERPT = 120;
	/** the most bytes of blobs one move downloads (`progress.blob_bytes`), kept or not; past it a blob stays missing */
	public const BUDGET = 10_000_000_000;

	public function __construct(
		private PdsClient $pds,
		private AtprotoRepoRequest $repoRequest,
		private AtprotoBlobRequest $blobs,
		private DocumentService $documents,
		private ActorsRequest $actors,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every blob the repository's records name, with the first record that
	 * names it.
	 *
	 * @return array<string, string> CID => record path
	 */
	public function referenced(string $did): array {
		$referenced = [];
		$this->repoRequest->eachRecordBytes($did, static function (Cid $cid, string $bytes, string $path) use (&$referenced): void {
			try {
				$value = DagCbor::decode($bytes);
			} catch (Throwable) {
				return;
			}
			foreach (self::blobsIn($value) as $blob) {
				$referenced[$blob] ??= $path;
			}
		});

		return $referenced;
	}

	/**
	 * The blobs the records name that are not here, in CID order.
	 *
	 * @param array<string, string>|null $referenced what referenced() answered, when it is at hand
	 * @return list<string>
	 */
	public function missing(string $did, ?array $referenced = null): array {
		$missing = [];
		foreach (array_keys($referenced ?? $this->referenced($did)) as $cid) {
			if ($this->blobs->get($did, (string)$cid) === null) {
				$missing[] = (string)$cid;
			}
		}
		sort($missing, SORT_STRING);

		return $missing;
	}

	/**
	 * Whether the move may go on as far as its blobs go: none missing, or no
	 * more than the person chose to go on without. Otherwise it waits for
	 * their decision (`needs_decision`, code `blobs-missing`) with how many
	 * are missing and which, the first `SHOWN` of them. The counts here
	 * beside the ones copied are logged.
	 */
	public function complete(Move $move): bool {
		$referenced = $this->referenced($move->did);
		$missing = $this->missing($move->did, $referenced);
		$this->logger->info('Blobs of a Bluesky account moving here checked', [
			'did' => $move->did,
			'records' => $this->repoRequest->countRecords($move->did),
			'recordsCopied' => $move->progress['records'] ?? null,
			'expectedBlobs' => count($referenced),
			'missingBlobs' => count($missing),
		]);
		if ($missing === [] || count($missing) <= (int)($move->progress['missing_accepted'] ?? -1)) {
			unset($move->progress['missing'], $move->progress['missing_records']);

			return true;
		}
		$move->state = Move::NEEDS_DECISION;
		$move->error = count($missing) . ' blobs the posts name could not be copied';
		$paths = array_slice(array_values(array_unique(array_map(static fn (string $cid): string => $referenced[$cid], $missing))), 0, self::NAMED);
		$records = array_filter(array_map(function (string $path) use ($move): ?StoredRecord {
			[$collection, $rkey] = explode('/', $path, 2) + ['', ''];

			return $this->repoRequest->getRecord($move->did, $collection, $rkey);
		}, $paths));
		$move->progress = [
			'code' => 'blobs-missing',
			'missing' => ['count' => count($missing), 'cids' => array_slice($missing, 0, self::SHOWN)],
			'missing_records' => self::named($records),
		] + $move->progress;

		return false;
	}

	/**
	 * What the person is shown of records that lose a picture or video
	 * that did not arrive, before they go on without it: a post by its date,
	 * the start of its text and where it can be read now (the post here when
	 * it is one, else its address on Bluesky); any other record, the
	 * profile, by its collection alone.
	 *
	 * @param iterable<StoredRecord> $records
	 * @return list<array{collection: string, rkey: string, created_at: string, text: string, url: string}>
	 */
	public static function named(iterable $records): array {
		$named = [];
		foreach ($records as $record) {
			$value = [];
			try {
				$value = $record->value();
			} catch (Throwable) {
			}
			$post = $record->collection === BlueskyIds::POST;
			$text = $post && is_string($value['text'] ?? null) ? trim($value['text']) : '';
			$named[] = [
				'collection' => $record->collection,
				'rkey' => $record->rkey,
				'created_at' => is_string($value['createdAt'] ?? null) ? $value['createdAt'] : '',
				'text' => mb_strlen($text) > self::EXCERPT ? rtrim(mb_substr($text, 0, self::EXCERPT)) . '…' : $text,
				'url' => !$post ? '' : (str_starts_with($record->localId, 'https://') ? $record->localId : BlueskyIds::postId($record->did, $record->rkey)),
			];
			if (count($named) >= self::NAMED) {
				break;
			}
		}

		return $named;
	}

	/** Whether the move spent its BUDGET, or $bytes more would take it past. */
	public static function overBudget(Move $move, int $bytes = 0): bool {
		$spent = (int)($move->progress['blob_bytes'] ?? 0);

		return $spent >= self::BUDGET || $spent + $bytes > self::BUDGET;
	}

	/** Counts blob bytes downloaded against the move's BUDGET. */
	public static function spend(Move $move, int $bytes): void {
		$move->progress['blob_bytes'] = (int)($move->progress['blob_bytes'] ?? 0) + $bytes;
	}

	/**
	 * Copies one blob from the old PDS, checked against its CID; nothing
	 * is kept of one that does not arrive or does not match, or once the
	 * move has spent its BUDGET.
	 *
	 * @return int the bytes kept, 0 when nothing was
	 * @throws MoveFailure when the old PDS cannot be reached
	 */
	public function copy(Move $move, string $cid): int {
		if (self::overBudget($move)) {
			$this->logger->info('Blob not copied: the move downloaded as many bytes as one may', ['did' => $move->did, 'cid' => $cid]);

			return 0;
		}
		try {
			$blob = $this->pds->bytes($move->pds, 'com.atproto.sync.getBlob', ['did' => $move->did, 'cid' => $cid], ClientXrpc::MAX_UPLOAD);
		} catch (MoveFailure $e) {
			if ($e->errorCode !== 'blob-too-large') {
				throw $e;
			}
			$this->logger->info('Blob not copied: larger than any taken here', ['did' => $move->did, 'cid' => $cid]);
			self::spend($move, ClientXrpc::MAX_UPLOAD);

			return 0;
		}
		self::spend($move, $blob['size']);
		try {
			if ($blob['status'] !== 200 || !Cid::forRawDigest((string)hash_file('sha256', $blob['path'], true))->equals(Cid::parse($cid))) {
				$this->logger->info('Blob not copied', ['did' => $move->did, 'cid' => $cid, 'status' => $blob['status']]);

				return 0;
			}
			$document = $this->documents->storeAsIs($this->actors->getFromUserId($move->userId), $blob['path']);
		} finally {
			if ($blob['path'] !== '') {
				@unlink($blob['path']);
			}
		}
		if ($this->blobs->put(new BlobRef($move->did, Cid::parse($cid), $document->getId(), BlobRef::servableType($document->getMimeType()), $blob['size']))) {
			$this->repoRequest->addBlobBytes($move->did, $blob['size']);
		}

		return $blob['size'];
	}

	/**
	 * @return list<string> the CIDs of the blobs a record value names
	 */
	private static function blobsIn(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		if (($value['$type'] ?? null) === 'blob' && ($value['ref'] ?? null) instanceof Cid) {
			return [$value['ref']->toString()];
		}
		$found = [];
		foreach ($value as $item) {
			array_push($found, ...self::blobsIn($item));
		}

		return $found;
	}
}
