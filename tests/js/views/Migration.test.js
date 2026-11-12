/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Migration from '../../../src/views/Migration.vue'
import MigrationSettings from '../../../src/components/MigrationSettings.vue'

// the panel asks the server what it can export as soon as it is drawn
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: {} }), post: vi.fn(), delete: vi.fn() },
}))

describe('the Migration page', () => {
	/**
	 * A page of its own, reached from the account menu. It was the twelfth
	 * section of Settings, under eleven switches — and moving an account in or
	 * out of a server is a job somebody sits down to do rather than a setting
	 * changed in passing.
	 */
	it('is a page with its own heading, holding the migration tools', async () => {
		const wrapper = mount(Migration)
		await flushPromises()

		expect(wrapper.find('h1').text()).toBe('Migration')
		expect(wrapper.findComponent(MigrationSettings).exists()).toBe(true)
	})

	/** The lede says what the page is for, since no section header does now. */
	it('says what it is for', async () => {
		const wrapper = mount(Migration)
		await flushPromises()

		expect(wrapper.text()).toContain('Your account is yours')
	})
})
