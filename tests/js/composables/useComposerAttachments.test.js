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
})
