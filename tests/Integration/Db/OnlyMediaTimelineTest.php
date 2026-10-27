<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\ConfigService;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `only_media` against the real database.
 *
 * The option had been parsed off the request since the hashtag timeline gained
 * it and was never put to a query, so asking for media-only quietly returned
 * everything. It is the whole of the Photos view, so what it filters on is
 * worth asserting where the SQL actually runs.
 *
 * "No media" has three spellings in the `attachments` column — NULL, `''` and
 * `'[]'` — and a predicate that caught only the commonest would look right on
 * a seeded database and wrong on a real one.
 */
class OnlyMediaTimelineTest extends TestCase {
	use MediaUnderText;

	private const BASE = 'https://cloud.example.org/onlymedia';
	private const VIEWER = self::BASE . '/users/viewer';
	private const AUTHOR = 'https://remote.example/onlymedia/users/author';
	private const AUTHOR_FOLLOWERS = self::AUTHOR . '/followers';

	private const SUFFIXES = ['with-media', 'empty-array', 'empty-string', 'two-pictures', 'own-photo'];

	private StreamRequest $streamRequest;
	private string $fastPathBefore = '';
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->fastPathBefore = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_DEST_NID_FILLED);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->removeMediaUnderText();
		$this->useHomeFastPath($this->fastPathBefore === '1');
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach ([self::VIEWER, self::AUTHOR] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function cachedPerson(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . ($local ? '@cloud.example.org' : '@remote.example'))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function attachment(string $id): MediaAttachment {
		$media = new MediaAttachment();
		$media->setId($id);
		$media->setType('image');
		$media->setUrl(self::BASE . '/media/' . $id . '.jpg');
		$media->setPreviewUrl(self::BASE . '/media/' . $id . '.jpg');
		$media->setDescription('a picture');

		return $media;
	}

	/** @param MediaAttachment[] $attachments */
	private function note(string $suffix, array $attachments, ?Person $author = null): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo($author?->getId() ?? self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$author?->getFollowers() ?? self::AUTHOR_FOLLOWERS]);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setAttachments($attachments);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/** @return string[] the ids the viewer's home timeline returns */
	private function home(Person $viewer, bool $onlyMedia): array {
		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::HOME)
			->setLimit(50)
			->setOnlyMedia($onlyMedia);

		return array_map(
			static fn ($stream): string => $stream->getId(),
			$this->streamRequest->getTimeline($options)
		);
	}

	private function seedFollowedAuthor(): Person {
		$viewer = $this->cachedPerson(self::VIEWER, 'omviewer', true);
		$this->cachedPerson(self::AUTHOR, 'omauthor', false);

		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/1');
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId(self::AUTHOR);
		$follow->setFollowId(self::AUTHOR_FOLLOWERS);
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);

		return $viewer;
	}

	public function testTheHomeTimelineStillCarriesEverythingWhenNotAskedToFilter(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('with-media', [$this->attachment('m1')]);
		$this->note('empty-array', []);

		$home = $this->home($viewer, false);

		$this->assertContains(self::BASE . '/notes/with-media', $home);
		$this->assertContains(self::BASE . '/notes/empty-array', $home);
	}

	public function testOnlyMediaKeepsThePostsThatCarryPictures(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('with-media', [$this->attachment('m1')]);
		$this->note('two-pictures', [$this->attachment('m2'), $this->attachment('m3')]);
		$this->note('empty-array', []);

		$home = $this->home($viewer, true);

		$this->assertContains(self::BASE . '/notes/with-media', $home);
		$this->assertContains(self::BASE . '/notes/two-pictures', $home);
	}

	/**
	 * A post saved with no attachments stores `[]`, and one written before the
	 * column existed holds `''`. Both mean the same thing to a reader.
	 */
	public function testOnlyMediaDropsEverySpellingOfNoMedia(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('with-media', [$this->attachment('m1')]);
		$this->note('empty-array', []);

		$home = $this->home($viewer, true);

		$this->assertNotContains(self::BASE . '/notes/empty-array', $home);
		$this->assertSame(
			[self::BASE . '/notes/with-media'],
			array_values(array_filter(
				$home,
				static fn (string $id): bool => str_starts_with($id, self::BASE . '/notes/')
			)),
			'a post with no pictures reached the photo timeline'
		);
	}

	/** A post carrying two pictures is still one post. */
	public function testAPostWithSeveralPicturesAppearsOnce(): void {
		$viewer = $this->seedFollowedAuthor();
		$both = $this->note('two-pictures', [$this->attachment('m2'), $this->attachment('m3')]);

		$home = $this->home($viewer, true);

		$this->assertSame(1, count(array_keys($home, $both->getId(), true)));
	}

	public function testOwnPicturesRemainInTheFilteredHomeTimeline(): void {
		$viewer = $this->seedFollowedAuthor();
		$own = $this->note('own-photo', [$this->attachment('own1')], $viewer);

		$this->assertContains($own->getId(), $this->home($viewer, true));
	}

	/**
	 * The Photos view — `only_media` with `media_type=image`, what the web
	 * app sends — on home and public, with three pictures under sixty newer
	 * posts of text. The fast path reads the newest rows and narrows after,
	 * so it must hand a narrowed home timeline to the join instead.
	 */
	#[DataProvider('homeFastPath')]
	public function testPhotosFindThreePicturesUnderSixtyNewerPostsOfText(bool $fast): void {
		$viewer = $this->seedFollowedAuthor();
		$this->useHomeFastPath($fast);
		$photos = $this->seedMediaUnderText(self::BASE, $this->cacheActorsRequest->getFromId(self::AUTHOR))['photos'];
		$this->streamRequest->setViewer($viewer);

		foreach ([ProbeOptions::HOME, ProbeOptions::PUBLIC] as $probe) {
			$this->assertFoundUnderText(
				self::BASE, $photos,
				static function (ProbeOptions $options) use ($probe): void {
					$options->setProbe($probe)->setOnlyMedia(true)->setMediaType('image');
				},
				$probe . ' Photos'
			);
		}
	}
}
