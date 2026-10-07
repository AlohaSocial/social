/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DayShortPanel from '../../../src/components/DayShortPanel.vue'

const { post, del, get } = vi.hoisted(() => ({ post: vi.fn(), del: vi.fn(), get: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post, delete: del, get } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

const alice = { id: '7', acct: 'alice', username: 'alice', display_name: 'Alice', avatar: '' }
const bob = { id: '9', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob', avatar: 'https://remote.example/b.png' }

function short(id, account, overrides = {}) {
	return {
		id,
		account,
		seen: false,
		duration: 5,
		caption: '',
		view_count: 0,
		expires_at: new Date(Date.now() + 2 * 3600 * 1000).toISOString(),
		media: { type: 'image', url: `https://cloud.example/${id}.jpg` },
		...overrides,
	}
}

function mountPanel(props) {
	return mount(DayShortPanel, {
		props: { active: true, ...props },
		global: { stubs: { RouterLink: RouterLinkStub, NcButton: { template: '<button class="nc-button" type="button"><slot name="icon" /><slot /></button>' } } },
	})
}

describe('DayShortPanel', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		post.mockReset().mockResolvedValue({ data: {} })
		del.mockReset()
		get.mockReset().mockResolvedValue({ data: { reactions: [] } })
		showError.mockReset()
		showSuccess.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('names the poster, links to them, and shows the caption', () => {
		const wrapper = mountPanel({ short: short('2', bob, { caption: 'at the lake' }) })

		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'bob@remote.example' } })
		expect(wrapper.find('.day-short__name').text()).toBe('Bob')
		expect(wrapper.find('.day-short__caption').text()).toBe('at the lake')
	})

	it('says at least one hour is left, and nothing when it cannot tell', () => {
		const soon = mountPanel({ short: short('2', bob, { expires_at: new Date(Date.now() + 60 * 1000).toISOString() }) })
		expect(soon.find('.day-short__left').text()).toBe('1h left')

		const unknown = mountPanel({ short: short('3', bob, { expires_at: undefined }) })
		expect(unknown.find('.day-short__left').exists()).toBe(false)
	})

	it('does nothing while its slide is not the one on screen', async () => {
		const wrapper = mountPanel({ short: short('2', bob), active: false })
		await flushPromises()
		vi.advanceTimersByTime(10_000)

		expect(post).not.toHaveBeenCalled()
		expect(wrapper.emitted('done')).toBeUndefined()
	})

	it('says done when a picture\'s seconds are up, and starts over when it comes back', async () => {
		const wrapper = mountPanel({ short: short('2', bob, { duration: 3 }) })
		vi.advanceTimersByTime(3200)
		expect(wrapper.emitted('done')).toHaveLength(1)

		await wrapper.setProps({ active: false })
		expect(wrapper.vm.progress).toBe(0)
		await wrapper.setProps({ active: true })
		vi.advanceTimersByTime(1000)
		expect(wrapper.emitted('done')).toHaveLength(1)
	})

	it('runs no clock for a video, which ends by itself', () => {
		const wrapper = mountPanel({ short: short('2', bob, { media: { type: 'video', url: 'https://cloud.example/2.mp4' } }) })
		vi.advanceTimersByTime(60_000)

		expect(wrapper.emitted('done')).toBeUndefined()
		expect(wrapper.find('.day-short__progress').exists()).toBe(false)
	})

	it('holds the clock while a reply is written, and says so', async () => {
		const wrapper = mountPanel({ short: short('2', bob, { duration: 3 }) })

		await wrapper.find('.day-short__reply-field').trigger('focus')
		vi.advanceTimersByTime(9000)
		expect(wrapper.emitted('done')).toBeUndefined()
		expect(wrapper.emitted('hold')[0]).toEqual([true])

		await wrapper.find('.day-short__reply-field').trigger('blur')
		expect(wrapper.emitted('hold')[1]).toEqual([false])
	})

	it('says so when the short could not be deleted', async () => {
		del.mockRejectedValue(new Error('nope'))
		const wrapper = mountPanel({ short: short('1', alice, { seen: true }), own: true })

		await wrapper.find('.day-short__delete').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not delete the short')
		expect(wrapper.emitted('deleted')).toBeUndefined()
	})

	it('reacts with the emoji under its name', () => {
		const wrapper = mountPanel({ short: short('2', bob) })

		expect(wrapper.find('.day-short__reaction').attributes('aria-label')).toBe('React with ❤️')
	})

	/**
	 * A slow request for one short's replies must not put them under the next
	 * one: the poster would be reading answers to a short they have left.
	 */
	it('does not show one short\'s answers under the next', async () => {
		let answerFirst
		get.mockImplementationOnce(() => new Promise((resolve) => {
			answerFirst = () => resolve({ data: { reactions: [{ id: 'r1', type: 'reply', content: 'about the first', account: bob }] } })
		}))
		const wrapper = mountPanel({ short: short('1', alice, { seen: true }), own: true })
		await flushPromises()

		get.mockResolvedValueOnce({ data: { reactions: [{ id: 'r2', type: 'reply', content: 'about the second', account: bob }] } })
		await wrapper.setProps({ short: short('2', alice, { seen: true }) })
		await flushPromises()
		expect(wrapper.vm.answers.map((one) => one.content)).toEqual(['about the second'])

		answerFirst()
		await flushPromises()
		expect(wrapper.vm.answers.map((one) => one.content)).toEqual(['about the second'])
	})
})
