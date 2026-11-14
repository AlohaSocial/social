/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import AccessSection from '../../../../src/components/admin/AccessSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const FEDIVERSE = '/index.php/apps/social/moderation/fediverse'

/**
 * One entry of a block list, as `Admin::DomainBlock`.
 *
 * @param {string} domain the instance
 * @param {object} fields what differs from a plain block
 * @return {object} the entry
 */
function entry(domain, fields = {}) {
	return { domain, severity: 'suspend', private_comment: null, public_comment: null, ...fields }
}

/**
 * @param {object} props the list and which way round it is read
 * @return {object} the mounted section
 */
function mountAccess(props = {}) {
	return mount(AccessSection, {
		props: {
			accessType: 'all_but',
			addresses: ['noisy.example'],
			blocks: [entry('noisy.example')],
			...props,
		},
	})
}

describe('the fediverse access section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('shows the list the server is keeping', () => {
		expect(mountAccess().text()).toContain('noisy.example')
	})

	it('says so when nothing is on the list', () => {
		expect(mountAccess({ addresses: [], blocks: [] }).text()).toContain('No instance is on the list.')
	})

	it('starts on the mode the instance is in', () => {
		expect(mountAccess({ accessType: 'none_but' }).vm.mode.id).toBe('none_but')
	})

	it('switches between an allow list and a block list, and redraws the list', async () => {
		axios.post.mockResolvedValue({ data: { accessType: 'none_but', list: ['noisy.example'], blocks: [] } })
		const wrapper = mountAccess()

		await wrapper.vm.saveMode({ id: 'none_but' })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(FEDIVERSE + '/access', { type: 'none_but' })
		expect(wrapper.vm.entries).toEqual([])
	})

	it('says whether each entry is blocked or silenced, and why', () => {
		const wrapper = mountAccess({
			addresses: ['noisy.example'],
			blocks: [
				entry('noisy.example', { public_comment: 'Spam', private_comment: 'ticket 42' }),
				entry('loud.example', { severity: 'silence' }),
			],
		})

		expect(wrapper.text()).toContain('Blocked')
		expect(wrapper.text()).toContain('Silenced')
		expect(wrapper.text()).toContain('Public reason: Spam')
		expect(wrapper.text()).toContain('Note for moderators: ticket 42')
	})

	it('adds an instance at the tier chosen, with its reasons, and redraws from the answer', async () => {
		axios.post.mockResolvedValue({
			data: { list: ['noisy.example'], blocks: [entry('noisy.example'), entry('worse.example', { severity: 'silence' })] },
		})
		const wrapper = mountAccess()

		wrapper.vm.address = '  worse.example '
		wrapper.vm.severity = 'silence'
		wrapper.vm.publicComment = ' Spam '
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(FEDIVERSE + '/add', {
			address: 'worse.example',
			severity: 'silence',
			privateComment: '',
			publicComment: 'Spam',
		})
		expect(wrapper.text()).toContain('worse.example')
		expect(wrapper.vm.address).toBe('')
		expect(wrapper.vm.severity).toBe('suspend')
		expect(wrapper.vm.publicComment).toBe('')
	})

	it('asks for no tier and no reason on an allow list', async () => {
		axios.post.mockResolvedValue({ data: { list: ['friend.example'], blocks: [] } })
		const wrapper = mountAccess({ accessType: 'none_but', blocks: [] })

		expect(wrapper.text()).not.toContain('What happens to it')

		wrapper.vm.address = 'friend.example'
		await wrapper.vm.add()

		expect(axios.post).toHaveBeenCalledWith(FEDIVERSE + '/add', { address: 'friend.example' })
		expect(wrapper.text()).toContain('friend.example')
	})

	it('adds nothing at all for an empty field', async () => {
		const wrapper = mountAccess()

		wrapper.vm.address = '   '
		await wrapper.vm.add()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('switches an entry and rewrites its reasons from its own form', async () => {
		axios.post.mockResolvedValue({
			data: { list: [], blocks: [entry('noisy.example', { severity: 'silence', public_comment: 'Noise' })] },
		})
		const wrapper = mountAccess({
			blocks: [entry('noisy.example', { public_comment: 'Spam', private_comment: 'ticket 42' })],
		})

		wrapper.vm.edit(wrapper.vm.entries[0])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.draft).toEqual({ severity: 'suspend', privateComment: 'ticket 42', publicComment: 'Spam' })

		wrapper.vm.draft.severity = 'silence'
		wrapper.vm.draft.publicComment = 'Noise'
		wrapper.vm.draft.privateComment = ''
		await wrapper.find('.access__form--edit').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(FEDIVERSE + '/update', {
			address: 'noisy.example',
			severity: 'silence',
			privateComment: '',
			publicComment: 'Noise',
		})
		expect(wrapper.vm.editing).toBeNull()
		expect(wrapper.text()).toContain('Silenced')
		expect(wrapper.text()).toContain('Public reason: Noise')
	})

	it('keeps the form open and says why when the server refuses an edit', async () => {
		const { showError } = await import('../../../../src/services/toast.js')
		axios.post.mockRejectedValue({ response: { data: { error: 'a reason is at most 1000 characters' } } })
		const wrapper = mountAccess()

		wrapper.vm.edit(wrapper.vm.entries[0])
		await wrapper.vm.save(wrapper.vm.entries[0])

		expect(wrapper.vm.editing).toBe('noisy.example')
		expect(showError).toHaveBeenCalledWith({ text: 'Could not save the instance', details: 'a reason is at most 1000 characters' })
	})

	it('removes an instance from the button in its row', async () => {
		axios.post.mockResolvedValue({ data: { list: [], blocks: [] } })
		const wrapper = mountAccess()

		const remove = wrapper.findAll('.access__item button').find((button) => button.text() === 'Remove')
		await remove.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(FEDIVERSE + '/remove', { address: 'noisy.example' })
		expect(wrapper.text()).toContain('No instance is on the list.')
	})

	it('follows the list when another section changes it', async () => {
		const wrapper = mountAccess()

		await wrapper.setProps({ addresses: ['noisy.example', 'other.example'], blocks: [entry('noisy.example'), entry('other.example')] })

		expect(wrapper.text()).toContain('other.example')
	})
})
