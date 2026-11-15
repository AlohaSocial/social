/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import FirstPostCelebration from '../../../src/components/FirstPostCelebration.vue'

/**
 * Answers the one media query the component asks about.
 *
 * @param {boolean} reduce whether the reader asked for no motion
 */
function setReducedMotion(reduce) {
	window.matchMedia = vi.fn((query) => ({
		matches: reduce && query.includes('prefers-reduced-motion'),
		media: query,
		addEventListener: () => {},
		removeEventListener: () => {},
		addListener: () => {},
		removeListener: () => {},
	}))
}

describe('FirstPostCelebration', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		setReducedMotion(false)
	})

	afterEach(() => {
		vi.runOnlyPendingTimers()
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('says what happened, and throws confetti to say it', async () => {
		const wrapper = mount(FirstPostCelebration)

		expect(wrapper.find('.first-post__banner').text()).toContain('Your first post is out there')
		// announced politely: a live region, not a dialog, so nothing is
		// interrupted and no focus moves — and it is filled a tick after it is
		// on the page, which is the only way it is reliably read out
		const live = wrapper.find('[role="status"]')
		expect(live.exists()).toBe(true)
		// the region is on the page before it is filled, a tick later, which is
		// the only way it is reliably read out
		await nextTick()
		await nextTick()
		expect(live.text()).toContain('Your first post is out there')
		expect(wrapper.findAll('.first-post__piece').length).toBe(36)
		// the pieces are decoration and are told so
		expect(wrapper.find('.first-post__confetti').attributes('aria-hidden')).toBe('true')

		wrapper.unmount()
	})

	it('holds nothing focusable, so keyboard focus can never land in it', () => {
		const wrapper = mount(FirstPostCelebration, { attachTo: document.body })

		expect(wrapper.element.querySelectorAll('a, button, input, select, textarea, [tabindex]').length).toBe(0)

		wrapper.unmount()
	})

	it('leaves on its own, briefly, without being asked', () => {
		const wrapper = mount(FirstPostCelebration)

		vi.advanceTimersByTime(2599)
		expect(wrapper.emitted('done')).toBeUndefined()

		vi.advanceTimersByTime(1)
		// it fades first, and only then tells the parent it may go
		expect(wrapper.vm.leaving).toBe(true)
		expect(wrapper.emitted('done')).toBeUndefined()

		vi.advanceTimersByTime(300)
		expect(wrapper.emitted('done')).toHaveLength(1)

		wrapper.unmount()
	})

	it.each([
		['Escape', () => window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }))],
		['a click anywhere', () => window.dispatchEvent(new window.Event('pointerdown'))],
	])('is skippable with %s', (name, skip) => {
		const wrapper = mount(FirstPostCelebration)

		skip()
		expect(wrapper.vm.leaving).toBe(true)

		vi.advanceTimersByTime(300)
		expect(wrapper.emitted('done')).toHaveLength(1)

		wrapper.unmount()
	})

	it('ignores keys that do not mean "not now"', () => {
		const wrapper = mount(FirstPostCelebration)

		window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'a' }))
		expect(wrapper.vm.leaving).toBe(false)

		wrapper.unmount()
	})

	// a reader who asked for no motion still gets told, just held still
	it('drops the confetti but keeps the acknowledgement under reduced motion', () => {
		setReducedMotion(true)
		const wrapper = mount(FirstPostCelebration)

		// not a single piece of confetti, and nothing left over to animate
		expect(wrapper.find('.first-post__confetti').exists()).toBe(false)
		expect(wrapper.findAll('.first-post__piece').length).toBe(0)
		// but the reader is still told, and for just as long
		expect(wrapper.find('.first-post__banner').text()).toContain('Your first post is out there')

		vi.advanceTimersByTime(2600)
		expect(wrapper.emitted('done')).toHaveLength(1)

		wrapper.unmount()
	})

	it('takes back every timer and every listener when it goes', () => {
		const wrapper = mount(FirstPostCelebration)
		const removeListener = vi.spyOn(window, 'removeEventListener')

		expect(vi.getTimerCount()).toBe(1)
		wrapper.unmount()

		expect(vi.getTimerCount()).toBe(0)
		expect(removeListener).toHaveBeenCalledWith('keydown', expect.any(Function))
		expect(removeListener).toHaveBeenCalledWith('pointerdown', expect.any(Function))

		// and nothing is listening any more: the events it used to answer no
		// longer reach it
		window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }))
		vi.advanceTimersByTime(5000)
		expect(wrapper.emitted('done')).toBeUndefined()
	})

	it('takes back the fade-out timer too when it is unmounted mid-skip', () => {
		const wrapper = mount(FirstPostCelebration)

		window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }))
		expect(vi.getTimerCount()).toBe(1)

		wrapper.unmount()
		expect(vi.getTimerCount()).toBe(0)
	})

	/**
	 * The first post is the one moment that happens before anybody has a
	 * reason to care. These are the others, and each is once.
	 */
	it.each([
		['first-follower', 'first follower'],
		['hundred-posts', 'A hundred posts'],
		['anniversary', 'One year here today'],
	])('marks %s', (occasion, said) => {
		const wrapper = mount(FirstPostCelebration, { props: { occasion } })

		expect(wrapper.find('.first-post__banner').text()).toContain(said)
	})

	it('falls back to the first post for an occasion it does not know', () => {
		const wrapper = mount(FirstPostCelebration, { props: { occasion: 'birthday-of-the-server' } })

		expect(wrapper.find('.first-post__banner').text()).toContain('first post')
	})

	it('lets the flower from the logo bloom for a first post', () => {
		const wrapper = mount(FirstPostCelebration)

		expect(wrapper.find('.first-post__banner .blooming-flower').exists()).toBe(true)
		expect(wrapper.find('.first-post__banner').text()).not.toContain('🎉')
	})

	it('keeps its own emoji for the other moments', () => {
		const wrapper = mount(FirstPostCelebration, { props: { occasion: 'anniversary' } })

		expect(wrapper.find('.blooming-flower').exists()).toBe(false)
		expect(wrapper.find('.first-post__banner').text()).toContain('🎂')
	})
})
