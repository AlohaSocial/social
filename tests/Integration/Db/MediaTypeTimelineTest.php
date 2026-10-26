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
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\ConfigService;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `media_type` against the real database.
 *
 * It is what tells a profile's Photos tab from its Videos tab, and it is a
 * `LIKE` on a JSON column rather than a comparison against one — which is
 * exactly the kind of predicate that looks right in a unit test with a doubled
 * query builder and matches the wrong rows where the SQL actually runs.
 */
class MediaTypeTimelineTest extends TestCase {
	use MediaUnderText;

	private const BASE = 'https://cloud.example.org/mediatype';
	private const AUTHOR = self::BASE . '/users/author';

	private const SUFFIXES = ['picture', 'film', 'sound', 'words', 'both'];

	private StreamRequest $streamRequest;
	private string $fastPathBefore = '';
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->fastPathBefore = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_DEST_NID_FILLED);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
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
		$this->cacheActorsRequest->deleteCacheById(self::AUTHOR);
	}

	private function author(): Person {
		$person = new Person();
		$person->setId(self::AUTHOR)->setPreferredUsername('mtauthor');
		$person->setAccount('mtauthor@cloud.example.org')
			->setFollowers(self::AUTHOR . '/followers')
			->setFollowing(self::AUTHOR . '/following')
			->setInbox(self::AUTHOR . '/inbox')
			->setOutbox(self::AUTHOR . '/outbox')
			->setLocal(true);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function attachment(string $id, string $type): MediaAttachment {
		$media = new MediaAttachment();
		$media->setId($id);
		$media->setType($type);
		$media->setUrl(self::BASE . '/media/' . $id);
		$media->setPreviewUrl(self::BASE . '/media/' . $id);
		$media->setDescription('an attachment');

		return $media;
	}

	/** @param MediaAttachment[] $attachments */
	private function note(string $suffix, array $attachments, string $content = ''): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>' . ($content === '' ? $suffix : $content) . '</p>');
		$note->setAttachments($attachments);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/** @return string[] the ids of the author's posts, as the profile asks for them */
	private function profile(string $mediaType, bool $onlyMedia = false): array {
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::ACCOUNT)
			->setAccountId(self::AUTHOR)
			->setLimit(50)
			->setOnlyMedia($onlyMedia)
			->setMediaType($mediaType);

		return array_values(array_filter(
			array_map(
				static fn ($stream): string => $stream->getId(),
				$this->streamRequest->getTimeline($options)
			),
			static fn (string $id): bool => str_starts_with($id, self::BASE . '/notes/')
		));
	}

	private function seed(): void {
		$this->author();
		$this->note('picture', [$this->attachment('p1', 'image')]);
		$this->note('film', [$this->attachment('v1', 'video')]);
		$this->note('sound', [$this->attachment('a1', 'audio')]);
		$this->note('words', []);
	}

	public function testTheProfileStillCarriesEverythingWhenNoKindIsAskedFor(): void {
		$this->seed();

		$this->assertEqualsCanonicalizing(
			[
				self::BASE . '/notes/picture',
				self::BASE . '/notes/film',
				self::BASE . '/notes/sound',
				self::BASE . '/notes/words',
			],
			$this->profile('')
		);
	}

	public function testVisualFeedIncludesPhotosAndVideosAndExcludesTextAndAudio(): void {
		$this->seed();
		$this->assertEqualsCanonicalizing(
			[self::BASE . '/notes/picture', self::BASE . '/notes/film'],
			$this->profile('visual')
		);
	}

	public function testPhotosAreThePostsCarryingAPicture(): void {
		$this->seed();

		$this->assertSame([self::BASE . '/notes/picture'], $this->profile('image'));
	}

	public function testVideosAreThePostsCarryingAFilm(): void {
		$this->seed();

		$this->assertSame([self::BASE . '/notes/film'], $this->profile('video'));
	}

	/**
	 * A kind implies media: asking for videos of an account that posts text
	 * must not fall back to everything they ever wrote.
	 */
	public function testAKindImpliesOnlyMedia(): void {
		$this->seed();

		$this->assertNotContains(self::BASE . '/notes/words', $this->profile('video'));
	}

	/** An album of a picture and a film is both, and belongs to both tabs. */
	public function testAPostCarryingBothIsInBothTabs(): void {
		$this->author();
		$this->note('both', [$this->attachment('p2', 'image'), $this->attachment('v2', 'video')]);

		$this->assertSame([self::BASE . '/notes/both'], $this->profile('image'));
		$this->assertSame([self::BASE . '/notes/both'], $this->profile('video'));
	}

	/**
	 * The predicate is a `LIKE` on the stored JSON, so the one thing worth
	 * proving is that it reads the attachment's `type` and not the post: a
	 * description that says the same words is stored with its quotes escaped.
	 */
	public function testTextThatLooksLikeTheFilterDoesNotMatchIt(): void {
		$this->author();
		$this->note('words', [], 'my "type":"video" impression of a JSON column');

		$this->assertSame([], $this->profile('video'));
	}

	/**
	 * A profile's Photos and Videos tabs, on an account whose last sixty
	 * posts are text: three pictures and two videos further down.
	 */
	#[DataProvider('homeFastPath')]
	public function testAProfilesTabsFindTheMediaUnderSixtyNewerPostsOfText(bool $fast): void {
		$this->useHomeFastPath($fast);
		$seeded = $this->seedMediaUnderText(self::BASE, $this->author());

		foreach (['image' => $seeded['photos'], 'video' => $seeded['videos']] as $mediaType => $expected) {
			$this->assertFoundUnderText(
				self::BASE, $expected,
				static function (ProbeOptions $options) use ($mediaType): void {
					$options->setProbe(ProbeOptions::ACCOUNT)
						->setAccountId(self::AUTHOR)
						->setOnlyMedia(true)
						->setMediaType($mediaType);
				},
				'profile ' . $mediaType
			);
		}
	}
}
