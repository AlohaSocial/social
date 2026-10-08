<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use OCA\Social\Tools\SearchTerms;
use PHPUnit\Framework\TestCase;

/**
 * The words a post is indexed under and a search looks for. Both sides go
 * through the same normalisation, so what is tested here is what can be
 * found at all.
 */
class SearchTermsTest extends TestCase {
	public function testMarkupIsNotTextAndSeparatesWords(): void {
		$this->assertSame(
			['the', 'zebra', 'crossed', 'road'],
			SearchTerms::ofHtml('<p>The <span class="h-card"><a href="https://x.example/@a">Zebra</a></span> crossed</p><p>the road</p>')
		);
		$this->assertSame(['one', 'two'], SearchTerms::ofHtml('<p>one</p><p>two</p>'));
	}

	public function testEntitiesAreDecodedAfterTheMarkupIsGone(): void {
		$this->assertSame(['tom', 'jerry', 'script'], SearchTerms::ofHtml('Tom &amp; Jerry &lt;script&gt;'));
	}

	public function testWordsAreLowercasedAcrossScripts(): void {
		$this->assertSame(['straße', 'éclair', 'σοφία'], SearchTerms::ofText('Straße ÉCLAIR ΣΟΦΊΑ'));
	}

	public function testHashtagsMentionsAndPunctuationLeaveTheWord(): void {
		$this->assertSame(['nextcloud', 'alice', 'example', 'org', 'don'], SearchTerms::ofText("#Nextcloud @alice@example.org don't!"));
	}

	public function testDigitsAreWordsAndCombiningMarksStayInsideThem(): void {
		$this->assertSame(['2026', 'नमस्ते'], SearchTerms::ofText('2026 नमस्ते'));
	}

	public function testVeryShortWordsAreSkipped(): void {
		$this->assertSame(['ai', 'is', 'here'], SearchTerms::ofText('a AI is here x'));
	}

	public function testALongWordIsCutAtTheColumnWidthInCharacters(): void {
		$word = str_repeat('ü', 40);

		$this->assertSame([str_repeat('ü', SearchTerms::MAX_LENGTH)], SearchTerms::ofText($word));
	}

	public function testEachWordIsStoredOnceInTheOrderItFirstAppears(): void {
		$this->assertSame(['zebra', 'and'], SearchTerms::ofText('zebra and Zebra and ZEBRA'));
	}

	public function testAPostHasAtMostSoManyWords(): void {
		$text = implode(' ', array_map(static fn (int $i): string => 'w' . $i, range(1, SearchTerms::MAX_PER_POST + 50)));

		$this->assertCount(SearchTerms::MAX_PER_POST, SearchTerms::ofHtml($text));
	}

	public function testInvalidUtf8DoesNotLoseTheRest(): void {
		$this->assertSame(['broken', 'text'], SearchTerms::ofText("broken \xC3 text"));
	}

	public function testTheLastWordOfAQueryIsABeginning(): void {
		$this->assertSame(['whole' => ['crossed'], 'prefix' => 'zeb'], SearchTerms::ofQuery('Crossed Zeb'));
		$this->assertSame(['whole' => [], 'prefix' => 'zeb'], SearchTerms::ofQuery('zeb'));
	}

	/** `ai` is a word somebody looks for; as a beginning it is half the dictionary. */
	public function testAShortLastWordIsAWholeWord(): void {
		$this->assertSame(['whole' => ['open', 'ai'], 'prefix' => ''], SearchTerms::ofQuery('open AI'));
	}

	public function testAQueryOfNothingSearchableAsksForNothing(): void {
		$this->assertSame(['whole' => [], 'prefix' => ''], SearchTerms::ofQuery('a ! ?'));
	}

	public function testAQueryWordAsLongAsTheColumnIsAWholeWord(): void {
		$word = str_repeat('x', 40);

		$this->assertSame(['whole' => [str_repeat('x', SearchTerms::MAX_LENGTH)], 'prefix' => ''], SearchTerms::ofQuery($word));
	}

	public function testTheHeadIsTheBeginningEveryPrefixSearchStartsWith(): void {
		$this->assertSame('zeb', SearchTerms::head('zebra'));
		$this->assertSame('ai', SearchTerms::head('ai'));
		$this->assertSame('übe', SearchTerms::head('über'), 'characters, not bytes');
	}
}
