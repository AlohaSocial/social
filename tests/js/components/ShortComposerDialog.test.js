/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import ShortComposerDialog from '../../../src/components/ShortComposerDialog.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'
import { showError, showSuccess } from '../../../src/services/toast.js'
import { feel } from '../../../src/services/senses.js'
import { trimVideo } from '../../../src/utils/shortVideo.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(() => Promise.resolve({ data: [{ name: 'dance' }, { name: 'cats' }] })) },
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))
vi.mock('../../../src/services/senses.js', () => ({ feel: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

// the parts that need a real video element and an encoder; the arithmetic stays real
vi.mock('../../../src/utils/shortVideo.js', async (importOriginal) => ({
	...(await importOriginal()),
	filmstrip: vi.fn(async () => ['data:a', 'data:b']),
	seekTo: vi.fn(async () => {}),
	captureFrame: vi.fn(async () => new Blob(['jpg'], { type: 'image/jpeg' })),
	trimVideo: vi.fn(async () => new File(['cut'], 'clip-short.webm', { type: 'video/webm' })),
}))

const stubs = {
	NcModal: { props: ['name'], emits: ['close'], template: '<div class="modal-stub" :data-name="name"><slot /></div>' },
	NcButton: { template: '<button class="nc-button" type="button"><slot name="icon" /><slot /></button>' },
	NcLoadingIcon: true,
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" class="switch" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)">',
	},
}

function mountDialog() {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useTimelineStore()
	store.createMedia = vi.fn(async () => ({ id: 'm1' }))
	store.post = vi.fn(async () => ({ id: 's1' }))

	const wrapper = mount(ShortComposerDialog, {
		props: { open: true },
		global: { plugins: [pinia], stubs },
	})

	return { wrapper, store }
}

/** Picks a video and lets the preview say how long it is. */
async function withVideo(wrapper, duration = 20, file = new File(['v'], 'clip.mp4', { type: 'video/mp4' })) {
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
	await input.trigger('change')
	const preview = wrapper.find('.short__edit video')
	Object.defineProperty(preview.element, 'duration', { value: duration, configurable: true })
	await preview.trigger('loadedmetadata')
	await flushPromises()

	return file
}

function button(wrapper, text) {
	return wrapper.findAll('button').find((one) => one.text().trim() === text)
}

describe('ShortComposerDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		URL.createObjectURL = vi.fn(() => 'blob:video')
		URL.revokeObjectURL = vi.fn()
		HTMLMediaElement.prototype.play = vi.fn(() => Promise.resolve())
		HTMLMediaElement.prototype.pause = vi.fn()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.useRealTimers()
	})

	it('starts by asking where the video comes from', async () => {
		const { wrapper } = mountDialog()
		await flushPromises()

		expect(wrapper.find('.modal-stub').attributes('data-name')).toBe('New short')
		expect(wrapper.text()).toContain('Upload a video')
		// jsdom has no MediaRecorder, and a Record button that cannot record is a lie
		expect(wrapper.find('.short__source--record').exists()).toBe(false)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/trends/tags', expect.anything())
	})

	it('says why it cannot record on a page served over plain http', async () => {
		vi.stubGlobal('MediaRecorder', class {})
		vi.stubGlobal('isSecureContext', false)
		const { wrapper } = mountDialog()

		expect(wrapper.find('button.short__source--record').exists()).toBe(false)
		expect(wrapper.find('.short__source--unavailable').text()).toContain('https')
	})

	it('refuses something that is not a video', async () => {
		const { wrapper } = mountDialog()
		const input = wrapper.find('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [new File(['p'], 'a.png', { type: 'image/png' })] })
		await input.trigger('change')

		expect(showError).toHaveBeenCalledWith('A short has to be a video')
		expect(wrapper.find('.short__edit').exists()).toBe(false)
	})

	it('takes a video dropped on it', async () => {
		const { wrapper } = mountDialog()
		const clip = new File(['v'], 'drop.webm', { type: 'video/webm' })
		const drop = new Event('drop', { bubbles: true, cancelable: true })
		drop.dataTransfer = { files: [new File(['t'], 'notes.txt', { type: 'text/plain' }), clip] }
		wrapper.find('.short').element.dispatchEvent(drop)
		await flushPromises()

		expect(wrapper.find('.short__edit').exists()).toBe(true)
		expect(wrapper.vm.file).toBe(clip)
	})

	it('shows the whole video between the handles, with stills and a cover', async () => {
		const { wrapper } = mountDialog()
		await withVideo(wrapper, 20)

		expect(wrapper.vm.trim).toEqual({ start: 0, end: 20 })
		expect(wrapper.findAll('.short__strip img')).toHaveLength(2)
		expect(wrapper.find('.short__trim-times strong').text()).toBe('0:20')
		await wrapper.find('.short__edit video[aria-hidden="true"]').trigger('loadeddata')
		await flushPromises()
		expect(wrapper.vm.cover).toBeInstanceOf(Blob)
		expect(wrapper.find('.short__cover-image').attributes('src')).toBe('blob:video')
	})

	it('moves a handle from the keyboard, never past the other one', async () => {
		const { wrapper } = mountDialog()
		await withVideo(wrapper, 10)
		const [start, end] = wrapper.findAll('.short__handle')

		await start.trigger('keydown', { key: 'ArrowRight' })
		expect(wrapper.vm.trim.start).toBe(0.5)
		await start.trigger('keydown', { key: 'ArrowRight', shiftKey: true })
		expect(wrapper.vm.trim.start).toBe(3)
		await end.trigger('keydown', { key: 'Home' })
		// a short is at least a second long, so the end stops a second after the start
		expect(wrapper.vm.trim).toEqual({ start: 3, end: 4 })
		expect(end.attributes('aria-valuetext')).toBe('0:04')
	})

	it('moves a handle by dragging it along the bar', async () => {
		const { wrapper } = mountDialog()
		await withVideo(wrapper, 10)
		wrapper.find('.short__trim').element.getBoundingClientRect = () => ({ left: 100, width: 200 })

		wrapper.find('.short__handle--end').element.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }))
		window.dispatchEvent(new MouseEvent('pointermove', { clientX: 250 }))
		window.dispatchEvent(new MouseEvent('pointerup'))
		window.dispatchEvent(new MouseEvent('pointermove', { clientX: 120 }))

		expect(wrapper.vm.trim).toEqual({ start: 0, end: 7.5 })
	})

	it('adds a suggested hashtag once', async () => {
		const { wrapper } = mountDialog()
		await withVideo(wrapper)
		await wrapper.find('textarea').setValue('my cat')

		const tag = wrapper.findAll('.short__tag').find((one) => one.text() === '#cats')
		await tag.trigger('click')

		expect(wrapper.vm.caption).toBe('my cat #cats ')
		expect(tag.attributes('disabled')).toBeDefined()
	})

	it('posts an untouched video as it is, with its cover and its settings', async () => {
		const { wrapper, store } = mountDialog()
		const clip = await withVideo(wrapper)
		await wrapper.find('.short__edit video[aria-hidden="true"]').trigger('loadeddata')
		await flushPromises()
		await wrapper.find('textarea').setValue('  hello #dance ')
		await button(wrapper, 'Followers').trigger('click')
		await wrapper.find('.switch').setValue(true)

		await button(wrapper, 'Post').trigger('click')
		await flushPromises()

		expect(trimVideo).not.toHaveBeenCalled()
		expect(store.createMedia).toHaveBeenCalledWith(expect.objectContaining({ file: clip, thumbnail: expect.any(Blob) }))
		expect(store.post).toHaveBeenCalledWith({
			status: 'hello #dance',
			media_ids: ['m1'],
			visibility: 'private',
			sensitive: true,
			spoiler_text: '',
		})
		expect(feel).toHaveBeenCalledWith('post')
		expect(showSuccess).toHaveBeenCalled()
		expect(wrapper.emitted('posted')[0]).toEqual([{ id: 's1' }])
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
	})

	it('cuts a trimmed video before uploading it, where the browser can', async () => {
		vi.stubGlobal('MediaRecorder', class {})
		HTMLCanvasElement.prototype.captureStream = vi.fn()
		const { wrapper, store } = mountDialog()
		await withVideo(wrapper, 30)
		await wrapper.find('.short__handle--start').trigger('keydown', { key: 'ArrowRight', shiftKey: true })

		await button(wrapper, 'Post').trigger('click')
		await flushPromises()
		delete HTMLCanvasElement.prototype.captureStream

		expect(trimVideo).toHaveBeenCalledWith(expect.any(File), 2.5, 30, expect.anything())
		expect(store.createMedia.mock.calls[0][0].file.name).toBe('clip-short.webm')
	})

	it('says so, and posts the whole video, where the browser cannot cut', async () => {
		const { wrapper, store } = mountDialog()
		const clip = await withVideo(wrapper, 30)
		await wrapper.find('.short__handle--end').trigger('keydown', { key: 'Home' })

		expect(wrapper.find('.short__note').exists()).toBe(true)
		await button(wrapper, 'Post').trigger('click')
		await flushPromises()
		expect(trimVideo).not.toHaveBeenCalled()
		expect(store.createMedia.mock.calls[0][0].file).toBe(clip)
	})

	it('stops when the upload fails, and stays open', async () => {
		const { wrapper, store } = mountDialog()
		store.createMedia = vi.fn(async () => undefined)
		await withVideo(wrapper)

		await button(wrapper, 'Post').trigger('click')
		await flushPromises()

		expect(store.post).not.toHaveBeenCalled()
		expect(wrapper.emitted('update:open')).toBeUndefined()
		expect(wrapper.vm.busy).toBe('')
	})

	it('does not cheer for a short a moderator has to look at first', async () => {
		const { wrapper, store } = mountDialog()
		store.post = vi.fn(async () => ({ held_for_review: true }))
		await withVideo(wrapper)

		await button(wrapper, 'Post').trigger('click')
		await flushPromises()

		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
	})

	it('asks before throwing a chosen video away', async () => {
		const { wrapper } = mountDialog()
		wrapper.findComponent(stubs.NcModal).vm.$emit('close')
		expect(wrapper.emitted('update:open')[0]).toEqual([false])

		await withVideo(wrapper)
		wrapper.findComponent(stubs.NcModal).vm.$emit('close')
		await flushPromises()
		expect(wrapper.find('.short__confirm').exists()).toBe(true)
		expect(wrapper.emitted('update:open')).toHaveLength(1)

		await button(wrapper, 'Keep editing').trigger('click')
		expect(wrapper.find('.short__confirm').exists()).toBe(false)
		wrapper.findComponent(stubs.NcModal).vm.$emit('close')
		await flushPromises()
		await button(wrapper, 'Discard').trigger('click')
		expect(wrapper.emitted('update:open')).toHaveLength(2)
	})

	it('starts empty again once closed', async () => {
		const { wrapper } = mountDialog()
		await withVideo(wrapper)
		await wrapper.find('textarea').setValue('draft')

		await wrapper.setProps({ open: false })
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:video')
		expect(wrapper.vm.phase).toBe('choose')
		expect(wrapper.vm.caption).toBe('')
	})

	describe('recording', () => {
		let track
		let recorders

		beforeEach(() => {
			track = { stop: vi.fn() }
			recorders = []
			vi.stubGlobal('MediaRecorder', class {
				static isTypeSupported(type) {
					return type === 'video/webm'
				}

				constructor(stream, options) {
					this.options = options
					this.mimeType = options?.mimeType ?? ''
					this.state = 'inactive'
					this.listeners = {}
					recorders.push(this)
				}

				addEventListener(name, callback) {
					this.listeners[name] = callback
				}

				start() {
					this.state = 'recording'
				}

				stop() {
					this.state = 'inactive'
					this.listeners.dataavailable?.({ data: new Blob(['frames']) })
					this.listeners.stop?.()
				}
			})
			Object.defineProperty(window.navigator, 'mediaDevices', {
				value: { getUserMedia: vi.fn(async () => ({ getTracks: () => [track] })) },
				configurable: true,
			})
		})

		afterEach(() => {
			delete window.navigator.mediaDevices
		})

		it('counts down, records up to the limit, and edits what it recorded', async () => {
			vi.useFakeTimers()
			const { wrapper } = mountDialog()
			await wrapper.find('.short__source--record').trigger('click')
			await flushPromises()
			expect(wrapper.find('.short__record').exists()).toBe(true)
			expect(window.navigator.mediaDevices.getUserMedia).toHaveBeenCalledWith(expect.objectContaining({ audio: true }))

			await button(wrapper, '15 s').trigger('click')
			await wrapper.find('.short__shutter').trigger('click')
			expect(wrapper.find('.short__countdown').text()).toBe('3')
			vi.advanceTimersByTime(3000)
			expect(recorders[0].options).toEqual({ mimeType: 'video/webm' })
			expect(recorders[0].state).toBe('recording')

			vi.advanceTimersByTime(15_100)
			await flushPromises()
			expect(track.stop).toHaveBeenCalled()
			expect(wrapper.find('.short__edit').exists()).toBe(true)
			expect(wrapper.vm.file.type).toBe('video/webm')
			expect(wrapper.vm.file.name).toMatch(/^short-\d+\.webm$/)
		})

		it('says so when the camera cannot be opened', async () => {
			window.navigator.mediaDevices.getUserMedia = vi.fn(async () => {
				throw new Error('NotAllowedError')
			})
			const { wrapper } = mountDialog()
			await wrapper.find('.short__source--record').trigger('click')
			await flushPromises()

			expect(wrapper.find('.short__error').text()).toContain('camera could not be opened')
			expect(wrapper.find('.short__record').exists()).toBe(false)
		})

		it('switches between the front and the back camera', async () => {
			const { wrapper } = mountDialog()
			await wrapper.find('.short__source--record').trigger('click')
			await flushPromises()
			await wrapper.findAll('.short__rec-buttons .nc-button')[1].trigger('click')
			await flushPromises()

			expect(track.stop).toHaveBeenCalled()
			expect(window.navigator.mediaDevices.getUserMedia).toHaveBeenLastCalledWith(expect.objectContaining({
				video: expect.objectContaining({ facingMode: 'environment' }),
			}))
		})
	})
})
