/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import Announcements from '../../../src/components/Announcements.vue'
import eventBus, { REACTION_PICK } from '../../../src/services/eventBus.js'
import { showError } from '../../../src/services/toast.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const LIST = '/index.php/apps/social/api/v1/announcements'

/**
 * One announcement, as `GET /api/v1/announcements` sends it.
 *
 * @param {object} overrides what this one differs in
 * @return {object} the entity
 */
function announcement(overrides = {}) {
	return {
		id: '3',
		content: '<p>We are moving servers on Friday</p>',
		starts_at: null,
		ends_at: null,
		all_day: false,
		published_at: '2026-09-14T09:00:00.000Z',
		updated_at: '2026-09-14T09:00:00.000Z',
		read: false,
		mentions: [],
		statuses: [],
		tags: [],
		emojis: [],
		reactions: [],
		...overrides,
	}
}

/**
 * @param {object[]|Error} answer what the route answers, or what it throws
 * @param {object} serverData what the page was told about itself
 * @return {Promise<object>} the mounted card, once its request has settled
 */
async function mountCard(answer = [announcement()], serverData = {}) {
	if (answer instanceof Error) {
		axios.get.mockRejectedValue(answer)
	} else {
		axios.get.mockResolvedValue({ data: answer })
	}

	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: false, ...serverData })

	const wrapper = mount(Announcements, {
		global: {
			plugins: [pinia],
			stubs: { RouterLink: RouterLinkStub },
		},
	})
	await flushPromises()

	return wrapper
}

/** @param {object} wrapper the mounted card @return {object[]} the reaction pills */
function reactions(wrapper) {
	return wrapper.findAll('.reactions .reaction').filter((button) => !button.classes('reaction--add'))
}

