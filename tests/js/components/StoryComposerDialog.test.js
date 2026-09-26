/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import StoryComposerDialog from '../../../src/components/StoryComposerDialog.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))
vi.mock('../../../src/services/senses.js', () => ({ feel: vi.fn() }))

// jsdom has no canvas: the drawing is stood in for, and what it was asked to
// draw is what the tests look at
const { renderTextCard, bakeStickers } = vi.hoisted(() => ({
	renderTextCard: vi.fn(),
	bakeStickers: vi.fn(),
}))
vi.mock('../../../src/utils/textCard.js', async (importOriginal) => ({
	...(await importOriginal()),
	renderTextCard,
	bakeStickers,
}))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'buttons', 'name'],
	emits: ['update:open'],
	template: '<div v-if="open" class="nc-dialog">'
		+ '<button v-for="(button, index) in buttons" :key="index" :class="\'nc-dialog__button--\' + index" :disabled="button.disabled" @click="button.callback()">{{ button.label }}</button><slot /></div>',
}

function mountDialog() {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useTimelineStore()
	const createMedia = vi.spyOn(store, 'createMedia').mockResolvedValue({ id: 'm1' })
	const describeMedia = vi.spyOn(store, 'describeMedia').mockResolvedValue(undefined)
	// Assigned, not defaulted. jsdom provides both now, and its own
	// `createObjectURL` reads a `_buffer` off the blob that a `File` built in
	// the test realm does not carry — so `??=` left the real one in place and
	// every pick threw past the assertions, failing the run while every test
	// passed.
	globalThis.URL.createObjectURL = () => 'blob:preview'
	globalThis.URL.revokeObjectURL = () => {}

	const wrapper = mount(StoryComposerDialog, {
		props: { open: true },
		global: { plugins: [pinia], stubs: { NcDialog: NcDialogStub } },
	})

	return { wrapper, createMedia, describeMedia }
}

async function pick(wrapper, file) {
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
	await input.trigger('change')
}

