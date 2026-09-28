/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
const { state } = vi.hoisted(() => ({ state: { value: {} } }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (app === 'social' && key === 'signup') ? state.value : fallback,
}))

const OPEN = {
	open: true,
	reason: '',
	mode: 'approval',
	invited: false,
	inviteToken: '',
	approval: true,
	verifyEmail: true,
	twoFactorRequired: false,
	minAge: 16,
	rules: ['Be kind', 'No spam'],
	privacyUrl: 'https://cloud.example/privacy',
	legalUrl: '',
	domain: 'cloud.example',
	loginUrl: '/index.php/login',
}

async function page(pageState) {
	state.value = pageState
	const { default: Signup } = await import('../../../src/views/Signup.vue')

	return mount(Signup)
}

async function continueToForm(wrapper) {
	await button(wrapper, 'Continue to registration').trigger('click')
}

function button(wrapper, text) {
	return wrapper.findAll('button, a').find((element) => element.text() === text)
}

describe('the registration page', () => {
	beforeEach(() => {
		post.mockReset()
	})

	it('says why it is closed and offers the way back', async () => {
		const wrapper = await page({ ...OPEN, open: false, reason: 'This server accepts registrations by invitation only.' })

		expect(wrapper.text()).toContain('This server accepts registrations by invitation only.')
		expect(wrapper.find('form').exists()).toBe(false)
		expect(button(wrapper, 'Back to the login page').attributes('href')).toBe('/index.php/login')
	})

	it('shows the rules, the age to confirm and the address the handle becomes', async () => {
		const wrapper = await page(OPEN)
		expect(wrapper.text()).toContain('A Social account, connected to the fediverse')
		expect(wrapper.text()).toContain('does not give you access to Files, Talk, WebDAV')
		expect(wrapper.text()).toContain('finish the introduction, find people and starter packs')
		expect(wrapper.text()).not.toContain('This server requires two-factor authentication.')
		await continueToForm(wrapper)
		wrapper.vm.handle = '@Alice'
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Be kind')
		expect(wrapper.text()).toContain('No spam')
		expect(wrapper.text()).toContain('I am at least 16 years old')
		expect(wrapper.text()).toContain('@alice@cloud.example')
		expect(wrapper.text()).toContain('An administrator looks at every registration')
		expect(wrapper.find('a[href="https://cloud.example/privacy"]').exists()).toBe(true)
	})

	it('explains the configured two-factor requirement before collecting details', async () => {
		const wrapper = await page({ ...OPEN, twoFactorRequired: true })
		expect(wrapper.text()).toContain('This server requires two-factor authentication.')
	})

	it('sends everything, the honeypot and the invitation included', async () => {
		post.mockResolvedValue({ data: { state: 'verify', handle: 'alice' } })
		const wrapper = await page({ ...OPEN, invited: true, inviteToken: 'tok' })
		await continueToForm(wrapper)
		Object.assign(wrapper.vm, { handle: 'alice', email: 'a@example.org', password: 'long enough', rules: true, age: true })
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/index.php/apps/social/signup', {
			handle: 'alice',
			email: 'a@example.org',
			password: 'long enough',
			rules: true,
			age: true,
			invite: 'tok',
			website: '',
		})
		expect(wrapper.text()).toContain('Check your email')
		expect(wrapper.text()).toContain('a@example.org')
		expect(wrapper.vm.password).toBe('')
	})

	it('keeps the honeypot out of sight and out of the tab order', async () => {
		const wrapper = await page(OPEN)
		await continueToForm(wrapper)
		const trap = wrapper.find('input[name="website"]')

		expect(trap.attributes('tabindex')).toBe('-1')
		expect(trap.element.closest('[aria-hidden="true"]')).not.toBeNull()
	})

	it('puts a refusal on the field it is about', async () => {
		post.mockRejectedValue({ response: { status: 422, data: { message: 'This username is already taken.', field: 'handle' } } })
		const wrapper = await page(OPEN)
		await continueToForm(wrapper)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.vm.field).toBe('handle')
		expect(wrapper.text()).toContain('This username is already taken.')
	})

	it('says what the confirmation link came to', async () => {
		const created = await page({ ...OPEN, result: { state: 'created', handle: 'alice' } })
		expect(created.text()).toContain('Your account is ready')
		expect(button(created, 'Log in').attributes('href')).toBe('/index.php/login')

		const failed = await page({ ...OPEN, result: { state: 'error', message: 'This confirmation link is not valid any more.' } })
		expect(failed.text()).toContain('This confirmation link is not valid any more.')
		expect(button(failed, 'Register again')).toBeDefined()
	})
})
