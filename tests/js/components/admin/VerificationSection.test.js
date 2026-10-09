/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VerificationSection from '../../../../src/components/admin/VerificationSection.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess }))

const ROUTE = '/index.php/apps/social/moderation/verifications'
const VERIFIER_ROUTE = '/index.php/apps/social/admin/verifications/verifier'
const VERIFIER = { actor_id: 'https://cloud.example.org/users/company', account: 'company', name: 'Example Inc', did: 'did:plc:company' }
const ANNA = { actor_id: 'https://cloud.example.org/users/anna', account: 'anna', name: 'Anna', did: 'did:plc:anna', local: true, verified_by: 'admin', created_at: '2026-10-09T10:00:00.000Z' }
const BOB = { actor_id: 'https://remote.example/users/bob', account: 'bob@remote.example', name: 'Bob', did: '', local: false, verified_by: '', created_at: '2026-10-08T10:00:00.000Z' }

/**
 * @param {object} state what the server answers
 * @param {boolean} administrator whether the reader administers the server
 * @return {Promise<object>} the mounted section, once the read settled
 */
async function mountSection(state, administrator = true) {
	get.mockResolvedValue({ data: state })
	const wrapper = mount(VerificationSection, { props: { administrator } })
	await flushPromises()

	return wrapper
}

const buttonByText = (wrapper, text) => wrapper.findAllComponents({ name: 'NcButton' }).find((button) => button.text() === text)

describe('the verified accounts section', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		del.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('lists the verified accounts, who verified them, and the ones shown here only', async () => {
		const wrapper = await mountSection({ verifier: VERIFIER, publishing: true, verifications: [ANNA, BOB] })

		expect(get).toHaveBeenCalledWith(ROUTE)
		const items = wrapper.findAll('.verification__item')
		expect(items.map((item) => item.find('.verification__account').text())).toEqual(['@anna', '@bob@remote.example'])
		expect(items[0].text()).toContain('Verified by admin on')
		expect(items[0].text()).not.toContain('Shown here only')
		expect(items[1].text()).toContain('Shown here only')
		expect(wrapper.text()).toContain('Checks are given in the name of Example Inc (@company) and published.')
	})

	it('says when nobody is verified yet', async () => {
		expect((await mountSection({ verifier: null, publishing: false, verifications: [] })).text())
			.toContain('No account is verified yet.')
	})

	it('verifies an account by its handle, and lists it', async () => {
		const wrapper = await mountSection({ verifier: null, publishing: false, verifications: [] })
		post.mockResolvedValue({ data: { actor_id: ANNA.actor_id, verification: { by: 'Cloud', issuer: '', created_at: ANNA.created_at } } })
		get.mockResolvedValue({ data: { verifier: null, publishing: false, verifications: [ANNA] } })

		await wrapper.findAll('form')[1].find('input').setValue(' anna ')
		await wrapper.findAll('form')[1].trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(ROUTE, { account: 'anna' })
		expect(wrapper.findAll('.verification__item')).toHaveLength(1)
		expect(showSuccess).toHaveBeenCalledWith('The account is verified')
	})

	it('says what the server refused', async () => {
		const wrapper = await mountSection({ verifier: null, publishing: false, verifications: [] })
		post.mockRejectedValue({ response: { status: 404, data: { error: 'No account goes by that name' } } })

		await wrapper.findAll('form')[1].find('input').setValue('nobody')
		await wrapper.findAll('form')[1].trigger('submit')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('No account goes by that name')
	})

	it('takes a verification back', async () => {
		const wrapper = await mountSection({ verifier: null, publishing: false, verifications: [ANNA, BOB] })
		del.mockResolvedValue({ data: { actor_id: BOB.actor_id, verification: null } })

		await buttonByText(wrapper.findAll('.verification__item')[1], 'Remove').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(ROUTE, { data: { account: BOB.actor_id } })
		expect(wrapper.findAll('.verification__item')).toHaveLength(1)
	})

	it('lets an administrator choose the verifying account', async () => {
		const wrapper = await mountSection({ verifier: null, publishing: false, verifications: [] })
		post.mockResolvedValue({ data: { verifier: VERIFIER, publishing: true } })

		await wrapper.findAll('form')[0].find('input').setValue('company')
		await wrapper.findAll('form')[0].trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(VERIFIER_ROUTE, { account: 'company' })
		expect(wrapper.text()).toContain('Checks are given in the name of Example Inc (@company) and published.')
		expect(wrapper.text()).toContain('Bluesky apps show those checks only once Bluesky trusts the account as a verifier')
	})

	/** The verifying account speaks for the instance: a delegate does not choose it. */
	it('does not offer a delegated moderator to choose the verifying account', async () => {
		const wrapper = await mountSection({ verifier: VERIFIER, publishing: false, verifications: [] }, false)

		expect(wrapper.findAll('form')).toHaveLength(1)
		expect(wrapper.text()).toContain('Checks are given in the name of Example Inc (@company) and shown here only.')
	})
})
