/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { useComposerAttachments } from '../../../src/composables/useComposerAttachments.js'
import { describeFile } from '../../../src/services/fileDescriptions.js'
import { useInstanceStore } from '../../../src/store/instance.js'
import { useTimelineStore } from '../../../src/store/timeline.js'
import { prepareImage } from '../../../src/utils/imageFilters.js'

import { showError, showWarning } from '../../../src/services/toast.js'

vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showWarning: vi.fn() }))

// the real one needs a canvas; what is tested here is when it is asked, and
// what is done with its answer
vi.mock('../../../src/utils/imageFilters.js', async (importOriginal) => ({
	...(await importOriginal()),
	prepareImage: vi.fn(async (file, { filter = 'none' } = {}) => (filter === 'none'
		? file
		: new File(['filtered'], 'filtered.jpg', { type: 'image/jpeg' }))),
}))

/**
 * The composable in a component of its own, as the composer runs it.
 *
 * @return {{ attachments: ReturnType<typeof useComposerAttachments>, wrapper: object, expand: Function }}
 */
function mountAttachments() {
	const expand = vi.fn()
	let attachments
	const wrapper = mount(defineComponent({
		setup() {
			attachments = useComposerAttachments({ expand, root: () => undefined })
			return () => h('div')
		},
	}))

	return { attachments, wrapper, expand }
}

