/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FollowConstellation from '../../../src/components/FollowConstellation.vue'

function suggestion(acct, via = []) {
	return {
		account: { acct, display_name: acct.split('@')[0], avatar: `https://x.example/${acct}.png` },
		via,
		followed_by: via.length,
	}
}

const SUGGESTIONS = [
	suggestion('ana@a.example', ['bo@b.example']),
	suggestion('cy@c.example', ['bo@b.example', 'dee@d.example']),
	suggestion('eve@e.example'),
]

async function mountSky(props = {}) {
	const wrapper = mount(FollowConstellation, {
		props: {
			suggestions: SUGGESTIONS,
			youAvatar: 'https://x.example/me.png',
			reasonFor: (one) => `Followed by ${one.via.length}`,
			...props,
		},
		attachTo: document.body,
	})
	// the sky is laid out once mounted, and drawn on the next tick
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('FollowConstellation', () => {
	beforeEach(() => {
		// the settled sky straight away, as for a reader who asked for no motion
		window.matchMedia = vi.fn((query) => ({ matches: query.includes('reduce') }))
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('draws the reader, the people the suggestions came through, and a star for each', async () => {
		const wrapper = await mountSky()

		expect(wrapper.find('.constellation__you img').attributes('src')).toBe('https://x.example/me.png')
		expect(wrapper.findAll('.constellation__via').map((via) => via.text())).toEqual(['@bo', '@dee'])
		expect(wrapper.findAll('.constellation__star')).toHaveLength(3)
		// a tie from the reader to each of the two, and one per suggestion-via pair plus the direct one
		expect(wrapper.findAll('.constellation__line')).toHaveLength(2 + 3 + 1)
	})

	it('keeps every star on the card once it has settled', async () => {
		const wrapper = await mountSky()

		for (const star of wrapper.findAll('.constellation__star')) {
			const left = parseFloat(star.attributes('style').match(/left: ([\d.]+)%/)[1])
			const top = parseFloat(star.attributes('style').match(/top: ([\d.]+)%/)[1])
			expect(left).toBeGreaterThan(0)
			expect(left).toBeLessThan(100)
			expect(top).toBeGreaterThan(0)
			expect(top).toBeLessThan(100)
		}
	})

	/** every star says who it is and why, so a screen reader gets the list too */
	it('labels each star with who it is and why it is there', async () => {
		const star = (await mountSky()).findAll('.constellation__star')[1]

		expect(star.attributes('aria-label')).toBe('Follow cy. Followed by 2')
	})

	it('follows on a press, and not again once followed', async () => {
		const followed = new Set()
		const wrapper = await mountSky({ isFollowed: (account) => followed.has(account.acct) })

		await wrapper.findAll('.constellation__star')[0].trigger('click')
		expect(wrapper.emitted('follow')).toEqual([[SUGGESTIONS[0].account]])

		followed.add('ana@a.example')
		await wrapper.setProps({ isFollowed: (account) => followed.has(account.acct) })
		await wrapper.findAll('.constellation__star')[0].trigger('click')
		expect(wrapper.emitted('follow')).toHaveLength(1)
		expect(wrapper.findAll('.constellation__star')[0].classes()).toContain('constellation__star--followed')
	})

	/** a drag moves the star and leaves it there; it is not also a follow */
	it('moves a dragged star, keeps it where it was dropped, and does not follow', async () => {
		const wrapper = await mountSky()
		wrapper.find('.constellation').element.getBoundingClientRect = () => ({ left: 0, top: 0, width: 200, height: 200 })
		const star = wrapper.findAll('.constellation__star')[2]

		star.element.dispatchEvent(new MouseEvent('pointerdown', { clientX: 10, clientY: 10, bubbles: true }))
		window.dispatchEvent(new MouseEvent('pointermove', { clientX: 20, clientY: 180 }))
		window.dispatchEvent(new MouseEvent('pointerup'))
		await wrapper.vm.$nextTick()
		await star.trigger('click')

		expect(star.attributes('style')).toContain('left: 10%')
		expect(star.attributes('style')).toContain('top: 90%')
		expect(wrapper.emitted('follow')).toBeUndefined()
	})

	it('treats a press that barely moved as a press', async () => {
		const wrapper = await mountSky()
		wrapper.find('.constellation').element.getBoundingClientRect = () => ({ left: 0, top: 0, width: 200, height: 200 })
		const star = wrapper.findAll('.constellation__star')[0]

		star.element.dispatchEvent(new MouseEvent('pointerdown', { clientX: 50, clientY: 50, bubbles: true }))
		window.dispatchEvent(new MouseEvent('pointermove', { clientX: 52, clientY: 51 }))
		window.dispatchEvent(new MouseEvent('pointerup'))
		await star.trigger('click')

		expect(wrapper.emitted('follow')).toHaveLength(1)
	})
})