describe('the announcements above the timeline', () => {
	it('does not ask when the page says nothing applies, and asks the next time', async () => {
		await mountCard([announcement()], { startup: { has_announcements: false } })
		expect(axios.get).not.toHaveBeenCalled()

		const wrapper = mount(Announcements, { global: { stubs: { RouterLink: RouterLinkStub } } })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/announcements')
		wrapper.unmount()
	})

	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-09-15T09:00:00.000Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('reads what applies now from the client route', async () => {
		const wrapper = await mountCard()

		expect(axios.get).toHaveBeenCalledWith(LIST)
		expect(wrapper.find('.announcements').exists()).toBe(true)
		expect(wrapper.text()).toContain('We are moving servers on Friday')
	})

	it('shows nothing at all when the instance is announcing nothing', async () => {
		const wrapper = await mountCard([])

		expect(wrapper.find('.announcements').exists()).toBe(false)
	})

	it('shows nothing when every announcement has already been read', async () => {
		const wrapper = await mountCard([announcement({ read: true })])

		// a dismissed announcement is gone: the point of the card is the
		// interruption, and there is nothing to interrupt for here
		expect(wrapper.find('.announcements').exists()).toBe(false)
	})

	it('asks for nothing at all on the public page', async () => {
		// the route needs a viewer; a logged-out reader would get a 401 for a
		// card that is not theirs to see
		const wrapper = await mountCard([announcement()], { public: true })

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('.announcements').exists()).toBe(false)
	})

	it('keeps quiet when the list cannot be read', async () => {
		const wrapper = await mountCard(new Error('network'))

		expect(wrapper.find('.announcements').exists()).toBe(false)
		expect(showError).not.toHaveBeenCalled()
	})

	it('renders the administrator\'s text as text, never as markup', async () => {
		const wrapper = await mountCard([announcement({
			// what a client would be sent if the escaping upstream ever failed
			content: '<p>Edit &lt;Directory&gt; in the config</p><img src="x" onerror="boom()"><script>boom()</script>',
		})])

		expect(wrapper.text()).toContain('Edit <Directory> in the config')
		expect(wrapper.find('.announcement__text img').exists()).toBe(false)
		expect(wrapper.find('.announcement__text script').exists()).toBe(false)
		expect(wrapper.html()).not.toContain('onerror')
	})

	it('says when an announcement runs out, and to the day for a whole-day window', async () => {
		const wrapper = await mountCard([
			announcement({
				starts_at: '2026-09-14T00:00:00.000Z',
				ends_at: '2026-09-22T00:00:00.000Z',
				all_day: true,
			}),
		])

		expect(wrapper.find('.announcement__until').text()).toContain('September 22, 2026')
		expect(wrapper.find('.announcement__until').text()).not.toContain('00:00')
	})

	describe('dismissing one', () => {
		it('marks it read for the account and leaves it on the screen', async () => {
			axios.post.mockResolvedValue({ data: {} })
			const wrapper = await mountCard()

			await wrapper.find('.announcement__dismiss').trigger('click')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(LIST + '/3/dismiss')
			// still there, so the sentence being read does not vanish mid-word
			expect(wrapper.text()).toContain('We are moving servers on Friday')
			expect(wrapper.find('.announcement--read').exists()).toBe(true)
			expect(wrapper.find('.announcement__dismiss').exists()).toBe(false)
		})

		it('says so when the server will not take it', async () => {
			axios.post.mockRejectedValue({ response: { data: { error: 'Record not found' } } })
			const wrapper = await mountCard()

			await wrapper.find('.announcement__dismiss').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith({ text: 'Could not dismiss that announcement', details: 'Record not found' })
			expect(wrapper.find('.announcement--read').exists()).toBe(false)
		})
	})

	describe('the ones already read', () => {
		it('are kept back until they are asked for', async () => {
			const wrapper = await mountCard([
				announcement({ id: '1', content: '<p>Last week</p>', read: true }),
				announcement({ id: '2', content: '<p>This week</p>' }),
			])

			expect(wrapper.text()).toContain('This week')
			expect(wrapper.text()).not.toContain('Last week')

			await wrapper.find('.announcements__earlier').trigger('click')

			expect(wrapper.text()).toContain('Last week')
		})

		it('are not offered when there are none', async () => {
			const wrapper = await mountCard()

			expect(wrapper.find('.announcements__earlier').exists()).toBe(false)
		})
	})

	describe('reactions', () => {
		it('puts the chosen emoji on the announcement, encoded for the path', async () => {
			axios.put.mockResolvedValue({ data: {} })
			const wrapper = await mountCard()

			await wrapper.vm.send(wrapper.vm.announcements[0], '👍', true)
			await flushPromises()

			expect(axios.put).toHaveBeenCalledWith(LIST + '/3/reactions/%F0%9F%91%8D')
			expect(reactions(wrapper)).toHaveLength(1)
			expect(reactions(wrapper)[0].text()).toContain('1')
		})

		it('asks the page for the one emoji picker rather than carrying one', async () => {
			const asked = vi.fn()
			eventBus.on(REACTION_PICK, asked)
			const wrapper = await mountCard()

			await wrapper.find('.reaction--add').trigger('click')

			expect(asked).toHaveBeenCalled()
			eventBus.off(REACTION_PICK, asked)
		})

		it('takes back one of its own, and drops the pill when it was the only one', async () => {
			axios.delete.mockResolvedValue({ data: {} })
			const wrapper = await mountCard([
				announcement({ reactions: [{ name: '🎉', count: 1, me: true }] }),
			])

			await reactions(wrapper)[0].trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(LIST + '/3/reactions/%F0%9F%8E%89')
			expect(reactions(wrapper)).toHaveLength(0)
		})

		it('leaves somebody else\'s count behind when it takes its own back', async () => {
			axios.delete.mockResolvedValue({ data: {} })
			const wrapper = await mountCard([
				announcement({ reactions: [{ name: '🎉', count: 3, me: true }] }),
			])

			await reactions(wrapper)[0].trigger('click')
			await flushPromises()

			expect(reactions(wrapper)[0].text()).toContain('2')
			expect(reactions(wrapper)[0].classes()).not.toContain('reaction--mine')
		})

		it('says what the server said when it refuses one, and does not draw it', async () => {
			axios.put.mockRejectedValue({
				response: { data: { error: 'an account may put at most 8 reactions on one announcement' } },
			})
			const wrapper = await mountCard()

			await wrapper.vm.send(wrapper.vm.announcements[0], '🐧', true)
			await flushPromises()

			expect(showError).toHaveBeenCalledWith({ text: 'Could not add that reaction', details: 'an account may put at most 8 reactions on one announcement' })
			expect(reactions(wrapper)).toHaveLength(0)
		})

		it('draws a custom emoji as the picture the entity names', async () => {
			const wrapper = await mountCard([
				announcement({
					reactions: [{ name: 'blobcat', count: 2, me: false, url: '/emoji/blobcat.png' }],
				}),
			])

			const picture = wrapper.find('.reaction__image')
			expect(picture.attributes('src')).toBe('/emoji/blobcat.png')
			expect(picture.attributes('alt')).toBe('blobcat')
		})

		it('keeps the bar most-reacted first, as the server orders it', async () => {
			axios.put.mockResolvedValue({ data: {} })
			const wrapper = await mountCard([
				announcement({
					reactions: [
						{ name: '🎉', count: 1, me: false },
						{ name: '👍', count: 3, me: false },
					],
				}),
			])

			// the new one arrives with a count of 1 and has to sort below the
			// three, not wherever it was pushed
			await wrapper.vm.send(wrapper.vm.announcements[0], '🚀', true)
			await flushPromises()

			expect(wrapper.vm.announcements[0].reactions.map((reaction) => reaction.count))
				.toEqual([3, 1, 1])
			expect(wrapper.vm.announcements[0].reactions[0].name).toBe('👍')
		})
	})
})
