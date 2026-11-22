/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import FeaturedTags from '../../../src/components/FeaturedTags.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
// the stub below still lets Vue Test Utils run the lazy import, which could
// finish loading @nextcloud/vue after vitest tore the environment down
vi.mock('../../../src/components/FeaturedTagsSettings.vue', () => ({ default: { name: 'FeaturedTagsSettings', template: '<div />' } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const tags = [
	{ id: '1', name: 'design', url: 'https://cloud.example/tags/design', statuses_count: 12, last_status_at: '2026-09-01' },
	{ id: '2', name: 'moss', url: 'https://cloud.example/tags/moss', statuses_count: 0, last_status_at: null },
]

/**
 * @param {Array} answered what the server sends
 * @param {boolean} editable whether this is the reader's own profile
 * @return {Promise<object>} the mounted component, once its request has settled
 */
async function mountTags(answered = tags, editable = false) {
	axios.get.mockResolvedValue({ data: answered })

	const wrapper = mount(FeaturedTags, {
		props: { accountId: '42', editable },
		global: { stubs: { RouterLink: RouterLinkStub, FeaturedTagsSettings: true, NcModal: { name: 'NcModal', template: '<div><slot /></div>' } } },
	})
	await flushPromises()

	return wrapper
}

describe('FeaturedTags', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('asks for the account it was given', async () => {
		await mountTags()

		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/accounts/42/featured_tags'))
	})

	it('shows one chip per tag', async () => {
		const wrapper = await mountTags()

		expect(wrapper.findAll('.featured-tags__tag')).toHaveLength(2)
		expect(wrapper.findAll('.featured-tags__name')[0].text()).toBe('#design')
	})

	it('links a tag into the timeline for it', async () => {
		const wrapper = await mountTags()

		expect(wrapper.findAllComponents(RouterLinkStub)[0].props('to'))
			.toEqual({ name: 'tags', params: { tag: 'design' } })
	})

	// the same hue it wears on the tag page it leads to
	it('gives each tag its own colour', async () => {
		const wrapper = await mountTags()

		expect(wrapper.find('.featured-tags__tag').attributes('style')).toContain('--tag-colour')
	})

	it('shows how many posts carry a tag', async () => {
		const wrapper = await mountTags()

		expect(wrapper.find('.featured-tags__count').text()).toBe('12')
	})

	// a count of nothing is noise next to the tag it belongs to
	it('leaves the count off a tag with no posts', async () => {
		const wrapper = await mountTags()
		const chips = wrapper.findAll('.featured-tags__tag')

		expect(chips[1].find('.featured-tags__count').exists()).toBe(false)
	})

	it('renders nothing when the account features none', async () => {
		const wrapper = await mountTags([])

		expect(wrapper.find('.featured-tags').exists()).toBe(false)
	})

	it('ignores an entry with no name rather than drawing a bare #', async () => {
		const wrapper = await mountTags([{ id: '3', name: '', statuses_count: 1 }])

		expect(wrapper.find('.featured-tags').exists()).toBe(false)
	})

	it('renders nothing when the request fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mount(FeaturedTags, {
			props: { accountId: '42' },
			global: { stubs: { RouterLink: RouterLinkStub, FeaturedTagsSettings: true, NcModal: { name: 'NcModal', template: '<div><slot /></div>' } } },
		})
		await flushPromises()

		expect(wrapper.find('.featured-tags').exists()).toBe(false)
	})

	it('asks for nothing until it knows whose profile it is on', async () => {
		mount(FeaturedTags, {
			props: { accountId: '' },
			global: { stubs: { RouterLink: RouterLinkStub, FeaturedTagsSettings: true, NcModal: { name: 'NcModal', template: '<div><slot /></div>' } } },
		})
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('offers nobody else a way to change them', async () => {
		const wrapper = await mountTags()

		expect(wrapper.find('.featured-tags__edit').exists()).toBe(false)
	})

	it('opens the editor on the reader\'s own profile, and reads the tags again once it closes', async () => {
		const wrapper = await mountTags(tags, true)

		await wrapper.find('.featured-tags__edit').trigger('click')
		const dialog = wrapper.findComponent({ name: 'SettingsDialog' })
		expect(dialog.props('title')).toBe('Featured hashtags')

		const reads = axios.get.mock.calls.length
		dialog.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SettingsDialog' }).exists()).toBe(false)
		expect(axios.get.mock.calls.length).toBe(reads + 1)
	})

	// featuring none is exactly when somebody needs telling that they could
	it('still offers the way in when the reader features nothing', async () => {
		const wrapper = await mountTags([], true)

		expect(wrapper.find('.featured-tags__edit').text()).toBe('Feature a hashtag')
	})

	it('asks again when the profile changes', async () => {
		const wrapper = await mountTags()

		await wrapper.setProps({ accountId: '43' })
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(expect.stringContaining('/accounts/43/featured_tags'))
	})
})
