<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use OCA\Social\Security\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase {
	/**
	 * Sanitises through libxml's HTML4 parser and, where PHP has it, the HTML5
	 * one too, and holds both to the same answer: which one runs depends on
	 * the PHP version a server has.
	 */
	private function sanitize(string $html, string $base = ''): string {
		$html4 = HtmlSanitizer::sanitizeWith($html, $base, false);
		if (PHP_VERSION_ID < 80400) {
			return $html4;
		}

		$html5 = HtmlSanitizer::sanitizeWith($html, $base, true);
		$this->assertSame($html5, $html4, 'the HTML4 and the HTML5 parser disagree');

		return $html5;
	}

	public function testKeepsMastodonFormatting(): void {
		$html = '<p>Hello <strong>world</strong></p><blockquote><p>quoted</p></blockquote>'
			. '<ul><li>one</li><li>two</li></ul><pre><code>x &lt; y</code></pre><h2>Title</h2>';

		$this->assertSame($html, $this->sanitize($html));
	}

	public function testDropsEventHandlerAttributes(): void {
		$result = $this->sanitize('<p onclick="alert(1)" onmouseover="alert(2)">text</p>');

		$this->assertSame('<p>text</p>', $result);
	}

	public function testDropsJavascriptLinks(): void {
		$result = $this->sanitize('<a href="javascript:alert(1)">click</a>');

		$this->assertSame('<a>click</a>', $result);
	}

	public function testDropsObfuscatedJavascriptLinks(): void {
		// Browsers ignore control characters and whitespace inside a scheme
		$result = $this->sanitize("<a href=\"  java\tscript:alert(1)\">click</a>");

		$this->assertSame('<a>click</a>', $result);
	}

	public function testDropsUnknownSchemes(): void {
		$this->assertSame('<a>d</a>', $this->sanitize('<a href="data:text/html,x">d</a>'));
		$this->assertSame('<a>v</a>', $this->sanitize('<a href="vbscript:x">v</a>'));
		$this->assertSame('<a>m</a>', $this->sanitize('<a href="mailto:a@b.c">m</a>'));
	}

	public function testKeepsAllowedSchemes(): void {
		$result = $this->sanitize(
			'<a href="https://example.org/@alice">a</a>'
			. '<a href="gemini://example.org">g</a>'
		);

		$this->assertStringContainsString('href="https://example.org/@alice"', $result);
		$this->assertStringContainsString('href="gemini://example.org"', $result);
	}

	/** @return array<string, array{string, string|null}> */
	public static function relativeLinks(): array {
		return [
			'path-absolute' => ['/logout', 'https://remote.example/logout'],
			'path-relative' => ['tags/cats', 'https://remote.example/users/bob/statuses/tags/cats'],
			'dot segments stay on the origin' => ['../../../x', 'https://remote.example/users/bob/statuses/../../../x'],
			'query' => ['?page=2', 'https://remote.example/users/bob/statuses/1?page=2'],
			'protocol-relative' => ['//other.example/x', 'https://other.example/x'],
			'backslashes read as slashes' => ['/\\other.example/x', 'https://other.example/x'],
			'whitespace inside the slashes' => ["/\t/other.example/x", 'https://other.example/x'],
			'fragment' => ['#top', '#top'],
			'absolute' => ['https://example.org/x', 'https://example.org/x'],
		];
	}

	#[DataProvider('relativeLinks')]
	public function testResolvesARelativeLinkAgainstThePostsAddress(string $href, ?string $expected): void {
		$result = $this->sanitize(
			'<a href="' . htmlspecialchars($href) . '">x</a>',
			'https://remote.example/users/bob/statuses/1'
		);

		$this->assertStringContainsString('href="' . htmlspecialchars((string)$expected) . '"', $result);
	}

	public function testDropsARelativeLinkWithNothingToResolveItAgainst(): void {
		$this->assertSame('<a>x</a>', $this->sanitize('<a href="/logout">x</a>'));
		$this->assertSame('<a>x</a>', $this->sanitize('<a href="/logout">x</a>', 'tag:remote.example,2026:1'));
	}

	public function testResolvesARelativeCiteToo(): void {
		$this->assertSame(
			'<blockquote cite="https://remote.example/x">q</blockquote>',
			$this->sanitize('<blockquote cite="/x">q</blockquote>', 'https://remote.example/users/bob')
		);
	}

	public function testKeepsThePortOfTheBase(): void {
		$this->assertStringContainsString(
			'href="http://remote.example:8080/x"',
			$this->sanitize('<a href="/x">x</a>', 'http://remote.example:8080/users/bob')
		);
	}

	public function testDropsAProtocolRelativeLinkWithoutAHost(): void {
		$this->assertSame('<a>x</a>', $this->sanitize('<a href="///">x</a>', 'https://remote.example/'));
	}

	/** @return array<string, array{string}> */
	public static function mutationPayloads(): array {
		return [
			'noscript' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'],
			'svg style' => ['<svg><p><style><img src=x onerror=alert(1)></style></p></svg>'],
			'math mglyph' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
			'xmp' => ['<xmp><a href="https://e.org/</xmp><img src=x onerror=alert(1)>">x</a></xmp>'],
			'textarea' => ['<textarea><a href="https://e.org/</textarea><img src=x onerror=alert(1)>">x</a>'],
			'nested form' => ['<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>'],
			'comment' => ['<!--><img src=x onerror=alert(1)>-->'],
			'cdata' => ['<![CDATA[><img src=x onerror=alert(1)>]]>'],
		];
	}

	/**
	 * What the browser builds out of the output has to be what the sanitiser
	 * kept: read back through an HTML5 parser — the browser's — the output
	 * holds no element the allowlist does not.
	 */
	#[DataProvider('mutationPayloads')]
	public function testTheOutputReadsBackAsWhatWasKept(string $payload): void {
		$parsers = PHP_VERSION_ID >= 80400 ? [false, true] : [false];
		foreach ($parsers as $html5) {
			$result = HtmlSanitizer::sanitizeWith($payload, '', $html5);

			$this->assertSame($result, HtmlSanitizer::sanitizeWith($result, '', $html5), 'sanitising the output again changes it');
			if (PHP_VERSION_ID >= 80400) {
				$document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>' . $result, LIBXML_NOERROR);
				foreach ($document->body->querySelectorAll('*') as $element) {
					$this->assertContains(strtolower($element->localName), ['p', 'a', 'span', 'br', 'blockquote', 'pre', 'code'], $result);
					foreach ($element->attributes as $attribute) {
						$this->assertStringStartsNotWith('on', strtolower($attribute->localName), $result);
					}
				}
			}
		}
	}

	public function testForcesSafeLinkRelAndTarget(): void {
		$result = $this->sanitize('<a href="https://example.org" rel="opener" target="_self">x</a>');

		$this->assertSame(
			'<a href="https://example.org" rel="nofollow noopener noreferrer" target="_blank">x</a>',
			$result,
		);
	}

	public function testRemovesScriptAndStyleWithTheirContent(): void {
		$result = $this->sanitize('<p>a</p><script>alert(1)</script><style>p{}</style><p>b</p>');

		$this->assertSame('<p>a</p><p>b</p>', $result);
	}

	public function testUnwrapsUnknownElementsButKeepsTheirText(): void {
		$result = $this->sanitize('<div class="x"><p>inside <font color="red">red</font></p></div>');

		$this->assertSame('<p>inside red</p>', $result);
	}

	public function testDropsImagesAndTheirAttributes(): void {
		$result = $this->sanitize('<p>pic <img src="x" onerror="alert(1)"> after</p>');

		$this->assertSame('<p>pic  after</p>', $result);
	}

	public function testDoesNotResurrectEncodedMarkup(): void {
		// The old strip_tags-then-decode order turned this into a live <img>
		$result = $this->sanitize('<p>&lt;img src=x onerror=alert(1)&gt;</p>');

		$this->assertSame('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $result);
	}

	public function testSanitizesElementsLiftedOutOfAnUnwrappedParent(): void {
		// The children of a removed wrapper must be checked too, not skipped
		$result = $this->sanitize('<div><a href="javascript:x" onclick="y">l</a><script>z</script></div>');

		$this->assertSame('<a>l</a>', $result);
	}

	public function testKeepsOnlyKnownClasses(): void {
		$result = $this->sanitize(
			'<span class="h-card evil"><a href="https://e.org/@a" class="u-url mention">@a</a></span>'
			. '<span class="evil">x</span>'
		);

		$this->assertStringContainsString('<span class="h-card">', $result);
		$this->assertStringContainsString('class="u-url mention"', $result);
		$this->assertStringContainsString('<span>x</span>', $result);
	}

	public function testDropsStyleAndIdAttributes(): void {
		$result = $this->sanitize('<p style="position:fixed" id="app-content">x</p>');

		$this->assertSame('<p>x</p>', $result);
	}

	public function testValidatesListAttributes(): void {
		$this->assertSame('<ol start="3"><li value="7">x</li></ol>', $this->sanitize('<ol start="3"><li value="7">x</li></ol>'));
		$this->assertSame('<ol><li>x</li></ol>', $this->sanitize('<ol start="a"><li value="b">x</li></ol>'));
	}

	public function testPreservesMultibyteText(): void {
		$html = '<p>Ünïcödé 日本語 🙂</p>';

		$this->assertSame($html, $this->sanitize($html));
	}

	public function testRemovesComments(): void {
		$this->assertSame('<p>a</p>', $this->sanitize('<p>a</p><!-- <script>x</script> -->'));
	}

	public function testEmptyInputStaysEmpty(): void {
		$this->assertSame('', $this->sanitize(''));
		$this->assertSame('', $this->sanitize('   '));
	}

	public function testIsAllowedUrl(): void {
		$this->assertTrue(HtmlSanitizer::isAllowedUrl('https://example.org'));
		$this->assertTrue(HtmlSanitizer::isAllowedUrl('HTTP://EXAMPLE.ORG'));
		$this->assertTrue(HtmlSanitizer::isAllowedUrl('/relative'));
		$this->assertTrue(HtmlSanitizer::isAllowedUrl('#fragment'));
		$this->assertFalse(HtmlSanitizer::isAllowedUrl('javascript:alert(1)'));
		$this->assertFalse(HtmlSanitizer::isAllowedUrl("java\nscript:alert(1)"));
		$this->assertFalse(HtmlSanitizer::isAllowedUrl('data:text/html,x'));
		$this->assertFalse(HtmlSanitizer::isAllowedUrl(''));
	}
}
