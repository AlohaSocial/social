/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import MuteDialog, { MUTE_DURATIONS } from '../../../src/components/MuteDialog.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const bob = { id: '22', acct: 'bob@remote.example', username: 'bob' }

// NcDialog teleports its content and puts its buttons in a prop; this one
// renders both where they can be read
const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	template: '<div v-if="open" class="dialog"><h2>{{ name }}</h2><slot /></div>',
}

function mountDialog() {
	const pinia = createPinia()
	setActivePinia(pinia)
	const wrapper = mount(MuteDialog, {
		props: { open: true, account: bob },
		global: { plugins: [pinia], stubs: { NcDialog: NcDialogStub } },
	})

	return { wrapper, store: useAccountStore() }
}

/** Presses the dialog's own Mute button, which is a prop rather than markup. */
async function confirm(wrapper) {
	const button = wrapper.findComponent(NcDialogStub).props('buttons').find((b) => b.label === 'Mute')
	await button.callback()
	await flushPromises()
}

const durationRadios = (wrapper) => wrapper.findAll('.mute-dialog__duration input')

describe('MuteDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: { id: bob.id, muting: true } })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('names who is being muted', () => {
		const { wrapper } = mountDialog()

		expect(wrapper.find('h2').text()).toBe('Mute @bob@remote.example?')
	})

	it('offers indefinitely and four durations', () => {
		const { wrapper } = mountDialog()

		expect(durationRadios(wrapper)).toHaveLength(5)
		expect(MUTE_DURATIONS.map(({ seconds }) => seconds)).toEqual([0, 3600, 86400, 604800, 2592000])
	})

	it('mutes for good and hides the notifications unless told otherwise', async () => {
		const { wrapper } = mountDialog()

		await confirm(wrapper)

		expect(axios.post).toHaveBeenCalledWith(
			`${API}/accounts/22/mute`,
			{ notifications: true, duration: 0 },
		)
	})

	it('sends the duration that was chosen', async () => {
		const { wrapper } = mountDialog()

		await durationRadios(wrapper)[1].setValue()
		await confirm(wrapper)

		expect(axios.post).toHaveBeenCalledWith(
			`${API}/accounts/22/mute`,
			{ notifications: true, duration: 3600 },
		)
	})

	it('leaves the notifications alone when the switch is turned off', async () => {
		const { wrapper } = mountDialog()

		await wrapper.find('.mute-dialog__notifications input').setValue(false)
		await confirm(wrapper)

		expect(axios.post).toHaveBeenCalledWith(
			`${API}/accounts/22/mute`,
			{ notifications: false, duration: 0 },
		)
	})

	it('closes and says so once the mute has been taken', async () => {
		const { wrapper } = mountDialog()

		await confirm(wrapper)

		expect(wrapper.emitted('muted')).toHaveLength(1)
		expect(wrapper.emitted('update:open').at(-1)).toEqual([false])
	})

	it('stays open when the server refused, so the answer is not lost', async () => {
		axios.post.mockRejectedValue(new Error('nope'))
		const { wrapper } = mountDialog()

		await confirm(wrapper)

		expect(wrapper.emitted('muted')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	/** The last answer was about somebody else. */
	it('asks afresh each time it opens', async () => {
		const { wrapper } = mountDialog()
		await wrapper.find('.mute-dialog__notifications input').setValue(false)
		await durationRadios(wrapper)[2].setValue()

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(wrapper.find('.mute-dialog__notifications input').element.checked).toBe(true)
		expect(durationRadios(wrapper)[0].element.checked).toBe(true)
	})
})

describe('muting from a post', () => {
	const post = { id: '1789344497747043682', account: bob, tags: [{ name: 'film' }] }

	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: { id: bob.id, muting: true } })
	})

	function mountFromPost(interests) {
		const pinia = createPinia()
		setActivePinia(pinia)
		useSettingsStore().setServerData({ public: false, interests })

		return mount(MuteDialog, {
			props: { open: true, account: bob, status: post, timelineType: 'federated' },
			global: { plugins: [pinia], stubs: { NcDialog: NcDialogStub } },
		})
	}

	it('tells For you, under the timeline the post was in', async () => {
		await confirm(mountFromPost({ enabled: true, learning: true, paused: false }))

		expect(axios.post).toHaveBeenCalledWith(`${API}/interests/signals`, {
			events: [{ status_id: post.id, kind: 'mute', context: 'federated' }],
		})
	})

	it('tells it nothing while learning is paused', async () => {
		await confirm(mountFromPost({ enabled: true, learning: true, paused: true }))

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(`${API}/accounts/22/mute`, expect.anything())
	})
})
