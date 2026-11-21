/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import GalleryMedia from '../../../src/components/GalleryMedia.vue'
import MediaAttachment from '../../../src/components/MediaAttachment.vue'

function photo(overrides = {}) {
	return {
		id: '7',
		type: 'image',
		url: 'https://cloud.example.org/media/original.jpg',
		preview_url: 'https://cloud.example.org/media/small.jpg',
		description: 'A cat asleep on a sofa',
		blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
		meta: { original: { width: 1600, height: 1200 }, small: { width: 4, height: 3 } },
		...overrides,
	}
}

/**
 * @param {import('@vue/test-utils').DOMWrapper<HTMLElement>} frame
 * @return {number} the frame's aspect ratio, however the DOM writes it
 */
function aspectRatio(frame) {
	const [width, height = '1'] = frame.element.style.aspectRatio.split('/')
	return Number(width) / Number(height)
}

function mountMedia(props = {}) {
	return mount(GalleryMedia, {
		props: { attachment: photo(), ...props },
	})
}

describe('GalleryMedia', () => {
	let getContext

	beforeEach(() => {
		getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
			createImageData: (width, height) => ({ data: new Uint8ClampedArray(width * height * 4) }),
			putImageData: () => {},
		})
	})

	afterEach(() => {
		getContext.mockRestore()
	})

	describe('reserving the space', () => {
		it('gives the frame the shape of the picture, so nothing reflows on load', () => {
			const frame = mountMedia().find('.photo')

			expect(aspectRatio(frame)).toBeCloseTo(1600 / 1200, 4)
		})

		it('falls back to the small copy when the original reported no size', () => {
			const attachment = photo({ meta: { small: { width: 400, height: 400 } } })

			expect(mountMedia({ attachment }).find('.photo').attributes('style'))
				.toContain('aspect-ratio: 1')
		})

		/**
		 * The frame used to be clamped between 3:4 and 16:9, and anything
		 * outside that was reshaped: a 9:16 short was given a 3:4 box and had
		 * its top and bottom cut off by `cover`. A timeline is kept usable by
		 * the `max-height` on the frame instead, which makes a tall picture
		 * smaller without changing what shape it is.
		 */
		it('gives a panorama and a tall portrait their own shape, extreme or not', () => {
			const panorama = mountMedia({ attachment: photo({ meta: { original: { width: 3000, height: 500 } } }) })
			const short = mountMedia({ attachment: photo({ meta: { original: { width: 1080, height: 1920 } } }) })

			expect(panorama.find('.photo').attributes('style')).toContain(`aspect-ratio: ${3000 / 500}`)
			expect(short.find('.photo').attributes('style')).toContain(`aspect-ratio: ${1080 / 1920}`)
		})

		it('reserves a default shape for media that carries no dimensions at all', () => {
			const attachment = photo({ meta: null })

			expect(aspectRatio(mountMedia({ attachment }).find('.photo'))).toBeCloseTo(4 / 3, 4)
		})

		it('takes the shape the caller asks for over the picture\'s own', () => {
			expect(mountMedia({ ratio: 1 }).find('.photo').attributes('style')).toContain('aspect-ratio: 1')
		})

		it('reserves no shape for audio, which has no picture', () => {
			const attachment = photo({ type: 'audio' })

			expect(mountMedia({ attachment }).find('.photo').attributes('style') ?? '')
				.not.toContain('aspect-ratio')
		})
	})

	describe('the blurhash placeholder', () => {
		it('hands the attachment to MediaAttachment, blurhash and all', () => {
			const wrapper = mountMedia()

			expect(wrapper.findComponent(MediaAttachment).props('attachment').blurhash)
				.toBe('LEHV6nWB2yk8pyo0adR*.7kCMdnj')
			expect(wrapper.find('canvas.attachment__blurhash').exists()).toBe(true)
			expect(wrapper.find('canvas.attachment__blurhash').classes())
				.not.toContain('attachment__blurhash--hidden')
		})
	})

	describe('alt text', () => {
		it('sets the description as the alt attribute of the image', () => {
			expect(mountMedia().find('img.attachment__preview').attributes('alt'))
				.toBe('A cat asleep on a sofa')
		})

		it('reveals the description behind an ALT badge', async () => {
			const wrapper = mountMedia()
			const badge = wrapper.find('.alt-badge')

			expect(badge.text()).toBe('ALT')
			expect(badge.attributes('aria-expanded')).toBe('false')
			expect(wrapper.find('.alt-description').attributes('style')).toContain('display: none')

			await badge.trigger('click')

			expect(badge.attributes('aria-expanded')).toBe('true')
			expect(wrapper.find('.alt-description').attributes('style') ?? '').not.toContain('display: none')
			expect(wrapper.find('.alt-description').text()).toBe('A cat asleep on a sofa')
		})

		it('names the panel the badge controls', () => {
			const wrapper = mountMedia()

			expect(wrapper.find('.alt-badge').attributes('aria-controls'))
				.toBe(wrapper.find('.alt-description').attributes('id'))
		})

		it('offers no badge for a picture whose author wrote no description', () => {
			expect(mountMedia({ attachment: photo({ description: null }) }).find('.alt-badge').exists()).toBe(false)
			expect(mountMedia({ attachment: photo({ description: '  ' }) }).find('.alt-badge').exists()).toBe(false)
		})

		it('closes the description again when the frame is given another picture', async () => {
			const wrapper = mountMedia()
			await wrapper.find('.alt-badge').trigger('click')

			await wrapper.setProps({ attachment: photo({ id: '8', description: 'A dog' }) })

			expect(wrapper.find('.alt-badge').attributes('aria-expanded')).toBe('false')
		})
	})

	describe('opening the viewer', () => {
		it('presses through a real button that says what it will show', async () => {
			const wrapper = mountMedia()
			const button = wrapper.find('button.photo__open')

			expect(button.attributes('type')).toBe('button')
			expect(button.attributes('aria-label')).toBe('Open attachment: A cat asleep on a sofa')

			await button.trigger('click')

			expect(wrapper.emitted('open')).toHaveLength(1)
		})

		it('names a picture without a description by its place in the post', () => {
			const wrapper = mountMedia({ attachment: photo({ description: null }), index: 2, total: 8 })

			expect(wrapper.find('button.photo__open').attributes('aria-label')).toBe('Open attachment 3 of 8')
		})

		it('never nests video inside a button, which no browser may focus', () => {
			const wrapper = mountMedia({ attachment: photo({ type: 'video' }) })

			expect(wrapper.find('button.photo__open').exists()).toBe(false)
			expect(wrapper.findComponent(MediaAttachment).exists()).toBe(true)
		})
	})
})
