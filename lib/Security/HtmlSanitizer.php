<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allowlist sanitizer for HTML received from remote instances.
 *
 * Mastodon and its relatives ship post bodies and profile bios as HTML. That
 * HTML ends up in the timeline of every local reader, so it has to be treated
 * as hostile: anything not explicitly permitted is removed.
 *
 * The allowlist mirrors what Mastodon itself accepts on ingest, so posts keep
 * their lists, quotes and code blocks instead of being flattened to text, while
 * everything with script potential — event handler attributes, `javascript:`
 * URLs, `<img>`, `<style>`, `<form>` — is dropped.
 *
 * Unknown elements are unwrapped rather than deleted: `<div>hello</div>` still
 * yields "hello". Only the elements whose *content* is itself dangerous or
 * meaningless without the element (`<script>`, `<style>`, …) are removed with
 * their children.
 *
 * Implemented on a DOM parser rather than with regular expressions: a parser
 * sees the same tree the browser will, which is what makes an allowlist
 * trustworthy against malformed or deliberately confusing markup — the HTML5
 * one where PHP has it, see `sanitizeWith()`.
 */
final class HtmlSanitizer {
	/**
	 * Elements that survive, mapped to the attributes each may keep.
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOWED_ELEMENTS = [
		'p' => [],
		'br' => [],
		'span' => ['class', 'translate'],
		'a' => ['href', 'rel', 'class', 'translate'],
		'del' => [],
		's' => [],
		'pre' => [],
		'blockquote' => ['cite'],
		'code' => [],
		'b' => [],
		'strong' => [],
		'u' => [],
		'i' => [],
		'em' => [],
		'ul' => [],
		'ol' => ['start', 'reversed'],
		'li' => ['value'],
		'h1' => [],
		'h2' => [],
		'h3' => [],
		'h4' => [],
		'h5' => [],
		'h6' => [],
	];

	/**
	 * Elements removed together with everything inside them.
	 *
	 * Their text content is not prose, so unwrapping would leak script source or
	 * stylesheet rules into the post body.
	 *
	 * @var list<string>
	 */
	private const DROPPED_WITH_CONTENT = [
		'script',
		'style',
		'iframe',
		'object',
		'embed',
		'noscript',
		'template',
		'svg',
		'math',
		'head',
		'title',
	];

	/**
	 * URL schemes an `<a href>` may use.
	 *
	 * Matches Mastodon's list. A scheme-less URL is resolved against the
	 * address of the object the HTML belongs to — see `resolveUrl()`.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_SCHEMES = [
		'http',
		'https',
		'dat',
		'dweb',
		'ipfs',
		'ipns',
		'ssb',
		'gopher',
		'xmpp',
		'magnet',
		'gemini',
	];

	/**
	 * Class names a `<span>` or `<a>` may carry.
	 *
	 * Mastodon marks mentions, hashtags and shortened links this way, and the
	 * frontend renders them accordingly. Anything else is stripped so remote
	 * markup cannot borrow the look of local UI. `src/utils/sanitizeHtml.js`
	 * keeps the same list, and its test reads this one to hold them in step.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_CLASSES = [
		'mention',
		'hashtag',
		'ellipsis',
		'invisible',
		'h-card',
		'u-url',
		'p-name',
		'p-author',
		'quote-inline',
	];

	/**
	 * Reduce untrusted HTML to the allowed subset.
	 *
	 * Returns a fragment (no `<html>`/`<body>` wrapper) suitable for storing and
	 * for handing to the client as-is.
	 *
	 * @param string $base the address of the object the HTML belongs to, which
	 *                     a relative link in it is resolved against. Without
	 *                     an http(s) one, relative links lose their `href`.
	 */
	public static function sanitize(string $html, string $base = ''): string {
		return self::sanitizeWith($html, $base, PHP_VERSION_ID >= 80400);
	}

	/**
	 * `sanitize()` with the parser chosen by the caller.
	 *
	 * PHP 8.4's `Dom\HTMLDocument` parses and serialises as an HTML5 browser
	 * does; `DOMDocument` is libxml's HTML4 parser, whose tree and output can
	 * differ from what the browser builds out of the same bytes, which is the
	 * opening mutation XSS needs. The HTML5 parser is used wherever PHP has
	 * it. Where it does not, the HTML4 result is sanitised again until it no
	 * longer changes, so markup that libxml itself reads back differently
	 * from how it wrote it is caught; how a browser reads it is only the
	 * HTML5 parser's to promise.
	 *
	 * @internal public for the tests, which run every case through both parsers
	 */
	public static function sanitizeWith(string $html, string $base, bool $html5): string {
		if (trim($html) === '') {
			return '';
		}

		if ($html5) {
			return self::sanitizeOnce($html, $base, true);
		}

		$result = self::sanitizeOnce($html, $base, false);
		for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
			$again = self::sanitizeOnce($result, $base, false);
			if ($again === $result) {
				return $result;
			}
			$result = $again;
		}

