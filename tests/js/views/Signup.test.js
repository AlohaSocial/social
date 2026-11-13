/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createHash } from 'node:crypto'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { leadingZeroBits } from '../../../src/utils/proofOfWork.js'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
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
	signupNotice: 'Instance registration notice',
	signupNoticeRequired: false,
	signupNoticeVersion: 'v1',
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
		get.mockReset()
	})

	it('solves the proof of work while the form is filled in and sends its answer', async () => {
		get.mockResolvedValue({ data: { challenge: 'challenge-1', bits: 8, expires: 0 } })
		post.mockResolvedValue({ data: { state: 'verify', handle: 'alice' } })
		const wrapper = await page({ ...OPEN, proofOfWork: true })
		expect(get).not.toHaveBeenCalled()
		await continueToForm(wrapper)
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/signup/challenge')

		await wrapper.find('form').trigger('submit')
		await vi.waitFor(() => expect(post).toHaveBeenCalled())

		const sent = post.mock.calls[0][1]
		expect(sent.challenge).toBe('challenge-1')
		const digest = createHash('sha256').update('challenge-1' + sent.nonce).digest()
		expect(leadingZeroBits(new Uint8Array(digest))).toBeGreaterThanOrEqual(8)
	})

	it('starts a new proof of work after a refusal, since the challenge is used up', async () => {
		get.mockResolvedValueOnce({ data: { challenge: 'first', bits: 4, expires: 0 } })
			.mockResolvedValueOnce({ data: { challenge: 'second', bits: 4, expires: 0 } })
		post.mockRejectedValue({ response: { status: 422, data: { message: 'This username is already taken.', field: 'handle' } } })
		const wrapper = await page({ ...OPEN, proofOfWork: true })
		await continueToForm(wrapper)
		await wrapper.find('form').trigger('submit')
		await vi.waitFor(() => expect(get).toHaveBeenCalledTimes(2))

		expect(post.mock.calls[0][1].challenge).toBe('first')
		expect(wrapper.text()).toContain('This username is already taken.')
	})

	it('says so when no challenge could be had, and sends nothing', async () => {
		get.mockRejectedValue(new Error('offline'))
		const wrapper = await page({ ...OPEN, proofOfWork: true })
		await continueToForm(wrapper)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(post).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('The check of this browser did not succeed. Please try again.')
	})

	it('shows how far along the puzzle is while a sent form waits for it', async () => {
		const wrapper = await page(OPEN)
		await continueToForm(wrapper)
		Object.assign(wrapper.vm, { solving: true, powBits: 20, powAttempts: 2 ** 20 })
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[role="status"]').text()).toBe('Your browser is solving a small puzzle that keeps automated registrations out: 63%')
	})

	it('says why it is closed and offers the way back', async () => {
		const wrapper = await page({ ...OPEN, open: false, reason: 'This server accepts registrations by invitation only.' })

		expect(wrapper.text()).toContain('This server accepts registrations by invitation only.')
		expect(wrapper.find('form').exists()).toBe(false)
		expect(button(wrapper, 'Back to the login page').attributes('href')).toBe('/index.php/login')
	})

	it('shows the rules, the age to confirm and the address the handle becomes', async () => {
		const wrapper = await page(OPEN)
		expect(wrapper.text()).toContain('An Aloha Social account')
		expect(wrapper.text()).toContain('Your address will look like @username@cloud.example. You can follow and talk with people on Mastodon, Pixelfed, PeerTube and more.')
		expect(wrapper.text()).not.toMatch(/fediverse|ActivityPub|your name|A Aloha|a Aloha/)
		expect(wrapper.text()).not.toContain('Bluesky')
		expect(wrapper.text()).toContain('does not give you access to Files, Talk, WebDAV')
		expect(wrapper.text()).toContain('finish the introduction, find people and any starter packs this server offers')
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

	it('names the Bluesky address too, where this server issues one', async () => {
		const wrapper = await page({ ...OPEN, bluesky: { enabled: true, host: 'social.example' } })
		expect(wrapper.text()).toContain('Your address will look like @username@cloud.example, and @username.social.example on Bluesky. You can follow and talk with people on Mastodon, Bluesky, Pixelfed and more.')

		await continueToForm(wrapper)
		wrapper.vm.handle = 'Frank_K'
		await wrapper.vm.$nextTick()

		// the handle the server will issue, as HandleMapper::label() maps it
		expect(wrapper.text()).toContain('Your address will be @frank_k@cloud.example, and @frank-k.social.example on Bluesky')
	})

	it('explains the configured two-factor requirement before collecting details', async () => {
		const wrapper = await page({ ...OPEN, twoFactorRequired: true })
		expect(wrapper.text()).toContain('This server requires two-factor authentication.')
	})

	it('returns to the overview without submitting the form', async () => {
		const wrapper = await page(OPEN)
		await continueToForm(wrapper)

		const back = wrapper.find('.signup__back')
		expect(back.attributes('type')).toBe('button')
		await back.trigger('click')

		expect(wrapper.find('form').exists()).toBe(false)
		expect(wrapper.find('.signup__landing').exists()).toBe(true)
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
			notice: false,
			noticeVersion: 'v1',
			age: true,
			invite: 'tok',
			website: '',
		})
		expect(wrapper.text()).toContain('Check your email')
		expect(wrapper.text()).toContain('a@example.org')
		expect(wrapper.vm.password).toBe('')
	})

	it('requires and submits the configured notice acknowledgement', async () => {
		post.mockResolvedValue({ data: { state: 'verify', handle: 'alice' } })
		const wrapper = await page({ ...OPEN, signupNoticeRequired: true, signupNoticeVersion: 'notice-v2' })
		await continueToForm(wrapper)
		expect(wrapper.text()).toContain('I have read and accept this registration notice')
		Object.assign(wrapper.vm, { handle: 'alice', email: 'a@example.org', password: 'long enough', rules: true, age: true, noticeAccepted: true })
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/index.php/apps/social/signup', expect.objectContaining({
			notice: true,
			noticeVersion: 'notice-v2',
		}))
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
