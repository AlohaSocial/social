<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Publisher\TextMapper;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\DocumentService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every rule of what a post becomes on Bluesky, and that each result fits
 * the lexicon it is written against.
 */
#[AllowMockObjectsWithoutExpectations]
class RecordMapperTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const POST_ID = 'https://social.test/@alice/1';

	/** @var PictureService&MockObject */
	private PictureService $pictures;
	/** @var DocumentService&MockObject */
	private DocumentService $documents;
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	private RecordMapper $mapper;
	private Identity $identity;
	private Person $author;
	private Lexicon $lexicon;

	protected function setUp(): void {
		$this->pictures = $this->createMock(PictureService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('getByActorId')->willThrowException(new AtprotoIdentityNotFoundException());
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('getRecordsByLocalId')->willReturn([]);
		$this->mapper = new RecordMapper(new TextMapper(), $this->pictures, $this->documents, $this->identities, $this->repositories);
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', 'did:key:z', '', Identity::STATE_ACTIVE, '', 1700000000);
		$this->author = new Person();
		$this->author->setId('https://social.test/@alice');
		$this->author->setPreferredUsername('alice');
		$this->lexicon = new Lexicon();
	}

	public function testAPlainPost(): void {
		$post = $this->post('<p>Hello <a href="https://social.test/tags/world" class="mention hashtag" rel="tag">#world</a></p>');
		$post->setLanguage('en');

		['record' => $record, 'truncated' => $truncated] = $this->mapper->post($post, $this->identity, $this->author);

		$this->assertFalse($truncated);
		$this->assertSame('Hello #world', $record['text']);
		$this->assertSame('2026-10-08T10:00:00.000Z', $record['createdAt']);
		$this->assertSame(['en'], $record['langs']);
		$this->assertSame('world', $record['facets'][0]['features'][0]['tag']);
		$this->assertArrayNotHasKey('labels', $record);
		$this->assertArrayNotHasKey('embed', $record);
		$this->lexicon->validateRecord($record);
	}

	public function testAContentWarningBecomesAPrefixAndASelfLabel(): void {
		$post = $this->post('<p>the <a href="https://x.example">spoiler</a></p>');
		$post->setSpoilerText('Spoilers');

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame("CW: Spoilers\n\nthe spoiler (https://x.example)", $record['text']);
		$this->assertSame(['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => '!warn']]], $record['labels']);
		$this->assertSame('https://x.example', $record['facets'][0]['features'][0]['uri']);
		$this->assertSame(['byteStart' => 27, 'byteEnd' => 44], $record['facets'][0]['index'], 'the facet moved with the prefix');
		$this->lexicon->validateRecord($record);
	}

	public function testALongPostIsCutAndLinked(): void {
		$post = $this->post('<p>' . str_repeat('word ', 120) . '</p>');

		['record' => $record, 'truncated' => $truncated] = $this->mapper->post($post, $this->identity, $this->author);

		$this->assertTrue($truncated);
		$this->assertStringEndsWith("…\n\n" . self::POST_ID, $record['text']);
		$this->assertLessThanOrEqual(300, Lexicon::graphemes($record['text']));
		$this->lexicon->validateRecord($record);
	}

	public function testAPollBecomesItsQuestionAndOptionsAndALink(): void {
		$poll = new Question();
		$poll->setId(self::POST_ID);
		$poll->setContent('<p>Tea or coffee?</p>');
		$poll->setPublished('2026-10-08T10:00:00+00:00');
		$poll->setVisibility('public');
		$poll->setPollData(['Tea', 'Coffee'], false, 3600);

		$record = $this->mapper->post($poll, $this->identity, $this->author)['record'];

		$this->assertSame("Tea or coffee?\n\nPoll: Tea / Coffee\n\n" . self::POST_ID, $record['text']);
		$this->assertSame(self::POST_ID, end($record['facets'])['features'][0]['uri']);
		$this->lexicon->validateRecord($record);
	}

	public function testUpToFourPicturesGoAndTheRestAreCounted(): void {
		$post = $this->post('<p>Six pictures</p>');
		$documents = [];
		$attachments = [];
		for ($i = 1; $i <= 6; $i++) {
			$document = new Document();
			$document->setNid($i);
			$document->setId('https://social.test/doc/' . $i);
			$document->setDescription('picture ' . $i);
			$documents[] = $document;
			$attachment = new MediaAttachment();
			$attachment->setId((string)$i);
			$attachment->setType('image');
			$attachments[] = $attachment;
		}
		$post->setAttachments($attachments);
		$this->documents->method('getMediaFromArray')->willReturn($documents);
		$this->pictures->method('blobFor')->willReturnCallback(function (Identity $identity, Person $owner, Document $document): array {
			return ['blob' => new BlobRef(self::DID, Cid::forRaw($document->getId()), $document->getId(), 'image/jpeg', 100), 'width' => 40, 'height' => 30];
		});

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertCount(4, $record['embed']['images']);
		$this->assertSame('picture 1', $record['embed']['images'][0]['alt']);
		$this->assertSame(['width' => 40, 'height' => 30], $record['embed']['images'][0]['aspectRatio']);
		$this->assertSame("Six pictures\n\n+2 more pictures\n\n" . self::POST_ID, $record['text']);
		$this->lexicon->validateRecord($record);
	}

	public function testASensitivePictureIsLabelled(): void {
		$post = $this->post('<p>Look</p>');
		$post->setSensitive(true);
		$document = new Document();
		$document->setNid(1);
		$document->setId('https://social.test/doc/1');
		$attachment = new MediaAttachment();
		$attachment->setId('1');
		$attachment->setType('image');
		$post->setAttachments([$attachment]);
		$this->documents->method('getMediaFromArray')->willReturn([$document]);
		$this->pictures->method('blobFor')->willReturn(['blob' => new BlobRef(self::DID, Cid::forRaw('x'), 'https://social.test/doc/1', 'image/jpeg', 100), 'width' => 1, 'height' => 1]);

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame([['val' => 'graphic-media']], $record['labels']['values']);
		$this->lexicon->validateRecord($record);
	}

	public function testAReplyToAPostThatIsOnBlueskyIsAReplyThere(): void {
		$parentRecord = ['$type' => RecordMapper::POST, 'text' => 'root', 'createdAt' => '2026-10-08T09:00:00.000Z'];
		$parentBytes = DagCbor::encode($parentRecord);
		$parent = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forDagCbor($parentBytes), $parentBytes, 'https://social.test/@alice/0', 0);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('getRecordsByLocalId')->willReturnCallback(static fn (string $id): array => $id === 'https://social.test/@alice/0' ? [$parent] : []);
		$this->mapper = new RecordMapper(new TextMapper(), $this->pictures, $this->documents, $this->identities, $this->repositories);
		$post = $this->post('<p>an answer</p>');
		$post->setInReplyTo('https://social.test/@alice/0');

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$expected = ['uri' => $parent->uri(), 'cid' => $parent->cid->toString()];
		$this->assertSame(['root' => $expected, 'parent' => $expected], $record['reply']);
		$this->assertSame('an answer', $record['text'], 'no link needed');
		$this->lexicon->validateRecord($record);
	}

	public function testAReplyToAPostThatIsNotOnBlueskyLinksToIt(): void {
		$post = $this->post('<p>an answer</p>');
		$post->setInReplyTo('https://mastodon.example/@bob/5');

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertArrayNotHasKey('reply', $record);
		$this->assertSame("an answer\n\nhttps://mastodon.example/@bob/5\n\n" . self::POST_ID, $record['text']);
		$uris = array_map(static fn (array $f): string => $f['features'][0]['uri'], $record['facets']);
		$this->assertSame(['https://mastodon.example/@bob/5', self::POST_ID], $uris);
		$this->lexicon->validateRecord($record);
	}

	public function testAMentionOfALocalAccountIsAMentionFacet(): void {
		$this->identities = $this->createMock(IdentityService::class);
		$bob = new Identity(2, 'https://social.test/@bob', 'did:plc:bobbobbobbobbobbobbobbob', 'bob.social.test', '', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0);
		$this->identities->method('getByActorId')->willReturnCallback(static function (string $id) use ($bob): Identity {
			if ($id === 'https://social.test/@bob') {
				return $bob;
			}
			throw new AtprotoIdentityNotFoundException();
		});
		$this->mapper = new RecordMapper(new TextMapper(), $this->pictures, $this->documents, $this->identities, $this->repositories);
		$post = $this->post('<p><a href="https://social.test/@bob" class="u-url mention">@bob</a> hi</p>');

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame(['$type' => 'app.bsky.richtext.facet#mention', 'did' => $bob->did], $record['facets'][0]['features'][0]);
		$this->lexicon->validateRecord($record);
	}

	public function testAProfile(): void {
		$this->author->setDisplayName('Alice ' . str_repeat('x', 100));
		$this->author->setDescription('<p>I run <a href="https://nextcloud.com">Nextcloud</a></p>');

		$record = $this->mapper->profile($this->author, $this->identity);

		$this->assertSame(64, Lexicon::graphemes($record['displayName']));
		$this->assertSame('I run Nextcloud (https://nextcloud.com)', $record['description']);
		$this->assertArrayNotHasKey('avatar', $record);
		$this->lexicon->validateRecord($record);
	}

	private function post(string $html): Note {
		$note = new Note();
		$note->setId(self::POST_ID);
		$note->setContent($html);
		$note->setPublished('2026-10-08T10:00:00+00:00');
		$note->setVisibility('public');

		return $note;
	}
}
