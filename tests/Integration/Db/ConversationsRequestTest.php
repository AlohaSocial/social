<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Direct-message threads: walking a thread from its root, and the read,
 * hidden and muted state each reader keeps per thread.
 */
class ConversationsRequestTest extends TestCase {
	private const BASE = 'https://cloud.example.org/conversations';
	private const READER = self::BASE . '/users/reader';
	private const OUTSIDER = self::BASE . '/users/outsider';
	private const AUTHOR = 'https://remote.example/conversations/users/author';
	private const ROOT = self::BASE . '/notes/root';
	private const REPLY = self::BASE . '/notes/reply';
	private const DEEPER = self::BASE . '/notes/deeper';
	private const PUBLIC = self::BASE . '/notes/public-reply';
	private const CHAIN = self::BASE . '/notes/chain-';
	/** a post whose id is no address, and so has no prim */
	private const UNADDRESSED = 'at://did:plc:conversations/app.bsky.feed.post/1';

	private ConversationsRequest $conversations;
	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->conversations = Server::get(ConversationsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ROOT, self::REPLY, self::DEEPER, self::PUBLIC, ...array_map(static fn (int $n): string => self::CHAIN . $n, range(1, 8))] as $id) {
			$this->streamRequest->deleteById($id, Note::TYPE);
		}
		// by its id: a post with no prim cannot be deleted by one
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('social_stream')
			->where($qb->expr()->eq('id', $qb->createNamedParameter(self::UNADDRESSED)))
			->executeStatement();
		foreach ([self::READER, self::OUTSIDER, self::AUTHOR] as $id) {
			$this->conversations->deleteRelatedId($id);
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	private function person(string $id, bool $local): void {
		$username = basename($id);
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@' . parse_url($id, PHP_URL_HOST))
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);
	}

	private function note(string $id, string $to, string $inReplyTo = ''): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo($to);
		$note->setContent('<p>' . basename($id) . '</p>');
		if ($inReplyTo !== '') {
			$note->setInReplyTo($inReplyTo);
		}
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	private function seedThread(): void {
		$this->person(self::READER, true);
		$this->person(self::OUTSIDER, true);
		$this->person(self::AUTHOR, false);
		$this->note(self::ROOT, self::READER);
		$this->note(self::REPLY, self::READER, self::ROOT);
		$this->note(self::DEEPER, self::READER, self::REPLY);
		$this->note(self::PUBLIC, ACore::CONTEXT_PUBLIC, self::REPLY);
	}

	public function testAThreadIsWalkedFromItsRootThroughEveryLevel(): void {
		$this->seedThread();

		$thread = $this->conversations->getThread(self::ROOT);

		$this->assertEqualsCanonicalizing([self::ROOT, self::REPLY, self::DEEPER, self::PUBLIC], array_keys($thread));
		$this->assertSame(self::REPLY, $thread[self::DEEPER]['inReplyTo']);
		$this->assertSame([], $this->conversations->getThread('not-a-url'));
	}

	public function testTheAuthorsOfPostsAreReadInOneStatement(): void {
		$this->seedThread();

		$authors = $this->conversations->getAuthorsOf([self::ROOT, self::REPLY, self::BASE . '/notes/missing', 'not-a-url']);
		ksort($authors);

		$this->assertSame([self::REPLY => self::AUTHOR, self::ROOT => self::AUTHOR], $authors);
		$this->assertSame([], $this->conversations->getAuthorsOf([]));
	}

	public function testAReaderSeesOnlyTheMessagesAddressedToThem(): void {
		$this->seedThread();

		$this->assertEqualsCanonicalizing(
			[self::ROOT, self::REPLY, self::DEEPER],
			array_keys($this->conversations->getThreadFor(self::READER, self::ROOT))
		);
		$this->assertSame([], $this->conversations->getThreadFor(self::OUTSIDER, self::ROOT));
	}

	public function testTheRootIsFoundFromAnyReply(): void {
		$this->seedThread();

		$this->assertSame(self::ROOT, $this->conversations->rootOf(self::DEEPER));
		$this->assertSame(self::ROOT, $this->conversations->rootOf(self::ROOT));
		$this->assertSame(self::ROOT, $this->conversations->rootOf(self::DEEPER, 1), 'a direct message stored its root');

		$this->forgetRoots();
		$this->assertSame(self::ROOT, $this->conversations->rootOf(self::DEEPER));
		$this->assertSame(self::REPLY, $this->conversations->rootOf(self::DEEPER, 1), 'the walk stops at its depth');
	}

	/** Forgets every stored root, as the rows stored before the column were, so the walk is what answers. */
	private function forgetRoots(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('social_stream')
			->set('root_prim', $qb->createNamedParameter(null))
			->where($qb->expr()->like('id', $qb->createNamedParameter(self::BASE . '/notes/%')))
			->orWhere($qb->expr()->eq('id', $qb->createNamedParameter(self::UNADDRESSED)));
		$qb->executeStatement();
	}

	/** A chain of eight replies, `chain-1` its root. */
	private function seedChain(): void {
		$this->person(self::READER, true);
		$this->person(self::AUTHOR, false);
		$this->note(self::CHAIN . 1, self::READER);
		for ($n = 2; $n <= 8; $n++) {
			$this->note(self::CHAIN . $n, self::READER, self::CHAIN . ($n - 1));
		}
	}

	public function testAncestorsAreReadSeveralLevelsInOneStatement(): void {
		$this->seedChain();

		$run = QueryCounter::record(fn (): array => $this->conversations->getAncestorLinks([self::CHAIN . 8, self::CHAIN . 2], 5));

		$this->assertSame(1, $run->count(), $run->summary());
		$this->assertEqualsCanonicalizing(
			array_map(static fn (int $n): string => self::CHAIN . $n, [8, 7, 6, 5, 4, 2, 1]),
			array_keys($run->result())
		);
		$this->assertSame(self::CHAIN . 6, $run->result()[self::CHAIN . 7]['inReplyTo']);
	}

	public function testTheRootIsWalkedToSeveralLevelsAStatement(): void {
		$this->seedChain();
		$this->forgetRoots();

		$run = QueryCounter::record(fn (): string => $this->conversations->rootOf(self::CHAIN . 8));

		$this->assertSame(self::CHAIN . 1, $run->result());
		$this->assertSame(2, $run->count(), 'eight levels in two statements: ' . $run->summary());
		$this->assertSame(self::CHAIN . 2, $this->conversations->rootOf(self::CHAIN . 8, 6), 'the walk stops at its depth');
	}

	/** A post whose parent is no address is a root, whatever else has no address either. */
	public function testAParentThatIsNoAddressIsNoPost(): void {
		$this->person(self::READER, true);
		$this->person(self::AUTHOR, false);
		$this->note(self::UNADDRESSED, self::READER);
		$this->note(self::ROOT, self::READER, 'at://did:plc:conversations/app.bsky.feed.post/2');

		$this->assertSame([self::ROOT], array_keys($this->conversations->getAncestorLinks([self::ROOT], 5)));
		// stored with itself as its root, its parent not being here
		$this->assertSame(self::ROOT, $this->conversations->rootOf(self::ROOT));
		$this->forgetRoots();
		$this->assertSame('at://did:plc:conversations/app.bsky.feed.post/2', $this->conversations->rootOf(self::ROOT));
	}

	public function testMarkersOnlyMoveForward(): void {
		$this->conversations->markRead(self::READER, self::ROOT, '200');
		$this->conversations->markRead(self::READER, self::ROOT, '100');
		$this->conversations->markHidden(self::READER, self::ROOT, '150');

		$marker = $this->conversations->getMarkers(self::READER, [self::ROOT])[self::ROOT] ?? null;
		$this->assertNotNull($marker);
		$this->assertSame('200', (string)$marker['readNid']);
		$this->assertSame('150', (string)$marker['hiddenNid']);
		$this->assertSame([], $this->conversations->getMarkers(self::OUTSIDER, [self::ROOT]));
		$this->assertSame([], $this->conversations->getMarkers(self::READER, []));
	}

	public function testAThreadIsMutedAndUnmutedPerReader(): void {
		$this->assertFalse($this->conversations->isMuted(self::READER, self::ROOT));

		$this->conversations->setMuted(self::READER, self::ROOT, true);
		$this->assertTrue($this->conversations->isMuted(self::READER, self::ROOT));
		$this->assertFalse($this->conversations->isMuted(self::OUTSIDER, self::ROOT));
		$this->assertSame([self::ROOT], $this->conversations->getMutedRoots(self::READER));

		$this->conversations->markRead(self::READER, self::ROOT, '300');
		$this->conversations->setMuted(self::READER, self::ROOT, false);
		$this->assertFalse($this->conversations->isMuted(self::READER, self::ROOT));
		$this->assertSame('300', (string)$this->conversations->getMarkers(self::READER, [self::ROOT])[self::ROOT]['readNid'], 'unmuting keeps the read marker');
	}
}
