/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PreviewGridItem from '../../../src/components/Composer/PreviewGridItem.vue'
import MediaAttachment from '../../../src/components/MediaAttachment.vue'

const media = {
	id: 'media-1',
	type: 'image',
	url: 'https://cloud.example.org/media/original.png',
	preview_url: 'https://cloud.example.org/media/small.png',
	description: 'Screenshot',
	blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
	meta: { small: { width: 4, height: 3 } },
}

const file = new File(['x'], 'screenshot.png', { type: 'image/png' })
const sound = new File(['x'], 'talk.ogg', { type: 'audio/ogg' })

function mountItem(preview) {
	return mount(PreviewGridItem, {
		props: { preview, randomKey: 'blob:preview-1' },
	})
}

const pad = (wrapper) => wrapper.find('.preview-item__focus-pad')

/**
 * A picture showing its focal point editor, 400 by 200 on screen: jsdom lays
 * nothing out, so the pad is told how big it is.
 *
 * @param {object} [focus] the point the attachment already carries
 */
async function focusing(focus) {
	const wrapper = mountItem({ file, data: media, ...(focus ? { focus } : {}) })
	await wrapper.find('.preview-item__focus-toggle').trigger('click')
	pad(wrapper).element.getBoundingClientRect = () => ({ left: 0, top: 0, width: 400, height: 200 })

	return wrapper
}

/**
 * A pointer event on the pad. jsdom has no PointerEvent and refuses to let
 * `trigger` write `clientX` onto a MouseEvent, so the event is built with the
 * position in it and dispatched by hand.
 *
 * @param {object} wrapper the mounted item
 * @param {string} type the event to send
 * @param {number} [x] pixels from the left edge of the picture
 * @param {number} [y] pixels from its top edge
 */
function pointer(wrapper, type, x = 0, y = 0) {
	const event = new MouseEvent(type, { bubbles: true, button: 0, clientX: x, clientY: y })
	pad(wrapper).element.dispatchEvent(event)

	return nextTick()
}

/**
 * @param {object} wrapper the mounted item
 * @param {number} x pixels from the left edge of the picture
 * @param {number} y pixels from its top edge
 */
function press(wrapper, x, y) {
	return pointer(wrapper, 'pointerdown', x, y)
}