describe('StoryComposerDialog', () => {
	beforeEach(() => {
		renderTextCard.mockReset().mockResolvedValue(new File(['png'], 'card.png', { type: 'image/png' }))
		bakeStickers.mockReset().mockImplementation(async (file) => new File(['baked'], file.name, { type: file.type }))
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('keeps the file input behind its button out of the tab order', () => {
		const input = mountDialog().wrapper.find('input[type="file"]')

		expect(input.attributes('tabindex')).toBe('-1')
		expect(input.attributes('aria-hidden')).toBe('true')
	})

	it('cannot post before a picture was chosen', () => {
		const { wrapper } = mountDialog()

		expect(wrapper.find('.nc-dialog__button--1').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.story-composer__pick').exists()).toBe(true)
	})

	it('uploads the picture the way every attachment goes up, then makes the story of it', async () => {
		const { wrapper, createMedia } = mountDialog()
		post.mockResolvedValue({ data: { id: '5', caption: 'hello' } })

		await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))
		expect(wrapper.find('.story-composer__media').exists()).toBe(true)
		await wrapper.find('.story-composer__caption input').setValue('hello')
		await wrapper.find('.nc-dialog__button--1').trigger('click')
		await flushPromises()

		expect(createMedia).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/stories'), { media_id: 'm1', caption: 'hello', duration: 5 })
		expect(wrapper.emitted('posted')[0][0].id).toBe('5')
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
		expect(showSuccess).toHaveBeenCalled()
	})

	it('offers no seconds for a video, which runs for as long as it runs', async () => {
		const { wrapper } = mountDialog()

		await pick(wrapper, new File(['x'], 'clip.mp4', { type: 'video/mp4' }))

		expect(wrapper.find('.story-composer__duration').exists()).toBe(false)
		expect(wrapper.find('video').exists()).toBe(true)
	})

	it('says so when the server refused the story', async () => {
		const { wrapper } = mountDialog()
		post.mockRejectedValue({ response: { data: { error: 'this account already has 40 live stories' } } })

		await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))
		await wrapper.find('.nc-dialog__button--1').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('this account already has 40 live stories')
		expect(wrapper.emitted('posted')).toBeUndefined()
	})

	describe('a text story', () => {
		const writeText = async (wrapper, words) => {
			await wrapper.findAll('.story-composer__kind')[1].trigger('click')
			await wrapper.find('.story-composer__words textarea').setValue(words)
		}

		it('cannot be posted until something is written', async () => {
			const { wrapper } = mountDialog()
			await wrapper.findAll('.story-composer__kind')[1].trigger('click')

			expect(wrapper.find('.nc-dialog__button--1').attributes('disabled')).toBeDefined()
			expect(wrapper.find('.story-composer__card').text()).toBe('Your words here')
		})

		it('shows the words on the chosen background as they are typed', async () => {
			const { wrapper } = mountDialog()
			await writeText(wrapper, 'Off to the sea')
			await wrapper.findAll('.story-composer__gradient')[2].trigger('click')

			expect(wrapper.find('.story-composer__card-text').text()).toBe('Off to the sea')
			expect(wrapper.find('.story-composer__card').attributes('style')).toContain('linear-gradient')
			expect(wrapper.findAll('.story-composer__gradient')[2].attributes('aria-checked')).toBe('true')
		})

		/**
		 * Drawn to a tall picture and uploaded like any other, with the words
		 * as the picture's description: a reader who cannot see the card is
		 * told what it says.
		 */
		it('is drawn to a picture, described with its words, and posted as a story', async () => {
			const { wrapper, createMedia, describeMedia } = mountDialog()
			post.mockResolvedValue({ data: { id: '7' } })
			await writeText(wrapper, '  Off to the sea  ')

			await wrapper.find('.nc-dialog__button--1').trigger('click')
			await flushPromises()

			expect(renderTextCard).toHaveBeenCalledWith('Off to the sea', expect.objectContaining({ id: 'account' }), { width: 1080, height: 1920 })
			expect(createMedia.mock.calls[0][0].name).toBe('card.png')
			expect(describeMedia).toHaveBeenCalledWith({ id: 'm1', description: 'Off to the sea' })
			expect(post).toHaveBeenCalledWith(expect.stringContaining('/stories'), { media_id: 'm1', caption: '', duration: 5 })
		})

		it('says so when the browser could not draw it, and uploads nothing', async () => {
			const { wrapper, createMedia } = mountDialog()
			renderTextCard.mockResolvedValue(null)
			await writeText(wrapper, 'hello')

			await wrapper.find('.nc-dialog__button--1').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('This browser could not draw the story')
			expect(createMedia).not.toHaveBeenCalled()
		})
	})

	describe('stickers on a picture', () => {
		it('adds an emoji sticker to the picture, and can take it off again', async () => {
			const { wrapper } = mountDialog()
			await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))

			await wrapper.findAll('.story-composer__add')[2].trigger('click')
			expect(wrapper.findAll('.story-composer__sticker').map((sticker) => sticker.text())).toEqual(['🔥'])

			await wrapper.find('.story-composer__sticker').trigger('keydown', { key: 'Delete' })
			expect(wrapper.findAll('.story-composer__sticker')).toHaveLength(0)
		})

		it('moves a sticker with the arrow keys, and keeps it on the picture', async () => {
			const { wrapper } = mountDialog()
			await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))
			await wrapper.findAll('.story-composer__add')[0].trigger('click')
			const sticker = wrapper.find('.story-composer__sticker')
			const left = () => parseFloat(sticker.attributes('style').match(/left: ([\d.]+)%/)[1])

			const before = left()
			await sticker.trigger('keydown', { key: 'ArrowRight' })
			expect(left()).toBeCloseTo(before + 2, 5)

			for (let i = 0; i < 60; i++) {
				await sticker.trigger('keydown', { key: 'ArrowRight' })
			}
			expect(left()).toBe(100)
		})

		/** a finger or a pointer puts a sticker where it is let go */
		it('drags a sticker to where it is let go', async () => {
			const { wrapper } = mountDialog()
			await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))
			await wrapper.findAll('.story-composer__add')[0].trigger('click')
			wrapper.find('.story-composer__stage').element.getBoundingClientRect = () => ({ left: 0, top: 0, width: 400, height: 200 })
			const sticker = wrapper.find('.story-composer__sticker')

			sticker.element.dispatchEvent(new MouseEvent('pointerdown', { clientX: 200, clientY: 100, bubbles: true }))
			window.dispatchEvent(new MouseEvent('pointermove', { clientX: 100, clientY: 150 }))
			window.dispatchEvent(new MouseEvent('pointerup'))
			await wrapper.vm.$nextTick()

			expect(sticker.attributes('style')).toContain('left: 25%')
			expect(sticker.attributes('style')).toContain('top: 75%')
		})

		it('adds words as a sticker of their own', async () => {
			const { wrapper } = mountDialog()
			await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))

			await wrapper.find('.story-composer__add-text input').setValue('Day one')
			await wrapper.find('.story-composer__add-text').trigger('submit')

			expect(wrapper.find('.story-composer__sticker--text').text()).toBe('Day one')
		})

		it('bakes the stickers into the picture before it goes up', async () => {
			const { wrapper, createMedia } = mountDialog()
			post.mockResolvedValue({ data: { id: '8' } })
			await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))
			await wrapper.findAll('.story-composer__add')[5].trigger('click')

			await wrapper.find('.nc-dialog__button--1').trigger('click')
			await flushPromises()

			expect(bakeStickers).toHaveBeenCalledWith(expect.any(File), [expect.objectContaining({ kind: 'emoji', value: '❤️' })])
			expect(await createMedia.mock.calls[0][0].text()).toBe('baked')
		})

		it('leaves a picture without stickers exactly as it was chosen', async () => {
			const { wrapper } = mountDialog()
			post.mockResolvedValue({ data: { id: '9' } })
			await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))

			await wrapper.find('.nc-dialog__button--1').trigger('click')
			await flushPromises()

			expect(bakeStickers).not.toHaveBeenCalled()
		})

		it('offers no stickers on a video', async () => {
			const { wrapper } = mountDialog()
			await pick(wrapper, new File(['x'], 'clip.mp4', { type: 'video/mp4' }))

			expect(wrapper.find('.story-composer__stickers').exists()).toBe(false)
		})
	})
})
