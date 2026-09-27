/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import ExternalAccountsSection from '../../../../src/components/admin/ExternalAccountsSection.vue'
import ExternalRequestsSection from '../../../../src/components/admin/ExternalRequestsSection.vue'
import ExternalUsersSection from '../../../../src/components/admin/ExternalUsersSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const { confirmPassword } = vi.hoisted(() => ({ confirmPassword: vi.fn() }))
vi.mock('../../../../src/services/externalApi.js', async (original) => ({
	...(await original()),
	confirmPassword,
}))

const ADMIN = '/index.php/apps/social/admin/external'

function external(overrides = {}) {
	return {
		settings: {
			enabled: true,
			max: 100,
			quota: 1024,
			mode: 'approval',
			verifyEmail: true,
			minAge: 16,
			reserved: ['ceo'],
			userInvites: false,
			count: 3,
			awaitingApproval: 1,
			restricted: false,
			restrictionIncludesExternals: true,
		},
		twoFactor: { enforced: false, everybody: false },
		requests: [{ id: 7, handle: 'dave', email: 'd@example.org', created: 1700000000 }],
		invites: [],
		users: [{ uid: 'alice', displayName: 'Alice', email: 'a@example.org', created: 1700000000, lastLogin: 0, enabled: true, mediaBytes: 2048, origin: 'open' }],
		...overrides,
	}
}

function button(wrapper, text) {
	return wrapper.findAll('button').find((element) => element.text() === text)
}

describe('the External users cards', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		confirmPassword.mockResolvedValue()
	})

	it('saves every setting as the server takes it', async () => {
		axios.post.mockResolvedValue({ data: external().settings })
		const wrapper = mount(ExternalUsersSection, { props: { external: external() } })
		wrapper.vm.form.max = '250'
		wrapper.vm.form.reserved = 'ceo\n  board \n\n'
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ADMIN, {
			enabled: true,
			max: 250,
			quota: 1024,
			mode: 'approval',
			verifyEmail: true,
			minAge: 16,
			reserved: ['ceo', 'board'],
			userInvites: false,
		})
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('warns when Social is restricted to groups that leave external users out', () => {
		const settings = { ...external().settings, restricted: true, restrictionIncludesExternals: false }
		const wrapper = mount(ExternalUsersSection, { props: { external: external({ settings }) } })

		expect(wrapper.text()).toContain('external users are not in them')
		expect(button(wrapper, 'Let external users open Social')).toBeDefined()
	})

	it('asks for the password before changing two-factor, and not at all when dismissed', async () => {
		axios.post.mockResolvedValue({ data: { enforced: true, everybody: false } })
		const wrapper = mount(ExternalUsersSection, { props: { external: external() } })

		await wrapper.vm.setTwoFactor(true)
		expect(confirmPassword).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(ADMIN + '/two-factor', { enforced: true })

		axios.post.mockClear()
		confirmPassword.mockRejectedValue(new Error('dismissed'))
		await wrapper.vm.setTwoFactor(false)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('approves a registration and hands the new state up', async () => {
		axios.post.mockResolvedValue({ data: external({ requests: [] }) })
		const wrapper = mount(ExternalRequestsSection, { props: { external: external() } })

		expect(wrapper.text()).toContain('@dave')
		await button(wrapper, 'Approve').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ADMIN + '/requests/7')
		expect(wrapper.emitted('changed')[0][0].requests).toEqual([])
	})

	it('rejects with the reason the administrator gave', async () => {
		axios.delete.mockResolvedValue({ data: external({ requests: [] }) })
		const wrapper = mount(ExternalRequestsSection, { props: { external: external() } })
		wrapper.vm.askReject({ id: 7, handle: 'dave' })
		wrapper.vm.reason = 'Not now'
		await wrapper.vm.reject()
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(ADMIN + '/requests/7', { data: { reason: 'Not now' } })
	})

	it('promotes an account only after asking twice', async () => {
		axios.post.mockResolvedValue({ data: external({ users: [] }) })
		const wrapper = mount(ExternalAccountsSection, { props: { external: external() } })

		expect(wrapper.text()).toContain('@alice')
		await button(wrapper, 'Promote').trigger('click')
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.asking).toEqual({ user: external().users[0], action: 'promote' })

		await wrapper.vm.confirmed()
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith(ADMIN + '/users/alice/promote')
		expect(wrapper.vm.users).toEqual([])
	})

	it('deletes nothing when the password is not given', async () => {
		confirmPassword.mockRejectedValue(new Error('dismissed'))
		const wrapper = mount(ExternalAccountsSection, { props: { external: external() } })
		wrapper.vm.ask(external().users[0], 'delete')
		await wrapper.vm.confirmed()

		expect(axios.delete).not.toHaveBeenCalled()
	})
})
