<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\CardThumbnail;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Publisher\TextMapper;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\CardNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\PinService;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every rule of what a post becomes on Bluesky, and that each result fits
 * the lexicon it is written against.
 */
#[AllowMockObjectsWithoutExpectations]
class RecordMapperTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const POST_ID = 'https://social.test/@alice/1';
	private const OTHER = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var PictureService&MockObject */
	private PictureService $pictures;
	/** @var DocumentService&MockObject */
	private DocumentService $documents;
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var StreamRequest&MockObject */
	private StreamRequest $streams;
	/** @var AppViewClient&MockObject */
	private AppViewClient $appView;
	/** @var StreamCardsRequest&MockObject */
	private StreamCardsRequest $cards;
	/** @var CardThumbnail&MockObject */
	private CardThumbnail $thumbnails;
	/** @var CacheDocumentsRequest&MockObject */
	private CacheDocumentsRequest $cacheDocuments;
	/** @var PinService&MockObject */
	private PinService $pins;
	/** the Nextcloud user an account belongs to, by username */
	private array $userIds = [];
	/** the Nextcloud language of each user; the instance's is English */
	private array $userLanguages = [];
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
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->appView = $this->createMock(AppViewClient::class);
		$this->cards = $this->createMock(StreamCardsRequest::class);
		$this->cards->method('getByStreamId')->willThrowException(new CardNotFoundException());
		$this->thumbnails = $this->createMock(CardThumbnail::class);
		$this->cacheDocuments = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocuments->method('getByUrl')->willThrowException(new CacheDocumentDoesNotExistException());
		$this->pins = $this->createMock(PinService::class);
		$this->mapper = $this->mapper();
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', 'did:key:z', '', Identity::STATE_ACTIVE, '', 1700000000);
		$this->author = new Person();
		$this->author->setId('https://social.test/@alice');
		$this->author->setPreferredUsername('alice');
		$this->lexicon = new Lexicon();
	}

	public function testAPlainPost(): void {
		$post = $this->post('<p>Hello <a href="https://social.test/tags/world" class="mention hashtag" rel="tag">#world</a></p>');
		$post->setLanguage('en');

		$this->assertSame('Hello #world', $this->mapper->sourceText($post), 'the source text checker uses the same HTML and mention mapping');
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

		$this->assertSame("Content warning: Spoilers\n\nthe spoiler (https://x.example)", $record['text']);
		$this->assertSame(['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => '!warn']]], $record['labels']);
		$this->assertSame('https://x.example', $record['facets'][0]['features'][0]['uri']);
		$this->assertSame(['byteStart' => 40, 'byteEnd' => 57], $record['facets'][0]['index'], 'the facet moved with the prefix');
		$this->lexicon->validateRecord($record);
	}

	/**
	 * What a post gains on its way — the warning's line, the poll's, the
	 * count of pictures left out — is in the language of the post, from the
	 * app's own catalogue, and the facets move by the bytes it takes.
	 */
	public function testTheAddedWordsAreInThePostsLanguage(): void {
		$post = $this->post('<p>the <a href="https://x.example">spoiler</a></p>');
		$post->setLanguage('ru');
		$post->setSpoilerText('Спойлеры');

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$prefix = "Предупреждение о содержимом: Спойлеры\n\n";
		$this->assertSame($prefix . 'the spoiler (https://x.example)', $record['text']);
		$this->assertSame(['byteStart' => strlen($prefix) + 13, 'byteEnd' => strlen($prefix) + 30], $record['facets'][0]['index'], 'the facet moved by the bytes of the line');
		$this->lexicon->validateRecord($record);

		$poll = new Question();
		$poll->setId(self::POST_ID);
		$poll->setContent('<p>Tee oder Kaffee?</p>');
		$poll->setPublished('2026-10-08T10:00:00+00:00');
		$poll->setLanguage('de');
		$poll->setPollData(['Tee', 'Kaffee'], false, 3600);

		$this->assertSame("Tee oder Kaffee?\n\nUmfrage: Tee / Kaffee\n\n" . self::POST_ID, $this->mapper->post($poll, $this->identity, $this->author)['record']['text']);

		$pictures = $this->post('<p>Sechs Bilder</p>');
		$pictures->setLanguage('de');
		$this->withPictures($pictures, 6);

		$this->assertSame('Sechs Bilder', $this->mapper->post($pictures, $this->identity, $this->author)['record']['text']);
	}

	/**
	 * `pt-PT` is Nextcloud's `pt_PT`; a post in a language the app has no
	 * catalogue for, or of no language, takes its author's language.
	 */
	public function testTheLanguageIsNextcloudsOrElseTheAuthors(): void {
		$this->userIds['alice'] = 'alice-login';
		$this->userLanguages['alice-login'] = 'fr';
		$post = $this->post('<p>texto</p>');
		$post->setSpoilerText('aviso');

		$post->setLanguage('pt-PT');
		$this->assertSame("Aviso de conteúdo: aviso\n\ntexto", $this->mapper->post($post, $this->identity, $this->author)['record']['text']);

		$post->setLanguage('tlh');
		$this->assertSame("Avertissement de contenu : aviso\n\ntexto", $this->mapper->post($post, $this->identity, $this->author)['record']['text']);

		$post->setLanguage('');
		$this->assertSame("Avertissement de contenu : aviso\n\ntexto", $this->mapper->post($post, $this->identity, $this->author)['record']['text']);
	}

	public function testALongPostIsPublishedAsAFullThread(): void {
		$words = array_map(static fn (int $number): string => sprintf('word%03d', $number), range(1, 120));
		$post = $this->post('<p>' . implode(' ', $words) . '</p>');

		$mapped = $this->mapper->post($post, $this->identity, $this->author);
		$record = $mapped['record'];
		$parts = [$record, ...($mapped['continuations'] ?? [])];

		$this->assertTrue($mapped['truncated'], 'the text needed more than one Bluesky record');
		$this->assertGreaterThan(1, count($parts));
		$this->assertSame(
			implode(' ', [...$words, self::POST_ID]),
			(string)preg_replace('/\s+/u', ' ', implode(' ', array_column($parts, 'text'))),
			'the root and continuations must preserve every source word in order and add the Social link once',
		);
		foreach ($parts as $part) {
			$this->assertLessThanOrEqual(300, Lexicon::graphemes($part['text']));
			$this->assertLessThanOrEqual(3000, strlen($part['text']));
		}
		$this->assertStringNotContainsString(self::POST_ID, $parts[0]['text'], 'the root starts with the post, not a link replacing its continuation');
		$this->assertStringContainsString(self::POST_ID, $parts[count($parts) - 1]['text'], 'the Social link follows the full post');
		$links = [];
		foreach ($parts as $index => $part) {
			foreach ($part['facets'] ?? [] as $facet) {
				if (($facet['features'][0]['uri'] ?? '') === self::POST_ID) {
					$links[] = $index;
				}
			}
		}
		$this->assertSame([count($parts) - 1], $links, 'the full-post link is clickable exactly once, on the final part');
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

	public function testUpToTenPicturesUseGallery(): void {
		$post = $this->post('<p>Ten pictures</p>');
		$this->withPictures($post, 10);

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame('app.bsky.embed.gallery', $record['embed']['$type']);
		$this->assertCount(10, $record['embed']['items']);
		$this->assertSame('picture 1', $record['embed']['items'][0]['alt']);
		$this->assertSame(['width' => 40, 'height' => 30], $record['embed']['items'][0]['aspectRatio']);
		$this->assertSame('Ten pictures', $record['text']);
		$this->lexicon->validateRecord($record);
	}

	public function testPicturesOverTheAuthoringLimitAreCountedAndOmitted(): void {
		$post = $this->post('<p>Eleven pictures</p>');
		$this->withPictures($post, 11);

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame('app.bsky.embed.gallery', $record['embed']['$type']);
		$this->assertCount(10, $record['embed']['items']);
		$this->assertStringContainsString('+1 more picture', $record['text']);
		$this->lexicon->validateRecord($record);
	}

	public function testGalleryPicturesWithoutDimensionsGetASquareAspectRatio(): void {
		$post = $this->post('<p>Five pictures</p>');
		$this->withPictures($post, 5, 0, 0);

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame(['width' => 1, 'height' => 1], $record['embed']['items'][0]['aspectRatio']);
		$this->lexicon->validateRecord($record);
	}

	public function testFourPicturesKeepTheImagesEmbed(): void {
		$post = $this->post('<p>Four pictures</p>');
		$this->withPictures($post, 4);

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame('app.bsky.embed.images', $record['embed']['$type']);
		$this->assertCount(4, $record['embed']['images']);
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
		$this->mapper = $this->mapper();
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
		$this->mapper = $this->mapper();
		$post = $this->post('<p><a href="https://social.test/@bob" class="u-url mention">@bob</a> hi</p>');

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame(['$type' => 'app.bsky.richtext.facet#mention', 'did' => $bob->did], $record['facets'][0]['features'][0]);
		$this->lexicon->validateRecord($record);
	}

	public function testAReplyToABlueskyPostNamesItAndItsThreadsRoot(): void {
		$root = ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kroot', 'cid' => Cid::forRaw('3kroot')->toString()];
		$parent = $this->blueskyPost('3kparent', $root);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willReturnCallback(static fn (string $id): Note => $id === $parent->getId() ? $parent : throw new StreamNotFoundException());
		$this->appView->expects($this->never())->method('query');
		$this->mapper = $this->mapper();
		$post = $this->post('<p>an answer</p>');
		$post->setInReplyTo($parent->getId());

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame(['root' => $root, 'parent' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kparent', 'cid' => Cid::forRaw('3kparent')->toString()]], $record['reply']);
		$this->assertSame('an answer', $record['text']);
		$this->lexicon->validateRecord($record);
	}

	public function testAnUnknownRootIsAskedOfTheAppViewAndTheParentStandsInWhenItIsARoot(): void {
		$parent = $this->blueskyPost('3kparent');
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willReturn($parent);
		$this->appView->expects($this->exactly(2))->method('query')->with('app.bsky.feed.getPosts', ['uris' => ['at://' . self::OTHER . '/app.bsky.feed.post/3kparent']])
			->willReturnOnConsecutiveCalls(
				['posts' => [['record' => ['reply' => ['root' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kfar', 'cid' => Cid::forRaw('3kfar')->toString()]]]]]],
				['posts' => [['record' => ['text' => 'a root']]]],
			);
		$this->mapper = $this->mapper();
		$post = $this->post('<p>an answer</p>');
		$post->setInReplyTo($parent->getId());

		$this->assertSame('at://' . self::OTHER . '/app.bsky.feed.post/3kfar', $this->mapper->post($post, $this->identity, $this->author)['record']['reply']['root']['uri']);
		$this->assertSame('at://' . self::OTHER . '/app.bsky.feed.post/3kparent', $this->mapper->post($post, $this->identity, $this->author)['record']['reply']['root']['uri']);
	}

	public function testAQuoteOfABlueskyPostIsARecordEmbedAndWithPicturesBoth(): void {
		$quoted = $this->blueskyPost('3kquoted');
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willReturn($quoted);
		$this->mapper = $this->mapper();
		$post = $this->post('<p>look at this</p>');
		$post->setQuote($quoted->getId());

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];
		$ref = ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kquoted', 'cid' => Cid::forRaw('3kquoted')->toString()];
		$this->assertSame(['$type' => 'app.bsky.embed.record', 'record' => $ref], $record['embed']);
		$this->assertSame('look at this', $record['text'], 'no link: the quote is embedded');
		$this->lexicon->validateRecord($record);

		$document = new Document();
		$document->setNid(1);
		$document->setId('https://social.test/doc/1');
		$attachment = new MediaAttachment();
		$attachment->setId('1');
		$attachment->setType('image');
		$post->setAttachments([$attachment]);
		$this->documents->method('getMediaFromArray')->willReturn([$document]);
		$this->pictures->method('blobFor')->willReturn(['blob' => new BlobRef(self::DID, Cid::forRaw('x'), 'https://social.test/doc/1', 'image/jpeg', 100), 'width' => 4, 'height' => 3]);
		$withMedia = $this->mapper->post($post, $this->identity, $this->author)['record'];
		$this->assertSame('app.bsky.embed.recordWithMedia', $withMedia['embed']['$type']);
		$this->assertSame(['$type' => 'app.bsky.embed.record', 'record' => $ref], $withMedia['embed']['record']);
		$this->assertSame('app.bsky.embed.images', $withMedia['embed']['media']['$type']);
		$this->lexicon->validateRecord($withMedia);
	}

	public function testAQuoteKeepsItsExternalCardAsTheRecordWithMediaEmbed(): void {
		$quoted = $this->blueskyPost('3kquoted');
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willReturn($quoted);
		$card = new StreamCard(self::POST_ID, 'https://example.com/story');
		$card->setTitle('A story');
		$card->setDescription('Details');
		$this->cards = $this->createMock(StreamCardsRequest::class);
		$this->cards->method('getByStreamId')->with(self::POST_ID)->willReturn($card);
		$this->mapper = $this->mapper();
		$post = $this->post('<p>look at this</p>');
		$post->setQuote($quoted->getId());

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame('app.bsky.embed.recordWithMedia', $record['embed']['$type']);
		$this->assertSame('app.bsky.embed.external', $record['embed']['media']['$type']);
		$this->assertSame('https://example.com/story', $record['embed']['media']['external']['uri']);
		$this->lexicon->validateRecord($record);
	}

	public function testSelfLabelsFromAnImportedPostSurviveRepublishing(): void {
		$post = $this->post('Sensitive announcement');
		$post->setSpoilerText('Content warning');
		$post->setDetailArray(Details::ATPROTO, [Details::ATPROTO_SELF_LABELS => ['!warn', 'custom-label']]);

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$this->assertSame([
			'$type' => 'com.atproto.label.defs#selfLabels',
			'values' => [['val' => '!warn'], ['val' => 'custom-label']],
		], $record['labels']);
		$this->lexicon->validateRecord($record);
	}

	public function testAVideoTheVideoServiceMadeIsAVideoEmbed(): void {
		$post = $this->post('<p>At the beach</p>');
		$blob = new BlobRef(self::DID, Cid::forRaw('a video'), 'https://social.test/documents/local/8', 'video/mp4', 4000000);

		$record = $this->mapper->post($post, $this->identity, $this->author, ['state' => 'ready', 'blob' => $blob, 'alt' => 'Waves', 'width' => 1080, 'height' => 1920])['record'];

		$this->assertSame('At the beach', $record['text'], 'nothing to link: the video is there');
		$this->assertSame([
			'$type' => 'app.bsky.embed.video',
			'video' => $blob->toRecordValue(),
			'alt' => 'Waves',
			'aspectRatio' => ['width' => 1080, 'height' => 1920],
		], $record['embed']);
		$this->lexicon->validateRecord($record);
	}

	public function testAVideoThatIsNotABlueskyVideoLinksToThePost(): void {
		$record = $this->mapper->post($this->post('<p>At the beach</p>'), $this->identity, $this->author, ['state' => 'link'])['record'];

		$this->assertSame("At the beach\n\n" . self::POST_ID, $record['text']);
		$this->assertSame(self::POST_ID, end($record['facets'])['features'][0]['uri']);
		$this->assertArrayNotHasKey('embed', $record);
	}

	public function testALinkPreviewIsAnExternalCardWhenThereAreNoPictures(): void {
		$card = new StreamCard(self::POST_ID, 'https://nextcloud.com/blog/');
		$card->setTitle('Nextcloud Hub');
		$card->setDescription(str_repeat('A long description. ', 40));
		$this->cards = $this->createMock(StreamCardsRequest::class);
		$this->cards->method('getByStreamId')->with(self::POST_ID)->willReturn($card);
		$this->mapper = $this->mapper();

		$record = $this->mapper->post($this->post('<p>read <a href="https://nextcloud.com/blog/">this</a></p>'), $this->identity, $this->author)['record'];

		$this->assertSame('app.bsky.embed.external', $record['embed']['$type']);
		$this->assertSame('https://nextcloud.com/blog/', $record['embed']['external']['uri']);
		$this->assertSame('Nextcloud Hub', $record['embed']['external']['title']);
		$this->assertLessThanOrEqual(300, mb_strlen($record['embed']['external']['description']));
		$this->assertArrayNotHasKey('thumb', $record['embed']['external'], 'no picture to be had, none named');
		$this->lexicon->validateRecord($record);

		$untitled = new StreamCard(self::POST_ID, 'https://nextcloud.com/');
		$this->cards = $this->createMock(StreamCardsRequest::class);
		$this->cards->method('getByStreamId')->willReturn($untitled);
		$this->assertArrayNotHasKey('embed', $this->mapper()->post($this->post('<p>x</p>'), $this->identity, $this->author)['record'], 'a card with no title is no card');
	}

	public function testALinkCardCarriesThePagesPicture(): void {
		$card = new StreamCard(self::POST_ID, 'https://nextcloud.com/blog/');
		$card->setTitle('Nextcloud Hub');
		$card->setImage('https://nextcloud.com/hub.jpg');
		$this->cards = $this->createMock(StreamCardsRequest::class);
		$this->cards->method('getByStreamId')->willReturn($card);
		$thumb = new BlobRef(self::DID, Cid::forRaw('hub'), 'https://social.test/documents/7', 'image/jpeg', 3);
		$this->thumbnails->expects($this->once())->method('blobFor')->with($this->identity, $this->author, $card)->willReturn($thumb);

		$record = $this->mapper()->post($this->post('<p>read <a href="https://nextcloud.com/blog/">this</a></p>'), $this->identity, $this->author)['record'];

		$this->assertSame($thumb->toRecordValue(), $record['embed']['external']['thumb']);
		$this->lexicon->validateRecord($record);
	}

	public function testARestrictedQuotePolicyIsAPostgate(): void {
		$post = $this->post('<p>mine</p>');
		$this->assertNull($this->mapper->postgate($post, 'at://' . self::DID . '/app.bsky.feed.post/3k'));
		$post->setQuotePolicy('public');
		$this->assertNull($this->mapper->postgate($post, 'at://' . self::DID . '/app.bsky.feed.post/3k'));
		foreach (['followers', 'nobody'] as $policy) {
			$post->setQuotePolicy($policy);
			$gate = $this->mapper->postgate($post, 'at://' . self::DID . '/app.bsky.feed.post/3k');
			$this->assertSame([['$type' => 'app.bsky.feed.postgate#disableRule']], $gate['embeddingRules'], $policy);
			$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3k', $gate['post']);
			$this->lexicon->validateRecord($gate);
		}
	}

	public function testADetachedQuoteIsListedInThePostgate(): void {
		$uri = 'at://' . self::DID . '/app.bsky.feed.post/3k';
		$post = $this->post('<p>mine</p>');
		$post->addDetachedQuote('at://did:plc:bob/app.bsky.feed.post/3kq');
		$gate = $this->mapper->postgate($post, $uri);
		$this->assertSame(['at://did:plc:bob/app.bsky.feed.post/3kq'], $gate['detachedEmbeddingUris']);
		$this->assertArrayNotHasKey('embeddingRules', $gate, 'anybody may still quote it');
		$this->lexicon->validateRecord($gate);

		$post->setQuotePolicy('nobody');
		$gate = $this->mapper->postgate($post, $uri);
		$this->assertSame([['$type' => 'app.bsky.feed.postgate#disableRule']], $gate['embeddingRules']);
		$this->assertSame(['at://did:plc:bob/app.bsky.feed.post/3kq'], $gate['detachedEmbeddingUris']);
		$this->lexicon->validateRecord($gate);
	}

	public function testTheRepliesTheAuthorHidAreInTheThreadgate(): void {
		$uri = 'at://' . self::DID . '/app.bsky.feed.post/3k';
		$post = $this->post('<p>mine</p>');
		$post->setHiddenReplies(['https://bsky.app/profile/did:plc:bob/post/3kr', 'https://remote.example/notes/not-on-bluesky']);
		$reply = (new Note())->setId('https://bsky.app/profile/did:plc:bob/post/3kr');
		$reply->setDetailArray(PostMapper::DETAIL, ['uri' => 'at://did:plc:bob/app.bsky.feed.post/3kr', 'cid' => Cid::forRaw('r')->toString()]);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willReturnCallback(static fn (string $id): Note => $id === $reply->getId() ? $reply : throw new StreamNotFoundException());

		$gate = $this->mapper()->threadgate($post, $uri);

		$this->assertSame(['at://did:plc:bob/app.bsky.feed.post/3kr'], $gate['hiddenReplies']);
		$this->assertArrayNotHasKey('allow', $gate, 'anybody may still reply');
		$this->lexicon->validateRecord($gate);
	}

	public function testACombinationIsARuleEachAndAListIsTheBlueskyListItWasPublishedAs(): void {
		$uri = 'at://' . self::DID . '/app.bsky.feed.post/3k';
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('getRecordsByLocalId')->willReturnCallback(static fn (string $id): array => $id === 'list:7'
			? [new StoredRecord(self::DID, 'app.bsky.graph.list', '3klist', Cid::forRaw('l'), '', 'list:7', 0)]
			: []);
		$post = $this->post('<p>mine</p>');
		$post->setReplyRule('followers,mentioned,list:7,list:8');

		$gate = $this->mapper()->threadgate($post, $uri);

		$this->assertSame([
			['$type' => 'app.bsky.feed.threadgate#followerRule'],
			['$type' => 'app.bsky.feed.threadgate#mentionRule'],
			['$type' => 'app.bsky.feed.threadgate#listRule', 'list' => 'at://' . self::DID . '/app.bsky.graph.list/3klist'],
		], $gate['allow'], 'a list never published is left out, which is stricter');
		$this->lexicon->validateRecord($gate);
	}

	public function testWhoMayReplyIsAThreadgate(): void {
		$uri = 'at://' . self::DID . '/app.bsky.feed.post/3k';
		$post = $this->post('<p>mine</p>');
		$this->assertNull($this->mapper->threadgate($post, $uri));
		$post->setReplyRule('everyone');
		$this->assertNull($this->mapper->threadgate($post, $uri));
		foreach (['followers' => ['followerRule'], 'following' => ['followingRule'], 'mentioned' => ['mentionRule'], 'nobody' => []] as $rule => $allow) {
			$post->setReplyRule($rule);
			$gate = $this->mapper->threadgate($post, $uri);
			$this->assertSame(array_map(static fn (string $r): array => ['$type' => 'app.bsky.feed.threadgate#' . $r], $allow), $gate['allow'], $rule);
			$this->assertSame($uri, $gate['post']);
			$this->lexicon->validateRecord($gate);
		}
	}

	public function testAReferenceWithABrokenCidIsNotOnBluesky(): void {
		$quoted = new Note();
		$quoted->setId('https://bsky.app/profile/' . self::OTHER . '/post/3kbad');
		$quoted->setDetailArray(PostMapper::DETAIL, ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kbad', 'cid' => 'not-a-cid']);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willReturn($quoted);
		$post = $this->post('<p>see</p>');
		$post->setQuote($quoted->getId());

		$record = $this->mapper()->post($post, $this->identity, $this->author)['record'];
		$this->assertArrayNotHasKey('embed', $record, 'linked instead of embedded');
		$this->assertStringContainsString($quoted->getId(), $record['text']);
		$this->lexicon->validateRecord($record);
	}

	public function testAMentionOfABlueskyAccountIsAMentionFacetWithItsDid(): void {
		$post = $this->post('<p>hi <span class="h-card"><a href="https://bsky.app/profile/' . self::OTHER . '" class="u-url mention">@<span>bob.bsky.social</span></a></span></p>');
		$post->addTag(['type' => 'Mention', 'href' => 'https://bsky.app/profile/' . self::OTHER, 'name' => '@bob.bsky.social']);

		$record = $this->mapper->post($post, $this->identity, $this->author)['record'];

		$mentions = array_values(array_filter($record['facets'] ?? [], static fn (array $f): bool => $f['features'][0]['$type'] === 'app.bsky.richtext.facet#mention'));
		$this->assertCount(1, $mentions);
		$this->assertSame(self::OTHER, $mentions[0]['features'][0]['did']);
		$this->lexicon->validateRecord($record);
	}

	public function testAProfile(): void {
		$this->author->setName('Alice ' . str_repeat('x', 100));
		$this->author->setDescription('<p>I run <a href="https://nextcloud.com">Nextcloud</a></p>');

		$record = $this->mapper->profile($this->author, $this->identity);

		$this->assertSame(64, Lexicon::graphemes($record['displayName']));
		$this->assertSame('I run Nextcloud (https://nextcloud.com)', $record['description']);
		$this->assertArrayNotHasKey('avatar', $record);
		$this->assertArrayNotHasKey('pronouns', $record);
		$this->assertArrayNotHasKey('website', $record);
		$this->lexicon->validateRecord($record);
	}

	/**
	 * A profile record has no facets, so a mention in the bio can only be
	 * its text: `@bob` stays `@bob`, `@bob@host` stays whole, whether the
	 * bio is the plain text a local account keeps or the HTML a remote one
	 * arrived with.
	 */
	public function testAMentionInTheBioIsKeptAsItsText(): void {
		$this->author->setDescription('Ask @bob or @alice@remote.example about <b>tags</b>');
		$this->assertSame('Ask @bob or @alice@remote.example about tags', $this->mapper->profile($this->author, $this->identity)['description']);

		$this->author->setDescription('<p>Ask <span class="h-card"><a href="https://social.test/@bob" class="u-url mention">@<span>bob</span></a></span> or <a href="https://bsky.app/profile/' . self::OTHER . '" class="u-url mention">@carol.bsky.social</a></p>');
		$this->assertSame('Ask @bob or @carol.bsky.social', $this->mapper->profile($this->author, $this->identity)['description']);
	}

	/** A verification of the account names the name the profile carries, or it does not hold. */
	public function testTheProfilesDisplayNameIsTheNameTheAccountFederates(): void {
		$this->author->setName('Alice Liddell');

		$this->assertSame('Alice Liddell', $this->mapper->profile($this->author, $this->identity)['displayName'], 'not the username');
		$this->assertSame('alice', RecordMapper::displayNameOf((new Person())->setPreferredUsername('alice')), 'one that federates none: its username, as on the Fediverse');
	}

	public function testTheProfilesDisplayNameIsTheOneAVerificationNames(): void {
		$this->author->setName('  Alice ' . str_repeat('x', 100));

		$this->assertSame($this->mapper->profile($this->author, $this->identity)['displayName'], RecordMapper::displayNameOf($this->author));
		$this->assertSame('', RecordMapper::displayNameOf((new Person())->setPreferredUsername('')));
	}

	public function testAProfileCarriesThePronounsAndTheWebsiteFromItsRows(): void {
		$this->author->setFields([['name' => 'Pronouns', 'value' => 'she/her'], ['name' => 'Website', 'value' => 'https://alice.example']]);

		$record = $this->mapper->profile($this->author, $this->identity);

		$this->assertSame('she/her', $record['pronouns']);
		$this->assertSame('https://alice.example', $record['website']);
		$this->lexicon->validateRecord($record);
	}

	public function testAProfileCarriesTheAccountsOwnAvatar(): void {
		$avatar = new BlobRef(self::DID, Cid::forRaw('avatar'), 'https://social.test/doc/avatar', 'image/png', 6);
		$this->pictures->method('avatarBlob')->with($this->identity, $this->author, PictureService::PROFILE_MAX_BYTES, PictureService::PROFILE_TYPES)->willReturn(['blob' => $avatar, 'width' => 4, 'height' => 4]);

		$record = $this->mapper->profile($this->author, $this->identity);

		$this->assertSame($avatar->toRecordValue(), $record['avatar']);
		$this->lexicon->validateRecord($record);
	}

	public function testAProfileCarriesItsBannerAndItsNewestPinOnBlueskyWithinTheProfilesLimits(): void {
		$banner = new Document();
		$banner->setId('https://social.test/documents/header/1');
		$banner->setLocalCopy('5f0c3e4a-1d2b-4c3d-8e9f-0a1b2c3d4e5f');
		$this->author->setHeader('https://social.test/media/banner.png');
		$this->cacheDocuments = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocuments->method('getByUrl')->with('https://social.test/media/banner.png')->willReturn($banner);
		$blob = new BlobRef(self::DID, Cid::forRaw('the sea'), $banner->getId(), 'image/jpeg', 7);
		$this->pictures->expects($this->once())->method('blobFor')
			->with($this->identity, $this->author, $banner, PictureService::PROFILE_MAX_BYTES, PictureService::PROFILE_TYPES)
			->willReturn(['blob' => $blob, 'width' => 1500, 'height' => 500]);
		$this->pins->method('getPinnedIds')->willReturn(['https://social.test/@alice/not-on-bluesky', self::POST_ID]);
		$pinned = new StoredRecord(self::DID, RecordMapper::POST, '3kpinned', Cid::forRaw('pinned'), '', self::POST_ID, 0);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('getRecordsByLocalId')->willReturnCallback(static fn (string $id): array => $id === self::POST_ID ? [$pinned] : []);

		$record = $this->mapper()->profile($this->author, $this->identity);

		$this->assertSame($blob->toRecordValue(), $record['banner']);
		$this->assertSame(['uri' => $pinned->uri(), 'cid' => $pinned->cid->toString()], $record['pinnedPost'], 'the newest pin that is on Bluesky');
		$this->lexicon->validateRecord($record);
	}

	private function mapper(): RecordMapper {
		$refs = new PostRefs($this->repositories, $this->streams, $this->appView, new NullLogger());
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		});
		$actors = $this->createStub(ActorsRequest::class);
		$actors->method('userIdsOf')->willReturnCallback(fn (array $usernames): array => array_intersect_key($this->userIds, array_flip($usernames)));

		return new RecordMapper(new TextMapper(), $this->pictures, $this->documents, $this->identities, $this->repositories, $refs, $this->cards, $this->thumbnails, $this->cacheDocuments, $this->pins, $this->l10nFactory(), $users, $actors);
	}

	/**
	 * The app's translations as its catalogues in `l10n/` have them, and a
	 * user's language as `$userLanguages` says.
	 */
	private function l10nFactory(): IFactory {
		$factory = $this->createStub(IFactory::class);
		$catalogue = static fn (string $language): ?string => is_file($file = dirname(__DIR__, 3) . '/l10n/' . $language . '.json') ? $file : null;
		$factory->method('languageExists')->willReturnCallback(static fn (string $app, string $language): bool => $language === 'en' || $catalogue($language) !== null);
		$factory->method('getUserLanguage')->willReturnCallback(fn (?IUser $user = null): string => $user === null ? 'en' : ($this->userLanguages[$user->getUID()] ?? 'en'));
		$factory->method('get')->willReturnCallback(function (string $app, ?string $language = null) use ($catalogue): IL10N {
			$file = $catalogue((string)$language);
			$translations = $file === null ? [] : json_decode((string)file_get_contents($file), true)['translations'];
			$l10n = $this->createStub(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($translations[$text] ?? $text, $params));
			$l10n->method('n')->willReturnCallback(static function (string $one, string $many, int $count) use ($translations): string {
				$forms = $translations['_' . $one . '_::_' . $many . '_'] ?? [$one, $many];

				return str_replace('%n', (string)$count, $count === 1 ? $forms[0] : $forms[1]);
			});

			return $l10n;
		});

		return $factory;
	}

	/**
	 * Gives a post of alice's this many pictures, each a blob when asked.
	 */
	private function withPictures(Note $post, int $count, int $width = 40, int $height = 30): void {
		$documents = [];
		$attachments = [];
		for ($i = 1; $i <= $count; $i++) {
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
		$this->documents->method('getMediaFromArray')
			->with(array_map('strval', range(1, $count)), 'alice')->willReturn($documents);
		$this->pictures->method('blobFor')->willReturnCallback(function (Identity $identity, Person $owner, Document $document) use ($width, $height): array {
			return ['blob' => new BlobRef(self::DID, Cid::forRaw($document->getId()), $document->getId(), 'image/jpeg', 100), 'width' => $width, 'height' => $height];
		});
	}

	private function blueskyPost(string $rkey, ?array $replyRoot = null): Note {
		$note = new Note();
		$note->setId('https://bsky.app/profile/' . self::OTHER . '/post/' . $rkey);
		$note->setDetailArray(PostMapper::DETAIL, ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/' . $rkey, 'cid' => Cid::forRaw($rkey)->toString(), 'reply_root' => $replyRoot]);

		return $note;
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
