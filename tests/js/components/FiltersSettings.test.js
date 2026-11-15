/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import FiltersSettings from '../../../src/components/FiltersSettings.vue'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v2'

// NcActions only renders its entries in a popover once it is opened; these
// stand-ins put them inline, next to the row's own buttons
const NcActionsStub = { name: 'NcActions', template: '<div class="filter-menu"><slot /></div>' }
const NcActionButtonStub = {
	name: 'NcActionButton',
	emits: ['click'],
	template: '<button class="filter-menu__item" @click="$emit(\'click\')"><slot /></button>',
}

/** In an hour, or an hour ago, as the API writes a date. */
const iso = (offsetMs) => new Date(Date.now() + offsetMs).toISOString().replace(/\.\d+Z$/, '.000Z')

/** @param {object} overrides what to change about it */
function filter(overrides = {}) {
	return {
		id: '7',
		title: 'Politics',
		context: ['home', 'public'],
		filter_action: 'hide',
		expires_at: null,
		keywords: [{ id: '11', keyword: 'election', whole_word: false }],
		statuses: [],
		...overrides,
	}
}

/** @param {object[]} filters what `GET /api/v2/filters` answers with */
async function mountFilters(filters = []) {
	axios.get.mockResolvedValue({ data: filters })
	const wrapper = mount(FiltersSettings, {
		global: {
			stubs: {
				NcDialog: true,
				NcActions: NcActionsStub,
				NcActionButton: NcActionButtonStub,
			},
		},
	})
	await flushPromises()

	return wrapper
}

const rows = (wrapper) => wrapper.findAll('.filters__item')
const rowFor = (wrapper, title) => rows(wrapper).find((row) => row.text().includes(title))
const buttonIn = (root, label) => root.findAll('button').find((button) => button.text() === label)

/**
 * Fills in the open form and submits it.
 *
 * @param {object} wrapper the mounted section
 * @param {object} what the words to type
 */
async function fillIn(wrapper, { title = 'Politics', word = 'election', where = 'My Feed' } = {}) {
	await wrapper.find('.filter-form__title input').setValue(title)
	await wrapper.find('.filter-form__word input').setValue(word)
	await wrapper.findAll('.filter-form__choice')
		.find((choice) => choice.text().includes(where))
		.find('input')
		.setValue(true)
	await wrapper.find('form').trigger('submit')
	await flushPromises()
}