		// markup that keeps changing shape every time it is read back is not
		// a post anybody wrote; its text is all that is kept
		return htmlspecialchars(html_entity_decode(strip_tags($result), ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5);
	}

	/** How often libxml output is read back before it is given up on. */
	private const MAX_PASSES = 3;

	private static function sanitizeOnce(string $html, string $base, bool $html5): string {
		// The meta tag pins the charset so multi-byte text is not mangled;
		// the wrapper gives every top-level node a common parent to walk
		$wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="social-sanitizer-root">'
			. $html
			. '</div></body></html>';

		$previous = libxml_use_internal_errors(true);
		try {
			if ($html5) {
				$document = \Dom\HTMLDocument::createFromString($wrapped, LIBXML_NOERROR);
			} else {
				$document = new DOMDocument();
				$document->loadHTML($wrapped, LIBXML_NONET);
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		$root = $document->getElementById('social-sanitizer-root');
		if ($root === null) {
			return '';
		}

		self::sanitizeChildren($root, $base);

		$result = '';
		foreach ($root->childNodes as $child) {
			/** @psalm-suppress InvalidArgument the node comes from this document, so from the same parser */
			$result .= $document->saveHTML($child);
		}

		return $result;
	}

	/**
	 * Walk the children of a node, removing, unwrapping or cleaning each.
	 *
	 * Iterates over a snapshot because the live NodeList changes underneath us
	 * as nodes are replaced.
	 */
	private static function sanitizeChildren(DOMNode|\Dom\Node $parent, string $base): void {
		$children = [];
		foreach ($parent->childNodes as $child) {
			$children[] = $child;
		}

		foreach ($children as $child) {
			if (self::isElement($child)) {
				self::sanitizeNode($child, $base);
			} elseif (!self::isText($child)) {
				// Comments, processing instructions, CDATA: nothing a post needs
				/** @psalm-suppress InvalidArgument a child comes from the same parser as its parent */
				$parent->removeChild($child);
			}
		}
	}

	/**
	 * Remove, unwrap or clean one element that hangs off an already clean parent.
	 */
	private static function sanitizeNode(DOMElement|\Dom\Element $element, string $base): void {
		$parent = $element->parentNode;
		if ($parent === null) {
			return;
		}

		$name = strtolower($element->localName ?? '');

		if (in_array($name, self::DROPPED_WITH_CONTENT, true) || !self::isHtmlElement($element)) {
			$parent->removeChild($element);
			return;
		}

		if (!array_key_exists($name, self::ALLOWED_ELEMENTS)) {
			self::unwrap($element, $base);
			return;
		}

		self::sanitizeAttributes($element, self::ALLOWED_ELEMENTS[$name], $base);
		self::sanitizeChildren($element, $base);
	}

	/**
	 * Replace an element by its own children, then sanitize those in place.
	 *
	 * The lifted children were not part of the caller's snapshot, so they are
	 * checked here rather than being skipped.
	 */
	private static function unwrap(DOMElement|\Dom\Element $element, string $base): void {
		$parent = $element->parentNode;
		if ($parent === null) {
			return;
		}

		$children = [];
		foreach ($element->childNodes as $child) {
			$children[] = $child;
		}

		foreach ($children as $child) {
			$parent->insertBefore($child, $element);
		}
		$parent->removeChild($element);

		foreach ($children as $child) {
			if (self::isElement($child)) {
				self::sanitizeNode($child, $base);
			} elseif (!self::isText($child)) {
				$parent->removeChild($child);
			}
		}
	}

	/**
	 * Drop every attribute not in the allowlist, and validate the ones kept.
	 *
	 * @param list<string> $allowed
	 */
	private static function sanitizeAttributes(DOMElement|\Dom\Element $element, array $allowed, string $base): void {
		$names = [];
		foreach ($element->attributes as $attribute) {
			$names[] = $attribute->nodeName;
		}

		foreach ($names as $attributeName) {
			$lower = strtolower($attributeName);
			if (!in_array($lower, $allowed, true)) {
				$element->removeAttribute($attributeName);
				continue;
			}

			$value = $element->getAttribute($attributeName);
			switch ($lower) {
				case 'href':
				case 'cite':
					$url = self::resolveUrl($value, $base);
					if ($url === null) {
						$element->removeAttribute($attributeName);
					} elseif ($url !== $value) {
						$element->setAttribute($attributeName, $url);
					}
					break;
				case 'class':
					$classes = array_filter(
						preg_split('/\s+/', trim($value)) ?: [],
						static fn (string $class): bool => in_array($class, self::ALLOWED_CLASSES, true),
					);
					if ($classes === []) {
						$element->removeAttribute($attributeName);
					} else {
						$element->setAttribute($attributeName, implode(' ', $classes));
					}
					break;
				case 'start':
				case 'value':
					if (!preg_match('/^-?\d+$/', $value)) {
						$element->removeAttribute($attributeName);
					}
					break;
				case 'translate':
					if (!in_array(strtolower($value), ['yes', 'no'], true)) {
						$element->removeAttribute($attributeName);
					}
					break;
			}
		}

		if (strtolower($element->localName ?? '') === 'a' && $element->hasAttribute('href')) {
			// Whatever the remote sent, a link out of a timeline must neither
			// pass referrers nor hand over window.opener
			$element->setAttribute('rel', 'nofollow noopener noreferrer');
			$element->setAttribute('target', '_blank');
		}
	}

	/**
	 * Whether a URL uses an allowed scheme, or none at all.
	 *
	 * Control characters and whitespace are stripped before looking at the
	 * scheme, because browsers ignore them — `java\tscript:` is still executed.
	 */
	public static function isAllowedUrl(string $url): bool {
		$normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';
		if ($normalized === '') {
			return false;
		}

		if (!preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalized, $match)) {
			// No scheme: relative, protocol-relative or fragment link
			return true;
		}

		return in_array(strtolower($match[1]), self::ALLOWED_SCHEMES, true);
	}

	/**
	 * What a link in remote HTML is written out as, or null when it cannot
	 * be kept.
	 *
	 * A link without a scheme resolves against the page it is shown on, and
	 * that page is this server: `href="/logout"` in a remote post pointed at
	 * this instance, `//evil.example` at whatever host it names. A relative
	 * link is therefore resolved against `$base`, the address of the post or
	 * profile it came in, and a protocol-relative one is given `https:`.
	 * Without an http(s) base there is nothing to resolve against and the
	 * link is not kept. A fragment-only link stays as it is: it does not
	 * leave the page.
	 *
	 * Browsers skip whitespace and control characters and read `\` as `/` in
	 * an http(s) URL, so `/\evil.example` is protocol-relative to them; the
	 * URL is looked at the way they look at it.
	 */
	public static function resolveUrl(string $url, string $base): ?string {
		if (!self::isAllowedUrl($url)) {
			return null;
		}

		$normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';
		if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized)) {
			return $url;
		}

