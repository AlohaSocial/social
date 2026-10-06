<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\AP;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TActivityPubMocks.php';

class NoteTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/** A Note as Mastodon delivers it inside a Create. */
	private function mastodonNote(): array {
		return [
			'id' => 'https://mastodon.social/users/alice/statuses/112000000000000001',
			'type' => 'Note',
			'summary' => 'CW: cats',
			'inReplyTo' => 'https://mastodon.social/users/bob/statuses/111999999999999999',
			'published' => '2024-05-01T12:00:00Z',
			'url' => 'https://mastodon.social/@alice/112000000000000001',
			'attributedTo' => 'https://mastodon.social/users/alice',
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => ['https://mastodon.social/users/alice/followers', 'https://remote.example/users/bob'],
			'sensitive' => true,
			'atomUri' => 'https://mastodon.social/users/alice/statuses/112000000000000001',
			'conversation' => 'tag:mastodon.social,2024-05-01:objectId=1:objectType=Conversation',
			'content' => '<p><span class="h-card"><a href="https://remote.example/@bob" class="u-url mention">@<span>bob</span></a></span> look at this <a href="https://mastodon.social/tags/nextcloud" class="mention hashtag" rel="tag">#<span>nextcloud</span></a></p>',
			'contentMap' => ['en' => '<p>...</p>'],
			'attachment' => [
				[
					'type' => 'Document',
					'mediaType' => 'image/jpeg',
					'url' => 'https://files.mastodon.social/media_attachments/files/112/000/original/cat.jpg',
					'name' => 'A cat',
					'blurhash' => 'UBL_:rOpGG-oBUNG,qRj2so|=eE1w^n4S5NH',
					'width' => 1200,
					'height' => 800,
				],
			],
			'tag' => [
				['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example'],
				['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/nextcloud', 'name' => '#nextcloud'],
			],
			'replies' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/replies', 'type' => 'Collection'],
			'likes' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/likes', 'type' => 'Collection', 'totalItems' => 5],
			'shares' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/shares', 'type' => 'Collection', 'totalItems' => 2],
		];
	}

	private function nobodyIsKnown(): void {
		$this->apInterface(PersonInterface::class)->method('getItemById')
			->willThrowException(new ItemNotFoundException());
	}

	public function testConstructorSetsTheType(): void {
		$this->assertSame('Note', (new Note())->getType());
	}

	public function testImportReadsAMastodonNote(): void {
		$this->nobodyIsKnown();
		$note = new Note();

		$note->import($this->mastodonNote());

		$this->assertSame('https://mastodon.social/users/alice/statuses/112000000000000001', $note->getId());
		$this->assertSame('https://mastodon.social/@alice/112000000000000001', $note->getUrl());
		$this->assertSame('CW: cats', $note->getSummary());
		$this->assertTrue($note->isSensitive());
		$this->assertSame('https://mastodon.social/users/bob/statuses/111999999999999999', $note->getInReplyTo());
		$this->assertSame('https://mastodon.social/users/alice', $note->getAttributedTo());
		$this->assertSame('2024-05-01T12:00:00Z', $note->getPublished());
		$this->assertSame(1714564800, $note->getPublishedTime());
		$this->assertStringContainsString('look at this', $note->getContent());
		$this->assertSame('tag:mastodon.social,2024-05-01:objectId=1:objectType=Conversation', $note->getConversation());

		$this->assertTrue($note->isPublic());
		$this->assertSame([ACore::CONTEXT_PUBLIC], $note->getToArray());
		$this->assertSame(
			['https://mastodon.social/users/alice/followers', 'https://remote.example/users/bob'],
			$note->getCcArray()
		);
		$this->assertNotContains(ACore::CONTEXT_PUBLIC, $note->getRecipients(true));

		$this->assertSame([
			['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example'],
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/nextcloud', 'name' => '#nextcloud'],
		], $note->getTags());
		$this->assertSame(['nextcloud'], $note->getHashtags());

		$this->assertSame(5, $note->getDetailInt('likes'));
		$this->assertSame(2, $note->getDetailInt('boosts'));
		$this->assertSame(0, $note->getDetailInt('replies'), 'replies collection without totalItems');

		$attachments = $note->getAttachments();
		$this->assertCount(1, $attachments);
		$this->assertInstanceOf(MediaAttachment::class, $attachments[0]);
		$this->assertSame('image', $attachments[0]->getType());
		$this->assertSame(
			'https://files.mastodon.social/media_attachments/files/112/000/original/cat.jpg',
			$attachments[0]->getRemoteUrl()
		);
	}

	/**
	 * Mastodon votes on a poll with a bare `Note` whose `name` is the option.
	 * The name was never read, so no vote from Mastodon was ever counted: it
	 * was stored as a message to the poll's author instead.
	 */
	public function testImportReadsTheOptionAPollVoteChose(): void {
		$this->nobodyIsKnown();
		$note = new Note();

		$note->import([
			'id' => 'https://mastodon.social/users/alice#votes/7',
			'type' => 'Note',
			'name' => 'Dogs',
			'inReplyTo' => 'https://cloud.example/@bob/1',
			'attributedTo' => 'https://mastodon.social/users/alice',
			'to' => 'https://cloud.example/@bob',
		]);

		$this->assertSame('Dogs', $note->getName());
	}

	/** @return iterable<string, array{array<string, string>}> */
	public static function notAVote(): iterable {
		yield 'it has content' => [['content' => '<p>Dogs, obviously</p>']];
		yield 'it answers nothing' => [['inReplyTo' => '']];
		yield 'it is a video' => [['type' => 'Video']];
	}

	/** @param array<string, string> $differs */
	#[\PHPUnit\Framework\Attributes\DataProvider('notAVote')]
	public function testANameThatIsNotAVoteIsNotRead(array $differs): void {
		$this->nobodyIsKnown();
		$note = new Note();

		$note->import($differs + [
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'name' => 'Dogs',
			'inReplyTo' => 'https://cloud.example/@bob/1',
			'attributedTo' => 'https://mastodon.social/users/alice',
		]);

		$this->assertSame('', $note->getName());
	}

	public function testFillMentionsKeepsTagDataForUnknownActors(): void {
		$this->nobodyIsKnown();
		$note = new Note();
		$note->setTags([
			['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example'],
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/nextcloud', 'name' => '#nextcloud'],
		]);

		$note->fillMentions();

		$this->assertSame([
			[
				'id' => 0,
				'username' => 'bob@remote.example',
				'url' => 'https://remote.example/users/bob',
				'acct' => 'bob@remote.example',
			],
		], $note->getDetails('mentions'));
	}

	public function testFillMentionsResolvesKnownActors(): void {
		$this->installActivityPub(mocked: [PersonInterface::class]);
		$bob = new Person();
		$bob->setNid(77)
			->setPreferredUsername('bob')
			->setAccount('bob@remote.example');
		$this->apInterface(PersonInterface::class)->method('getItemById')
			->with('https://remote.example/users/bob')
			->willReturn($bob);

		$note = new Note();
		$note->setTags([['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example']]);

		$note->fillMentions();

		$this->assertSame([
			[
				'id' => '77',
				'username' => 'bob',
				'url' => 'https://remote.example/users/bob',
				'acct' => 'bob@remote.example',
			],
		], $note->getDetails('mentions'));
	}

	public function testFillHashtagsStripsTheHashAndIgnoresOtherTags(): void {
		$note = new Note();
		$note->setTags([
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/nextcloud', 'name' => '#Nextcloud'],
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/fediverse', 'name' => '#fediverse '],
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/plain', 'name' => 'plain'],
			['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob'],
		]);

		$note->fillHashtags();

		$this->assertSame(['Nextcloud', 'fediverse', 'plain'], $note->getHashtags());
	}

	public function testExportAsActivityPubRoundTripsTheImportedNote(): void {
		$this->nobodyIsKnown();
		$source = $this->mastodonNote();
		$note = new Note();
		$note->import($source);

		$export = $note->exportAsActivityPub();

		$this->assertSame(
			[ACore::CONTEXT_ACTIVITYSTREAMS, ACore::CONTEXT_EXTENSIONS], $export['@context']
		);
		foreach (['id', 'type', 'url', 'to', 'cc', 'published', 'summary', 'content', 'attributedTo', 'inReplyTo', 'sensitive', 'conversation', 'tag'] as $key) {
			$this->assertSame($source[$key], $export[$key], $key . ' survives the round trip');
		}
		$this->assertArrayNotHasKey('actor', $export);
		$this->assertArrayNotHasKey('object', $export);
	}

	public function testANoteThatNamesNoPageIsOpenedAtItsOwnId(): void {
		$this->nobodyIsKnown();
		$source = $this->mastodonNote();
		unset($source['url']);
		$note = new Note();
		$note->import($source);

		$status = $note->exportAsLocal();

		$this->assertSame($status['uri'], $status['url']);
	}

	/**
	 * `parse_url()` parses `javascript:alert(1)` quite happily, and this field
	 * is rendered as a link by every client that reads it.
	 */
	public function testAPageAddressThatIsNotTheWebIsRefused(): void {
		$this->nobodyIsKnown();
		$source = $this->mastodonNote();
		$source['url'] = 'javascript:alert(1)';
		$note = new Note();
		$note->import($source);

		$status = $note->exportAsLocal();

		$this->assertSame($status['uri'], $status['url']);
	}

	/**
	 * `social_stream` has no column for the page address, so a status read
	 * back out of the database would lose it — and everything a client is
	 * served comes from there, not from the object that was imported.
	 */
	public function testThePageAddressSurvivesTheDatabase(): void {
		$this->nobodyIsKnown();
		$note = new Note();
		$note->import($this->mastodonNote());

		$stored = new Note();
		$stored->importFromDatabase([
			'id' => $note->getId(),
			'type' => 'Note',
			'details' => json_encode($note->getDetailsAll()),
		]);

		$this->assertSame('https://mastodon.social/@alice/112000000000000001', $stored->pageUrl());
	}

	public function testExportAsLocalOfAnImportedNote(): void {
		$this->nobodyIsKnown();
		$note = new Note();
		$note->import($this->mastodonNote());

		$status = $note->exportAsLocal();

		$this->assertSame('https://mastodon.social/users/alice/statuses/112000000000000001', $status['uri']);
		// the two are different things: `uri` is the object, `url` is the page
		// a person can open, which every Mastodon-family server sends
		// separately — and Loops, whose ids are under /ap/, only reaches a
		// video through it
		$this->assertSame('https://mastodon.social/@alice/112000000000000001', $status['url']);
		$this->assertStringContainsString('look at this', $status['content']);
		$this->assertTrue($status['sensitive']);
		$this->assertSame(5, $status['favourites_count']);
		$this->assertSame(2, $status['reblogs_count']);
		$this->assertCount(1, $status['media_attachments']);
		$this->assertFalse($status['local']);
	}

	public function testImportFromDatabaseReadsHashtagsAndExposesThemOnlyWithCompleteDetails(): void {
		$note = new Note();

		$note->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'hashtags' => '["nextcloud","fediverse"]',
		]);

		$this->assertSame(['nextcloud', 'fediverse'], $note->getHashtags());
		$this->assertArrayNotHasKey('hashtags', $note->jsonSerialize());

		$note->setCompleteDetails(true);
		$this->assertSame(['nextcloud', 'fediverse'], $note->jsonSerialize()['hashtags']);
	}

	public function testTheLanguageComesFromTheContentMapAndGoesBackOut(): void {
		$this->nobodyIsKnown();
		$note = new Note();
		$note->import($this->mastodonNote());

		$this->assertSame('en', $note->getLanguage());
		$this->assertSame('en', $note->exportAsLocal()['language']);
		$this->assertSame(['en' => $note->getContent()], $note->exportAsActivityPub()['contentMap']);
	}

	// a post that is a video goes onto the wire as one

	private function videoAttachment(string $type = 'video'): MediaAttachment {
		$media = new MediaAttachment();
		$media->setId('7')
			->setType($type)
			->setMediaType('video/mp4')
			->setUrl('https://cloud.example.org/media/movie.mp4')
			->setPreviewUrl('https://cloud.example.org/media/poster.jpeg')
			->setDescription('a cat')
			// PeerTube's validator makes the duration and the file's size
			// mandatory, so a `Video` cannot be published without either
			->setSizeBytes(4_194_304);

		$meta = new \OCA\Social\Model\Client\AttachmentMeta();
		$meta->setDuration(113.0);

		return $media->setMeta($meta);
	}

	private function localVideoPost(string $type = 'video'): Note {
		$note = new Note();
		$note->setId('https://cloud.example.org/apps/social/@alice/0123');
		$note->setLocal(true);
		$note->setAttributedTo('https://cloud.example.org/apps/social/@alice');
		$note->setContent('<p>The cat and the glass</p>');
		$note->setAttachments([$this->videoAttachment($type)]);
		$note->setExportFormat(ACore::FORMAT_ACTIVITYPUB);

		return $note;
	}

	/**
	 * The setting is read only in the branch that could use it, and so is the
	 * channel: a `Video` without a `Group` in `attributedTo` is one PeerTube
	 * refuses, so the publisher asks for one before it makes the shape.
	 */
	private function publishVideoObjects(bool $enabled, bool $hasChannel = true): void {
		$config = $this->createStub(\OCA\Social\Service\ConfigService::class);
		$config->method('getAppValueBool')->willReturn($enabled);
		\OC::$server->register(\OCA\Social\Service\ConfigService::class, $config);

		$channels = $this->createStub(\OCA\Social\Service\ChannelService::class);
		$channels->method('attributionOf')->willReturn($hasChannel ? [
			['type' => 'Group', 'id' => 'https://cloud.example.org/apps/social/@alice_channel'],
			['type' => 'Person', 'id' => 'https://cloud.example.org/apps/social/@alice'],
		] : []);
		\OC::$server->register(\OCA\Social\Service\ChannelService::class, $channels);
	}

	public function testALocalVideoPostIsPublishedAsAVideo(): void {
		$this->publishVideoObjects(true);

		$published = $this->localVideoPost()->jsonSerialize();

		$this->assertSame('Video', $published['type']);
		$this->assertSame('The cat and the glass', $published['name']);
		$this->assertIsArray($published['url']);
		// the channel PeerTube will not take a video without, first in the
		// list as PeerTube itself writes it
		$this->assertSame('Group', $published['attributedTo'][0]['type']);
		// and the fields its validator reads before anything else
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$published['uuid']
		);
		$this->assertIsInt($published['views']);
		$this->assertNotSame('', (string)$published['updated']);
		$this->assertNotSame([], $published['icon']);
		// and everything a Mastodon-family server reads is still there
		$this->assertArrayHasKey('attachment', $published);
	}

	/**
	 * PeerTube resolves a video's channel by looking for a `Group` in
	 * `attributedTo` and refuses the video when there is none, so an account
	 * without a channel publishes the `Note` it would have published — which
	 * every Mastodon-family server reads — rather than a shape that is going
	 * to be thrown away on arrival.
	 */
	public function testAVideoPostFromAnAccountWithNoChannelStaysANote(): void {
		$this->publishVideoObjects(true, hasChannel: false);

		$this->assertSame('Note', $this->localVideoPost()->jsonSerialize()['type']);
	}

	/** A remote note is somebody else's document, re-serialised as it arrived. */
	public function testARemoteVideoPostIsLeftAsANote(): void {
		$this->publishVideoObjects(true);
		$note = $this->localVideoPost();
		$note->setLocal(false);

		$this->assertSame('Note', $note->jsonSerialize()['type']);
	}

	/** The client API is Mastodon's, and Mastodon has no `Video` status. */
	public function testTheClientFormatIsNeverAVideo(): void {
		$this->publishVideoObjects(true);
		$note = $this->localVideoPost();
		$note->setExportFormat(ACore::FORMAT_LOCAL);

		$this->assertArrayNotHasKey('type', $note->jsonSerialize());
	}

	public function testAPostWithAPictureStaysANote(): void {
		$this->publishVideoObjects(true);

		$this->assertSame('Note', $this->localVideoPost('image')->jsonSerialize()['type']);
	}

	/**
	 * Whether a Mastodon-family server renders a `Video` as well as it rendered
	 * the `Note` is a question only a real one can answer, so an admin can turn
	 * this off.
	 */
	public function testTheSettingTurnsItOff(): void {
		$this->publishVideoObjects(false);

		$this->assertSame('Note', $this->localVideoPost()->jsonSerialize()['type']);
	}

	/** Nothing to resolve the setting from must not lose the post. */
	public function testWithNoConfigServiceThePostIsStillPublished(): void {
		$published = $this->localVideoPost()->jsonSerialize();

		$this->assertSame('Note', $published['type']);
		$this->assertArrayHasKey('attachment', $published);
	}
}
