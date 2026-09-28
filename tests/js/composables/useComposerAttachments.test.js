/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { useComposerAttachments } from '../../../src/composables/useComposerAttachments.js'
import { useInstanceStore } from '../../../src/store/instance.js'
import { useTimelineStore } from '../../../src/store/timeline.js'
import { prepareImage } from '../../../src/utils/imageFilters.js'

vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))

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
			const store = useTimelineStore()
			store.createMedia = vi.fn(async () => ({ id: '1' }))
			vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:https://cloud.example/clip')
			const { attachments } = mountAttachments()

			await attachments.attachFiles([new File([new Uint8Array(50)], 'clip.mp4', { type: 'video/mp4' })])

			expect(store.createMedia).toHaveBeenCalledTimes(1)
		})
	})
})
