/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import DOMPurify from 'dompurify'

/**
 * URL schemes a link in remote content may use.
 *
 * Kept in step with `HtmlSanitizer::ALLOWED_SCHEMES` on the server: the server
 * is the authority, this is the belt to its braces for anything that reaches
 * the DOM through a path the server did not clean.
 */
export const ALLOWED_SCHEMES = ['http', 'https', 'dat', 'dweb', 'ipfs', 'ipns', 'ssb', 'gopher', 'xmpp', 'magnet', 'gemini']

/**
 * Elements remote HTML may contain, matching the server-side allowlist and
 * what Mastodon itself accepts.
 */
export const ALLOWED_TAGS = [
	'p',
	'br',
	'span',
	'a',
	'del',
	's',
	'pre',
	'blockquote',
	'code',
	'b',
	'strong',
	'u',
	'i',
	'em',
	'ul',
	'ol',
	'li',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
]

/**
 * Class names a remote element may carry, the same list as
 * `HtmlSanitizer::ALLOWED_CLASSES`: Mastodon's markers for mentions, hashtags
 * and shortened links. Anything else could dress remote markup up as this
 * app's or Nextcloud's own interface.
 */
export const ALLOWED_CLASSES = [
	'mention',
	'hashtag',
	'ellipsis',
	'invisible',
	'h-card',
	'u-url',
	'p-name',
	'p-author',
	'quote-inline',
]

const ALLOWED_ATTR = ['href', 'rel', 'class', 'translate', 'cite', 'start', 'reversed', 'value', 'target']

/**
 * Whether a URL may be used as a link target.
 *
 * Browsers ignore control characters and whitespace inside a scheme, so those
 * are removed before looking at it — a tab inside "javascript:" still executes.
 * A URL without a scheme (relative, protocol-relative, fragment) is fine.
 *
 * @param {string} url - The raw attribute value
 * @return {boolean}
 */
export function isAllowedUrl(url) {
	const normalized = Array.from(url ?? '')
		.filter((character) => {
			const code = character.charCodeAt(0)
			return code > 0x20 && code !== 0x7f
		})
		.join('')
	if (normalized === '') {
		return false
	}
	const match = normalized.match(/^([a-z][a-z0-9+.-]*):/i)
	if (match === null) {
		return true
	}
	return ALLOWED_SCHEMES.includes(match[1].toLowerCase())
}

// The default export is already bound to the page's window
const purifier = DOMPurify

// DOMPurify's own URI check permits `mailto:` and `tel:`; neither belongs in
// a timeline, so links are re-checked against the shared scheme list
purifier.addHook('uponSanitizeAttribute', (node, data) => {
	if ((data.attrName === 'href' || data.attrName === 'cite') && !isAllowedUrl(data.attrValue)) {
		data.keepAttr = false
	}
	if (data.attrName === 'class') {
		const classes = data.attrValue.split(/\s+/).filter((name) => ALLOWED_CLASSES.includes(name))
		if (classes.length === 0) {
			data.keepAttr = false
		} else {
			data.attrValue = classes.join(' ')
		}
	}
})

// Every surviving link opens in a new tab without a referrer or an opener,
// whatever the remote instance put there
purifier.addHook('afterSanitizeAttributes', (node) => {
	if (node.tagName === 'A' && node.hasAttribute('href')) {
		node.setAttribute('rel', 'nofollow noopener noreferrer')
		node.setAttribute('target', '_blank')
	}
})

/**
 * Reduce HTML from a remote instance to what may be rendered with `v-html`.
 *
 * @param {string} html - Untrusted markup, e.g. a profile bio
 * @return {string} A fragment safe to inject
 */
export function sanitizeHtml(html) {
	if (typeof html !== 'string' || html === '') {
		return ''
	}
	return purifier.sanitize(html, {
		ALLOWED_TAGS,
		ALLOWED_ATTR,
		// Not a document: keep the fragment as is, without a wrapper
		WHOLE_DOCUMENT: false,
		RETURN_DOM: false,
	})
}
