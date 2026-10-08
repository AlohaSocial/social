/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import { confirmPassword } from '../../../src/services/externalApi.js'

import BlueskyMoveAway from '../../../src/components/BlueskyMoveAway.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/externalApi.js', () => ({ confirmPassword: vi.fn().mockResolvedValue(undefined) }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1/social/bluesky'

const notFound = () => Promise.reject(Object.assign(new Error('404'), { response: { status: 404, data: {} } }))

/** A move as the server keeps it. */
function moveOf(state, step = 'repo', extra = {}) {
	return {
		id: 3,
		direction: 'away',
		pds: 'https://pds.example',
		handle: 'alice.pds.example',
		step,
		state,
		progress: {},
		error: '',
		created: 1,
		updated: 2,
		...extra,
	}
}

/**
 * The server: an identity in `state` (or none), and the moves the GET answers
 * one after the other, the last one repeated.
 */
function server({ identity = 'active', moves = [null] } = {}) {
	let polls = 0
	axios.get.mockImplementation((url) => {
		if (url === `${API}/identity`) {
			return identity === null
				? notFound()
				: Promise.resolve({ data: { handle: 'alice.example.org', did: 'did:plc:alice', state: identity } })
		}
		if (url === `${API}/move`) {
			const move = moves[Math.min(polls, moves.length - 1)]
			polls++
			return Promise.resolve({ data: { move } })
		}
		return notFound()
	})
}

const movePolls = () => axios.get.mock.calls.filter(([url]) => url === `${API}/move`).length

async function mountCard() {
	const wrapper = mount(BlueskyMoveAway, { attachTo: document.body })
	await flushPromises()

	return wrapper
}

const buttonNamed = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)

/** The inputs in the order the form lists them: server, handle, e-mail, password, invite code. */
const inputs = (wrapper) => wrapper.findAll('.migration__bluesky-form input')

async function fill(wrapper, { pds = 'bsky.social', handle = 'alice.bsky.social', email = 'alice@example.org', password = 'hunter22', invite = '' } = {}) {
	const [pdsInput, handleInput, emailInput, passwordInput, inviteInput] = inputs(wrapper)
	await pdsInput.setValue(pds)
	await handleInput.setValue(handle)
	await emailInput.setValue(email)
	await passwordInput.setValue(password)
	await inviteInput.setValue(invite)
}

