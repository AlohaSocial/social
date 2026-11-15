/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import PollEditor from '../../../src/components/Composer/PollEditor.vue'

// a plain button: re-emitting `click` would fire the listener twice, once for
// the component's event and once for the native one
const NcButton = {
	name: 'NcButton',
	props: ['ariaLabel', 'title', 'variant'],
	template: '<button :aria-label="ariaLabel"><slot /></button>',
}

/**
 * @param {object} props the poll as the composer holds it
 * @return {object} the mounted editor
 */
function mountEditor(props = {}) {
	return mount(PollEditor, {
		props: { options: ['', ''], multiple: false, expiresIn: 86400, ...props },
		global: { stubs: { NcButton, Close: true } },
	})
}

const fields = (wrapper) => wrapper.findAll('.poll-editor__option input')
const durations = (wrapper) => wrapper.find('.poll-editor__settings select')
function buttonFor(wrapper, label) {
	return wrapper.findAll('button').find((one) => one.attributes('aria-label') === label
		|| one.text() === label)
}

/**
 * The poll being written. It holds nothing of its own — the poll is part of
 * the post, and has to survive being saved as a draft and restored — so every
 * change here is a change asked of the composer.
 */
describe('the poll editor', () => {
	it('starts as the two options a poll has to have', () => {
		expect(fields(mountEditor())).toHaveLength(2)
	})

	it('shows the options it is given', () => {
		const wrapper = mountEditor({ options: ['Tea', 'Coffee', 'Neither'] })

		expect(fields(wrapper).map((field) => field.element.value))
			.toEqual(['Tea', 'Coffee', 'Neither'])
	})

	it('numbers each empty option, so they can be told apart', () => {
		expect(fields(mountEditor()).map((field) => field.attributes('placeholder')))
			.toEqual(['Poll option 1', 'Poll option 2'])
	})

	it('asks for an option to be changed rather than changing it', async () => {
		const wrapper = mountEditor()

		await fields(wrapper)[1].setValue('Coffee')

		// the whole list, not the one that changed: the composer keeps the poll
		expect(wrapper.emitted('update:options')).toEqual([[['', 'Coffee']]])
	})

	describe('how many options there may be', () => {
		it('adds one', async () => {
			const wrapper = mountEditor({ options: ['Tea', 'Coffee'] })

			await buttonFor(wrapper, 'Add option').trigger('click')

			expect(wrapper.emitted('update:options')).toEqual([[['Tea', 'Coffee', '']]])
		})

		/** Mastodon takes four, and a fifth would be refused on send. */
		it('stops offering to add at four', () => {
			expect(buttonFor(mountEditor({ options: ['a', 'b', 'c'] }), 'Add option')).toBeTruthy()
			expect(buttonFor(mountEditor({ options: ['a', 'b', 'c', 'd'] }), 'Add option'))
				.toBeUndefined()
		})

		it('takes one out', async () => {
			const wrapper = mountEditor({ options: ['Tea', 'Coffee', 'Neither'] })

			await wrapper.findAll('.poll-editor__option')[1]
				.find('button').trigger('click')

			expect(wrapper.emitted('update:options')).toEqual([[['Tea', 'Neither']]])
		})

		/** A poll with one answer is not a poll. */
		it('offers no way to go below two', () => {
			const wrapper = mountEditor({ options: ['Tea', 'Coffee'] })

			expect(wrapper.findAll('.poll-editor__option button')).toHaveLength(0)
		})
	})

	describe('how it is answered and for how long', () => {
		it('asks for multiple choice to be turned on', async () => {
			const wrapper = mountEditor()

			await wrapper.find('.poll-editor__settings input[type="checkbox"]').setValue(true)

			expect(wrapper.emitted('update:multiple')).toEqual([[true]])
		})

		it('shows whether multiple choice is on', () => {
			expect(mountEditor({ multiple: true })
				.find('.poll-editor__settings input[type="checkbox"]').element.checked).toBe(true)
		})

		/** Thirty minutes to a week: what a poll is worth asking over. */
		it('offers six durations, and shows the one chosen', () => {
			const wrapper = mountEditor({ expiresIn: 3600 })

			expect(durations(wrapper).findAll('option').map((one) => one.element.value))
				.toEqual(['1800', '3600', '21600', '86400', '259200', '604800'])
			expect(durations(wrapper).element.value).toBe('3600')
		})

		/** It travels to the API as seconds, so it is asked for as a number. */
		it('asks for a duration as a number of seconds', async () => {
			const wrapper = mountEditor()

			await durations(wrapper).setValue('604800')

			expect(wrapper.emitted('update:expiresIn')).toEqual([[604800]])
		})

		it('names the duration control, which has no visible label', () => {
			expect(durations(mountEditor()).attributes('aria-label')).toBe('Poll duration')
		})
	})

	/** The way out of a panel belongs on the panel, not only on the toolbar. */
	it('can be taken off the post from inside itself', async () => {
		const wrapper = mountEditor()

		await wrapper.find('.poll-editor__remove').trigger('click')

		expect(wrapper.emitted('remove')).toHaveLength(1)
	})

	it('says what is missing once sending was tried with an incomplete poll', () => {
		expect(mountEditor({ valid: false }).find('.poll-editor__error').exists()).toBe(false)
		expect(mountEditor({ valid: true, attempted: true }).find('.poll-editor__error').exists()).toBe(false)
		expect(mountEditor({ valid: false, attempted: true }).find('.poll-editor__error').exists()).toBe(true)
	})
})
