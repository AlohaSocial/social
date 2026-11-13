<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\StreamActionService;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Whole posts read with no `DISTINCT`, and the client timelines read without
 * the `source` a `source_digest` stands in for, against the real tables.
 */
class NarrowStreamReadTest extends TestCase {
	private const BASE = 'https://cloud.example.org/narrow-read';
	private const VIEWER = self::BASE . '/users/viewer';
	private const AUTHOR = 'https://remote.test/users/narrow-author';
	private const EMOJI = ['type' => 'Emoji', 'name' => ':blob:', 'icon' => ['url' => 'https://remote.test/blob.png']];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		Server::get(StreamActionsRequest::class)->deleteByActor(self::VIEWER);
		foreach (['emoji', 'legacy', 'quoted', 'quote'] as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix);
		}
		foreach ([self::VIEWER, self::AUTHOR] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	private function person(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setPublicKey('-----BEGIN PUBLIC KEY-----narrow')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function note(string $suffix, string $quote = ''): Note {
		$id = self::BASE . '/notes/' . $suffix;
		$note = new Note();
		$note->setId($id)
			->setAttributedTo(self::AUTHOR)
			->setTo(ACore::CONTEXT_PUBLIC)
			->setCcArray([self::AUTHOR . '/followers', self::VIEWER])
			->setContent('<p>' . $suffix . ' :blob:</p>')
			->setPublishedTime(time())
			->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$note->setQuote($quote);
		$note->setSource((string)json_encode([
			'id' => $id, 'type' => 'Note', 'tag' => [self::EMOJI], 'replies' => ['totalItems' => 5],
		]));
		$this->streamRequest->save($note);

		return $note;
	}

	/** @return Stream[] the viewer's bookmarks, read as a client reads them */
	private function bookmarks(Person $viewer): array {
		$this->streamRequest->setViewer($viewer);
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL)->setProbe(ProbeOptions::BOOKMARKS)->setLimit(10);

		return $this->streamRequest->getTimeline($options);
	}

	public function testATimelineRowIsReadWithoutItsSourceAndLosesNothing(): void {
		$viewer = $this->person(self::VIEWER, 'narrowviewer', true);
		$this->person(self::AUTHOR, 'narrowauthor', false);
		$note = $this->note('emoji');
		Server::get(StreamActionService::class)->setActionBool(self::VIEWER, $note->getId(), StreamAction::BOOKMARKED, true);

		$page = $this->bookmarks($viewer);

		$this->assertCount(1, $page);
		$this->assertTrue($page[0]->isSourceOmitted());
		$this->assertSame('', $page[0]->getSource());
		$this->assertSame(['blob'], array_column($page[0]->getEmojis(), 'shortcode'));
		$this->assertSame(5, $page[0]->getDetailInt('replies'));
		$this->assertSame('', $page[0]->getActor()->getPublicKey(), 'the author\'s key is not read for a client');

		$full = $this->streamRequest->getStreamById($note->getId());
		$this->assertFalse($full->isSourceOmitted());
		$this->assertNotSame('', $full->getSource(), 'a read by id is whole');
	}

	public function testARowNotDigestedYetIsReadOutOfItsSource(): void {
		$viewer = $this->person(self::VIEWER, 'narrowviewer', true);
		$this->person(self::AUTHOR, 'narrowauthor', false);
		$note = $this->note('legacy');
		Server::get(StreamActionService::class)->setActionBool(self::VIEWER, $note->getId(), StreamAction::BOOKMARKED, true);

		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_STREAM)
			->set('source_digest', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($note->getId()))));
		$qb->executeStatement();

		$page = $this->bookmarks($viewer);

		$this->assertCount(1, $page);
		$this->assertFalse($page[0]->isSourceOmitted());
		$this->assertSame(['blob'], array_column($page[0]->getEmojis(), 'shortcode'));
	}

	/**
	 * The quote is public, addressed to the viewer and by somebody the viewer
	 * may read through more than one recipient row: without a DISTINCT, the
	 * page is chosen by nid and the post is listed once.
	 */
	public function testAPostMatchingSeveralRecipientRowsIsListedOnce(): void {
		$viewer = $this->person(self::VIEWER, 'narrowviewer', true);
		$this->person(self::AUTHOR, 'narrowauthor', false);
		$quoted = $this->note('quoted');
		$this->note('quote', $quoted->getId());

		$this->streamRequest->setViewer($viewer);
		$quotes = $this->streamRequest->getQuotesOf($quoted->getId());

		$this->assertSame([self::BASE . '/notes/quote'], array_map(static fn (Stream $s): string => $s->getId(), $quotes));
	}
}
