<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ImportService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * The forms ActivityStreams allows a document to take that are not the ones
 * Mastodon sends: a single object where a list is usual, a string where an
 * object is, a short spelling of a well-known IRI. Each of these arrives from
 * some peer, and each was lost or broke the delivery.
 */
#[AllowMockObjectsWithoutExpectations]
class LenientImportTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(\OCP\IURLGenerator::class, $this->createStub(\OCP\IURLGenerator::class));
		$this->apInterface(\OCA\Social\Interfaces\Actor\PersonInterface::class)
			->method('getItemById')
			->willThrowException(new \OCA\Social\Exceptions\ItemNotFoundException());
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/** @param array<string, mixed> $extra */
	private function actor(array $extra = []): Person {
		$person = AP::instance()->getItemFromData(array_merge([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => 'https://peertube.example/accounts/alice',
			'type' => 'Person',
			'preferredUsername' => 'alice',
			'name' => 'Alice',
			'inbox' => 'https://peertube.example/accounts/alice/inbox',
			'outbox' => 'https://peertube.example/accounts/alice/outbox',
			'publicKey' => [
				'id' => 'https://peertube.example/accounts/alice#main-key',
				'owner' => 'https://peertube.example/accounts/alice',
				'publicKeyPem' => "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n",
			],
		], $extra));
		$this->assertInstanceOf(Person::class, $person);

		return $person;
	}

	/** @param array<string, mixed> $extra */
	private function note(array $extra = []): Note {
		$note = AP::instance()->getItemFromData(array_merge([
			'id' => 'https://peer.example/notes/1',
			'type' => 'Note',
			'attributedTo' => 'https://peer.example/users/bob',
			'content' => '<p>hello</p>',
			'published' => '2026-09-01T00:00:00Z',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => ['https://peer.example/users/bob/followers'],
		], $extra));
		$this->assertInstanceOf(Note::class, $note);

		return $note;
	}

	public function testASingleAttachmentObjectIsOneAttachment(): void {
		$note = $this->note([
			'attachment' => ['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://peer.example/media/1.jpg'],
		]);

		$this->assertCount(1, $note->getAttachments());
	}

	public function testAnAttachmentThatIsNotAnObjectIsSkipped(): void {
		$note = $this->note(['attachment' => ['https://peer.example/media/1.jpg', 42]]);

		$this->assertSame([], $note->getAttachments());
	}

	public function testASingleTagObjectIsOneTag(): void {
		$note = $this->note([
			'tag' => ['type' => 'Hashtag', 'name' => '#photography', 'href' => 'https://peer.example/tags/photography'],
		]);

		$this->assertSame(['photography'], $note->getHashtags());
	}

	public function testASingleEmojiTagObjectIsRead(): void {
		$note = $this->note([
			'content' => '<p>hi :wave:</p>',
			'tag' => ['type' => 'Emoji', 'name' => ':wave:', 'icon' => ['type' => 'Image', 'url' => 'https://peer.example/emoji/wave.png']],
		]);

		$this->assertSame('wave', $note->getEmojis()[0]['shortcode'] ?? null);
	}

	public function testASingleRecipientStringIsARecipient(): void {
		$note = $this->note(['to' => 'https://www.w3.org/ns/activitystreams#Public', 'cc' => 'https://peer.example/users/bob/followers']);

		$this->assertSame(['https://www.w3.org/ns/activitystreams#Public'], $note->getToArray());
		$this->assertSame(['https://peer.example/users/bob/followers'], $note->getCcArray());
	}

	public function testASinglePropertyValueIsAProfileField(): void {
		$person = $this->actor(['attachment' => ['type' => 'PropertyValue', 'name' => 'Web', 'value' => 'https://alice.example']]);

		$this->assertSame([['name' => 'Web', 'value' => 'https://alice.example']], $person->getFields());
	}

	public function testAModelTypeErrorIsAFormatError(): void {
		// what a 500 used to be made of: a model handed a string where it
		// declares an array, deep inside the import
		$ap = $this->createStub(AP::class);
		$ap->method('getItemFromData')->willThrowException(new \TypeError('must be of type array, string given'));
		AP::set($ap);
		$service = $this->getMockBuilder(ImportService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();

		$this->expectException(ActivityPubFormatException::class);

		$service->importFromJson('{"type":"Create"}');
	}

	public function testListOfTakesEveryFormAFieldArrivesIn(): void {
		$this->assertSame([], ACore::listOf('k', []));
		$this->assertSame([], ACore::listOf('k', ['k' => null]));
		$this->assertSame(['a'], ACore::listOf('k', ['k' => 'a']));
		$this->assertSame([['type' => 'X']], ACore::listOf('k', ['k' => ['type' => 'X']]));
		$this->assertSame(['a', 'b'], ACore::listOf('k', ['k' => ['a', 'b']]));
	}
	public function testAPeerTubeAvatarListKeepsTheLargestPicture(): void {
		$person = $this->actor([
			'icon' => [
				['type' => 'Image', 'mediaType' => 'image/png', 'width' => 48, 'height' => 48, 'url' => 'https://peertube.example/lazy-static/avatars/48.png'],
				['type' => 'Image', 'mediaType' => 'image/png', 'width' => 120, 'height' => 120, 'url' => 'https://peertube.example/lazy-static/avatars/120.png'],
				['type' => 'Image', 'mediaType' => 'image/png', 'width' => 600, 'height' => 600, 'url' => 'https://peertube.example/lazy-static/avatars/600.png'],
			],
		]);

		$this->assertTrue($person->hasIcon());
		$this->assertSame('https://peertube.example/lazy-static/avatars/600.png', $person->getIcon()->getUrl());
	}

	public function testASingleAvatarObjectIsStillRead(): void {
		$person = $this->actor(['icon' => ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://peertube.example/a.png']]);

		$this->assertSame('https://peertube.example/a.png', $person->getIcon()->getUrl());
	}

	public function testAPeerTubeBannerListKeepsTheLargestPicture(): void {
		$person = $this->actor([
			'image' => [
				['type' => 'Image', 'width' => 1920, 'height' => 317, 'url' => 'https://peertube.example/banners/big.jpg'],
				['type' => 'Image', 'width' => 600, 'height' => 100, 'url' => 'https://peertube.example/banners/small.jpg'],
			],
		]);

		$this->assertSame('https://peertube.example/banners/big.jpg', $person->getHeader());
	}
	/** @return array<string, mixed> a Lemmy link post as Lemmy federates it */
	private function lemmyPage(array $extra = []): array {
		return array_merge([
			'id' => 'https://lemmy.example/post/1',
			'type' => 'Page',
			'attributedTo' => 'https://lemmy.example/u/bob',
			'to' => ['https://lemmy.example/c/news', 'https://www.w3.org/ns/activitystreams#Public'],
			'audience' => 'https://lemmy.example/c/news',
			'name' => 'A headline',
			'content' => '<p>body</p>',
			'mediaType' => 'text/html',
			'attachment' => [['type' => 'Link', 'href' => 'https://news.example/story']],
			'image' => ['type' => 'Image', 'url' => 'https://lemmy.example/pictrs/1.jpg'],
			'published' => '2026-09-01T00:00:00Z',
		], $extra);
	}

	public function testALemmyLinkPostKeepsItsLink(): void {
		$note = AP::instance()->getItemFromData($this->lemmyPage());

		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame('<p>body</p><p><a href="https://news.example/story">https://news.example/story</a></p>', $note->getContent());
	}

	public function testALemmyLinkPostWithoutABodyIsItsTitleAndItsLink(): void {
		$note = AP::instance()->getItemFromData($this->lemmyPage(['content' => '']));

		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame('<p>A headline</p><p><a href="https://news.example/story">https://news.example/story</a></p>', $note->getContent());
	}

	public function testALinkAlreadyInTheContentIsNotRepeatedNorAnUnsafeOneAdded(): void {
		$linked = AP::instance()->getItemFromData($this->lemmyPage([
			'content' => '<p><a href="https://news.example/story">story</a></p>',
		]));
		$unsafe = AP::instance()->getItemFromData($this->lemmyPage([
			'attachment' => ['type' => 'Link', 'href' => 'javascript:alert(1)'],
		]));

		$this->assertInstanceOf(Note::class, $linked);
		$this->assertInstanceOf(Note::class, $unsafe);
		$this->assertSame('<p><a href="https://news.example/story">story</a></p>', $linked->getContent());
		$this->assertSame('<p>body</p>', $unsafe->getContent());
	}
	public function testADisplayNameWithALessThanSignIsKeptWhole(): void {
		$this->assertSame('Alice <3 cats & dogs', $this->actor(['name' => 'Alice <3 cats & dogs'])->getName());
		$this->assertSame('a<b', $this->actor(['name' => 'a<b'])->getName());
	}

	public function testMarkupInADisplayNameIsStillRemoved(): void {
		$this->assertSame('Alice', $this->actor(['name' => '<b>Alice</b>'])->getName());
	}
	/** @return array<string, array{string}> */
	public static function publicSpellings(): array {
		return [
			'full IRI' => ['https://www.w3.org/ns/activitystreams#Public'],
			'compacted' => ['as:Public'],
			'bare' => ['Public'],
		];
	}

	#[DataProvider('publicSpellings')]
	public function testEverySpellingOfThePublicCollectionIsPublic(string $public): void {
		$note = $this->note(['to' => [$public]]);

		$this->assertSame(['https://www.w3.org/ns/activitystreams#Public'], $note->getToArray());
		$this->assertTrue($note->isPublic());
	}

	public function testAShortPublicInCcIsUnlistedAddressing(): void {
		$note = $this->note(['to' => ['https://peer.example/users/bob/followers'], 'cc' => 'as:Public']);

		$this->assertSame(['https://www.w3.org/ns/activitystreams#Public'], $note->getCcArray());
	}
	public function testTheBannerKeepsTheTypeItsDocumentGave(): void {
		$person = $this->actor(['image' => ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://peertube.example/header.png']]);

		$this->assertSame(
			['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://peertube.example/header.png'],
			$person->exportAsActivityPub()['image']
		);
	}

	public function testALocalBannerIsTypedByItsMediaAddress(): void {
		$person = $this->actor(['image' => ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://peertube.example/header.png']]);
		$person->setHeader('https://cloud.example/apps/social/media/abc.webp');

		$this->assertSame('image/webp', $person->exportAsActivityPub()['image']['mediaType']);
	}

	public function testABannerOfUnknownTypeStatesNone(): void {
		$person = $this->actor();
		$person->setHeader('https://cloud.example/banner');

		$this->assertArrayNotHasKey('mediaType', $person->exportAsActivityPub()['image']);
	}
	public function testAnEmbeddedInReplyToKeepsTheThreadLink(): void {
		$note = $this->note(['inReplyTo' => ['id' => 'https://peer.example/notes/0', 'type' => 'Note']]);

		$this->assertSame('https://peer.example/notes/0', $note->getInReplyTo());
	}

	public function testAnInReplyToThatIsNeitherIsNone(): void {
		$this->assertSame('', $this->note(['inReplyTo' => ['https://peer.example/notes/0']])->getInReplyTo());
		$this->assertSame('', $this->note(['inReplyTo' => ['id' => 'javascript:alert(1)']])->getInReplyTo());
	}

	public function testASingleAlsoKnownAsStringIsAnAlias(): void {
		// MoveInterface reads the target's aliases; a single string used to
		// read as none and a legitimate Move was refused
		$person = $this->actor(['alsoKnownAs' => 'https://old.example/users/alice']);

		$this->assertSame(['https://old.example/users/alice'], $person->getAlsoKnownAs());
	}
}