describe('useComposerAttachments', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		useInstanceStore().maxAttachments = 2
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('puts a deleted post\'s pictures back by id, as many as there is room for', () => {
		const { attachments } = mountAttachments()

		attachments.restoreMedia([
			{ id: '1', description: 'a dog', url: 'https://cloud.example/1.jpg' },
			{ description: 'no id, so nothing to put back' },
			{ id: '2', url: 'https://cloud.example/2.jpg' },
			{ id: '3', url: 'https://cloud.example/3.jpg' },
		])

		const restored = Object.values(attachments.attachments.value)
		expect(restored.map((attachment) => attachment.data.id)).toEqual(['1', '2'])
		expect(restored[0]).toMatchObject({ file: null, failed: false, description: 'a dog', saved: 'a dog' })
		expect(attachments.mediaIds.value).toEqual(['1', '2'])
		expect(attachments.attachmentsFull.value).toBe(true)
		expect(attachments.undescribed.value).toBe(1)
	})

	it('lets go of the previews it drew when everything is cleared', () => {
		const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
		const { attachments } = mountAttachments()
		attachments.attachments.value = {
			'blob:https://cloud.example/one': { file: null, data: null, failed: false },
			'nextcloud:1:/Photos/two.jpg': { file: null, path: '/Photos/two.jpg', data: null, failed: false },
		}

		attachments.clearAttachments()

		expect(attachments.attachments.value).toEqual({})
		expect(revoke).toHaveBeenCalledTimes(1)
		expect(revoke).toHaveBeenCalledWith('blob:https://cloud.example/one')
	})

	it('offers the file dialog the media and documents it takes', () => {
		const { attachments } = mountAttachments()

		expect(attachments.acceptedTypes.value.split(',')).toEqual(expect.arrayContaining(['image/*', 'video/*', 'audio/*', 'application/pdf', '.odt']))
	})

	it('knows a post of one video from a post with a video in it', () => {
		const { attachments } = mountAttachments()
		attachments.attachments.value = { a: { file: null, path: 'a', data: { id: '1', type: 'video' }, failed: false } }
		expect(attachments.isVideoPost.value).toBe(true)

		attachments.attachments.value = {
			...attachments.attachments.value,
			b: { file: null, path: 'b', data: { id: '2', type: 'image' }, failed: false },
		}
		expect(attachments.isVideoPost.value).toBe(false)
	})

	it('lets the post wait for a description whose field was just left', async () => {
		const store = useTimelineStore()
		let saved
		store.describeMedia = vi.fn(() => new Promise((resolve) => {
			saved = resolve
		}))
		const { attachments } = mountAttachments()
		attachments.restoreMedia([{ id: '1', description: 'a dog', url: 'https://cloud.example/1.jpg' }])
		const [key] = Object.keys(attachments.attachments.value)

		attachments.commitDescription({ key, description: 'a dog on a beach' })
		let ready = false
		const posting = attachments.saveDescriptions().then(() => {
			ready = true
		})
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(store.describeMedia).toHaveBeenCalledTimes(1)
		expect(store.describeMedia).toHaveBeenCalledWith({ id: '1', description: 'a dog on a beach' })
		expect(ready).toBe(false)

		saved()
		await posting
		expect(ready).toBe(true)
	})

	describe('filters', () => {
		/**
		 * One attached picture, uploaded as id 1, with the store's uploads
		 * answering 2, 3, … in turn.
		 *
		 * @return {Promise<{attachments: ReturnType<typeof useComposerAttachments>, key: string, store: object}>}
		 */
		async function attachedPicture() {
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/cat')
			const store = useTimelineStore()
			let next = 0
			store.createMedia = vi.fn(async () => ({ id: String(++next), type: 'image' }))
			store.describeMedia = vi.fn(async () => {})
			store.focusMedia = vi.fn(async () => {})
			const { attachments } = mountAttachments()

			await attachments.attachFiles([new File(['x'], 'cat.jpg', { type: 'image/jpeg' })])

			return { attachments, key: 'blob:https://cloud.example/cat', store }
		}

		it('uploads nothing while the filters are being tried', async () => {
			const { attachments, key, store } = await attachedPicture()
			expect(store.createMedia).toHaveBeenCalledTimes(1)

			for (const filter of ['mono', 'noir', 'warm', 'cool', 'vivid', 'faded']) {
				attachments.applyFilter({ key, filter })
			}
			await new Promise((resolve) => setTimeout(resolve, 700))

			expect(store.createMedia).toHaveBeenCalledTimes(1)
			expect(attachments.attachments.value[key].filter).toBe('faded')
			expect(attachments.mediaIds.value).toEqual(['1'])
		})

		it('bakes the chosen filter in once, as the post is sent', async () => {
			const { attachments, key, store } = await attachedPicture()
			attachments.applyFilter({ key, filter: 'mono' })
			attachments.applyFilter({ key, filter: 'sepia' })

			expect(await attachments.bakeFilters()).toBe(true)
			// a post that did not go out and is sent again bakes nothing twice
			expect(await attachments.bakeFilters()).toBe(true)

			expect(store.createMedia).toHaveBeenCalledTimes(2)
			expect(prepareImage).toHaveBeenLastCalledWith(expect.any(File), expect.objectContaining({ filter: 'sepia' }))
			expect(attachments.mediaIds.value).toEqual(['2'])
		})

		it('carries the description and the focal point over to the filtered copy', async () => {
			const { attachments, key, store } = await attachedPicture()
			attachments.describeAttachment({ key, description: 'a cat' })
			attachments.focusAttachment({ key, focus: { x: 0.5, y: -0.25 } })
			attachments.applyFilter({ key, filter: 'mono' })

			await attachments.bakeFilters()

			expect(store.describeMedia).toHaveBeenCalledWith({ id: '2', description: 'a cat' })
			expect(store.focusMedia).toHaveBeenCalledWith(expect.objectContaining({ id: '2' }))
			// saved already, so the send does not write it a second time
			expect(attachments.attachments.value[key].saved).toBe('a cat')
		})

		it('goes back to the first upload when the filter is taken off again', async () => {
			const { attachments, key, store } = await attachedPicture()
			attachments.applyFilter({ key, filter: 'mono' })
			await attachments.bakeFilters()
			attachments.applyFilter({ key, filter: 'none' })

			await attachments.bakeFilters()

			expect(store.createMedia).toHaveBeenCalledTimes(2)
			expect(attachments.mediaIds.value).toEqual(['1'])
		})

		it('says so when the filtered copy would not upload, and keeps the first', async () => {
			const { attachments, key, store } = await attachedPicture()
			store.createMedia = vi.fn(async () => undefined)
			attachments.applyFilter({ key, filter: 'mono' })

			expect(await attachments.bakeFilters()).toBe(false)
			expect(attachments.mediaIds.value).toEqual(['1'])
		})

		it('posts the picture as it is when the browser could not filter it', async () => {
			const { attachments, key, store } = await attachedPicture()
			prepareImage.mockImplementationOnce(async (file) => file)
			attachments.applyFilter({ key, filter: 'mono' })

			expect(await attachments.bakeFilters()).toBe(true)
			expect(store.createMedia).toHaveBeenCalledTimes(1)
		})
	})

	describe('the size limit', () => {
		/**
		 * The server says no to anything but a video over `image_size_limit`
		 * only after all of it has arrived; an animated GIF cannot be shrunk
		 * here, so it is turned away before it is sent.
		 */
		it('refuses a file still over the limit without uploading it', async () => {
			useInstanceStore().imageSizeLimit = 10
			const store = useTimelineStore()
			store.createMedia = vi.fn(async () => ({ id: '1' }))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/wave')
			const { attachments } = mountAttachments()

			await attachments.attachFiles([new File([new Uint8Array(50)], 'wave.gif', { type: 'image/gif' })])

			expect(store.createMedia).not.toHaveBeenCalled()
			expect(attachments.attachments.value['blob:https://cloud.example/wave'].failed).toBe(true)
			expect(attachments.failedUploads.value).toBe(1)
		})

		it('uploads the shrunk copy and keeps the original for the filters', async () => {
			useInstanceStore().imageSizeLimit = 10
			const store = useTimelineStore()
			store.createMedia = vi.fn(async () => ({ id: '1' }))
			const small = new File(['x'], 'big.jpg', { type: 'image/jpeg' })
			prepareImage.mockImplementationOnce(async () => small)
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/big')
			const { attachments } = mountAttachments()
			const original = new File([new Uint8Array(50)], 'big.jpg', { type: 'image/jpeg' })

			await attachments.attachFiles([original])

			expect(prepareImage).toHaveBeenCalledWith(original, { sizeLimit: 10 })
			expect(store.createMedia).toHaveBeenCalledWith(expect.objectContaining({ file: small }))
			expect(attachments.attachments.value['blob:https://cloud.example/big'].file).toBe(original)
		})

		it('leaves a video to its own, larger ceiling', async () => {
			useInstanceStore().imageSizeLimit = 10
			useInstanceStore().videoSizeLimit = 100
			const store = useTimelineStore()
			store.createMedia = vi.fn(async () => ({ id: '1' }))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/clip')
			const { attachments } = mountAttachments()
			const clip = new File([new Uint8Array(50)], 'clip.mp4', { type: 'video/mp4' })

			await attachments.attachFiles([clip])

			expect(store.createMedia).toHaveBeenCalledWith(expect.objectContaining({ file: clip }))
			// never through the picture path: there is nothing to shrink
			expect(prepareImage).not.toHaveBeenCalled()
		})

		/**
		 * A video cannot be shrunk, and the server only says no once all of
		 * it has arrived — a two-gigabyte upload for a refusal. The message
		 * names the ceiling, so the reader knows what would get through.
		 */
		it('refuses a video over the video ceiling before it is sent, naming the ceiling', async () => {
			useInstanceStore().videoSizeLimit = 3 * 1048576
			const store = useTimelineStore()
			store.createMedia = vi.fn(async () => ({ id: '1' }))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/clip')
			const { attachments } = mountAttachments()

			await attachments.attachFiles([new File([new Uint8Array(4 * 1048576)], 'clip.mp4', { type: 'video/mp4' })])

			expect(store.createMedia).not.toHaveBeenCalled()
			expect(attachments.attachments.value['blob:https://cloud.example/clip'].failed).toBe(true)
			expect(showError).toHaveBeenCalledWith('This video is larger than the 3 MB this server takes')
		})
	})

	/**
	 * A draft keeps its attachments by the id of their upload, and an upload
	 * nobody posted can be cleared away while the draft waits: each one is
	 * asked about again when the draft comes back.
	 */
	describe('what a draft kept', () => {
		const kept = (id, extra = {}) => ({
			data: { id, type: 'image', url: `https://cloud.example/${id}.jpg`, preview_url: `https://cloud.example/${id}-small.jpg`, description: '' },
			description: '',
			focus: null,
			filter: '',
			...extra,
		})

		beforeEach(() => {
			showWarning.mockClear()
			useInstanceStore().maxAttachments = 4
		})

		it('is in the grid at once, and Post waits until the server has answered', async () => {
			const store = useTimelineStore()
			let answer
			store.fetchMedia = vi.fn(() => new Promise((resolve) => {
				answer = resolve
			}))
			const { attachments } = mountAttachments()

			const restoring = attachments.restoreDraftMedia([kept('1', { description: 'a dog', focus: { x: 0.5, y: -0.25 } })])

			const [restored] = Object.values(attachments.attachments.value)
			expect(restored).toMatchObject({ data: { id: '1' }, description: 'a dog', focus: { x: 0.5, y: -0.25 }, checking: true })
			expect(attachments.checkingMedia.value).toBe(true)
			expect(attachments.mediaIds.value).toEqual(['1'])

			answer({ id: '1', type: 'image', url: 'https://cloud.example/1.jpg', description: 'a dog' })
			await restoring

			expect(attachments.checkingMedia.value).toBe(false)
			// the description the server holds is not written again
			expect(Object.values(attachments.attachments.value)[0]).toMatchObject({ description: 'a dog', saved: 'a dog' })
			expect(store.fetchMedia).toHaveBeenCalledWith('1')
		})

		it('takes off what the server no longer has, and says so once', async () => {
			const store = useTimelineStore()
			store.fetchMedia = vi.fn(async (id) => (id === '2' ? { id: '2', type: 'image', url: 'https://cloud.example/2.jpg' } : null))
			const { attachments } = mountAttachments()

			await attachments.restoreDraftMedia([kept('1'), kept('2'), kept('3')])

			expect(attachments.mediaIds.value).toEqual(['2'])
			expect(showWarning).toHaveBeenCalledTimes(1)
			expect(showWarning).toHaveBeenCalledWith('2 attachments of your draft are no longer on the server and were removed')
		})

		it('keeps one the server could not be asked about, as the draft had it', async () => {
			const store = useTimelineStore()
			store.fetchMedia = vi.fn(async () => undefined)
			const { attachments } = mountAttachments()

			await attachments.restoreDraftMedia([kept('1', { description: 'a dog' })])

			expect(Object.values(attachments.attachments.value)[0]).toMatchObject({ data: { id: '1' }, description: 'a dog', checking: false })
			expect(showWarning).not.toHaveBeenCalled()
		})

		it('fetches a filtered picture again, so the filter can still be baked in', async () => {
			const store = useTimelineStore()
			const file = new File(['x'], '1.jpg', { type: 'image/jpeg' })
			store.fetchMedia = vi.fn(async () => ({ id: '1', type: 'image', url: 'https://cloud.example/1.jpg' }))
			store.fetchMediaFile = vi.fn(async () => file)
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/refetched')
			const { attachments } = mountAttachments()

			await attachments.restoreDraftMedia([kept('1', { filter: 'warm' }), kept('2')])

			// the swatches draw the file now; the key and the order stay
			expect(Object.values(attachments.attachments.value)[0]).toMatchObject({ file, filter: 'warm', objectUrl: 'blob:https://cloud.example/refetched' })
			expect(attachments.mediaIds.value).toEqual(['1', '1'])
		})

		it('drops the filter of a picture it cannot fetch, so the preview is what would be posted', async () => {
			const store = useTimelineStore()
			store.fetchMedia = vi.fn(async () => ({ id: '1', type: 'image', url: 'https://cloud.example/1.jpg' }))
			store.fetchMediaFile = vi.fn(async () => undefined)
			const { attachments } = mountAttachments()

			await attachments.restoreDraftMedia([kept('1', { filter: 'warm' })])

			expect(Object.values(attachments.attachments.value)[0].filter).toBeUndefined()
		})

		it('leaves alone one that was taken off while the server was asked', async () => {
			const store = useTimelineStore()
			let answer
			store.fetchMedia = vi.fn(() => new Promise((resolve) => {
				answer = resolve
			}))
			const { attachments } = mountAttachments()

			const restoring = attachments.restoreDraftMedia([kept('1')])
			attachments.deletePreview(Object.keys(attachments.attachments.value)[0])
			answer({ id: '1' })
			await restoring

			expect(attachments.attachments.value).toEqual({})
		})

		// a picture the app drew itself, the weekly recap, knows what is in
		// it: the author finds the description written and can still change it
		it('fills in the description a file arrived with', async () => {
			const store = useTimelineStore()
			store.createMedia = vi.fn(async () => ({ id: '9', type: 'image' }))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/recap')
			const { attachments } = mountAttachments()
			const recap = describeFile(new File(['x'], 'weekly-recap.png', { type: 'image/png' }), 'A weekly recap')

			await attachments.attachFiles([recap])

			expect(attachments.attachments.value['blob:https://cloud.example/recap'].description).toBe('A weekly recap')
			expect(attachments.draftMedia.value[0].description).toBe('A weekly recap')
		})

		it('keeps each one by its upload, with what was written about it', async () => {
			const store = useTimelineStore()
			store.createMedia = vi.fn(async () => ({ id: '9', type: 'image', url: 'https://cloud.example/9.jpg', preview_url: 'https://cloud.example/9-small.jpg' }))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/cat')
			const { attachments } = mountAttachments()
			await attachments.attachFiles([new File(['x'], 'cat.png', { type: 'image/png' })])

			attachments.describeAttachment({ key: 'blob:https://cloud.example/cat', description: 'a cat' })
			attachments.focusAttachment({ key: 'blob:https://cloud.example/cat', focus: { x: 0.1, y: 0.2 } })
			attachments.applyFilter({ key: 'blob:https://cloud.example/cat', filter: 'warm' })

			expect(attachments.draftMedia.value).toEqual([{
				data: expect.objectContaining({ id: '9', url: 'https://cloud.example/9.jpg' }),
				description: 'a cat',
				focus: { x: 0.1, y: 0.2 },
				filter: 'warm',
			}])
		})
	})

	/**
	 * A picture added from Files, written again from a deleted post or
	 * brought back by a draft has no file in the browser, and the filter is
	 * baked in from the file: choosing one used to change the preview and
	 * nothing else.
	 */
	describe('a filter for a picture with no file in the browser', () => {
		const fromFiles = { id: '5', type: 'image', url: 'https://cloud.example/5.jpg', preview_url: 'https://cloud.example/5-small.jpg' }

		/**
		 * @return {object} the composable, holding the picture as Files left it
		 */
		function withPicture() {
			const mounted = mountAttachments()
			mounted.attachments.attachments.value = { 'nextcloud:1:/cat.jpg': { file: null, path: '/cat.jpg', data: fromFiles, failed: false } }
			return mounted.attachments
		}

		beforeEach(() => {
			showError.mockClear()
		})

		it('fetches the file first, and the post waits for it', async () => {
			const store = useTimelineStore()
			const file = new File(['x'], '5.jpg', { type: 'image/jpeg' })
			let answer
			store.fetchMediaFile = vi.fn(() => new Promise((resolve) => {
				answer = resolve
			}))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/5')
			const attachments = withPicture()

			const choosing = attachments.applyFilter({ key: 'nextcloud:1:/cat.jpg', filter: 'warm' })
			expect(attachments.checkingMedia.value).toBe(true)
			answer(file)
			await choosing

			expect(store.fetchMediaFile).toHaveBeenCalledWith(fromFiles)
			expect(attachments.attachments.value['nextcloud:1:/cat.jpg']).toMatchObject({ file, filter: 'warm', objectUrl: 'blob:https://cloud.example/5' })
			expect(attachments.checkingMedia.value).toBe(false)
		})

		it('bakes the filter in when the post goes out', async () => {
			const store = useTimelineStore()
			store.fetchMediaFile = vi.fn(async () => new File(['x'], '5.jpg', { type: 'image/jpeg' }))
			store.createMedia = vi.fn(async () => ({ id: '6' }))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/5')
			const attachments = withPicture()

			await attachments.applyFilter({ key: 'nextcloud:1:/cat.jpg', filter: 'warm' })
			expect(await attachments.bakeFilters()).toBe(true)

			expect(attachments.mediaIds.value).toEqual(['6'])
		})

		it('keeps the last choice made while the file was on its way', async () => {
			const store = useTimelineStore()
			let answer
			store.fetchMediaFile = vi.fn(() => new Promise((resolve) => {
				answer = resolve
			}))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/5')
			const attachments = withPicture()

			const first = attachments.applyFilter({ key: 'nextcloud:1:/cat.jpg', filter: 'warm' })
			await attachments.applyFilter({ key: 'nextcloud:1:/cat.jpg', filter: 'mono' })
			answer(new File(['x'], '5.jpg', { type: 'image/jpeg' }))
			await first

			expect(store.fetchMediaFile).toHaveBeenCalledTimes(1)
			expect(attachments.attachments.value['nextcloud:1:/cat.jpg'].filter).toBe('mono')
		})

		it('says so and leaves the picture as it is when the file cannot be had', async () => {
			const store = useTimelineStore()
			store.fetchMediaFile = vi.fn(async () => undefined)
			const attachments = withPicture()

			await attachments.applyFilter({ key: 'nextcloud:1:/cat.jpg', filter: 'warm' })

			expect(showError).toHaveBeenCalledWith('Could not load the photo to apply the filter, so it stays as it is')
			expect(attachments.attachments.value['nextcloud:1:/cat.jpg'].filter).toBeUndefined()
			expect(attachments.checkingMedia.value).toBe(false)
		})

		it('fetches nothing for Original', async () => {
			const store = useTimelineStore()
			store.fetchMediaFile = vi.fn()
			const attachments = withPicture()

			await attachments.applyFilter({ key: 'nextcloud:1:/cat.jpg', filter: 'none' })

			expect(store.fetchMediaFile).not.toHaveBeenCalled()
		})

		it('lets go of the fetched file when the picture is taken off', async () => {
			const store = useTimelineStore()
			store.fetchMediaFile = vi.fn(async () => new File(['x'], '5.jpg', { type: 'image/jpeg' }))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/5')
			const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
			const attachments = withPicture()
			await attachments.applyFilter({ key: 'nextcloud:1:/cat.jpg', filter: 'warm' })

			attachments.deletePreview('nextcloud:1:/cat.jpg')

			expect(revoke).toHaveBeenCalledWith('blob:https://cloud.example/5')
		})
	})
})
