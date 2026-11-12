/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import AboutSection from '../../../../src/components/admin/AboutSection.vue'
import { showError } from '../../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const ABOUT = '/index.php/apps/social/admin/about'
const THEMING = '/index.php/settings/admin/theming'

const SETTINGS = {
	contact_email: ' admin@instance.example ',
	contact_account: 'admin',
	extended_description: 'a friendly place',
	max_size: 20,
}

/**
 * @param {object} props the card's props
 * @return {Promise<object>} the mounted card
 */
async function mountCard(props = {}) {
	const wrapper = mount(AboutSection, {
		props: { settings: SETTINGS, about: { name: 'Cloud', privacy_policy_url: 'https://cloud.example/privacy', legal_notice_url: '' }, ...props },
	})
	await flushPromises()

	return wrapper
}

describe('the About this server card', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: { rules: 'Be kind' } })
		axios.post.mockResolvedValue({ data: {} })
	})

	it('holds what the About page shows: name, description, contact, rules and the legal links', async () => {
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Cloud')
		expect(wrapper.find('textarea').element.value).toBe('a friendly place')
		// the browser sanitises an email field's value; the trim on the way out
		// is what makes the payload the same either way
		expect(wrapper.find('input[type="email"]').element.value).toBe('admin@instance.example')
		expect(wrapper.vm.form.contactAccount).toBe('admin')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/moderation/rules')
		expect(wrapper.findComponent({ name: 'RulesSection' }).exists()).toBe(true)
		expect(wrapper.find('a[href="https://cloud.example/privacy"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Legal notice: Not set')
	})

	it('links to Theming for what it cannot edit', async () => {
		const wrapper = await mountCard()

		const theming = wrapper.findAll(`a[href="${THEMING}"]`).map((link) => link.text())
		expect(theming).toEqual(['Change it in Theming', 'Set the links in Theming'])
	})

	it('writes the description and the contact to their own route, and nothing else', async () => {
		const wrapper = await mountCard()

		expect(await wrapper.vm.save()).toBe(true)

		expect(axios.post).toHaveBeenCalledWith(ABOUT, {
			extendedDescription: 'a friendly place',
			contactEmail: 'admin@instance.example',
			contactAccount: 'admin',
		})
		expect(wrapper.text()).toContain('Saved')
	})

	it('names the refused field by its label', async () => {
		axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'contact_email is not an email address', code: 'setting-not-email', field: 'contact_email' } } })
		const wrapper = await mountCard()

		expect(await wrapper.vm.save()).toBe(false)
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.text()).toContain('Contact address')
	})

	it('says what a public server lacks, one sentence each', async () => {
		const wrapper = await mountCard({ missing: ['contact', 'privacy_policy'] })

		expect(wrapper.findAll('.about-card__missing p').map((line) => line.text())).toEqual([
			'No contact address or account is set.',
			'No privacy policy link is set.',
		])
	})

	it('shows a delegate the rules and the rest read-only', async () => {
		const wrapper = await mountCard({ settings: null })

		// the one text area left is the rules'
		expect(wrapper.findAll('textarea')).toHaveLength(1)
		expect(wrapper.find('.rules__field textarea').exists()).toBe(true)
		expect(wrapper.find('input[type="email"]').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'RulesSection' }).exists()).toBe(true)
		expect(wrapper.text()).toContain('Cloud')
	})
})
