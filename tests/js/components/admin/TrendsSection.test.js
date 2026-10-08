/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TrendsSection from '../../../../src/components/admin/TrendsSection.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const EMPTY = { tags: [], links: [], statuses: [], trending: [] }

function mountSection() {
	return mount(TrendsSection, {
		global: {
			stubs: {
				NcSelect: true,
			},
		},
	})
}

describe('the trends card', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: EMPTY })
		post.mockReset().mockResolvedValue({ data: EMPTY })
		del.mockReset().mockResolvedValue({ data: EMPTY })
	})

	it('says so plainly when nothing is trending and nothing is kept out', async () => {
		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.text()).toContain('Nothing is trending.')
		expect(wrapper.text()).toContain('Nothing is kept out.')
	})

	it('offers a Keep out beside each trending hashtag', async () => {
		get.mockResolvedValue({ data: { ...EMPTY, trending: [{ hashtag: 'cats' }, { hashtag: 'dogs' }] } })

		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.text()).toContain('#cats')
		await wrapper.findAll('.trends__item button')[0].trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/moderation/trends'),
			{ kind: 'tag', ref: 'cats' },
		)
	})

	/**
	 * The question a moderator has is "what am I keeping out of Explore", not
	 * "what hashtags am I keeping out", so the three kinds are one list.
	 */
	it('shows what is kept out as one list whatever kind it is', async () => {
		get.mockResolvedValue({
			data: {
				tags: [{ ref: 'cats', approved: false, moderator: 'alice' }],
				links: [{ ref: 'https://news.example/a', approved: false, moderator: 'alice' }],
				statuses: [],
				trending: [],
			},
		})

		const wrapper = mountSection()
		await flushPromises()

		const text = wrapper.text()
		expect(text).toContain('cats')
		expect(text).toContain('https://news.example/a')
		expect(text).not.toContain('Nothing is kept out.')
	})

	/** An approval records that somebody looked; it is not something to undo. */
	it('lists only what is actually kept out, not what was approved', async () => {
		get.mockResolvedValue({
			data: { ...EMPTY, tags: [{ ref: 'cats', approved: true, moderator: 'alice' }] },
		})

		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.text()).toContain('Nothing is kept out.')
	})

	it('lets one back in', async () => {
		get.mockResolvedValue({
			data: { ...EMPTY, tags: [{ ref: 'cats', approved: false, moderator: 'alice' }] },
		})

		const wrapper = mountSection()
		await flushPromises()

		await wrapper.findAll('.trends__item button').at(-1).trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(
			expect.stringContaining('/moderation/trends'),
			{ data: { kind: 'tag', ref: 'cats' } },
		)
	})
})
