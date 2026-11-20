/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('../../../src/services/interests.js', () => ({ fetchInterests: vi.fn() }))

import FeedHelp from '../../../src/components/FeedHelp.vue'
import { fetchInterests } from '../../../src/services/interests.js'

const NcPopover = {
	name: 'NcPopover',
	emits: ['show'],
	template: '<div class="popover"><slot name="trigger" /><slot /></div>',
}

/**
 * @param {object} props what the help is told
 * @return {object} the mounted help
 */
function mountHelp(props) {
	return mount(FeedHelp, {
		props,
		global: { stubs: { NcPopover, RouterLink: { props: ['to'], template: '<a class="router-link"><slot /></a>' } } },
	})
}

describe('FeedHelp', () => {
	it.each([
		['home', 'Posts from the people and hashtags you follow, newest first.'],
		['timeline', 'Public posts from everybody on this server, newest first.'],
		['federated', 'Public posts from other servers that reach this one, newest first.'],
	])('says in one sentence what %s shows', (scope, sentence) => {
		const wrapper = mountHelp({ scope })

		expect(wrapper.find('.feed-help__body').text()).toBe(sentence)
		expect(wrapper.find('button').attributes('aria-label')).toBe('About this feed')
	})

	it('says what For you learns from, and links to its settings', () => {
		const text = mountHelp({ scope: 'interests' }).find('.feed-help__body').text()

		expect(text).toContain('based on the hashtags of posts you read, like and save')
		expect(text).toContain('For you settings')
		expect(text).not.toContain('still learning')
	})

	it('says when For you is still learning, asking only once', async () => {
		fetchInterests.mockResolvedValue({ thin: true })
		const wrapper = mountHelp({ scope: 'interests' })

		wrapper.findComponent(NcPopover).vm.$emit('show')
		wrapper.findComponent(NcPopover).vm.$emit('show')
		await flushPromises()

		expect(fetchInterests).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('It is still learning')
		expect(mountHelp({ scope: 'interests', underfilled: true }).text()).toContain('It is still learning')
	})

	it('draws nothing for a feed it has nothing to say about', () => {
		expect(mountHelp({ scope: 'photos' }).html()).toBe('<!--v-if-->')
	})
})
