<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use OCA\Social\AP;
use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Atproto\AtprotoLink;
use OCA\Social\Model\Atproto\AtprotoWatch;
use OCA\Social\Model\Details;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\SignatureService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts read out of AT-Proto and stored the way a delivery stores them.
 *
 * The records come from the PDS that holds them — one `listRecords` page
 * per pass, newest first — and go in through `ImportService`, which is the
 * inbox's own entry point. Nothing about a Bluesky post is special to the
 * rest of the app afterwards: it has a `Create` behind it, a note with
 * recipients, a row in `social_stream_dest`, a notification for whoever
 * follows the author. What differs is only where the document was read from
 * and the ids it carries.
 *
 * Two entry points, one store:
 *
 * - `sync()` walks a watched actor's repository and stops as soon as a page
 *   brings nothing new — which is what makes an idle actor cost one request
 *   per interval rather than a request per post.
 * - `fetch()` takes one record by the local id of it, for the queue a reply
 *   writes when its parent is not here yet. That is how a thread spanning
 *   two accounts, only one of which is watched, completes itself.
 */
class AtprotoIngress {
	/** The only collection whose records this app stores as posts. */
	public const COLLECTION = 'app.bsky.feed.post';

	/** records per `listRecords` page */
	private const PAGE = 25;

	/** pages one pass may read: how far a first sight of an old account gets */
	private const MAX_PAGES = 4;

	public function __construct(
		private AtprotoClient $client,
		private AtprotoIdentity $identity,
		private RecordMapper $mapper,
		private ImportService $importService,
		private StreamRequest $streamRequest,
		private AtprotoRequest $atprotoRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads what a watched actor published since the last pass, and says how
	 * many posts were stored.
	 *
	 * The cursor only moves over pages that were read: a PDS that stops
	 * answering leaves it where it was, so the pass is repeated rather than
	 * the records skipped. A record that cannot be stored — a document this
	 * app cannot model, a note whose author row would not be written — is
	 * logged and passed over, because refusing the rest of an actor's
	 * repository for one bad record would stop every later post behind it.
	 * A repository that stops answering is the one thing that does stop the
	 * pass: it is thrown, the cursor stays where it was, and the records
	 * behind the failure are the next pass's to read rather than lost.
	 *
	 * @throws AtprotoException the repository itself could not be read
	 */
	public function sync(AtprotoWatch $watch): int {
		$did = $watch->getDid();
		$pds = $this->identity->pdsOf($did);

		// the author first: a post is addressed to them, and the timeline
		// joins their followers collection through the cached actor row
		$this->identity->actor($did, $watch->getHandle(), null, $pds);

		$cursor = $watch->getCursor();
		$imported = 0;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$params = [
				'repo' => $did,
				'collection' => self::COLLECTION,
				'limit' => self::PAGE,
			];
			if ($cursor !== '') {
				$params['cursor'] = $cursor;
			}

			$answer = $this->client->get('com.atproto.repo.listRecords', $params, $pds);
			$records = array_values(array_filter(
				(array)($answer['records'] ?? []),
				static fn ($entry): bool => is_array($entry)
			));
			if ($records === []) {
				break;
			}

			$fresh = 0;
			foreach ($records as $entry) {
				if ($this->store($entry, $did, $pds, 0) !== null) {
					$fresh++;
					$imported++;
				}
			}

			$next = (string)($answer['cursor'] ?? '');
			if ($next === '' || $next === $cursor) {
				$cursor = $next !== '' ? $next : $cursor;
				break;
			}

			$cursor = $next;

			// pages come newest first, so a page of posts this instance
			// already holds is the past: everything after it was read in an
			// earlier pass, and the next one starts here instead of here
			if ($fresh === 0 || count($records) < self::PAGE) {
				break;
			}
		}

		// where the next pass picks up, written back however this one ended:
		// a page that came short, a page that brought nothing new, an actor
		// whose repository is empty
		$watch->setCursor($cursor);

		return $imported;
	}

