/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ProfileHighlights from '../../../src/components/ProfileHighlights.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * @param {object} data what the server answers
 * @return {object} the mounted component, once its request has settled
 */
async function mountHighlights(data) {
	axios.get.mockResolvedValue({ data })

	const wrapper = mount(ProfileHighlights, {
		props: { accountId: '42' },
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
	await flushPromises()

	return wrapper
}

function available(overrides = {}) {
	return {
		available: true,
		since: 1600000000,
		weeks: [0, 0, 1, 4, 2, 0, 0, 8, 3, 1, 0, 2],
		week_starts: 1750000000,
		hashtags: [{ name: 'nextcloud', count: 12 }],
		...overrides,
	}
}

describe('ProfileHighlights', () => {
	beforeEach(() => {
		window.localStorage.clear()
		axios.get.mockReset()
	})

	it('places posting activity in a card that stays hidden after dismissal', async () => {
		const wrapper = await mountHighlights(available())
		expect(wrapper.find('.info-card').attributes('aria-label')).toBe('Posting activity')
		await wrapper.find('[aria-label="Hide Posting activity"]').trigger('click')
		expect(wrapper.find('.profile-highlights').exists()).toBe(false)
		const reopened = await mountHighlights(available())
		expect(reopened.find('.profile-highlights').exists()).toBe(false)
	})

	it('asks the account it was given for', async () => {
		await mountHighlights(available())

		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/accounts/42/highlights'))
	})

	it('says when the account has been here', async () => {
		const wrapper = await mountHighlights(available())

		expect(wrapper.find('.profile-highlights__since').text()).toContain('Here since')
	})

	/**
	 * A line rather than the twelve bars it was: what somebody reads off a
	 * profile is a shape -- busy then quiet, steady, just arrived -- which a
	 * line gives and twelve separate rectangles do not.
	 */
	it('draws the twelve weeks as one line', async () => {
		const wrapper = await mountHighlights(available())
		const line = wrapper.find('.profile-highlights__spark-line').attributes('d')

		// one move and eleven lines, one per week
		expect(line.startsWith('M')).toBe(true)
		expect(line.match(/L/g)).toHaveLength(11)
	})

	it('closes the wash under the line along the floor', async () => {
		const wrapper = await mountHighlights(available())
		const area = wrapper.find('.profile-highlights__spark-area').attributes('d')

		expect(area.endsWith('Z')).toBe(true)
		expect(area).toContain(' 32 ')
	})

	// the chart is decoration over a sentence: a line says nothing to anybody
	// not looking at it
	it('writes the chart out in words as well', async () => {
		const wrapper = await mountHighlights(available())

		expect(wrapper.find('.profile-highlights__summary').text())
			.toContain('21 posts in the last twelve weeks')
		expect(wrapper.find('.profile-highlights__spark').attributes('aria-hidden')).toBe('true')
	})

	it('draws the busiest week at the top and an empty one on the floor', async () => {
		const wrapper = await mountHighlights(available({ weeks: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 4] }))
		const points = wrapper.find('.profile-highlights__spark-line').attributes('d')
			.split(/[ML]/).filter(Boolean).map((pair) => Number(pair.trim().split(' ')[1]))

		// y counts down the box, so the busiest week is the smallest number
		expect(points[11]).toBeLessThan(points[0])
		expect(points[0]).toBe(29)
	})

	/**
	 * The shape says how much; this says what kind. An account that has
	 * stopped posting says so, rather than trailing off and leaving the reader
	 * to notice.
	 */
	it('says what kind of week it has been', async () => {
		const quiet = await mountHighlights(available({ weeks: [4, 4, 4, 4, 4, 4, 4, 4, 0, 0, 0, 0] }))
		expect(quiet.find('.profile-highlights__summary').text()).toContain('Quiet lately')

		const busy = await mountHighlights(available({ weeks: [0, 0, 0, 0, 1, 0, 0, 1, 6, 7, 8, 9] }))
		expect(busy.find('.profile-highlights__summary').text()).toContain('Busier than usual')

		const steady = await mountHighlights(available({ weeks: [3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3] }))
		expect(steady.find('.profile-highlights__summary').text()).toContain('Posting steadily')
	})

	/**
	 * "Usual" needs a past: an account made twenty minutes ago has none, and
	 * neither has one whose earlier weeks are empty. The chart still counts.
	 */
	it('says nothing about the rhythm until there is a usual to compare with', async () => {
		const young = await mountHighlights(available({ since: Math.floor(Date.now() / 1000) - 20 * 60, weeks: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 5] }))
		expect(young.find('.profile-highlights__summary').text()).toContain('5 posts in the last twelve weeks')
		expect(young.find('.profile-highlights__summary').text()).not.toContain('than usual')

		const sevenWeeks = await mountHighlights(available({ since: Math.floor(Date.now() / 1000) - 7 * 7 * 86400, weeks: [0, 0, 0, 0, 0, 1, 0, 1, 6, 7, 8, 9] }))
		expect(sevenWeeks.find('.profile-highlights__summary').text()).not.toContain('Busier')

		const noPast = await mountHighlights(available({ weeks: [0, 0, 0, 0, 0, 0, 0, 0, 1, 2, 3, 4] }))
		expect(noPast.find('.profile-highlights__summary').text()).not.toContain('Busier')
	})

	/**
	 * The hashtags are drawn above the bio, with their counts, by
	 * `FeaturedTags`. Drawing them again here without the counts was the page
	 * saying the same thing twice and saying less the second time.
	 */
	it('leaves the hashtags to the list that has their counts', async () => {
		const wrapper = await mountHighlights(available())

		expect(wrapper.find('.profile-highlights__tag').exists()).toBe(false)
	})

	/**
	 * The server says so for a remote account: this instance holds only the
	 * part of their history that happened to arrive, and a chart of that would
	 * show a quiet year for an account that was busy.
	 */
	it('renders nothing at all when the server cannot chart the account', async () => {
		const wrapper = await mountHighlights({ available: false, since: 0, weeks: [], hashtags: [] })

		expect(wrapper.find('.profile-highlights').exists()).toBe(false)
	})

	it('renders nothing when the request fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mount(ProfileHighlights, {
			props: { accountId: '42' },
			global: { stubs: { RouterLink: RouterLinkStub } },
		})
		await flushPromises()

		expect(wrapper.find('.profile-highlights').exists()).toBe(false)
	})

	it('asks for nothing until it knows whose profile it is on', async () => {
		mount(ProfileHighlights, {
			props: { accountId: '' },
			global: { stubs: { RouterLink: RouterLinkStub } },
		})
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('asks again when the profile changes', async () => {
		const wrapper = await mountHighlights(available())

		await wrapper.setProps({ accountId: '43' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get).toHaveBeenLastCalledWith(expect.stringContaining('/accounts/43/highlights'))
	})
})
