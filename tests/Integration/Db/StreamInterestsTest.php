<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The reads behind My interests: candidate posts by hashtag, their tags, and
 * the viewer-filtered fetch of the posts chosen.
 */
class StreamInterestsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/stream-interests';
	private const VIEWER = self::BASE . '/users/viewer';
	private const AUTHOR = 'https://remote.example/stream-interests/users/author';
	private const NOTES = ['jazz', 'jazz-german', 'climbing', 'own', 'private'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	/** @var array<string, Note> */
	private array $notes = [];

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
		foreach (self::NOTES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach ([self::VIEWER, self::AUTHOR] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
		$this->notes = [];
	}

	private function person(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@' . parse_url($id, PHP_URL_HOST))
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	/** @param string[] $hashtags */
	private function note(string $suffix, array $hashtags, string $author = self::AUTHOR, bool $public = true, string $language = '', int $ago = 0): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo($author);
		$note->setTo($public ? ACore::CONTEXT_PUBLIC : $author . '/followers');
		$note->setVisibility($public ? 'public' : 'private');
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setHashtags($hashtags);
		$note->setLanguage($language);
		$note->setPublishedTime(time() - $ago);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $ago));
		$this->streamRequest->save($note);

		return $this->notes[$suffix] = $note;
	}

	private function seed(): Person {
		$viewer = $this->person(self::VIEWER, 'si-viewer', true);
		$this->person(self::AUTHOR, 'si-author', false);
		$this->note('jazz', ['Jazz', 'music'], ago: 300);
		$this->note('jazz-german', ['jazz'], language: 'de', ago: 200);
		$this->note('climbing', ['climbing'], ago: 100);
		$this->note('own', ['jazz'], author: self::VIEWER);
		$this->note('private', ['jazz'], public: false);
		$this->streamRequest->setViewer($viewer);

		return $viewer;
	}

	/** @return string[] the suffixes of the candidate rows, in order */
	private function candidates(array $tags, array $exclude = [], array $languages = []): array {
		$bySuffix = [];
		foreach ($this->notes as $suffix => $note) {
			$bySuffix[(string)$note->getNid()] = $suffix;
		}

		return array_values(array_filter(array_map(
			fn (array $row) => $bySuffix[$row['nid']] ?? null,
			$this->streamRequest->interestCandidates($tags, '0', 100, $exclude, $languages)
		)));
	}

	public function testCandidatesAreOthersPostsCarryingOneOfTheTagsNewestFirst(): void {
		$this->seed();

		$this->assertSame(['jazz-german', 'jazz'], $this->candidates(['jazz']));
		$this->assertSame(['climbing', 'jazz-german', 'jazz'], $this->candidates(['jazz', 'climbing']));
		$this->assertSame([], $this->candidates([]));
	}

	public function testHiddenPostsAndOtherLanguagesAreLeftOut(): void {
		$this->seed();

		$this->assertSame(['jazz'], $this->candidates(['jazz'], [(string)$this->notes['jazz-german']->getNid()]));
		$this->assertSame(['jazz'], $this->candidates(['jazz'], [], ['en']));
		$this->assertSame(['jazz-german', 'jazz'], $this->candidates(['jazz'], [], ['de']));
	}

	public function testEveryTagOfEachPostIsReadLowered(): void {
		$this->seed();
		$jazz = md5(self::BASE . '/notes/jazz');

		$tags = $this->streamRequest->hashtagsOfStreams([$jazz, $jazz]);

		$this->assertEqualsCanonicalizing(['jazz', 'music'], $tags[$jazz] ?? []);
		$this->assertSame([], $this->streamRequest->hashtagsOfStreams([]));
	}

	public function testOnlyThePostsTheViewerMaySeeAreFetched(): void {
		$this->seed();
		$nids = array_map(fn (Note $n) => (string)$n->getNid(), $this->notes);

		$visible = $this->streamRequest->getVisibleByNids(array_merge(array_values($nids), ['not-a-nid']));

		$this->assertArrayHasKey($nids['jazz'], $visible);
		$this->assertArrayNotHasKey($nids['private'], $visible);
		$this->assertSame([], $this->streamRequest->getVisibleByNids(['x']));
	}
}
