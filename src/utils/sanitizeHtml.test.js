/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @jest-environment jsdom
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { ALLOWED_CLASSES, ALLOWED_SCHEMES, ALLOWED_TAGS, isAllowedUrl, sanitizeHtml } from './sanitizeHtml.js'

describe('isAllowedUrl', () => {
	test('accepts web and fediverse schemes', () => {
		expect(isAllowedUrl('https://example.org/@alice')).toBe(true)
		expect(isAllowedUrl('HTTP://EXAMPLE.ORG')).toBe(true)
		expect(isAllowedUrl('gemini://example.org')).toBe(true)
	})

	test('accepts scheme-less links', () => {
		expect(isAllowedUrl('/local/path')).toBe(true)
		expect(isAllowedUrl('//example.org/x')).toBe(true)
		expect(isAllowedUrl('#fragment')).toBe(true)
	})

	test('rejects script-capable and unknown schemes', () => {
		expect(isAllowedUrl('javascript:alert(1)')).toBe(false)
		expect(isAllowedUrl('data:text/html,x')).toBe(false)
		expect(isAllowedUrl('vbscript:x')).toBe(false)
		expect(isAllowedUrl('mailto:a@b.c')).toBe(false)
	})

	test('sees through whitespace and control characters in the scheme', () => {
		expect(isAllowedUrl('  java\tscript:alert(1)')).toBe(false)
		expect(isAllowedUrl('java\nscript:alert(1)')).toBe(false)
	})

	test('rejects empty and missing values', () => {
		expect(isAllowedUrl('')).toBe(false)
		expect(isAllowedUrl(undefined)).toBe(false)
	})
})

describe('sanitizeHtml', () => {
	test('keeps the formatting Mastodon sends', () => {
		const html = '<p>Hello <strong>world</strong></p><blockquote><p>q</p></blockquote><ul><li>a</li></ul><pre><code>x</code></pre>'
		expect(sanitizeHtml(html)).toBe(html)
	})

	test('drops event handlers and javascript links', () => {
		const out = sanitizeHtml('<p onclick="alert(1)">t <a href="javascript:alert(2)" onmouseover="x">l</a></p>')
		expect(out).not.toContain('onclick')
		expect(out).not.toContain('onmouseover')
		expect(out).not.toContain('javascript:')
		expect(out).toContain('<a>l</a>')
	})

	test('removes images, scripts and styles', () => {
		const out = sanitizeHtml('<p>a<img src=x onerror=alert(1)><script>b()</script><style>p{}</style></p>')
		expect(out).toBe('<p>a</p>')
	})

	test('forces a safe rel and target on links', () => {
		const out = sanitizeHtml('<a href="https://example.org" rel="opener" target="_self">x</a>')
		expect(out).toContain('rel="nofollow noopener noreferrer"')
		expect(out).toContain('target="_blank"')
		expect(out).toContain('href="https://example.org"')
	})

	test('unwraps unknown elements but keeps their text', () => {
		expect(sanitizeHtml('<div><font color="red">red</font></div>')).toBe('red')
	})

	test('drops style and id attributes', () => {
		expect(sanitizeHtml('<p style="position:fixed" id="app">x</p>')).toBe('<p>x</p>')
	})

	test('keeps only the classes the server keeps', () => {
		const out = sanitizeHtml('<span class="h-card icon-delete"><a href="https://e.org/@a" class="u-url mention button-vue">@a</a></span><span class="app-navigation">x</span>')
		expect(out).toContain('<span class="h-card">')
		expect(out).toContain('class="u-url mention"')
		expect(out).toContain('<span>x</span>')
		expect(out).not.toContain('icon-delete')
		expect(out).not.toContain('button-vue')
		expect(out).not.toContain('app-navigation')
	})

	test('returns an empty string for non-strings and empty input', () => {
		expect(sanitizeHtml('')).toBe('')
		expect(sanitizeHtml(null)).toBe('')
		expect(sanitizeHtml(undefined)).toBe('')
	})
})

/**
 * The body of a constant array in `lib/Security/HtmlSanitizer.php`, read from
 * the source: the two sanitisers are only belt and braces while they agree.
 *
 * @param {string} name - The constant's name
 * @return {string}
 */
function serverConstant(name) {
	const source = readFileSync(resolve(process.cwd(), 'lib/Security/HtmlSanitizer.php'), 'utf8')
	const body = source.match(new RegExp(`const ${name} = \\[\\n([\\s\\S]*?)\\n\\t\\];`))
	expect(body, name).not.toBeNull()
	return body[1]
}

describe('the server-side sanitiser', () => {
	const strings = (body) => [...body.matchAll(/^\t\t'([^']+)',$/gm)].map((entry) => entry[1]).sort()

	test('allows the same classes', () => {
		expect([...ALLOWED_CLASSES].sort()).toEqual(strings(serverConstant('ALLOWED_CLASSES')))
	})

	test('allows the same schemes', () => {
		expect([...ALLOWED_SCHEMES].sort()).toEqual(strings(serverConstant('ALLOWED_SCHEMES')))
	})

	test('allows the same elements', () => {
		const keys = [...serverConstant('ALLOWED_ELEMENTS').matchAll(/^\t\t'([^']+)' =>/gm)].map((entry) => entry[1]).sort()
		expect([...ALLOWED_TAGS].sort()).toEqual(keys)
	})
})
