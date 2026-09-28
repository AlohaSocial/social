/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import ServerSection from '../../../../src/components/admin/ServerSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const SERVER = '/index.php/apps/social/admin/server'

/**
 * @param {object} overrides what this instance has set
 * @return {object} the mounted card
 */
function mountServer(overrides = {}) {
	return mount(ServerSection, {
		props: {
			settings: {
				contact_email: ' admin@instance.example ',
				contact_account: 'admin',
				extended_description: 'a friendly place',
				max_size: 20,
				max_video_size: 4096,
				inbox_throttle: 0,
				secure_mode: true,
				publish_blocks: false,
				allow_self_signed: false,
				...overrides,
			},
		},
	})
}

describe('the server card', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: {} })
	})

	it('shows what the instance is set to', () => {
		const wrapper = mountServer()

		// the browser sanitises an email field's value; the trim on the way out
		// is what makes the payload the same either way
		expect(wrapper.find('input[type="email"]').element.value).toBe('admin@instance.example')
		expect(wrapper.vm.form.contactAccount).toBe('admin')
		expect(wrapper.find('textarea').element.value).toBe('a friendly place')
		expect(wrapper.vm.form.secureMode).toBe(true)
	})

	it('says what turning secure mode on costs, and that self-signed is for development', () => {
		const wrapper = mountServer()

		expect(wrapper.text()).toContain('Unsigned ActivityPub fetches are refused.')
		expect(wrapper.text()).toContain('For development only.')
	})

	it('says video conversion is on where ffmpeg is, and that it replaces the original', () => {
		const wrapper = mountServer({ video_transcode: true })

		expect(wrapper.vm.form.videoTranscode).toBe(true)
		expect(wrapper.text()).toContain('On by default when ffmpeg is installed on the server')
		expect(wrapper.text()).toContain('The converted file replaces the original, which is deleted')
		expect(wrapper.text()).toContain('An MP4 that is already H.264 is never touched.')
	})

	it('writes the whole card in one request, with the numbers as numbers', async () => {
		const wrapper = mountServer()

		await wrapper.vm.save()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(SERVER, {
			contactEmail: 'admin@instance.example',
			contactAccount: 'admin',
			extendedDescription: 'a friendly place',
			maxSize: 20,
			maxVideoSize: 4096,
			imageMaxEdge: 0,
			imageQuality: 85,
			videoTranscode: false,
			videoMaxHeight: 1080,
			videoLadder: false,
			videoLadderHeights: '360,720,1080',
			videoQuota: 0,
			nsfwPolicy: 'default',
			inboxThrottle: 0,
			secureMode: true,
			publishBlocks: false,
			allowSelfSigned: false,
		})
		expect(wrapper.text()).toContain('Saved')
	})

	it('repeats the field the endpoint would not take', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'contact_email is not an address' } } })
		const wrapper = mountServer()

		expect(await wrapper.vm.save()).toBe(false)
		await flushPromises()

		// the endpoint names the field; it is the one thing that tells an
		// administrator what to change
		expect(wrapper.text()).toContain('contact_email is not an address')
	})

	it('falls back to saying it could not save when the server said nothing', async () => {
		axios.post.mockRejectedValue(new Error('network'))
		const wrapper = mountServer()

		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.text()).toContain('Could not save the server settings')
	})
})
