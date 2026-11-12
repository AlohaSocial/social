/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import About from '../../../src/views/About.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const COMPLETE = {
	name: 'Cloud',
	description: 'A server for the club.',
	rules: [{ id: '1', text: 'Be kind' }, { id: '2', text: 'No spam' }],
	contact: { email: 'mods@cloud.example', account: { username: 'carol', display_name: 'Carol' } },
	privacy_policy_url: 'https://cloud.example/privacy',
	legal_notice_url: 'https://cloud.example/imprint',
	apps: { mastodon: 'cloud.example', bluesky: '' },
}

/**
 * @param {object} data what the server answers
 * @return {Promise<object>} the mounted page
 */
async function mountAbout(data) {
	axios.get.mockResolvedValue({ data })
	const wrapper = mount(About, { global: { stubs: { RouterLink: RouterLinkStub } } })
	await flushPromises()

	return wrapper
}

describe('the About page', () => {
	beforeEach(() => vi.clearAllMocks())

	it('shows the rules, the contact and the legal links', async () => {
		const wrapper = await mountAbout(COMPLETE)

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/about/info')
		expect(wrapper.find('h1').text()).toBe('About this server')
		expect(wrapper.findAll('.about__rules li').map((rule) => rule.text())).toEqual(['Be kind', 'No spam'])
		expect(wrapper.find('a[href="mailto:mods@cloud.example"]').exists()).toBe(true)
		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'carol' } })
		const legal = wrapper.findAll('section').at(-1)
		expect(legal.find('h2').text()).toBe('Legal')
		expect(legal.findAll('a').map((link) => [link.text(), link.attributes('href')])).toEqual([
			['Privacy policy', 'https://cloud.example/privacy'],
			['Legal notice', 'https://cloud.example/imprint'],
		])
		// each section is named by its own heading
		for (const section of wrapper.findAll('section')) {
			expect(wrapper.find('#' + section.attributes('aria-labelledby')).exists()).toBe(true)
		}
	})

	it('says what is missing rather than leaving sections empty', async () => {
		const wrapper = await mountAbout({ name: 'Cloud', description: '', rules: [], contact: { email: '', account: null }, privacy_policy_url: '', legal_notice_url: '' })

		expect(wrapper.text()).toContain('The administrators have not written any rules yet.')
		expect(wrapper.text()).toContain('No contact has been given.')
		expect(wrapper.text()).not.toContain('Legal')
	})

	it('says how to use the server from an app, with the addresses it was handed', async () => {
		const wrapper = await mountAbout(COMPLETE)

		const section = wrapper.find('section[aria-labelledby="about-apps"]')
		expect(section.find('h2').text()).toBe('Use it in an app')
		expect(section.find('.use-in-app__code').text()).toBe('cloud.example')
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('says so when the page cannot be loaded', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mount(About, { global: { stubs: { RouterLink: RouterLinkStub } } })
		await flushPromises()

		expect(wrapper.text()).toContain('Could not load what this server says about itself.')
	})
})
