/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import InterestsNotice from '../../../src/components/InterestsNotice.vue'
import { saveInterestSettings } from '../../../src/services/interests.js'
import { showError } from '../../../src/services/toast.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('../../../src/services/interests.js', () => ({ saveInterestSettings: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const NcButton = {
	name: 'NcButton',
	props: ['to', 'variant', 'disabled'],
	emits: ['click'],
	template: '<button class="button" :data-to="to ? JSON.stringify(to) : null" @click="$emit(\'click\')"><slot /></button>',
}

const ON = { enabled: true, learning: true, paused: false, noticeAcknowledged: false }

let settingsStore
let router

function mountNotice(route = { name: 'timeline', params: {} }) {
	const pinia = createPinia()
	setActivePinia(pinia)
	settingsStore = useSettingsStore()
	settingsStore.setServerData({ public: false, interests: { ...ON } })
	router = { push: vi.fn() }

	return mount(InterestsNotice, {
		global: { plugins: [pinia], mocks: { $route: route, $router: router }, stubs: { NcButton } },
	})
}

const button = (wrapper, label) => wrapper.findAll('.button').find((one) => one.text() === label)

/** @param {object} settings what the server answers with */
const answers = (settings) => saveInterestSettings.mockResolvedValue({ settings: { ...ON, languages: [], ...settings }, interests: [], thin: false })

describe('the first-use notice', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('says what is learned and where it stays', () => {
		const text = mountNotice().text()
		expect(text).toContain('Watching a substantial part of a video can count too')
		expect(text).toContain('This activity profile stays on this server and is not shared with other users.')
	})

	it('leads to the interests section of the settings', () => {
		expect(JSON.parse(button(mountNotice(), 'Manage').attributes('data-to'))).toEqual({ name: 'settings', hash: '#interests' })
	})

	it('is acknowledged with Got it, on the server and on the page', async () => {
		answers({ noticeAcknowledged: true })
		const wrapper = mountNotice()
		await button(wrapper, 'Got it').trigger('click')
		await flushPromises()

		expect(saveInterestSettings).toHaveBeenCalledWith({ noticeAcknowledged: true })
		expect(settingsStore.getServerData.interests).toEqual({ ...ON, noticeAcknowledged: true })
	})

	it('turns learning off, which stops the tracking and takes the feed away', async () => {
		answers({ learning: false, noticeAcknowledged: true })
		const wrapper = mountNotice({ name: 'timeline', params: { type: 'interests' } })
		await button(wrapper, 'Turn off').trigger('click')
		await flushPromises()

		expect(saveInterestSettings).toHaveBeenCalledWith({ learning: false, noticeAcknowledged: true })
		expect(settingsStore.getServerData.interests.learning).toBe(false)
		expect(router.push).toHaveBeenCalledWith({ name: 'timeline' })
	})

	it('stays, and says so, when the server refuses', async () => {
		saveInterestSettings.mockRejectedValue(new Error('500'))
		const wrapper = mountNotice()
		await button(wrapper, 'Got it').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(settingsStore.getServerData.interests.noticeAcknowledged).toBe(false)
	})
})
