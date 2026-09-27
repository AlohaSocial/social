/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { editableToPlainText, htmlToPlainText } from '../../../src/utils/plainText.js'

describe('htmlToPlainText', () => {
	it('keeps the first line break between bare text and a block', () => {
		expect(htmlToPlainText('one<div>two</div>')).toBe('one\ntwo')
	})

	it('keeps one break between adjacent blocks', () => {
		expect(htmlToPlainText('<p>one</p><p>two</p>')).toBe('one\ntwo')
	})

	it('keeps explicit breaks inside a block', () => {
		expect(htmlToPlainText('<p>one<br>two</p>')).toBe('one\ntwo')
	})
})

describe('editableToPlainText', () => {
	/**
	 * @param {string} html what the editable box holds
	 * @return {HTMLElement}
	 */
	function box(html) {
		const element = document.createElement('div')
		element.innerHTML = html

		return element
	}

	it('is empty for a box that is not there yet', () => {
		expect(editableToPlainText(undefined)).toBe('')
		expect(editableToPlainText(null)).toBe('')
	})

	it('reads each line the browser wrapped in a div as a line', () => {
		expect(editableToPlainText(box('one<div>two</div><div>three</div>'))).toBe('one\ntwo\nthree')
	})

	it('counts an emoji picture as its alt text', () => {
		expect(editableToPlainText(box('hello <img class="emoji" alt="🎉" src="x.png"> there'))).toBe('hello 🎉 there')
	})

	it('reads a mention pill as the handle it shows', () => {
		expect(editableToPlainText(box('<span class="mention"><a href="https://x.example/@a"><img src="a.png">@a@x.example</a></span>\u00a0hi')))
			.toBe('@a@x.example\u00a0hi')
	})

	it('leaves the box itself alone', () => {
		const element = box('<img class="emoji" alt="🎉" src="x.png">')
		editableToPlainText(element)

		expect(element.getElementsByClassName('emoji')).toHaveLength(1)
	})
})