describe('FiltersSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks for the filters as it opens, over the v2 API', async () => {
		await mountFilters()

		expect(axios.get).toHaveBeenCalledWith(`${API}/filters`)
	})

	it('says what a filter is when there are none', async () => {
		const wrapper = await mountFilters()

		expect(wrapper.text()).toContain('You have no filters.')
		expect(wrapper.findComponent({ name: 'QuietTimeline' }).exists()).toBe(true)
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('shows a filter as what it does, where, and for how long', async () => {
		const wrapper = await mountFilters([filter()])

		const row = rowFor(wrapper, 'Politics')

		expect(row.text()).toContain('taken out of your timelines')
		expect(row.find('.filters__word').text()).toBe('election')
		expect(row.text()).toContain('In My Feed, Local, Global and hashtag timelines')
		expect(row.text()).toContain('Never expires')
	})

	it('marks the words that only match whole ones', async () => {
		const wrapper = await mountFilters([filter({
			keywords: [{ id: '11', keyword: 'cat', whole_word: true }],
		})])

		expect(wrapper.find('.filters__word').text()).toBe('cat (whole word)')
	})

	it('says a warn filter folds the post rather than removing it', async () => {
		const wrapper = await mountFilters([filter({ filter_action: 'warn' })])

		expect(rowFor(wrapper, 'Politics').text()).toContain('folded away behind this filter’s name')
	})

	/**
	 * The point of the section: a filter somebody set from a phone months ago
	 * has been taking posts out of this timeline with nothing on any page
	 * saying so, and a conversation with a hole in it is what that looks like.
	 */
	it('says at the top which filters are taking posts away right now', async () => {
		const wrapper = await mountFilters([filter()])

		const note = wrapper.find('.notecard')

		expect(note.text()).toContain('1 filter is taking posts out of what you read.')
		expect(note.text()).toContain('Politics — in My Feed, Local, Global and hashtag timelines')
	})

	it('says nothing of the sort when nothing is being taken away', async () => {
		const wrapper = await mountFilters([filter({ filter_action: 'warn' })])

		expect(wrapper.find('.notecard').exists()).toBe(false)
	})

	it('does not count a filter that has expired, because it is not applying', async () => {
		const wrapper = await mountFilters([filter({ expires_at: iso(-3600 * 1000) })])

		expect(wrapper.find('.notecard').exists()).toBe(false)
		expect(rowFor(wrapper, 'Politics').text()).toContain('Expired')
		expect(rowFor(wrapper, 'Politics').classes()).toContain('filters__item--inactive')
	})

	it('shows when a filter that is still running stops', async () => {
		const wrapper = await mountFilters([filter({ expires_at: iso(3600 * 1000) })])

		expect(rowFor(wrapper, 'Politics').text()).toContain('Until ')
		expect(rowFor(wrapper, 'Politics').classes()).not.toContain('filters__item--inactive')
	})

	/**
	 * A filter made by a client that named no context this server knows. It
	 * does nothing, and a row that looked like every other row would leave the
	 * reader wondering why it never matched anything.
	 */
	it('says so when a filter applies nowhere', async () => {
		const wrapper = await mountFilters([filter({ context: [] })])

		expect(rowFor(wrapper, 'Politics').text()).toContain('Nowhere')
	})

	it('creates a filter and reads the list back', async () => {
		const wrapper = await mountFilters()
		axios.post.mockResolvedValue({ data: filter() })

		await buttonIn(wrapper, 'Add a filter').trigger('click')
		axios.get.mockResolvedValue({ data: [filter()] })
		await fillIn(wrapper)

		expect(axios.post).toHaveBeenCalledWith(`${API}/filters`, {
			title: 'Politics',
			context: ['home'],
			filter_action: 'warn',
			keywords_attributes: [{ keyword: 'election', whole_word: false }],
			expires_in: '',
		})
		// read back rather than pushed: the server decides the order, and the
		// keyword comes back with the id an edit will need
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(rowFor(wrapper, 'Politics')).toBeDefined()
	})

	it('changes a filter in place, by its own id', async () => {
		const wrapper = await mountFilters([filter()])
		axios.put.mockResolvedValue({ data: filter({ title: 'Elections' }) })

		await buttonIn(rowFor(wrapper, 'Politics'), 'Edit').trigger('click')
		await wrapper.find('.filter-form__title input').setValue('Elections')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(`${API}/filters/7`, expect.objectContaining({
			title: 'Elections',
			context: ['home', 'public'],
			filter_action: 'hide',
			keywords_attributes: [{ id: '11', keyword: 'election', whole_word: false }],
		}))
	})

	it('opens the form on the filter that was clicked, and closes it again', async () => {
		const wrapper = await mountFilters([filter()])

		await buttonIn(rowFor(wrapper, 'Politics'), 'Edit').trigger('click')

		expect(wrapper.find('.filter-form__title input').element.value).toBe('Politics')

		await buttonIn(rowFor(wrapper, 'Politics'), 'Edit').trigger('click')

		expect(wrapper.find('.filter-form').exists()).toBe(false)
	})

	it('deletes a filter once the confirmation has agreed', async () => {
		const wrapper = await mountFilters([filter()])
		axios.delete.mockResolvedValue({ data: {} })

		await rowFor(wrapper, 'Politics').find('.filter-menu__item').trigger('click')
		// the dialog is stubbed; its buttons are a prop rather than markup
		const confirm = wrapper.findComponent({ name: 'NcDialog' }).props('buttons')
			.find((button) => button.label === 'Delete')
		axios.get.mockResolvedValue({ data: [] })
		await confirm.callback()
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${API}/filters/7`)
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('cancels out of the form without writing anything', async () => {
		const wrapper = await mountFilters()

		await buttonIn(wrapper, 'Add a filter').trigger('click')
		await buttonIn(wrapper, 'Cancel').trigger('click')

		expect(wrapper.find('.filter-form').exists()).toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
	})

	describe('when the server says no', () => {
		it('repeats what a refused filter was refused for', async () => {
			const wrapper = await mountFilters()
			axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'title is required' } } })

			await buttonIn(wrapper, 'Add a filter').trigger('click')
			await fillIn(wrapper)

			expect(showError).toHaveBeenCalledWith({ text: 'Could not create the filter', details: 'title is required' })
			// still open, with what was typed in it, so it can be corrected
			expect(wrapper.find('.filter-form__title input').element.value).toBe('Politics')
		})

		it('says something of its own when the server said nothing', async () => {
			const wrapper = await mountFilters()
			axios.post.mockRejectedValue(new Error('network'))

			await buttonIn(wrapper, 'Add a filter').trigger('click')
			await fillIn(wrapper)

			expect(showError).toHaveBeenCalledWith({ text: 'Could not create the filter', details: '' })
		})

		it('says so when the filters cannot be read at all', async () => {
			axios.get.mockRejectedValue(new Error('network'))
			mount(FiltersSettings, { global: { stubs: { NcDialog: true, NcActions: NcActionsStub } } })
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not load your filters')
		})
	})
})
