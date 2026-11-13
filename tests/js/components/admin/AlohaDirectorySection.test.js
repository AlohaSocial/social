/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AlohaDirectorySection from '../../../../src/components/admin/AlohaDirectorySection.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

function section(overrides = {}) {
	return {
		domain: 'cloud.example.com',
		directoryUrl: 'https://directory.aloha.social',
		participation: { phase: 'none' },
		settings: { advertise: false, advertisedAccounts: null, alohaList: true, listMirror: '' },
		signup: { enabled: true, open: true, mode: 'approval', freeAccounts: 40 },
		profile: { form: {}, sent: { identity: { name: 'Cloud Social' } }, missing: [] },
		lists: {
			rootKeyConfigured: true,
			checkedAt: 0,
			okAt: 0,
			error: '',
			lists: { keys: { serial: 7, fetchedAt: 1794586000, tooOld: false }, servers: { serial: 0, fetchedAt: 0, tooOld: false } },
		},
		publishedEntry: null,
		...overrides,
	}
}

function buttonByText(wrapper, text) {
	return wrapper.findAll('button').find((button) => button.text() === text)
}

describe('the Aloha Directory section', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: section() })
		post.mockReset().mockResolvedValue({ data: section() })
	})

	it('asks to be listed once sign-ups are open and the profile is complete', async () => {
		post.mockResolvedValue({ data: section({ participation: { phase: 'verifying', verification: { status: 'queued' } } }) })
		const wrapper = mount(AlohaDirectorySection)
		await flushPromises()

		const enrol = buttonByText(wrapper, 'Ask to be listed')
		expect(enrol.attributes('disabled')).toBeUndefined()
		await enrol.trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/apps/social/admin/aloha-directory/enrol'), {})
		expect(wrapper.text()).toContain('The directory is checking this server')
	})

	it('names what the profile still needs and does not enrol without it', async () => {
		get.mockResolvedValue({ data: section({ profile: { form: {}, sent: {}, missing: ['operator.name', 'moderation.moderators'] } }) })
		const wrapper = mount(AlohaDirectorySection)
		await flushPromises()

		expect(wrapper.text()).toContain('operator.name, moderation.moderators')
		expect(buttonByText(wrapper, 'Ask to be listed').attributes('disabled')).toBeDefined()
	})

	it('shows a failed verification and lets it be retried', async () => {
		get.mockResolvedValue({ data: section({ participation: { phase: 'pending', verification: { status: 'failed', message: 'wrong challenge' } } }) })
		const wrapper = mount(AlohaDirectorySection)
		await flushPromises()

		expect(wrapper.text()).toContain('wrong challenge')
		await buttonByText(wrapper, 'Check the domain again').trigger('click')
		expect(post).toHaveBeenCalledWith(expect.stringContaining('/admin/aloha-directory/verify'), {})
	})

	it('shows the listing, failing checks and the curators, and sends an answer', async () => {
		get.mockResolvedValue({
			data: section({
				participation: {
					phase: 'enrolled',
					listing: 'in_review',
					heartbeat: { at: 1794586000, failures: 0, error: '' },
					status: {
						state: 'in_review',
						checks: [{ name: 'terms', passed: false, message: 'The privacy policy does not load.' }],
						comments: [{ id: 1, from: 'curators', body: 'Who funds the server?' }],
						complaints: [],
					},
				},
			}),
		})
		const wrapper = mount(AlohaDirectorySection)
		await flushPromises()

		expect(wrapper.text()).toContain('Waiting for the curators')
		expect(wrapper.text()).toContain('The privacy policy does not load.')
		expect(wrapper.text()).toContain('Who funds the server?')

		await wrapper.find('textarea').setValue('Membership fees.')
		await buttonByText(wrapper, 'Send').trigger('click')
		expect(post).toHaveBeenCalledWith(expect.stringContaining('/admin/aloha-directory/comments'), { body: 'Membership fees.' })
	})

	it('shows the directory refusing a profile field by field', async () => {
		post.mockRejectedValue({
			response: {
				status: 422,
				data: { ...section(), error: 'Some fields are invalid.' },
			},
		})
		const wrapper = mount(AlohaDirectorySection)
		await flushPromises()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/admin/aloha-directory/profile'), expect.objectContaining({ profile: expect.any(Object) }))
		expect(wrapper.text()).toContain('Some fields are invalid.')
	})

	it('says when no root key is configured, so no list is read', async () => {
		get.mockResolvedValue({ data: section({ lists: { ...section().lists, rootKeyConfigured: false } }) })
		const wrapper = mount(AlohaDirectorySection)
		await flushPromises()

		expect(wrapper.text()).toContain('No root key of the Aloha Directory is configured yet')
		expect(buttonByText(wrapper, 'Read the lists now').attributes('disabled')).toBeDefined()
	})
})
