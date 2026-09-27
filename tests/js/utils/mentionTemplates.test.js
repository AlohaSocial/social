/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { escapeHtml, hashtagChip, mentionChip, mentionMenuItem, safeHref } from '../../../src/utils/mentionTemplates.js'

/**
 * @param {string} html what a template made
 * @return {HTMLElement} it, parsed the way tributejs puts it on the page
 */
function parsed(html) {
	const host = document.createElement('div')
	host.innerHTML = html
	return host
}

/** what a hostile server can put in the fields the menu shows */
const hostile = {
	// survives the server's tag flattener before it was taught unterminated tags
	key: 'Alice <img src=x onerror=alert(1)//',
	value: 'alice@evil.example"><script>alert(3)</script>',
	url: 'javascript:alert(4)',
	avatar: 'https://evil.example/a.png" onerror="alert(2)',
}

describe('mentionTemplates', () => {
	it('escapes the five characters that matter in HTML', () => {
		expect(escapeHtml('<a href="x">\'&\'</a>')).toBe('&lt;a href=&quot;x&quot;&gt;&#39;&amp;&#39;&lt;/a&gt;')
		expect(escapeHtml(undefined)).toBe('')
	})

	it('lets only http and https through as a link', () => {
		expect(safeHref('https://a.example/@x')).toBe('https://a.example/@x')
		expect(safeHref('javascript:alert(1)')).toBe('#')
		expect(safeHref('data:text/html,x')).toBe('#')
	})

	it('lets a path on this server through, but not a protocol-relative address', () => {
		expect(safeHref('/index.php/avatar/alice/32')).toBe('/index.php/avatar/alice/32')
		expect(safeHref('//evil.example/x')).toBe('#')
	})

	it('draws a menu row from a hostile account as text, with no handler anywhere', () => {
		const row = parsed(mentionMenuItem(hostile))

		expect(row.querySelectorAll('img')).toHaveLength(1)
		expect(row.querySelector('img').getAttribute('onerror')).toBeNull()
		expect(row.querySelector('script')).toBeNull()
		expect(row.querySelector('.displayName').textContent).toBe(hostile.key)
		expect(row.querySelector('.account').textContent).toBe(hostile.value)
	})

	it('inserts a chip from a hostile account that neither runs nor links to script', () => {
		const chip = parsed(mentionChip(hostile))

		expect(chip.querySelector('a').getAttribute('href')).toBe('#')
		expect(chip.querySelector('a').getAttribute('rel')).toBe('noopener noreferrer')
		expect(chip.querySelector('img').getAttribute('onerror')).toBeNull()
		expect(chip.querySelector('script')).toBeNull()
		expect(chip.querySelector('a').textContent).toBe('@' + hostile.value)
	})

	it('keeps an ordinary account as it was', () => {
		const chip = parsed(mentionChip({ value: 'alice@a.example', url: 'https://a.example/@alice', avatar: '/avatar/alice/32' }))

		expect(chip.querySelector('a').getAttribute('href')).toBe('https://a.example/@alice')
		expect(chip.querySelector('img').getAttribute('src')).toBe('/avatar/alice/32')
	})

	it('escapes a hashtag chip', () => {
		const chip = parsed(hashtagChip('x"><img src=x onerror=alert(1)>', '/timeline/tags/x'))

		expect(chip.querySelector('img')).toBeNull()
		expect(chip.querySelector('a').textContent).toBe('#x"><img src=x onerror=alert(1)>')
	})
})
