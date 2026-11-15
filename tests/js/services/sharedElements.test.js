/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { departFor, listenForPresses, sharedSelectors } from '../../../src/services/sharedElements.js'

/**
 * Two pages side by side in the cell App.vue gives them, the old one with
 * whatever is on it.
 *
 * @param {string} old the old page's markup
 * @return {HTMLElement} the pages
 */
function pages(old) {
	document.body.innerHTML = `<div class="social__pages"><div class="old">${old}</div></div>`

	return document.querySelector('.social__pages')
}

/**
 * The new page arriving next to the old one.
 *
 * @param {string} markup what is on it
 */
function arrive(markup) {
	const page = document.createElement('div')
	page.innerHTML = markup
	document.querySelector('.social__pages').append(page)
}

describe('carrying what was pressed into the page it opens', () => {
	let stop

	beforeEach(() => {
		document.startViewTransition = vi.fn()
		window.matchMedia = vi.fn(() => ({ matches: false }))
		stop = listenForPresses()
	})

	afterEach(() => {
		stop()
		delete document.startViewTransition
		document.body.innerHTML = ''
		vi.useRealTimers()
	})

	it('knows what a thread and a profile open from', () => {
		expect(sharedSelectors({ name: 'single-post', params: { id: '42' } })).toEqual({
			from: '[data-social-status="42"]',
			to: '.social__pages [data-social-status="42"]',
		})
		expect(sharedSelectors({ name: 'profile', params: { account: 'a@b' } })?.to).toContain('.user-profile')
		expect(sharedSelectors({ name: 'settings' })).toBeNull()
	})

	it('carries a pressed post card into the head of its thread', () => {
		pages('<article data-social-status="42"><p class="body">hello</p></article>')
		const card = document.querySelector('[data-social-status="42"]')
		document.querySelector('.body').click()

		const shared = departFor({ name: 'single-post', params: { id: '42' } })
		const name = card.style.viewTransitionName
		expect(name).toMatch(/^social-morph-\d+$/)

		arrive('<div class="main-post"><article data-social-status="42"></article></div>')
		shared.land()

		const head = document.querySelector('.main-post [data-social-status="42"]')
		expect(card.style.viewTransitionName).toBe('')
		expect(head.style.viewTransitionName).toBe(name)

		shared.release()
		expect(head.style.viewTransitionName).toBe('')
	})

	it('carries a pressed face into the top of the profile', () => {
		pages('<div class="post-avatar"><a><div class="avatardiv"><img></div></a></div>')
		const face = document.querySelector('.avatardiv')
		document.querySelector('img').click()

		const shared = departFor({ name: 'profile', params: { account: 'a@b' } })
		const name = face.style.viewTransitionName
		arrive('<div class="user-profile"><div class="avatardiv"></div></div>')
		shared.land()

		expect(name).toMatch(/^social-morph-\d+$/)
		expect(document.querySelector('.user-profile .avatardiv').style.viewTransitionName).toBe(name)
		expect(face.style.viewTransitionName).toBe('')
	})

	it('carries nothing that was not pressed', () => {
		pages('<article data-social-status="42"></article>')

		expect(departFor({ name: 'single-post', params: { id: '42' } })).toBeNull()
	})

	it('carries nothing when the press was a while ago', () => {
		vi.useFakeTimers()
		pages('<article data-social-status="42"></article>')
		document.querySelector('article').click()
		vi.advanceTimersByTime(5000)

		expect(departFor({ name: 'single-post', params: { id: '42' } })).toBeNull()
	})

	it('does not land on the page being left', () => {
		pages('<article data-social-status="42"><span class="tap"></span></article><article data-social-status="42"></article>')
		document.querySelector('.tap').click()
		const shared = departFor({ name: 'single-post', params: { id: '42' } })

		shared.land()

		for (const card of document.querySelectorAll('article')) {
			expect(card.style.viewTransitionName).toBe('')
		}
	})
})
