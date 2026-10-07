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
use OCA\Social\Db\StreamRequestBuilder;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `muted` on a status was hard-coded false, so a client could never offer
 * "Unmute conversation". It is the viewer's mute of the thread's root,
 * attached to every post of the thread as it is read.
 */
class MutedConversationFlagTest extends TestCase {
	private const BASE = 'https://cloud.example.org/mutedflag';
	private const READER = self::BASE . '/users/reader';
	private const OTHER = self::BASE . '/users/other';
	private const AUTHOR = 'https://remote.example/mutedflag/users/author';
	private const ROOT = self::BASE . '/notes/root';
	private const REPLY = self::BASE . '/notes/reply';
	private const DEEPER = self::BASE . '/notes/deeper';
	private const ELSEWHERE = self::BASE . '/notes/elsewhere';

	private ConversationsRequest $conversations;
	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->conversations = Server::get(ConversationsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();

		foreach ([self::READER, self::OTHER, self::AUTHOR] as $id) {
			$person = new Person();
			$person->setId($id)->setPreferredUsername(basename($id));
			$person->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST))
				->setInbox($id . '/inbox')->setOutbox($id . '/outbox')
				->setFollowers($id . '/followers')->setFollowing($id . '/following');
			$this->cacheActorsRequest->save($person);
		}
		$this->note(self::ROOT);
		$this->note(self::REPLY, self::ROOT);
		$this->note(self::DEEPER, self::REPLY);
		$this->note(self::ELSEWHERE);
	}

	protected function tearDown(): void {
		$this->cleanup();
		$this->streamRequest->resetViewer();
		StreamRequestBuilder::forgetMutedRoots();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ROOT, self::REPLY, self::DEEPER, self::ELSEWHERE] as $id) {
			$this->streamRequest->deleteById($id, Note::TYPE);
		}
		foreach ([self::READER, self::OTHER, self::AUTHOR] as $id) {
			$this->conversations->deleteRelatedId($id);
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	private function note(string $id, string $inReplyTo = ''): void {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>' . basename($id) . '</p>');
		if ($inReplyTo !== '') {
			$note->setInReplyTo($inReplyTo);
		}
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);
	}

	private function readAs(string $viewerId, string $postId): bool {
		$viewer = new Person();
		$viewer->setId($viewerId);
		StreamRequestBuilder::forgetMutedRoots();
		$this->streamRequest->setViewer($viewer);

		return $this->streamRequest->getStreamById($postId, true, ACore::FORMAT_LOCAL)->isMutedConversation();
	}

	public function testAMutedThreadIsMutedOnEveryPostOfItForTheViewerWhoMutedIt(): void {
		$this->conversations->setMuted(self::READER, self::ROOT, true);

		$this->assertTrue($this->readAs(self::READER, self::ROOT), 'the root itself');
		$this->assertTrue($this->readAs(self::READER, self::REPLY), 'a direct reply');
		$this->assertTrue($this->readAs(self::READER, self::DEEPER), 'a reply to the reply, found by walking up');
		$this->assertFalse($this->readAs(self::READER, self::ELSEWHERE), 'another thread');
		$this->assertFalse($this->readAs(self::OTHER, self::REPLY), 'somebody else did not mute it');
	}

	public function testUnmutingTakesTheFlagOffAgain(): void {
		$this->conversations->setMuted(self::READER, self::ROOT, true);
		$this->assertTrue($this->readAs(self::READER, self::DEEPER));

		$this->conversations->setMuted(self::READER, self::ROOT, false);
		$this->assertFalse($this->readAs(self::READER, self::DEEPER));
	}

	public function testTheFlagIsWhatTheStatusEntityCarriesAsMuted(): void {
		$this->conversations->setMuted(self::READER, self::ROOT, true);
		$viewer = new Person();
		$viewer->setId(self::READER);
		StreamRequestBuilder::forgetMutedRoots();
		$this->streamRequest->setViewer($viewer);

		$reply = $this->streamRequest->getStreamById(self::REPLY, true, ACore::FORMAT_LOCAL);

		$this->assertTrue($reply->exportAsLocal()['muted']);
	}

	/**
	 * Found on devel: the mute route reads the post, mutes, and answers with
	 * the post read again — through a memo filled before the mute, so the
	 * answer said `muted: false` and the menu flipped back.
	 */
	public function testAPostReadAgainAfterTheMuteInTheSameRequestIsMuted(): void {
		$viewer = new Person();
		$viewer->setId(self::READER);
		StreamRequestBuilder::forgetMutedRoots();
		$this->streamRequest->setViewer($viewer);
		$this->assertFalse($this->streamRequest->getStreamById(self::REPLY, true, ACore::FORMAT_LOCAL)->isMutedConversation());

		$this->conversations->setMuted(self::READER, self::ROOT, true);
		$this->assertTrue($this->streamRequest->getStreamById(self::REPLY, true, ACore::FORMAT_LOCAL)->isMutedConversation());

		// and again once the row exists, which is an update rather than an insert
		$this->conversations->setMuted(self::READER, self::ROOT, false);
		$this->assertFalse($this->streamRequest->getStreamById(self::REPLY, true, ACore::FORMAT_LOCAL)->isMutedConversation());
		$this->conversations->setMuted(self::READER, self::ROOT, true);
		$this->assertTrue($this->streamRequest->getStreamById(self::REPLY, true, ACore::FORMAT_LOCAL)->isMutedConversation());
	}

	public function testNobodyInParticularReadsNothingAsMuted(): void {
		$this->conversations->setMuted(self::READER, self::ROOT, true);
		$this->streamRequest->resetViewer();

		$this->assertFalse($this->streamRequest->getStreamById(self::REPLY, false, ACore::FORMAT_LOCAL)->isMutedConversation());
	}
}
