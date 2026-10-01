<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Reference;

use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Reference\PostReferenceProvider;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\StreamService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A link to a post or a profile of this app becomes a card; anything narrower
 * than public stays a link, and nothing is fetched to find out.
 */
class PostReferenceProviderTest extends TestCase {
	private const APP = 'https://cloud.example/index.php/apps/social/';
	private const PRETTY = 'https://cloud.example/apps/social/';
	private const SOCIAL_URL = 'https://cloud.example/index.php/apps/social/';

	private StreamService|Stub $streamService;
	private CacheActorService|Stub $cacheActorService;
	private PostReferenceProvider $provider;

	/** @var array<int, Stream> */
	private array $posts = [];
	/** @var array<string, Stream> */
	private array $byId = [];
	/** @var array<string, Person> */
	private array $accounts = [];
	/** @var array<int, array{string, bool}> every getFromAccount() call: the handle and whether a fetch was allowed */
	private array $lookups = [];

	protected function setUp(): void {
		$this->streamService = $this->createStub(StreamService::class);
		$this->streamService->method('getStreamByNid')->willReturnCallback(function (int $nid): Stream {
			return $this->posts[$nid] ?? throw new StreamNotFoundException('no post ' . $nid);
		});
		$this->streamService->method('getStreamById')->willReturnCallback(function (string $id): Stream {
			return $this->byId[$id] ?? throw new StreamNotFoundException('no post ' . $id);
		});

		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromLocalAccount')->willReturnCallback(function (string $account): Person {
			return $this->accounts[$account] ?? throw new CacheActorDoesNotExistException('unknown');
		});
		$this->cacheActorService->method('getFromAccount')->willReturnCallback(function (string $account, bool $retrieve = true): Person {
			$this->lookups[] = [$account, $retrieve];

			return $this->accounts[$account] ?? throw new CacheActorDoesNotExistException('unknown');
		});

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args = []): string => match ($route) {
				'social.Navigation.navigate' => self::APP,
				'social.ActivityPub.displayPost' => self::APP . '@' . $args['username'] . '/' . $args['token'],
				'social.ActivityPub.actorAlias' => self::APP . '@' . $args['username'] . '/',
				default => 'unexpected route ' . $route,
			}
		);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []): string => ((array)$params === []) ? $text : vsprintf($text, (array)$params)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string
				=> str_replace('%n', (string)$count, ($count === 1) ? $singular : $plural)
		);

		$this->provider = new PostReferenceProvider(
			$this->streamService, $this->cacheActorService, $configService, $urlGenerator, $l10n, new NullLogger()
		);

		$alice = (new Person())->setPreferredUsername('alice')->setAccount('alice@cloud.example')
			->setDisplayName('Alice Adams')->setAvatar(self::APP . 'avatar/alice')->setUserId('alice');
		$alice->setDescription('<p>Gardener. <a href="https://example.org">Plants</a>.</p>');
		$this->accounts['alice'] = $alice;

		$this->posts[42] = $this->post($alice, 42, Stream::TYPE_PUBLIC, '<p>Hello <b>world</b>!</p><p>Second paragraph.</p>');
		$this->posts[43] = $this->post($alice, 43, Stream::TYPE_FOLLOWERS, '<p>For my followers only</p>');
	}

	// matching

	/** @return iterable<string, array{string, bool}> */
	public static function links(): iterable {
		yield 'a post, as the browser shows it' => [self::APP . '@alice/42', true];
		yield 'a post, pretty URL' => [self::PRETTY . '@alice/42', true];
		yield 'a post, trailing slash and a fragment' => [self::APP . '@alice/42/#reply', true];
		yield 'the logged-out page, by id tail' => [self::APP . '@alice/17580000000012345678', true];
		yield 'a nineteen-digit nid' => [self::APP . '@alice/1789347296405416473', true];
		yield 'a profile' => [self::APP . '@alice', true];
		yield 'a remote profile cached here' => [self::PRETTY . '@bob@remote.example/', true];
		yield 'another server' => ['https://mastodon.example/@alice/42', false];
		yield 'another app' => ['https://cloud.example/index.php/apps/files/', false];
		yield 'a timeline' => [self::APP . 'timeline/home', false];
		yield 'the app itself' => [self::APP, false];
		yield 'not a URL' => ['@alice/42', false];
	}

	#[DataProvider('links')]
	public function testOnlyLinksToPostsAndProfilesOfThisAppMatch(string $link, bool $matches): void {
		$this->assertSame($matches, $this->provider->matchReference($link));
	}

	// posts

	public function testAPublicPostBecomesACardWithAuthorTextAndPicture(): void {
		$reference = $this->provider->resolveReference(self::APP . '@alice/42');

		$this->assertNotNull($reference);
		$this->assertTrue($reference->getAccessible());
		$this->assertSame('Alice Adams (@alice@cloud.example) on Aloha Social', $reference->getTitle());
		$this->assertSame('Hello world! Second paragraph. — 2 attachments', $reference->getDescription());
		$this->assertSame('https://cloud.example/preview/1.jpg', $reference->getImageUrl(), 'the first picture, not the video');
		$this->assertSame(self::APP . '@alice/42', $reference->getUrl(), 'the canonical page of the post');
		$this->assertSame('open-graph', $reference->getRichObjectType(), 'the default card, which every client renders');
	}

	public function testTheCardIsTheSameWhicheverWayTheLinkWasWritten(): void {
		$pretty = $this->provider->resolveReference(self::PRETTY . '@alice/42/');

		$this->assertNotNull($pretty);
		$this->assertSame('Alice Adams (@alice@cloud.example) on Aloha Social', $pretty->getTitle());
		$this->assertSame(self::PRETTY . '@alice/42/', $pretty->getId(), 'the id stays the text that was pasted');
	}

	public function testTheLoggedOutPageIsFoundByItsIdTail(): void {
		$post = $this->post($this->accounts['alice'], 0, Stream::TYPE_UNLISTED, '<p>Addressed by id</p>');
		$post->setId(self::SOCIAL_URL . '@alice/17580000000012345678');
		$this->byId[$post->getId()] = $post;

		$reference = $this->provider->resolveReference(self::APP . '@alice/17580000000012345678');

		$this->assertNotNull($reference);
		$this->assertSame('Addressed by id — 2 attachments', $reference->getDescription());
		$this->assertSame(self::APP . '@alice/17580000000012345678', $reference->getUrl(), 'no nid to canonicalise to: the link as pasted');
	}

	public function testANidIsNotMistakenForAnIdTailHoweverLongItIs(): void {
		// nids are generated, not counted: nineteen digits is what a real one looks like
		$this->posts[1789347296405416473] = $this->post($this->accounts['alice'], 1789347296405416473, Stream::TYPE_PUBLIC, '<p>By nid</p>');
		$this->posts[1789347296405416473]->setAttachments([]);

		$reference = $this->provider->resolveReference(self::APP . '@alice/1789347296405416473');

		$this->assertNotNull($reference);
		$this->assertSame('By nid', $reference->getDescription());
		$this->assertSame(self::APP . '@alice/1789347296405416473', $reference->getUrl());
	}

	public function testAFollowersOnlyPostStaysALink(): void {
		// the card would be cached for everyone, and not everyone may read it
		$this->assertNull($this->provider->resolveReference(self::APP . '@alice/43'));
	}

	public function testADirectPostStaysALink(): void {
		$this->posts[44] = $this->post($this->accounts['alice'], 44, Stream::TYPE_DIRECT, '<p>psst</p>');

		$this->assertNull($this->provider->resolveReference(self::APP . '@alice/44'));
	}

	public function testAPostThisServerDoesNotHoldStaysALink(): void {
		$this->assertNull($this->provider->resolveReference(self::APP . '@alice/99'));
	}

	public function testBehindAContentWarningOnlyTheWarningShows(): void {
		$post = $this->posts[42];
		$post->setSensitive(true);
		$post->setSummary('Spoilers for the finale');

		$reference = $this->provider->resolveReference(self::APP . '@alice/42');

		$this->assertNotNull($reference);
		$this->assertSame('Content warning: Spoilers for the finale', $reference->getDescription());
		$this->assertSame(self::APP . 'avatar/alice', $reference->getImageUrl(), 'the picture is what is warned about; the portrait instead');
	}

	public function testALongPostIsCutToCardLength(): void {
		$this->posts[42]->setContent('<p>' . str_repeat('word ', 200) . '</p>');
		$this->posts[42]->setAttachments([]);

		$reference = $this->provider->resolveReference(self::APP . '@alice/42');

		$this->assertNotNull($reference);
		$this->assertSame(PostReferenceProvider::EXCERPT_LENGTH, mb_strlen($reference->getDescription()));
		$this->assertStringEndsWith('…', $reference->getDescription());
	}

	public function testAPostWithoutPicturesShowsTheAuthorsPortrait(): void {
		$this->posts[42]->setAttachments([]);

		$reference = $this->provider->resolveReference(self::APP . '@alice/42');

		$this->assertNotNull($reference);
		$this->assertSame(self::APP . 'avatar/alice', $reference->getImageUrl());
		$this->assertSame('Hello world! Second paragraph.', $reference->getDescription());
	}

	// profiles

	public function testAProfileBecomesACardWithTheBio(): void {
		$reference = $this->provider->resolveReference(self::APP . '@alice');

		$this->assertNotNull($reference);
		$this->assertSame('Alice Adams (@alice@cloud.example) on Aloha Social', $reference->getTitle());
		$this->assertSame('Gardener. Plants.', $reference->getDescription());
		$this->assertSame(self::APP . 'avatar/alice', $reference->getImageUrl());
		$this->assertSame(self::APP . '@alice@cloud.example/', $reference->getUrl());
	}

	public function testARemoteProfileIsReadFromTheCacheAndNeverFetched(): void {
		$bob = (new Person())->setPreferredUsername('bob')->setAccount('bob@remote.example')->setDisplayName('Bob');
		$this->accounts['bob@remote.example'] = $bob;

		$reference = $this->provider->resolveReference(self::APP . '@bob@remote.example');

		$this->assertNotNull($reference);
		$this->assertSame('Bob (@bob@remote.example) on Aloha Social', $reference->getTitle());
		$this->assertNull($reference->getImageUrl(), 'no portrait known, none invented');
		$this->assertSame([['bob@remote.example', false]], $this->lookups, 'cached only: an unfurl is not a reason to call another server');
	}

	public function testAnUnknownRemoteProfileStaysALink(): void {
		$this->assertNull($this->provider->resolveReference(self::APP . '@nobody@remote.example'));
		$this->assertSame([['nobody@remote.example', false]], $this->lookups);
	}

	// caching and public shares

	public function testOneCardPerLinkForEveryone(): void {
		$link = self::APP . '@alice/42';

		$this->assertSame($link, $this->provider->getCachePrefix($link));
		$this->assertNull($this->provider->getCacheKey($link), 'nothing rendered depends on the viewer');
		$this->assertNull($this->provider->getCacheKeyPublic($link, 'sharetoken'));
	}

	public function testAPublicShareRendersTheSameCard(): void {
		$reference = $this->provider->resolveReferencePublic(self::APP . '@alice/42', 'sharetoken');

		$this->assertNotNull($reference);
		$this->assertSame('Alice Adams (@alice@cloud.example) on Aloha Social', $reference->getTitle());
		$this->assertNull($this->provider->resolveReferencePublic(self::APP . '@alice/43', 'sharetoken'));
	}

	// the excerpt

	/** @return iterable<string, array{string, string}> */
	public static function html(): iterable {
		yield 'paragraphs become spaces' => ['<p>One</p><p>Two</p>', 'One Two'];
		yield 'line breaks become spaces' => ['One<br>Two<br />Three', 'One Two Three'];
		yield 'entities are decoded' => ['Tom &amp; Jerry &lt;3', 'Tom & Jerry <3'];
		yield 'whitespace collapses' => ["  a \n\n  b\t c ", 'a b c'];
		yield 'nothing stays nothing' => ['<p></p>', ''];
	}

	#[DataProvider('html')]
	public function testTheExcerptIsPlainText(string $html, string $expected): void {
		$this->assertSame($expected, PostReferenceProvider::excerpt($html));
	}

	private function post(Person $author, int $nid, string $visibility, string $content): Stream {
		$post = new Note();
		$post->setId(self::SOCIAL_URL . '@alice/' . ($nid > 0 ? $nid : 'by-id'));
		$post->setNid($nid);
		$post->setLocal(true);
		$post->setActor($author);
		$post->setAttributedTo('https://cloud.example/users/alice');
		$post->setVisibility($visibility);
		$post->setContent($content);

		$video = new MediaAttachment();
		$video->setType('video');
		$video->setUrl('https://cloud.example/media/0.mp4');
		$video->setPreviewUrl('https://cloud.example/preview/0.jpg');
		$image = new MediaAttachment();
		$image->setType('image');
		$image->setUrl('https://cloud.example/media/1.jpg');
		$image->setPreviewUrl('https://cloud.example/preview/1.jpg');
		$post->setAttachments([$video, $image]);

		return $post;
	}
}
