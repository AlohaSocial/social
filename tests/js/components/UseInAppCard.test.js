/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import UseInAppCard from '../../../src/components/UseInAppCard.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * @param {object} [props] the card's props
 * @return {Promise<object>} the mounted card
 */
async function mountCard(props = {}) {
	const wrapper = mount(UseInAppCard, { props, global: { stubs: { RouterLink: RouterLinkStub } } })
	await flushPromises()

	return wrapper
}

const apps = (wrapper) => wrapper.findAll('.use-in-app__apps li').map((item) => item.text())
const codes = (wrapper) => wrapper.findAll('.use-in-app__code').map((code) => code.text())

describe('the Use it in an app card', () => {
	beforeEach(() => {
		get.mockReset()
		showError.mockReset()
	})

	it('gives the domain to a Mastodon app and names the apps that take it', async () => {
		const wrapper = await mountCard({ apps: { mastodon: 'cloud.example', bluesky: '' } })

		expect(get).not.toHaveBeenCalled()
		expect(wrapper.find('h3').text()).toBe('Use it in an app')
		expect(codes(wrapper)).toEqual(['cloud.example'])
		expect(apps(wrapper)).toEqual([
			'Aloha Social for iPhone, iPad and Mac',
			'Aloha Social for Android',
			'Elk and Phanpy, in a browser',
		])
		expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(false)
	})

	it('gives the hosting provider to a Bluesky app and points at the app passwords', async () => {
		const wrapper = await mountCard({ apps: { mastodon: '', bluesky: 'https://cloud.example' } })

		expect(codes(wrapper)).toEqual(['https://cloud.example'])
		expect(wrapper.text()).toContain('Mastodon apps cannot sign in to this server yet.')
		expect(apps(wrapper)).toEqual(['The Bluesky app'])
		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'settings', hash: '#bluesky' })
	})

	it('says so when no app can sign in, rather than offering an address that fails', async () => {
		const wrapper = await mountCard({ apps: { mastodon: '', bluesky: '' } })

		expect(codes(wrapper)).toEqual([])
		expect(wrapper.text()).toContain('Apps cannot sign in to this server yet.')
		expect(wrapper.find('.use-in-app__apps').exists()).toBe(false)
	})

	it('asks the About route for the addresses when it is not handed them', async () => {
		get.mockResolvedValue({ data: { name: 'Cloud', apps: { mastodon: 'cloud.example', bluesky: 'https://cloud.example' } } })

		const wrapper = await mountCard()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/about/info')
		expect(codes(wrapper)).toEqual(['cloud.example', 'https://cloud.example'])
		expect(apps(wrapper)).toContain('The Bluesky app')
	})

	it('offers no address when the addresses cannot be loaded', async () => {
		get.mockRejectedValue(new Error('offline'))

		const wrapper = await mountCard()

		expect(codes(wrapper)).toEqual([])
		expect(wrapper.text()).toContain('Apps cannot sign in to this server yet.')
	})

	it('leaves the heading to a page that has its own', async () => {
		const wrapper = await mountCard({ apps: { mastodon: 'cloud.example', bluesky: '' }, titled: false })

		expect(wrapper.find('h3').exists()).toBe(false)
	})

	it('copies an address', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = await mountCard({ apps: { mastodon: 'cloud.example', bluesky: '' } })

		await wrapper.find('.use-in-app__address button').trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith('cloud.example')
		expect(wrapper.find('.use-in-app__address button').attributes('aria-label')).toBe('Copied')
	})
})
