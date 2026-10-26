/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import eventBus from '../../../src/services/eventBus.js'
import VideoUploadButton from '../../../src/components/VideoUploadButton.vue'

const ComposerStub = { name: 'Composer', props: { initialFiles: Array, startExpanded: Boolean }, emits: ['posted'], template: '<div class="composer-stub" />' }
const ModalStub = { name: 'NcModal', emits: ['close'], template: '<div class="modal-stub"><slot /></div>' }

/** @return {object} the mounted button */
function mountButton() {
	return mount(VideoUploadButton, {
		global: { stubs: { Composer: ComposerStub, NcModal: ModalStub } },
	})
}

/**
 * @param {object} wrapper the button
 * @param {File[]} files what the reader picked
 */
async function pick(wrapper, files) {
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: files, configurable: true })
	await input.trigger('change')
}

describe('Upload a video', () => {
	it('opens the picker for videos from the press itself', async () => {
		const wrapper = mountButton()
		const input = wrapper.find('input[type="file"]')
		const click = vi.spyOn(input.element, 'click')

		await wrapper.find('button').trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
		expect(input.attributes('accept')).toBe('video/*')
		expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
	})

	it('opens the composer with what was picked', async () => {
		const wrapper = mountButton()
		const file = new File(['x'], 'harbour.mp4', { type: 'video/mp4' })

		const opened = vi.fn()
		eventBus.on('composer-open', opened)
		await pick(wrapper, [file])
		expect(opened).toHaveBeenCalledWith({ files: [file] })
		eventBus.off('composer-open', opened)

		expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		expect(wrapper.findComponent(ModalStub).exists()).toBe(false)
	})

	it('opens nothing when the picker was dismissed', async () => {
		const wrapper = mountButton()
		await pick(wrapper, [])

		expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
	})

	it('allows picking the same file again through the common composer entry point', async () => {
		const wrapper = mountButton()
		const opened = vi.fn()
		eventBus.on('composer-open', opened)
		const file = new File(['x'], 'a.mp4', { type: 'video/mp4' })
		await pick(wrapper, [file])
		await pick(wrapper, [file])
		expect(opened).toHaveBeenCalledTimes(2)
		expect(wrapper.find('input').element.value).toBe('')
		eventBus.off('composer-open', opened)
	})
})
