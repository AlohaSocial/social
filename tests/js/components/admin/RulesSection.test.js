/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RulesSection from '../../../../src/components/admin/RulesSection.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess }))

const ROUTE = '/index.php/apps/social/moderation/rules'

const field = (wrapper) => wrapper.find('.rules__field textarea')
const saveButton = (wrapper) => wrapper.findComponent({ name: 'NcButton' })

/**
 * @param {string} rules what the server holds now
 * @return {Promise<object>} the mounted card, once the read has settled
 */
async function mountCard(rules = '') {
	get.mockResolvedValue({ data: { rules } })
	const wrapper = mount(RulesSection)
	await flushPromises()

	return wrapper
}

/**
 * The rules an instance asks people to follow: one per line, in the same app
 * value `occ config:app:set social rules` writes and `/api/v1/instance/rules`
 * reads — so this is a place to edit them, not a second way of keeping them.
 */
describe('the rules card', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: { rules: '' } })
		post.mockReset().mockResolvedValue({ data: { rules: '' } })
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('shows the rules this server already has', async () => {
		const wrapper = await mountCard('Be kind.\nNo harassment.')

		expect(field(wrapper).element.value).toBe('Be kind.\nNo harassment.')
	})

	/** Why it is worth filling in: clients and other servers both read it. */
	it('says who will read them', async () => {
		expect((await mountCard()).find('.rules__readers').text())
			.toContain('Every app shows them to somebody deciding whether to join')
	})

	/** A blank field with no example is a page most administrators skip. */
	it('shows an example instead of an empty box', async () => {
		const placeholder = field(await mountCard()).attributes('placeholder')

		expect(placeholder.split('\n')).toHaveLength(2)
		expect(placeholder).toContain('Be kind')
	})

	it('saves what was typed', async () => {
		const wrapper = await mountCard()
		await field(wrapper).setValue('One rule.')

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(ROUTE, { rules: 'One rule.' })
		expect(showSuccess).toHaveBeenCalledWith('The rules were saved')
	})

	/**
	 * The server normalises what it is given — blank lines dropped, ends
	 * trimmed — so the field shows what was actually stored rather than what
	 * was typed at it.
	 */
	it('shows back what the server stored, not what was typed', async () => {
		post.mockResolvedValue({ data: { rules: 'One rule.' } })
		const wrapper = await mountCard()
		await field(wrapper).setValue('\n\nOne rule.  \n\n')

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(field(wrapper).element.value).toBe('One rule.')
	})

	it('confirms in place as well as in a toast', async () => {
		const wrapper = await mountCard()

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.find('.social-admin__hint').text()).toBe('Saved')
		expect(wrapper.find('.social-admin__hint').attributes('role')).toBe('status')
	})

	it('cannot be typed in or saved twice while it saves', async () => {
		const wrapper = await mountCard()
		post.mockReturnValue(new Promise(() => {}))

		saveButton(wrapper).vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(saveButton(wrapper).props('disabled')).toBe(true)
		expect(wrapper.findComponent({ name: 'NcTextArea' }).props('disabled')).toBe(true)
	})

	describe('when it does not go through', () => {
		it('says what the server said, and keeps what was typed', async () => {
			post.mockRejectedValue({ response: { data: { error: 'A rule is longer than the limit' } } })
			const wrapper = await mountCard()
			await field(wrapper).setValue('a very long rule')

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.social-admin__hint').text()).toBe('A rule is longer than the limit')
			expect(showError).toHaveBeenCalledWith('A rule is longer than the limit')
			expect(field(wrapper).element.value).toBe('a very long rule')
		})

		it('says something when the server said nothing', async () => {
			post.mockRejectedValue(new Error('offline'))
			const wrapper = await mountCard()

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.social-admin__hint').text()).toBe('Could not save the rules')
		})

		/** The failure the administrator has already seen and acted on. */
		it('clears the last failure when it is tried again', async () => {
			post.mockRejectedValue(new Error('offline'))
			const wrapper = await mountCard()
			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			post.mockResolvedValue({ data: { rules: '' } })
			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.social-admin__hint').text()).toBe('Saved')
		})
	})

	it('says so when the rules could not be read', async () => {
		get.mockRejectedValue(new Error('offline'))
		mount(RulesSection)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not load the rules')
	})

	/** An instance with no rules yet: an empty field, not the word "null". */
	it('starts empty when the server holds nothing', async () => {
		get.mockResolvedValue({ data: {} })
		const wrapper = mount(RulesSection)
		await flushPromises()

		expect(field(wrapper).element.value).toBe('')
	})
})