	/**
	 * One record, by this instance's id for it: what a queued parent or
	 * quoted post asks for when the id is one of ours.
	 *
	 * @param int $depth how far up the thread this sits, as the queue counts it
	 *
	 * @throws InvalidResourceException the id is not a post this app stores
	 * @throws AtprotoException the record could not be read
	 */
	public function fetch(string $id, int $depth = 0): Stream {
		$did = $this->identity->didOf($id);
		$rkey = $this->identity->rkeyOf($id);
		$collection = $this->identity->collectionOf($id);
		if ($did === '' || $rkey === '' || $collection !== self::COLLECTION) {
			throw new InvalidResourceException('not a post stored here: ' . $id);
		}

		$stored = $this->stored($id);
		if ($stored !== null) {
			return $stored;
		}

		$pds = $this->identity->pdsOf($did);
		$answer = $this->client->get('com.atproto.repo.getRecord', [
			'repo' => $did,
			'collection' => $collection,
			'rkey' => $rkey,
		], $pds);

		$entry = [
			'uri' => 'at://' . $did . '/' . $collection . '/' . $rkey,
			'cid' => (string)($answer['cid'] ?? ''),
			'value' => is_array($answer['value'] ?? null) ? (array)$answer['value'] : [],
		];

		$note = $this->store($entry, $did, $pds, $depth);
		if ($note === null) {
			throw new InvalidResourceException('the record could not be stored: ' . $id);
		}

		return $note;
	}

	/**
	 * One `listRecords` entry stored, or `null` when it was stored already
	 * or could not be.
	 *
	 * @param array<string, mixed> $entry a `listRecords`/`getRecord` entry
	 */
	private function store(array $entry, string $did, string $pds, int $depth): ?Stream {
		$value = $entry['value'] ?? null;
		if (!is_array($value)) {
			return null;
		}

		$rkey = $this->rkeyOf((string)($entry['uri'] ?? ''));
		if ($rkey === '') {
			return null;
		}

		$id = $this->identity->recordId($did, self::COLLECTION, $rkey);
		if ($this->stored($id) !== null) {
			return null;
		}

		$activity = AP::instance()->getItemFromData(
			$this->mapper->create($did, $rkey, $value, $pds)
		);
		$activity->setOrigin(
			(string)parse_url($id, PHP_URL_HOST),
			SignatureService::ORIGIN_REQUEST,
			time()
		);

		$object = $activity->getObject();
		if ($object instanceof Stream && $depth > 0) {
			// the queue stamps what it fetches, and this record was fetched
			// the same way: one level further up than the post that asked
			$object->setDetailInt(Details::ANCESTOR_DEPTH, $depth);
		}

		try {
			// the author first, the way the queue's own fetch does it: a post
			// is addressed to them, and a post whose author row is not here
			// yet cannot be saved at all. An actor already held costs one
			// lookup here and no request — `actor()` reads the row before it
			// reads the profile.
			$this->identity->actor($did, '', null, $pds);
			$this->importService->parseIncomingRequest($activity);
		} catch (AtprotoException $e) {
			if ($e->isTransient()) {
				// the repository stopped answering: the pass stops with it,
				// cursor where it was, and the next one starts from here
				throw $e;
			}

			$this->logger->warning('a post read from AT-Proto was refused', [
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return null;
		} catch (Throwable $e) {
			$this->logger->warning('a post read from AT-Proto was not taken in', [
				'id' => $id,
				'exception' => $e,
			]);

			return null;
		}

		$stored = $this->stored($id);
		if ($stored !== null) {
			$this->atprotoRequest->saveLink(
				(new AtprotoLink())
					->setLocalId($stored->getId())
					->setAtUri((string)($entry['uri'] ?? ''))
					->setCid((string)($entry['cid'] ?? ''))
					->setDid($did)
					->setCollection(self::COLLECTION)
					->setRkey($rkey)
					// The repository entry has no canonical handle. A future profile
					// refresh may fill it; identity and writes use the DID, never this
					// display value.
					->setHandle('')
			);
		}

		return $stored;
	}

	/**
	 * The stored copy of a post, or `null` when there is none.
	 */
	private function stored(string $id): ?Stream {
		try {
			return $this->streamRequest->getStreamById($id);
		} catch (StreamNotFoundException $e) {
			return null;
		}
	}

	/**
	 * The rkey inside an `at://` uri, or `''`.
	 */
	private function rkeyOf(string $uri): string {
		if (!str_starts_with($uri, 'at://')) {
			return '';
		}

		$parts = explode('/', substr($uri, 5));

		return count($parts) === 3 ? (string)$parts[2] : '';
	}
}
