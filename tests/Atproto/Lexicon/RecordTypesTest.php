<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Lexicon;

use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Lexicon\LexiconException;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every record type that goes into a repository here, whether this app
 * writes it (§5.1) or a Bluesky app signed in here does: one record of
 * each, shaped as Bluesky's lexicon documents it, fits the vendored
 * lexicon; the same record with a required field missing, or a field of
 * the wrong format, does not.
 *
 * And the rules for what arrives from elsewhere: a field the lexicon does
 * not name is kept (lexicons only grow), a value of an open union's type
 * the app does not know passes, a string outside `knownValues` passes;
 * a closed union, a `const` and the limits are held to.
 */
class RecordTypesTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const POST = 'at://did:plc:ewvi7nxzyoun6zhxrhs64oiz/app.bsky.feed.post/3kznmn7xqxl22';
	private const LIST = 'at://did:plc:ewvi7nxzyoun6zhxrhs64oiz/app.bsky.graph.list/3kznmn7xqxl23';
	private const NOW = '2026-10-09T12:00:00.000Z';

	private Lexicon $lexicon;

	protected function setUp(): void {
		$this->lexicon = new Lexicon();
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> record, a required field, a field with a format
	 */
	public static function records(): array {
		$strongRef = ['uri' => self::POST, 'cid' => 'bafyreidfayvfuwqa7qlnopdjiqrxzs6blmoeu4rujcjtnci5beludirz2a'];
		$picture = ['$type' => 'blob', 'ref' => ['$link' => 'bafkreibme22gw2h7y2h7tg2fhqotaqjucnbc24deqo72b6mkl2egezxhvy'], 'mimeType' => 'image/jpeg', 'size' => 1000];

		return [
			'post' => [[
				'$type' => 'app.bsky.feed.post',
				'text' => 'Hello @bob.social.example.com',
				'facets' => [['index' => ['byteStart' => 6, 'byteEnd' => 29], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => self::BOB]]]],
				'langs' => ['en'],
				'reply' => ['root' => $strongRef, 'parent' => $strongRef],
				'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['image' => $picture, 'alt' => 'a picture', 'aspectRatio' => ['width' => 4, 'height' => 3]]]],
				'labels' => ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => 'graphic-media']]],
				'createdAt' => self::NOW,
			], 'createdAt', 'langs'],
			'like' => [['$type' => 'app.bsky.feed.like', 'subject' => $strongRef, 'createdAt' => self::NOW], 'subject', 'createdAt'],
			'repost' => [['$type' => 'app.bsky.feed.repost', 'subject' => $strongRef, 'createdAt' => self::NOW], 'subject', 'createdAt'],
			'follow' => [['$type' => 'app.bsky.graph.follow', 'subject' => self::BOB, 'createdAt' => self::NOW], 'subject', 'subject'],
			'block' => [['$type' => 'app.bsky.graph.block', 'subject' => self::BOB, 'createdAt' => self::NOW], 'subject', 'subject'],
			'profile' => [[
				'$type' => 'app.bsky.actor.profile',
				'displayName' => 'Alice',
				'description' => 'Writes here',
				'avatar' => $picture,
				'banner' => $picture,
				'pinnedPost' => $strongRef,
				'createdAt' => self::NOW,
			], '', 'createdAt'],
			'list' => [['$type' => 'app.bsky.graph.list', 'purpose' => 'app.bsky.graph.defs#curatelist', 'name' => 'Friends', 'description' => 'People', 'createdAt' => self::NOW], 'name', 'createdAt'],
			'listitem' => [['$type' => 'app.bsky.graph.listitem', 'subject' => self::BOB, 'list' => self::LIST, 'createdAt' => self::NOW], 'list', 'list'],
			'threadgate' => [[
				'$type' => 'app.bsky.feed.threadgate',
				'post' => self::POST,
				'allow' => [['$type' => 'app.bsky.feed.threadgate#mentionRule'], ['$type' => 'app.bsky.feed.threadgate#followingRule'], ['$type' => 'app.bsky.feed.threadgate#listRule', 'list' => self::LIST]],
				'hiddenReplies' => [self::POST],
				'createdAt' => self::NOW,
			], 'post', 'post'],
			'postgate' => [[
				'$type' => 'app.bsky.feed.postgate',
				'post' => self::POST,
				'embeddingRules' => [['$type' => 'app.bsky.feed.postgate#disableRule']],
				'detachedEmbeddingUris' => [self::POST],
				'createdAt' => self::NOW,
			], 'post', 'post'],
			'starterpack' => [['$type' => 'app.bsky.graph.starterpack', 'name' => 'Start here', 'list' => self::LIST, 'feeds' => [['uri' => 'at://' . self::ALICE . '/app.bsky.feed.generator/news']], 'createdAt' => self::NOW], 'list', 'list'],
			'generator' => [['$type' => 'app.bsky.feed.generator', 'did' => 'did:web:feeds.example.com', 'displayName' => 'News', 'createdAt' => self::NOW], 'did', 'did'],
		];
	}

	#[DataProvider('records')]
	public function testARecordOfTheTypeFits(array $record, string $required, string $formatted): void {
		$this->lexicon->validateRecord(DagCbor::fromLexJson($record));
		$this->addToAssertionCount(1);
	}

	#[DataProvider('records')]
	public function testARecordWithoutARequiredFieldDoesNotFit(array $record, string $required, string $formatted): void {
		if ($required === '') {
			$this->assertFalse($this->lexicon->fits(['$type' => 'app.bsky.actor.profile', 'displayName' => str_repeat('a', 641)]), 'a profile requires nothing, so its limits stand in');

			return;
		}
		unset($record[$required]);

		$this->expectException(LexiconException::class);
		$this->expectExceptionMessage($required);
		$this->lexicon->validateRecord(DagCbor::fromLexJson($record));
	}

	#[DataProvider('records')]
	public function testARecordWithAFieldOfTheWrongFormatDoesNotFit(array $record, string $required, string $formatted): void {
		$record[$formatted] = is_array($record[$formatted]) && array_is_list($record[$formatted]) ? ['not a language tag!'] : 'not-of-the-format';

		$this->expectException(LexiconException::class);
		$this->lexicon->validateRecord(DagCbor::fromLexJson($record));
	}

	#[DataProvider('records')]
	public function testAFieldTheLexiconDoesNotNameIsKept(array $record, string $required, string $formatted): void {
		$record['someFutureField'] = ['nested' => true];

		$this->assertTrue($this->lexicon->fits(DagCbor::fromLexJson($record)));
	}

	public function testAnOpenUnionTakesATypeTheAppDoesNotKnow(): void {
		$post = ['$type' => 'app.bsky.feed.post', 'text' => 'something new', 'createdAt' => self::NOW, 'embed' => ['$type' => 'com.example.embed.poll', 'options' => ['a', 'b']]];

		$this->assertTrue($this->lexicon->fits($post));
	}

	/**
	 * The post lexicon names the gallery embed among its members, so the
	 * gallery's own document has to be there for such a post to be checked
	 * at all; without it every gallery post a Bluesky app writes here was
	 * refused.
	 */
	public function testAPostWithAGalleryFits(): void {
		$image = ['$type' => 'app.bsky.embed.gallery#image', 'image' => ['$type' => 'blob', 'ref' => Cid::forRaw('p'), 'mimeType' => 'image/png', 'size' => 1000], 'alt' => '', 'aspectRatio' => ['width' => 1, 'height' => 1]];
		$post = ['$type' => 'app.bsky.feed.post', 'text' => 'a gallery', 'createdAt' => self::NOW, 'embed' => ['$type' => 'app.bsky.embed.gallery', 'items' => [$image, $image]]];

		$this->lexicon->validateRecord($post);
		$this->assertFalse($this->lexicon->fits(['embed' => ['$type' => 'app.bsky.embed.gallery', 'items' => array_fill(0, 21, $image)]] + $post), 'at most twenty');
		$this->assertFalse($this->lexicon->fits(['embed' => ['$type' => 'app.bsky.embed.gallery', 'items' => [array_diff_key($image, ['aspectRatio' => true])]]] + $post), 'each picture needs its aspect ratio');
	}

	public function testAKnownMemberOfAnOpenUnionIsStillChecked(): void {
		$post = ['$type' => 'app.bsky.feed.post', 'text' => 'a link', 'createdAt' => self::NOW, 'embed' => ['$type' => 'app.bsky.embed.external', 'external' => ['uri' => 'https://example.com']]];

		$this->assertFalse($this->lexicon->fits($post), 'an external embed needs a title and a description');
	}

	public function testAUnionMemberNeedsAType(): void {
		$post = ['$type' => 'app.bsky.feed.post', 'text' => 'x', 'createdAt' => self::NOW, 'embed' => ['images' => []]];

		$this->assertFalse($this->lexicon->fits($post));
	}

	public function testAStringOutsideTheKnownValuesIsKept(): void {
		$list = ['$type' => 'app.bsky.graph.list', 'purpose' => 'app.bsky.graph.defs#somethingNew', 'name' => 'x', 'createdAt' => self::NOW];

		$this->assertTrue($this->lexicon->fits($list), 'knownValues is a hint, not an enum');
	}

	public static function outOfLimits(): array {
		return [
			'a post of 301 graphemes' => [['$type' => 'app.bsky.feed.post', 'text' => str_repeat('a', 301), 'createdAt' => self::NOW]],
			'a post with five pictures' => [['$type' => 'app.bsky.feed.post', 'text' => '', 'createdAt' => self::NOW, 'embed' => ['$type' => 'app.bsky.embed.images', 'images' => array_fill(0, 5, ['image' => ['$type' => 'blob', 'ref' => Cid::forRaw('p'), 'mimeType' => 'image/png', 'size' => 1], 'alt' => ''])]]],
			'a picture over a megabyte' => [['$type' => 'app.bsky.feed.post', 'text' => '', 'createdAt' => self::NOW, 'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['image' => ['$type' => 'blob', 'ref' => Cid::forRaw('p'), 'mimeType' => 'image/png', 'size' => 2000001], 'alt' => '']]]]],
			'an avatar that is a video' => [['$type' => 'app.bsky.actor.profile', 'avatar' => ['$type' => 'blob', 'ref' => Cid::forRaw('v'), 'mimeType' => 'video/mp4', 'size' => 1]]],
			'a blob whose ref is a string' => [['$type' => 'app.bsky.actor.profile', 'avatar' => ['$type' => 'blob', 'ref' => 'bafkreibme22gw2h7y2h7tg2fhqotaqjucnbc24deqo72b6mkl2egezxhvy', 'mimeType' => 'image/png', 'size' => 1]]],
			'a threadgate with six rules' => [['$type' => 'app.bsky.feed.threadgate', 'post' => self::POST, 'createdAt' => self::NOW, 'allow' => array_fill(0, 6, ['$type' => 'app.bsky.feed.threadgate#mentionRule'])]],
			'a threadgate list rule without its list' => [['$type' => 'app.bsky.feed.threadgate', 'post' => self::POST, 'createdAt' => self::NOW, 'allow' => [['$type' => 'app.bsky.feed.threadgate#listRule']]]],
			'a list name over 64 characters' => [['$type' => 'app.bsky.graph.list', 'purpose' => 'app.bsky.graph.defs#curatelist', 'name' => str_repeat('a', 65), 'createdAt' => self::NOW]],
			'a list without a name' => [['$type' => 'app.bsky.graph.list', 'purpose' => 'app.bsky.graph.defs#curatelist', 'name' => '', 'createdAt' => self::NOW]],
			'a like whose subject has no CID' => [['$type' => 'app.bsky.feed.like', 'subject' => ['uri' => self::POST], 'createdAt' => self::NOW]],
			'a follow of a handle' => [['$type' => 'app.bsky.graph.follow', 'subject' => 'bob.social.example.com', 'createdAt' => self::NOW]],
			'a datetime without a zone' => [['$type' => 'app.bsky.graph.follow', 'subject' => self::BOB, 'createdAt' => '2026-10-09T12:00:00']],
			'a null where nothing is nullable' => [['$type' => 'app.bsky.feed.post', 'text' => 'x', 'createdAt' => self::NOW, 'langs' => null]],
			'a type that is not a record' => [['$type' => 'app.bsky.feed.defs', 'text' => 'x']],
		];
	}

	#[DataProvider('outOfLimits')]
	public function testWhatTheLexiconRefusesDoesNotFit(array $record): void {
		$this->assertFalse($this->lexicon->fits(DagCbor::fromLexJson($record)));
	}
}
