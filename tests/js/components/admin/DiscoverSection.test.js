/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DiscoverSection from '../../../../src/components/admin/DiscoverSection.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))

const ROUTE = '/index.php/apps/social/moderation/discover/categories'
const stubs = {
	NcEmptyContent: { props: ['name', 'description'], template: '<div class="empty">{{ name }} {{ description }}</div>' },
}

const ARCHITECTURE = { id: 1, name: 'Architecture', hashtags: ['brutalism', 'concrete'] }

/**
 * @param {object[]} categories the subjects the server holds
 * @return {Promise<object>} the mounted card, once the read has settled
 */
async function mountCard(categories = []) {
	get.mockResolvedValue({ data: { categories } })
	const wrapper = mount(DiscoverSection, { global: { stubs } })
	await flushPromises()

	return wrapper
}

const rows = (wrapper) => wrapper.findAll('.discover__item')
const nameField = (wrapper) => wrapper.find('.discover__name input')
const tagsField = (wrapper) => wrapper.find('.discover__tags input')
const addButton = (wrapper) => wrapper.findAllComponents({ name: 'NcButton' })[0]

/**
 * The half of Explore an administrator chooses rather than counts. Trending on
 * a small server is four hashtags and a wedding; this is what makes the page
 * look like somewhere to start rather than somewhere abandoned.
 */
describe('the Explore subjects card', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: { categories: [] } })
		post.mockReset().mockResolvedValue({ data: { categories: [] } })
		del.mockReset().mockResolvedValue({ data: { categories: [] } })
		showError.mockReset()
	})

	it('lists each subject with the hashtags it means', async () => {
		const wrapper = await mountCard([ARCHITECTURE])

		expect(rows(wrapper)).toHaveLength(1)
		expect(rows(wrapper)[0].find('strong').text()).toBe('Architecture')
		expect(rows(wrapper)[0].find('.discover__item-tags').text()).toBe('#brutalism #concrete')
	})

	/** Says what an empty list means for the page it feeds, not just "empty". */
	it('says what Explore looks like while nothing is named', async () => {
		const wrapper = await mountCard()

		expect(rows(wrapper)).toHaveLength(0)
		expect(wrapper.find('.empty').text()).toContain('Explore shows only what is trending')
	})

	it('adds a subject and shows it', async () => {
		const wrapper = await mountCard()
		post.mockResolvedValue({ data: { categories: [ARCHITECTURE] } })

		await nameField(wrapper).setValue('Architecture')
		await tagsField(wrapper).setValue('#brutalism #concrete')
		addButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(ROUTE, {
			name: 'Architecture',
			hashtags: '#brutalism #concrete',
		})
		expect(rows(wrapper)).toHaveLength(1)
	})

	it('takes the slack of typing off both fields', async () => {
		const wrapper = await mountCard()

		await nameField(wrapper).setValue('  Architecture  ')
		await tagsField(wrapper).setValue('  #brutalism  ')
		addButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(ROUTE, { name: 'Architecture', hashtags: '#brutalism' })
	})

	/** Ready for the next one without the last one still sitting in the field. */
	it('empties the fields once the subject is added', async () => {
		const wrapper = await mountCard()

		await nameField(wrapper).setValue('Architecture')
		await tagsField(wrapper).setValue('#brutalism')
		addButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(nameField(wrapper).element.value).toBe('')
		expect(tagsField(wrapper).element.value).toBe('')
	})

	/** A subject with no name is a blank heading on Explore. */
	it('cannot add a subject with no name', async () => {
		const wrapper = await mountCard()

		expect(addButton(wrapper).props('disabled')).toBe(true)

		await nameField(wrapper).setValue('   ')
		expect(addButton(wrapper).props('disabled')).toBe(true)

		await nameField(wrapper).setValue('Architecture')
		expect(addButton(wrapper).props('disabled')).toBe(false)
	})

	/** A subject can be named without hashtags settled yet. */
	it('can add a subject before its hashtags are chosen', async () => {
		const wrapper = await mountCard()

		await nameField(wrapper).setValue('Architecture')
		addButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(ROUTE, { name: 'Architecture', hashtags: '' })
	})

	it('removes a subject', async () => {
		const wrapper = await mountCard([ARCHITECTURE])

		rows(wrapper)[0].findComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(ROUTE, { data: { id: 1 } })
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('cannot be added to or removed from twice at once', async () => {
		const wrapper = await mountCard([ARCHITECTURE])
		del.mockReturnValue(new Promise(() => {}))

		rows(wrapper)[0].findComponent({ name: 'NcButton' }).vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.findAllComponents({ name: 'NcButton' })
			.every((button) => button.props('disabled'))).toBe(true)
		expect(wrapper.findComponent({ name: 'NcTextField' }).props('disabled')).toBe(true)
	})

	describe('when something goes wrong', () => {
		it('passes on what the server refused for', async () => {
			const wrapper = await mountCard()
			post.mockRejectedValue({ response: { data: { error: 'That subject already exists' } } })

			await nameField(wrapper).setValue('Architecture')
			addButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('That subject already exists')
		})

		/** What was typed stays where it is, to be tried again or corrected. */
		it('keeps what was typed when the add fails', async () => {
			const wrapper = await mountCard()
			post.mockRejectedValue(new Error('offline'))

			await nameField(wrapper).setValue('Architecture')
			addButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not add that subject')
			expect(nameField(wrapper).element.value).toBe('Architecture')
		})

		it('keeps the subject when the removal fails', async () => {
			const wrapper = await mountCard([ARCHITECTURE])
			del.mockRejectedValue(new Error('offline'))

			rows(wrapper)[0].findComponent({ name: 'NcButton' }).vm.$emit('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not remove that subject')
			expect(rows(wrapper)).toHaveLength(1)
		})

		it('says so when the subjects could not be read', async () => {
			get.mockRejectedValue(new Error('offline'))
			mount(DiscoverSection, { global: { stubs } })
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not load the subjects')
		})
	})
})
