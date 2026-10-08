/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import { confirmPassword } from '../../../src/services/externalApi.js'

import BlueskyMoveIn from '../../../src/components/BlueskyMoveIn.vue'

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

/** A move here as the server keeps it. */
function moveOf(state, step = 'repo', extra = {}) {
	return {
		id: 4,
		direction: 'in',
		pds: 'https://bsky.social',
		handle: 'alice.bsky.social',
		step,
		state,
		progress: {},
		error: '',
		created: 1,
		updated: 2,
		...extra,
	}
}

/** The server: an identity (or none), and the moves the GET answers one after the other. */
function server({ identity = true, moves = [null] } = {}) {
	let polls = 0
	axios.get.mockImplementation((url) => {
		if (url === `${API}/identity`) {
			return identity
				? Promise.resolve({ data: { handle: 'alice.social.test', did: 'did:plc:alice', state: 'active' } })
				: notFound()
		}
		if (url === `${API}/move`) {
			const move = moves[Math.min(polls, moves.length - 1)]
			polls++
			return Promise.resolve({ data: { move } })
		}
		return notFound()
	})
}

async function mountCard() {
	const wrapper = mount(BlueskyMoveIn, { attachTo: document.body })
	await flushPromises()

	return wrapper
}

const buttonNamed = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)
const inputs = (wrapper) => wrapper.findAll('.migration__bluesky-in-form input')

describe('BlueskyMoveIn', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
		document.body.innerHTML = ''
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('draws nothing when Bluesky is off here', async () => {
		server({ identity: false })
		const wrapper = await mountCard()

		expect(wrapper.find('section').exists()).toBe(false)
	})

	it('asks for the handle and the password, and says the password is not kept', async () => {
		server()
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Bring your Bluesky account here')
		expect(wrapper.text()).toContain('The password is used to sign in once and is not kept.')
		expect(inputs(wrapper)).toHaveLength(3)
		expect(buttonNamed(wrapper, 'Bring my Bluesky account here').attributes('disabled')).toBeDefined()
	})

	it('warns that the account made here is replaced, then signs in after the Nextcloud password', async () => {
		server()
		axios.post.mockResolvedValue({ data: { move: moveOf('running') } })
		const wrapper = await mountCard()
		const [handle, password, signInCode] = inputs(wrapper)
		await handle.setValue(' alice.bsky.social ')
		await password.setValue('the password')
		await signInCode.setValue('')

		await buttonNamed(wrapper, 'Bring my Bluesky account here').trigger('click')
		expect(wrapper.text()).toContain('replaces alice.social.test, the one this server made for you')
		await buttonNamed(wrapper, 'Bring it here').trigger('click')
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith(`${API}/move-in`, { handle: 'alice.bsky.social', password: 'the password', authFactorToken: '' })
		expect(wrapper.text()).toContain('Bringing alice.bsky.social here')
	})

	it('sends nothing when the password confirmation is dismissed', async () => {
		server()
		confirmPassword.mockRejectedValueOnce(new Error('dismissed'))
		const wrapper = await mountCard()
		const [handle, password] = inputs(wrapper)
		await handle.setValue('alice.bsky.social')
		await password.setValue('pw')
		await buttonNamed(wrapper, 'Bring my Bluesky account here').trigger('click')
		await buttonNamed(wrapper, 'Bring it here').trigger('click')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('shows why the old server refused the sign-in', async () => {
		server()
		axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'The Bluesky server did not let you sign in: Invalid identifier or password' } } })
		const wrapper = await mountCard()
		const [handle, password] = inputs(wrapper)
		await handle.setValue('alice.bsky.social')
		await password.setValue('wrong')
		await buttonNamed(wrapper, 'Bring my Bluesky account here').trigger('click')
		await buttonNamed(wrapper, 'Bring it here').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('Invalid identifier or password')
		expect(showError).not.toHaveBeenCalled()
	})

	it('asks for the e-mailed code when the move waits for it, and sends it', async () => {
		server({ moves: [moveOf('waiting', 'code')] })
		axios.post.mockResolvedValue({ data: { move: moveOf('running', 'identity') } })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Bluesky e-mailed you a code to confirm the move.')
		await wrapper.find('.migration__bluesky-in-code input').setValue(' 12345-ABCDE ')
		await buttonNamed(wrapper, 'Confirm the move').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/move/code`, { code: '12345-ABCDE' })
		expect(wrapper.text()).toContain('Pointing your account at this server')
	})

	it('follows a running move and says when it is done, under the handle here', async () => {
		server({ moves: [moveOf('running', 'follows'), moveOf('done', 'done')] })
		const wrapper = await mountCard()
		expect(wrapper.text()).toContain('Following the accounts you follow')

		await vi.advanceTimersByTimeAsync(5000)
		await flushPromises()

		expect(wrapper.text()).toContain('Your Bluesky account now lives here, as alice.social.test.')
	})

	it('says when the posts are being put in the timeline', async () => {
		server({ moves: [moveOf('running', 'posts')] })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Putting your posts in your timeline here')
	})

	it('offers to start a failed move again', async () => {
		server({ moves: [moveOf('failed', 'identity', { error: 'signPlcOperation answered 400 (InvalidToken)' })] })
		axios.post.mockResolvedValue({ data: { move: moveOf('running', 'code') } })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('The move stopped: signPlcOperation answered 400 (InvalidToken)')
		await buttonNamed(wrapper, 'Try again').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/move/retry`)
	})

	it('leaves a move away to the other card', async () => {
		server({ moves: [moveOf('running', 'repo', { direction: 'away' })] })
		const wrapper = await mountCard()

		expect(wrapper.text()).not.toContain('Bringing')
		expect(inputs(wrapper)).toHaveLength(3)
	})
})