describe('BlueskyMoveAway', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
		document.body.innerHTML = ''
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('draws nothing when the server has no Bluesky identity for the person', async () => {
		server({ identity: null })
		const wrapper = await mountCard()

		expect(wrapper.find('section').exists()).toBe(false)
		expect(movePolls()).toBe(0)
	})

	it('draws nothing for an identity that is not active and never moved', async () => {
		server({ identity: 'deactivated' })
		const wrapper = await mountCard()

		expect(wrapper.find('section').exists()).toBe(false)
	})

	it('offers the form for an active identity', async () => {
		server()
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Move your Bluesky account away')
		expect(wrapper.text()).toContain('Your Bluesky account can live on another server (a PDS).')
		expect(inputs(wrapper)).toHaveLength(5)
		expect(inputs(wrapper)[3].attributes('type')).toBe('password')
		expect(wrapper.find('input[placeholder="bsky.social"]').exists()).toBe(true)
		expect(wrapper.find('input[placeholder="alice.bsky.social"]').exists()).toBe(true)
	})

	it('keeps the button off until server, handle, e-mail and password are there; the invite code is optional', async () => {
		server()
		const wrapper = await mountCard()
		const button = () => buttonNamed(wrapper, 'Move my Bluesky account')

		expect(button().attributes('disabled')).toBeDefined()
		await fill(wrapper, { password: '' })
		expect(button().attributes('disabled')).toBeDefined()
		await inputs(wrapper)[3].setValue('hunter22')
		expect(button().attributes('disabled')).toBeUndefined()
		await inputs(wrapper)[0].setValue('   ')
		expect(button().attributes('disabled')).toBeDefined()
	})

	it('asks first, then for the password, then posts exactly what the server takes', async () => {
		server()
		axios.post.mockResolvedValue({ data: { move: moveOf('running') } })
		const wrapper = await mountCard()
		await fill(wrapper, { pds: ' bsky.social ', invite: 'bsky-social-abc' })

		await buttonNamed(wrapper, 'Move my Bluesky account').trigger('click')
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Your Bluesky account is moved to bsky.social. This cannot be undone from here.')

		await buttonNamed(wrapper, 'Move it').trigger('click')
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(`${API}/move`, {
			pds: 'bsky.social',
			handle: 'alice.bsky.social',
			email: 'alice@example.org',
			password: 'hunter22',
			inviteCode: 'bsky-social-abc',
		})
		expect(wrapper.text()).toContain('Moving to https://pds.example …')
		expect(wrapper.find('.migration__bluesky-form').exists()).toBe(false)
	})

	it('sends nothing when the move is not confirmed', async () => {
		server()
		const wrapper = await mountCard()
		await fill(wrapper)

		await buttonNamed(wrapper, 'Move my Bluesky account').trigger('click')
		await buttonNamed(wrapper, 'Not now').trigger('click')

		expect(buttonNamed(wrapper, 'Move it')).toBeUndefined()
		expect(confirmPassword).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('sends nothing when the password confirmation is dismissed', async () => {
		server()
		confirmPassword.mockRejectedValueOnce(new Error('dismissed'))
		const wrapper = await mountCard()
		await fill(wrapper)

		await buttonNamed(wrapper, 'Move my Bluesky account').trigger('click')
		await buttonNamed(wrapper, 'Move it').trigger('click')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('asks to confirm on Enter in a field, and keeps the key from submitting a form around it', async () => {
		server()
		const wrapper = await mountCard()
		await fill(wrapper)

		const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
		inputs(wrapper)[1].element.dispatchEvent(event)
		await flushPromises()

		expect(event.defaultPrevented).toBe(true)
		expect(buttonNamed(wrapper, 'Move it')).toBeDefined()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('says why the server would not start the move, next to the form', async () => {
		server()
		axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'That server needs an invite code' } } })
		const wrapper = await mountCard()
		await fill(wrapper)

		await buttonNamed(wrapper, 'Move my Bluesky account').trigger('click')
		await buttonNamed(wrapper, 'Move it').trigger('click')
		await flushPromises()

		expect(wrapper.find('.migration__bluesky-error').text()).toBe('That server needs an invite code')
		expect(showError).not.toHaveBeenCalled()
		expect(wrapper.find('.migration__bluesky-form').exists()).toBe(true)
	})

	it('asks for the password again when the confirmation ran out', async () => {
		server()
		axios.post.mockRejectedValue({ response: { status: 403, data: {} } })
		const wrapper = await mountCard()
		await fill(wrapper)

		await buttonNamed(wrapper, 'Move my Bluesky account').trigger('click')
		await buttonNamed(wrapper, 'Move it').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Confirm your password again and retry.')
	})

	it('says so in a toast when the move could not be started for another reason', async () => {
		server()
		axios.post.mockRejectedValue({ response: { status: 500, data: {} } })
		const wrapper = await mountCard()
		await fill(wrapper)

		await buttonNamed(wrapper, 'Move my Bluesky account').trigger('click')
		await buttonNamed(wrapper, 'Move it').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not start the move')
	})

	it.each([
		['repo', {}, 'Copying your posts'],
		['blobs', { blobs: 12 }, 'Copying your pictures and videos (12 so far)'],
		['blobs', {}, 'Copying your pictures and videos'],
		['prefs', {}, 'Copying your settings'],
		['identity', {}, 'Pointing your account at the new server'],
		['activate', {}, 'Switching your account on there'],
	])('names the %s step of a running move in words', async (step, progress, words) => {
		server({ identity: 'active', moves: [moveOf('running', step, { progress })] })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Moving to https://pds.example …')
		expect(wrapper.find('.migration__bluesky-step').text()).toBe(words)
		expect(wrapper.find('.migration__bluesky-form').exists()).toBe(false)
		wrapper.unmount()
	})

	it('shows a move that runs even when the identity here is no longer active', async () => {
		server({ identity: 'deactivated', moves: [moveOf('running', 'activate')] })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Switching your account on there')
		wrapper.unmount()
	})

	it('asks every five seconds while the move runs, and stops once it is done', async () => {
		server({
			moves: [
				moveOf('running', 'repo'),
				moveOf('running', 'blobs', { progress: { blobs: 3 } }),
				moveOf('done', 'done'),
			],
		})
		const wrapper = await mountCard()
		expect(movePolls()).toBe(1)

		await vi.advanceTimersByTimeAsync(4999)
		expect(movePolls()).toBe(1)
		await vi.advanceTimersByTimeAsync(1)
		await flushPromises()
		expect(movePolls()).toBe(2)
		expect(wrapper.text()).toContain('Copying your pictures and videos (3 so far)')

		await vi.advanceTimersByTimeAsync(5000)
		await flushPromises()
		expect(movePolls()).toBe(3)
		expect(wrapper.text()).toContain('Your Bluesky account now lives on https://pds.example as alice.pds.example.')

		await vi.advanceTimersByTimeAsync(30000)
		expect(movePolls()).toBe(3)
	})

	it('stops asking when the page is left', async () => {
		server({ moves: [moveOf('running')] })
		const wrapper = await mountCard()
		wrapper.unmount()

		await vi.advanceTimersByTimeAsync(30000)
		expect(movePolls()).toBe(1)
	})

	it('says why a move stopped and starts it again from there', async () => {
		server({
			identity: 'active',
			moves: [moveOf('failed', 'blobs', { error: 'the other server answered 502' }), moveOf('done', 'done')],
		})
		axios.post.mockResolvedValue({ data: { move: moveOf('running', 'blobs') } })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('The move stopped: the other server answered 502')
		await buttonNamed(wrapper, 'Try again').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/move/retry`)
		expect(wrapper.text()).toContain('Moving to https://pds.example …')

		await vi.advanceTimersByTimeAsync(5000)
		await flushPromises()
		expect(movePolls()).toBe(2)
		expect(wrapper.text()).toContain('Your Bluesky account now lives on')
	})

	it('says why a move could not be started again, next to the button', async () => {
		server({ identity: 'active', moves: [moveOf('failed', 'repo', { error: 'timeout' })] })
		axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'There is no failed move to start again' } } })
		const wrapper = await mountCard()

		await buttonNamed(wrapper, 'Try again').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('There is no failed move to start again')
		expect(showError).not.toHaveBeenCalled()
	})

	it('says where the account lives now once the move is done, and nothing else', async () => {
		server({ identity: 'moved_away', moves: [moveOf('done', 'done')] })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Your Bluesky account now lives on https://pds.example as alice.pds.example.')
		expect(wrapper.find('.migration__bluesky-form').exists()).toBe(false)
		expect(wrapper.findAll('button')).toHaveLength(0)
	})
})