describe('PreviewGridItem', () => {
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

	it('shows the uploaded media through MediaAttachment', () => {
		const wrapper = mountItem({ file, data: media })
		expect(wrapper.findComponent(MediaAttachment).props('attachment')).toEqual({ ...media, preview_url: media.url })
		expect(wrapper.find('img.attachment__preview').attributes('src')).toBe(media.url)
	})

	it('shows a pending state while the upload has not finished', () => {
		const wrapper = mountItem({ file, data: null })
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.loading-icon').exists()).toBe(true)
	})

	it('offers a delete action', () => {
		const wrapper = mountItem({ file, data: media })
		expect(wrapper.find('.preview-item__actions button').text()).toBe('Delete')
	})

	it('keeps the poster for animated video attachments instead of using video bytes as an image', () => {
		const wrapper = mountItem({ data: { ...media, type: 'gifv', url: 'https://cloud.example/animation.mp4' } })
		expect(wrapper.findComponent(MediaAttachment).props('attachment').preview_url).toBe(media.preview_url)
	})

	it('uses the full image and sizes the frame to decoded portrait dimensions', async () => {
		const wrapper = mountItem({ file, data: media })
		expect(wrapper.find('img').attributes('src')).toBe(media.url)
		const image = wrapper.find('img').element
		Object.defineProperties(image, { naturalWidth: { value: 600 }, naturalHeight: { value: 1200 } })
		await wrapper.find('img').trigger('load')
		expect(wrapper.find('.preview-item').attributes('style')).toContain('--preview-ratio: 0.5')
	})

	it('emits delete with its key when the delete action is used', async () => {
		const wrapper = mountItem({ file, data: media })
		await wrapper.find('.preview-item__actions button').trigger('click')
		expect(wrapper.emitted('delete')).toEqual([['blob:preview-1']])
	})

	it('asks for the description to be saved once the field is left', async () => {
		const wrapper = mountItem({ file, data: media })
		const field = wrapper.find('.preview-item__description')
		field.element.value = 'a cat asleep on a keyboard'

		await field.trigger('input')
		// typing is not saving: a request per letter is what the composer
		// keeps the local copy to avoid
		expect(wrapper.emitted('commitDescription')).toBeUndefined()

		await field.trigger('change')

		expect(wrapper.emitted('describe')).toEqual([[{ key: 'blob:preview-1', description: 'a cat asleep on a keyboard' }]])
		expect(wrapper.emitted('commitDescription'))
			.toEqual([[{ key: 'blob:preview-1', description: 'a cat asleep on a keyboard' }]])
	})

	it('marks an attachment the server refused instead of spinning forever', () => {
		const wrapper = mountItem({ file, data: null, failed: true })

		expect(wrapper.find('.preview-item__failed').text()).toBe('Could not be attached')
		// MediaAttachment shows a spinner for a null attachment, and that one
		// would never stop
		expect(wrapper.findComponent(MediaAttachment).exists()).toBe(false)
		expect(wrapper.find('.loading-icon').exists()).toBe(false)
		// there is nothing to describe, and no id to describe it against
		expect(wrapper.find('.preview-item__description').exists()).toBe(false)
		expect(wrapper.find('.preview-item__missing').exists()).toBe(false)
	})

	it('can still be removed once it failed', async () => {
		const wrapper = mountItem({ file, data: null, failed: true })
		await wrapper.find('.preview-item__actions button').trigger('click')
		expect(wrapper.emitted('delete')).toEqual([['blob:preview-1']])
	})

	it('can be removed while the upload is still pending', async () => {
		const wrapper = mountItem({ file, data: null })
		await wrapper.find('.preview-item__actions button').trigger('click')
		expect(wrapper.emitted('delete')).toEqual([['blob:preview-1']])
	})
	// the focal point

	/**
	 * The point is saved against the upload, so there is nothing to set it
	 * against until the server has answered — and a sound file has no crop.
	 */
	it('offers a focal point for an uploaded picture and nothing else', () => {
		expect(mountItem({ file, data: media }).find('.preview-item__focus-toggle').exists()).toBe(true)
		expect(mountItem({ file, data: null }).find('.preview-item__focus-toggle').exists()).toBe(false)
		expect(mountItem({ file, data: media, failed: true }).find('.preview-item__focus-toggle').exists()).toBe(false)
		expect(mountItem({ file: sound, data: { ...media, type: 'audio' } })
			.find('.preview-item__focus-toggle').exists()).toBe(false)
	})

	it('shows the picture as the control once the focal point is being set', async () => {
		const wrapper = mountItem({ file, data: media })
		expect(wrapper.find('.preview-item__focus-pad').exists()).toBe(false)

		await wrapper.find('.preview-item__focus-toggle').trigger('click')

		expect(wrapper.find('.preview-item__focus-pad').exists()).toBe(true)
		// with no point yet the crosshair sits where a crop would cut anyway
		expect(wrapper.find('.preview-item__crosshair').attributes('style'))
			.toContain('inset-inline-start: 50%')
	})

	it('reads a press on the picture as a point in Mastodon\'s coordinates', async () => {
		const wrapper = await focusing()

		await press(wrapper, 300, 50)

		expect(wrapper.emitted('focus')).toEqual([[{ key: 'blob:preview-1', focus: { x: 0.5, y: 0.5 } }]])
		// a press is not a save; the release is
		expect(wrapper.emitted('commitFocus')).toBeUndefined()
	})

	it('follows the pointer while it is down and saves once on release', async () => {
		const wrapper = await focusing()

		await press(wrapper, 200, 100)
		await pointer(wrapper, 'pointermove', 100, 150)
		await pointer(wrapper, 'pointerup', 100, 150)

		expect(wrapper.emitted('focus').map(([{ focus }]) => focus))
			.toEqual([{ x: 0, y: 0 }, { x: -0.5, y: -0.5 }, { x: -0.5, y: -0.5 }])
		expect(wrapper.emitted('commitFocus')).toHaveLength(1)
	})

	/** A pointer crossing the picture on its way somewhere else moves nothing. */
	it('ignores a pointer that is not dragging', async () => {
		const wrapper = await focusing()

		await pointer(wrapper, 'pointermove', 100, 150)
		await pointer(wrapper, 'pointerup', 100, 150)

		expect(wrapper.emitted('focus')).toBeUndefined()
		expect(wrapper.emitted('commitFocus')).toBeUndefined()
	})

	it('leaves the point where it was when a drag is cancelled', async () => {
		const wrapper = await focusing({ x: 0.5, y: 0.5 })

		await press(wrapper, 0, 0)
		await pointer(wrapper, 'pointercancel')

		// the point the parent knows is the last one it was told about, and
		// the cancel does not read a position of its own
		expect(wrapper.emitted('focus').map(([{ focus }]) => focus)).toEqual([{ x: -1, y: 1 }])
		expect(wrapper.emitted('commitFocus')).toHaveLength(1)
	})

	/** Nobody with a keyboard alone can press a picture. */
	it('moves the point with the arrow keys, up meaning up', async () => {
		const wrapper = await focusing({ x: 0, y: 0 })

		await pad(wrapper).trigger('keydown', { key: 'ArrowUp' })
		await pad(wrapper).trigger('keydown', { key: 'ArrowLeft' })

		expect(wrapper.emitted('focus').map(([{ focus }]) => focus))
			.toEqual([{ x: 0, y: 0.05 }, { x: -0.05, y: 0 }])
		// a key press is a decision in itself: there is no release to wait for
		expect(wrapper.emitted('commitFocus')).toHaveLength(2)
	})

	it('leaves other keys to the page', async () => {
		const wrapper = await focusing()

		await pad(wrapper).trigger('keydown', { key: 'Tab' })

		expect(wrapper.emitted('focus')).toBeUndefined()
	})

	it('draws the crosshair where the point is', async () => {
		const wrapper = await focusing({ x: -1, y: 1 })
		const style = wrapper.find('.preview-item__crosshair').attributes('style')

		expect(style).toContain('inset-inline-start: 0%')
		expect(style).toContain('inset-block-start: 0%')
	})

	/** Once the editor is closed the badge is all that says a point is set. */
	it('badges a picture that has a focal point', async () => {
		const wrapper = mountItem({ file, data: media, focus: { x: 0.2, y: -0.4 } })
		expect(wrapper.find('.preview-item__focal').text()).toBe('Focal point')
		expect(wrapper.find('.preview-item__crosshair').exists()).toBe(true)

		await wrapper.find('.preview-item__focus-toggle').trigger('click')

		expect(wrapper.find('.preview-item__focal').exists()).toBe(false)
	})

	it('does not badge a picture that has none', () => {
		expect(mountItem({ file, data: media }).find('.preview-item__focal').exists()).toBe(false)
		expect(mountItem({ file, data: media }).find('.preview-item__crosshair').exists()).toBe(false)
	})

	describe('the filter swatches', () => {
		const swatch = (wrapper) => wrapper.find('.filter-picker__image')

		it('draw the picture a file was keyed by', () => {
			expect(swatch(mountItem({ file, data: media, failed: false })).attributes('src')).toBe('blob:preview-1')
		})

		it('draw the file fetched for a picture that came without one, and the server\'s preview until then', () => {
			const fromFiles = (preview) => mount(PreviewGridItem, { props: { preview, randomKey: 'nextcloud:1:/cat.png' } })

			expect(swatch(fromFiles({ file: null, data: media, failed: false })).attributes('src')).toBe(media.preview_url)
			expect(swatch(fromFiles({ file, data: media, failed: false, objectUrl: 'blob:fetched' })).attributes('src')).toBe('blob:fetched')
		})
	})
})