		if (str_starts_with($normalized, '#')) {
			return $url;
		}

		$normalized = str_replace('\\', '/', $normalized);
		if (str_starts_with($normalized, '//')) {
			$host = parse_url('https:' . $normalized, PHP_URL_HOST);

			return (is_string($host) && $host !== '') ? 'https:' . $normalized : null;
		}

		$parts = parse_url($base);
		if ($parts === false) {
			return null;
		}
		$scheme = strtolower($parts['scheme'] ?? '');
		$host = $parts['host'] ?? '';
		if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
			return null;
		}

		$origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($normalized, '/')) {
			return $origin . $normalized;
		}

		$path = $parts['path'] ?? '';
		$path = str_starts_with($path, '/') ? $path : '/' . $path;
		if (str_starts_with($normalized, '?')) {
			return $origin . $path . $normalized;
		}

		// a path relative to the directory the base names
		return $origin . substr($path, 0, (int)strrpos($path, '/') + 1) . $normalized;
	}

	/** @psalm-assert-if-true DOMElement|\Dom\Element $node */
	private static function isElement(mixed $node): bool {
		return $node instanceof DOMElement || $node instanceof \Dom\Element;
	}

	private static function isText(mixed $node): bool {
		return $node instanceof DOMText || $node instanceof \Dom\Text;
	}

	/**
	 * Whether an element is an HTML one. The HTML5 parser puts what is
	 * inside `<svg>` and `<math>` in their own namespaces, where the same
	 * name is a different element.
	 */
	private static function isHtmlElement(DOMElement|\Dom\Element $element): bool {
		return $element instanceof DOMElement
			|| $element->namespaceURI === 'http://www.w3.org/1999/xhtml';
	}
}
