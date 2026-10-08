/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EmojiSection from '../../../../src/components/admin/EmojiSection.vue'
import RulesSection from '../../../../src/components/admin/RulesSection.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))

describe('the custom emoji card', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: { emojis: [] } })
		post.mockReset().mockResolvedValue({ data: { emojis: [] } })
		del.mockReset().mockResolvedValue({ data: { emojis: [] } })
		showError.mockReset()
	})

	it('says so when the server has none of its own', async () => {
		const wrapper = mount(EmojiSection)
		await flushPromises()

		expect(wrapper.text()).toContain('This server has no emoji of its own.')
	})

	it('draws each one with its shortcode', async () => {
		get.mockResolvedValue({
			data: { emojis: [{ shortcode: 'blobcat', url: 'https://cloud.example/e/1.png', category: 'cats' }] },
		})

		const wrapper = mount(EmojiSection)
		await flushPromises()

		expect(wrapper.text()).toContain(':blobcat:')
		expect(wrapper.find('.emoji__image').attributes('src')).toBe('https://cloud.example/e/1.png')
	})

	/** Nothing is sent until there is both a name and a picture to send. */
	it('cannot be submitted without a picture', async () => {
		const wrapper = mount(EmojiSection)
		await flushPromises()

		await wrapper.vm.add()

		expect(post).not.toHaveBeenCalled()
	})

	it('sends the picture as a form upload', async () => {
		const wrapper = mount(EmojiSection)
		await flushPromises()

		wrapper.vm.shortcode = 'blobcat'
		wrapper.vm.picture = new File(['x'], 'blobcat.png', { type: 'image/png' })
		await wrapper.vm.add()

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/moderation/emojis'),
			expect.any(FormData),
		)
		expect(post.mock.calls[0][1].get('shortcode')).toBe('blobcat')
	})

	/** The service refuses a bad shortcode by name; the card repeats the reason. */
	it('repeats what the server would not take', async () => {
		post.mockRejectedValue({ response: { data: { error: 'a shortcode is 2 to 64 characters' } } })

		const wrapper = mount(EmojiSection)
		await flushPromises()
		wrapper.vm.shortcode = 'x'
		wrapper.vm.picture = new File(['x'], 'x.png', { type: 'image/png' })
		await wrapper.vm.add()

		expect(showError).toHaveBeenCalledWith('a shortcode is 2 to 64 characters')
	})

	it('removes one by its shortcode', async () => {
		get.mockResolvedValue({ data: { emojis: [{ shortcode: 'blobcat', url: '', category: '' }] } })

		const wrapper = mount(EmojiSection)
		await flushPromises()
		await wrapper.find('.emoji__item button').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(
			expect.stringContaining('/moderation/emojis'),
			{ data: { shortcode: 'blobcat' } },
		)
	})
})

describe('the rules card', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: { rules: '' } })
		post.mockReset().mockResolvedValue({ data: { rules: 'Be kind.' } })
	})

	it('shows what is stored, one rule per line', async () => {
		get.mockResolvedValue({ data: { rules: 'Be kind.\nNo harassment.' } })

		const wrapper = mount(RulesSection)
		await flushPromises()

		expect(wrapper.vm.rules).toBe('Be kind.\nNo harassment.')
	})

	it('writes them back and says so', async () => {
		const wrapper = mount(RulesSection)
		await flushPromises()
		wrapper.vm.rules = 'Be kind.'

		expect(await wrapper.vm.save()).toBe(true)

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/moderation/rules'),
			{ rules: 'Be kind.' },
		)
		expect(wrapper.vm.message).toBe('Saved')
	})
})
