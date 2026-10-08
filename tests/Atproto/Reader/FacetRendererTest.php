<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Reader\FacetRenderer;
use PHPUnit\Framework\TestCase;

class FacetRendererTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	public function testLinksMentionsAndTagsBecomeAnchors(): void {
		$text = 'Hi @bob.bsky.social, see https://example.com/a?b=1&c=2 #Tag';
		$facets = [
			['index' => ['byteStart' => 3, 'byteEnd' => 19], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => self::DID]]],
			['index' => ['byteStart' => 25, 'byteEnd' => 54], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/a?b=1&c=2']]],
			['index' => ['byteStart' => 55, 'byteEnd' => 59], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'Tag']]],
		];
		$this->assertSame(
			'<p>Hi <span class="h-card"><a href="https://bsky.app/profile/' . self::DID . '" class="u-url mention">@bob.bsky.social</a></span>, see '
			. '<a href="https://example.com/a?b=1&amp;c=2" rel="nofollow noopener noreferrer" target="_blank">https://example.com/a?b=1&amp;c=2</a> '
			. '<a href="https://bsky.app/hashtag/Tag" class="mention hashtag" rel="tag">#Tag</a></p>',
			FacetRenderer::html($text, $facets)
		);
	}

	public function testByteOffsetsCountUtf8AndParagraphsAreKept(): void {
		$text = "Grüße 🎉\n\nZweiter Absatz\nmit Umbruch";
		// "Grüße 🎉" is twelve bytes
		$facets = [['index' => ['byteStart' => 0, 'byteEnd' => 12], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.org']]]];
		$this->assertSame(
			'<p><a href="https://example.org" rel="nofollow noopener noreferrer" target="_blank">Grüße 🎉</a></p><p>Zweiter Absatz<br>mit Umbruch</p>',
			FacetRenderer::html($text, $facets)
		);
	}

	public function testBrokenFacetsAreIgnoredAndTextIsEscaped(): void {
		$text = '<b>not bold</b> & more';
		$facets = [
			['index' => ['byteStart' => 5, 'byteEnd' => 999], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://x']]],
			['index' => ['byteStart' => 0, 'byteEnd' => 3], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'javascript:alert(1)']]],
			['index' => ['byteStart' => 1, 'byteEnd' => 2], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'x']]],
			'junk',
		];
		$this->assertSame('<p>&lt;b&gt;not bold&lt;/b&gt; &amp; more</p>', FacetRenderer::html($text, $facets));
		$this->assertSame('', FacetRenderer::html('', null));
	}
}
