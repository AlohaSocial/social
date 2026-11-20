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
 * Allowlist sanitizer for HTML received from remote instances, which ends up
 * in every local reader's timeline.
 *
 * The allowlist mirrors what Mastodon accepts on ingest (lists, quotes, code
 * blocks) and drops anything with script potential. Unknown elements are
 * unwrapped; only elements whose content is dangerous or meaningless
 * (`<script>`, `<style>`, …) are removed with their children. It walks a DOM
 * tree, the HTML5 parser where PHP has one (see `sanitizeWith()`), so it sees
 * what the browser will.
 */
final class HtmlSanitizer {
	/**
	 * The revision of what this class lets through. Bump it whenever the
	 * allowlists or the way a tree is walked change: a stored post or bio
	 * stamped with an older `version()` is sanitized again on every read
	 * until `Cron\Resanitize` has rewritten it.
	 */
	public const VERSION = 1;

	/**
	 * What a stored text is stamped with once it went through `sanitize()`:
	 * the revision, and which parser this PHP has, because the HTML4 path's
	 * output carries a weaker promise than the HTML5 one's (see
	 * `sanitizeWith()`). A text is trusted as it is only under the stamp it
	 * was written with.
	 */
	public static function version(): int {
		return self::VERSION * 2 + ((PHP_VERSION_ID >= 80400) ? 1 : 0);
	}

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
	 * Class names a `<span>` or `<a>` may carry: Mastodon's markers for
	 * mentions, hashtags and shortened links. Anything else is stripped so
	 * remote markup cannot imitate local UI. `src/utils/sanitizeHtml.js` keeps
	 * the same list, and its test holds them in step.
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
	 * PHP 8.4's `Dom\HTMLDocument` parses as an HTML5 browser does and is used
	 * wherever available. libxml's HTML4 `DOMDocument` can build a different
	 * tree from the same bytes (the opening for mutation XSS), so its result
	 * is sanitised again until it stops changing.
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

		// the walk below recurses once per level, and the HTML5 parser, unlike
		// libxml's, builds a tree of any depth: deeper than libxml's own 256
		// levels, the content is lifted up to that depth
		self::flattenBeyond($root, 256);
		self::sanitizeChildren($root, $base);

		$result = '';
		foreach ($root->childNodes as $child) {
			/** @psalm-suppress InvalidArgument the node comes from this document, so from the same parser */
			$result .= $document->saveHTML($child);
		}

		return $result;
	}

	/**
	 * Lifts the content of every element nested deeper than `$limit` up to
	 * that depth, without recursing, so the walk that follows is bounded.
	 *
	 * Elements are emptied and removed one level at a time rather than
	 * detached as a subtree: PHP frees a detached subtree recursively, and a
	 * deep enough one crashes the process the same way the walk would.
	 */
	private static function flattenBeyond(DOMNode|\Dom\Node $root, int $limit): void {
		$stack = [[$root, 0]];
		while ($stack !== []) {
			[$node, $depth] = array_pop($stack);
			if ($depth >= $limit) {
				self::liftDescendants($node);
				continue;
			}

			foreach ($node->childNodes as $child) {
				if (self::isElement($child)) {
					$stack[] = [$child, $depth + 1];
				}
			}
		}
	}

	/**
	 * Replaces each element below `$node` by its own children until `$node`
	 * holds no element at all.
	 */
	private static function liftDescendants(DOMNode|\Dom\Node $node): void {
		$child = $node->firstChild;
		while ($child !== null) {
			if (!self::isElement($child)) {
				$child = $child->nextSibling;
				continue;
			}

			$first = $child->firstChild;
			while ($child->firstChild !== null) {
				/** @psalm-suppress InvalidArgument a child comes from the same parser as its parent */
				$node->insertBefore($child->firstChild, $child);
			}
			$next = $first ?? $child->nextSibling;
			/** @psalm-suppress InvalidArgument a child comes from the same parser as its parent */
			$node->removeChild($child);
			$child = $next;
		}
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
	 * What a link in remote HTML is written out as, or null when it cannot be
	 * kept.
	 *
	 * Relative links would point at this server, so they are resolved against
	 * `$base` (the post's or profile's address) and protocol-relative ones get
	 * `https:`; without an http(s) base they are dropped. Fragment-only links
	 * stay. The URL is read as browsers read it: whitespace and control
	 * characters skipped, `\` as `/`.
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
