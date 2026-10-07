/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SectionsSection from '../../../../src/components/admin/SectionsSection.vue'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess }))

const ROUTE = '/index.php/apps/social/admin/sections'
const stubs = { NcSettingsSection: { template: '<section><slot /></section>' } }

const GROUPS = [
	{ id: 'staff', name: 'Staff' },
	{ id: 'marketing', name: 'Marketing' },
]

/**
 * @param {object} settings what stands now, as SectionsService::current() answers it
 * @param {object[]} groups every group on this server
 * @return {object} the mounted card
 */
function mountCard(settings = {}, groups = GROUPS) {
	return mount(SectionsSection, { props: { settings, groups }, global: { stubs } })
}

const switches = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
const picker = (wrapper) => wrapper.findComponent({ name: 'NcSelect' })
const saveButton = (wrapper) => wrapper.findComponent({ name: 'NcButton' })

/**
 * @param {object} wrapper the mounted card
 * @return {object} what each switch stands at, by the section it names
 */
function state(wrapper) {
	return Object.fromEntries(switches(wrapper).map((box) => [box.text(), box.props('modelValue')]))
}

describe('the sections card', () => {
	beforeEach(() => {
		post.mockReset().mockResolvedValue({ data: {} })
		showError.mockReset()
		showSuccess.mockReset()
	})

	/**
	 * Everything an instance offers is on unless it was deliberately turned
	 * off, so an instance that has never opened this page offers all of it.
	 */
	it('shows everything as on when nothing was ever set', () => {
		expect(state(mountCard())).toEqual({
			'24-hour shorts': true,
			Photos: true,
			Videos: true,
		})
	})

	it('shows what was turned off as off', () => {
		const settings = { stories: false, section_photos: false, section_videos: true }

		expect(state(mountCard(settings))).toEqual({
			'24-hour shorts': false,
			Photos: false,
			Videos: true,
		})
	})

	it('calls the switch 24-hour shorts and says nothing of stories', () => {
		const wrapper = mountCard()

		expect(wrapper.text()).toContain('gone after 24 hours')
		expect(wrapper.text()).not.toMatch(/stor(y|ies)/i)
	})

	/**
	 * Turning a section off hides it; it does not touch what is already there,
	 * which is the question an administrator is actually asking.
	 */
	it('says that turning one off does not delete anything', () => {
		const wrapper = mountCard()

		expect(wrapper.text()).toContain('A post is not changed by its section being off')
	})

	/**
	 * One save for the card, not one per switch: the endpoint writes all of it
	 * or none of it, so four switches saving on their own would let a page be
	 * left half applied without anybody being told.
	 */
	it('saves the whole card at once', async () => {
		const wrapper = mountCard({ section_videos: false })

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith(ROUTE, {
			stories: true,
			photos: true,
			videos: false,
			groupLists: [],
		})
		expect(showSuccess).toHaveBeenCalledWith('Saved')
	})

	it('does not write anything until Save is pressed', async () => {
		const wrapper = mountCard()

		switches(wrapper)[0].vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(post).not.toHaveBeenCalled()
	})

	it('saves what the switches were moved to', async () => {
		const wrapper = mountCard()

		switches(wrapper)[0].vm.$emit('update:modelValue', false)
		await wrapper.vm.$nextTick()
		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(post.mock.calls[0][1].stories).toBe(false)
	})

	describe('the groups that become lists', () => {
		it('starts empty, because a group list tells the group who is in it', () => {
			const wrapper = mountCard()

			expect(picker(wrapper).props('modelValue')).toEqual([])
			expect(wrapper.text()).toContain('a group list tells everybody in the group who else is in it')
		})

		it('shows the chosen groups by name', () => {
			const wrapper = mountCard({ group_lists: ['staff'] })

			expect(picker(wrapper).props('modelValue')).toEqual([{ id: 'staff', name: 'Staff' }])
		})

		/** A group deleted since it was chosen: shown as its id, not dropped. */
		it('keeps a group whose name it can no longer look up', () => {
			const wrapper = mountCard({ group_lists: ['gone'] })

			expect(picker(wrapper).props('modelValue')).toEqual([{ id: 'gone', name: 'gone' }])
		})

		it('does not offer a group that is already chosen', () => {
			const wrapper = mountCard({ group_lists: ['staff'] })

			expect(picker(wrapper).props('options')).toEqual([{ id: 'marketing', name: 'Marketing' }])
		})

		it('saves the ids rather than the whole groups', async () => {
			const wrapper = mountCard({ group_lists: ['staff'] })

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(post.mock.calls[0][1].groupLists).toEqual(['staff'])
		})

		/**
		 * A server with no groups at all should say so, not answer "No results"
		 * — which reads as a search that found nothing rather than as there
		 * being nothing to search.
		 */
		it('says when this server has no groups', () => {
			const wrapper = mountCard({}, [])

			// the dropdown is only rendered once it is open, so the slot is
			// asked for its content rather than looked for in the page
			const empty = picker(wrapper).vm.$slots['no-options']
			expect(empty).toBeDefined()
			expect(empty()[0].children.trim()).toBe('This server has no groups')
		})
	})

	it('cannot be saved twice at once', async () => {
		const wrapper = mountCard()
		post.mockReturnValue(new Promise(() => {}))

		saveButton(wrapper).vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(saveButton(wrapper).props('disabled')).toBe(true)
	})

	describe('when the save is refused', () => {
		it('says what the server said', async () => {
			post.mockRejectedValue({ response: { data: { error: 'staff is not a group on this server' } } })
			const wrapper = mountCard()

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('staff is not a group on this server')
		})

		it('says something when the server said nothing', async () => {
			post.mockRejectedValue(new Error('offline'))
			const wrapper = mountCard()

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not save what this instance offers')
			expect(saveButton(wrapper).props('disabled')).toBe(false)
		})
	})
})
