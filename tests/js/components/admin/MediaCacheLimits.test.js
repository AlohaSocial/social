/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import MediaCacheLimits from '../../../../src/components/admin/MediaCacheLimits.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const ROUTE = '/index.php/apps/social/moderation/media-cache'
const LIMITS = { days: 30, sizeMb: 5120, lastPurge: null }

describe('the media cache limits', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: {} })
	})

	it('shows the days and the size, the size in GiB', () => {
		const wrapper = mount(MediaCacheLimits, { props: { mediaCache: LIMITS } })
		const inputs = wrapper.findAll('input[type="number"]')

		expect(inputs[0].element.value).toBe('30')
		expect(inputs[1].element.value).toBe('5')
	})

	it('says in plain words that a deleted copy comes back', () => {
		const wrapper = mount(MediaCacheLimits, { props: { mediaCache: LIMITS } })

		expect(wrapper.text()).toContain('fetched again when somebody looks at it')
		expect(wrapper.text()).toContain('Uploads from this server are never deleted')
	})

	it('sends both limits, the size in megabytes', async () => {
		const wrapper = mount(MediaCacheLimits, { props: { mediaCache: LIMITS } })
		const inputs = wrapper.findAll('input[type="number"]')

		await inputs[0].setValue('14')
		await inputs[1].setValue('2.5')
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ROUTE, { days: 14, sizeMb: 2560 })
	})

	it('sends 0 to turn a limit off', async () => {
		const wrapper = mount(MediaCacheLimits, { props: { mediaCache: LIMITS } })
		const inputs = wrapper.findAll('input[type="number"]')

		await inputs[0].setValue('0')
		await inputs[1].setValue('0')
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ROUTE, { days: 0, sizeMb: 0 })
	})

	it('sends nothing for a limit that is not a number', async () => {
		const wrapper = mount(MediaCacheLimits, { props: { mediaCache: LIMITS } })
		const inputs = wrapper.findAll('input[type="number"]')

		await inputs[0].setValue('1.5')
		await wrapper.vm.save()
		await inputs[0].setValue('')
		await wrapper.vm.save()
		await inputs[0].setValue('30')
		await inputs[1].setValue('-1')
		await wrapper.vm.save()
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('says what the last run deleted', () => {
		const wrapper = mount(MediaCacheLimits, {
			props: { mediaCache: { ...LIMITS, lastPurge: { purged: 1789480000, bytes: 3 * 1024 * 1024 * 1024 } } },
		})

		expect(wrapper.text()).toContain('3.0 GiB deleted')
	})

	it('says nothing about a last run before the first', () => {
		const wrapper = mount(MediaCacheLimits, { props: { mediaCache: LIMITS } })

		expect(wrapper.text()).not.toContain('Last run')
	})
})
