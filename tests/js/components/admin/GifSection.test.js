/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GifSection from '../../../../src/components/admin/GifSection.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))

const HIGH_FIVE = { slug: 'high-five', title: 'high five', url: 'https://cloud.example/gif/high-five', media_type: 'image/gif' }

describe('the GIF library card', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: { gifs: [] } })
		post.mockReset().mockResolvedValue({ data: { gifs: [] } })
		del.mockReset().mockResolvedValue({ data: { gifs: [] } })
		showError.mockReset()
	})

	it('says so when the server has none of its own', async () => {
		const wrapper = mount(GifSection)
		await flushPromises()

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/moderation/gifs'))
		expect(wrapper.text()).toContain('This server has no GIFs of its own.')
	})

	it('draws each one with its name and what it is searched by', async () => {
		get.mockResolvedValue({ data: { gifs: [HIGH_FIVE] } })

		const wrapper = mount(GifSection)
		await flushPromises()

		expect(wrapper.text()).toContain('high-five')
		expect(wrapper.text()).toContain('high five')
		expect(wrapper.find('img.gif__preview').attributes('src')).toBe(HIGH_FIVE.url)
	})

	it('plays an MP4 as a muted loop rather than as a picture', async () => {
		get.mockResolvedValue({ data: { gifs: [{ ...HIGH_FIVE, media_type: 'video/mp4' }] } })

		const wrapper = mount(GifSection)
		await flushPromises()

		expect(wrapper.find('video.gif__preview').exists()).toBe(true)
		expect(wrapper.find('img.gif__preview').exists()).toBe(false)
	})

	it('cannot be submitted without a picture', async () => {
		const wrapper = mount(GifSection)
		await flushPromises()

		wrapper.vm.slug = 'high-five'
		await wrapper.vm.add()

		expect(post).not.toHaveBeenCalled()
	})

	it('sends the picture as a form upload and draws the library it answers with', async () => {
		post.mockResolvedValue({ data: { gifs: [HIGH_FIVE] } })
		const wrapper = mount(GifSection)
		await flushPromises()

		wrapper.vm.slug = ' high-five '
		wrapper.vm.title = 'high five'
		wrapper.vm.picture = new File(['GIF89a'], 'high-five.gif', { type: 'image/gif' })
		await wrapper.vm.add()
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/moderation/gifs'), expect.any(FormData))
		const body = post.mock.calls[0][1]
		expect(body.get('slug')).toBe('high-five')
		expect(body.get('title')).toBe('high five')
		expect(body.get('picture')).toBeInstanceOf(File)
		expect(wrapper.vm.slug).toBe('')
		expect(wrapper.vm.picture).toBeNull()
		expect(wrapper.text()).toContain('high-five')
	})

	/** The service refuses by name; the card repeats the reason. */
	it('repeats what the server would not take', async () => {
		post.mockRejectedValue({ response: { data: { error: 'the picture is larger than 8 MiB' } } })

		const wrapper = mount(GifSection)
		await flushPromises()
		wrapper.vm.slug = 'huge'
		wrapper.vm.picture = new File(['x'], 'huge.gif', { type: 'image/gif' })
		await wrapper.vm.add()

		expect(showError).toHaveBeenCalledWith({ text: 'Could not add that GIF', details: 'the picture is larger than 8 MiB' })
		expect(wrapper.vm.slug).toBe('huge')
	})

	it('removes one by its name', async () => {
		get.mockResolvedValue({ data: { gifs: [HIGH_FIVE] } })

		const wrapper = mount(GifSection)
		await flushPromises()
		await wrapper.find('.gif__item button').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(expect.stringContaining('/moderation/gifs'), { data: { slug: 'high-five' } })
		expect(wrapper.text()).toContain('This server has no GIFs of its own.')
	})
})
