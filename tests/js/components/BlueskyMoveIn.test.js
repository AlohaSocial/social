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
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
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
function server({ identity = true, moves = [null], postsOnBluesky = 0 } = {}) {
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
			return Promise.resolve({ data: { move, posts_on_bluesky: postsOnBluesky } })
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

	it('says how many posts the move takes off Bluesky', async () => {
		server({ postsOnBluesky: 38 })
		const wrapper = await mountCard()
		const [handle, password] = inputs(wrapper)
		await handle.setValue('alice.bsky.social')
		await password.setValue('pw')

		await buttonNamed(wrapper, 'Bring my Bluesky account here').trigger('click')

		expect(wrapper.text()).toContain('38 posts disappear from Bluesky; you can publish them again afterwards.')
	})

	it('offers to publish the posts the move took off Bluesky again', async () => {
		server({ moves: [moveOf('done', 'done', { progress: { republish: { count: 38, state: '' } } })] })
		axios.post.mockResolvedValue({ data: { move: moveOf('done', 'done', { progress: { republish: { count: 38, state: 'queued' } } }) } })
		const wrapper = await mountCard()
		expect(wrapper.text()).toContain('38 posts you wrote here were taken off Bluesky by the move.')

		await buttonNamed(wrapper, 'Publish them again').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/move-in/republish`)
		expect(wrapper.text()).toContain('Your posts are being published on Bluesky again.')
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
		axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'The Bluesky server did not let you sign in: Invalid identifier or password', code: 'sign-in-refused' } } })
		const wrapper = await mountCard()
		const [handle, password] = inputs(wrapper)
		await handle.setValue('alice.bsky.social')
		await password.setValue('wrong')
		await buttonNamed(wrapper, 'Bring my Bluesky account here').trigger('click')
		await buttonNamed(wrapper, 'Bring it here').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('The old server did not let you sign in. The details say why.')
		expect(wrapper.find('.server-error__details').text()).toContain('Invalid identifier or password')
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

	it('says where the code went, and asks for a new one', async () => {
		server({ moves: [moveOf('waiting', 'code', { progress: { code_sent_to: 'alice@example.org' } })] })
		axios.post.mockResolvedValue({ data: { move: moveOf('waiting', 'code'), sent_to: '' } })
		const wrapper = await mountCard()
		expect(wrapper.text()).toContain('The code went to alice@example.org.')

		await buttonNamed(wrapper, 'Send a new code').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/move-in/code`)
		expect(wrapper.text()).toContain('A new code is on its way to the e-mail address of your Bluesky account.')
	})

	it('calls a waiting move off after the Nextcloud password', async () => {
		server({ moves: [moveOf('waiting', 'code')] })
		axios.delete.mockResolvedValue({ data: { move: moveOf('cancelled', 'code', { error: 'Called off' }) } })
		const wrapper = await mountCard()

		await buttonNamed(wrapper, 'Call off the move').trigger('click')
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalled()
		expect(axios.delete).toHaveBeenCalledWith(`${API}/move-in`)
		expect(wrapper.text()).not.toContain('The move stopped')
		expect(inputs(wrapper)).toHaveLength(3)
	})

	it('shows why a move could not be called off', async () => {
		server({ moves: [moveOf('waiting', 'code')] })
		axios.delete.mockRejectedValue({ response: { status: 422, data: { error: 'Too late', code: 'move-past-switch' } } })
		const wrapper = await mountCard()

		await buttonNamed(wrapper, 'Call off the move').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Your account already points at the new server, so the move can no longer be called off. Use "Try again" to finish it.')
		expect(wrapper.find('.server-error__details code').text()).toBe('Too late')
	})

	it('lets the person decide on pictures that did not arrive', async () => {
		server({ moves: [moveOf('needs_decision', 'code', { progress: { code: 'blobs-missing', missing: { count: 12, cids: [] } } })] })
		axios.post.mockResolvedValue({ data: { move: moveOf('running', 'code') } })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('12 photos or videos of your posts could not be copied here.')
		await buttonNamed(wrapper, 'Go on without them').trigger('click')
		await flushPromises()
		expect(axios.post).not.toHaveBeenCalled()
		wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/move-in/continue`, { without_missing: true })
		expect(wrapper.text()).toContain('Asking Bluesky to e-mail you a code')
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
		server({ moves: [moveOf('failed', 'identity', { error: 'The move stopped before it was finished', progress: { code: 'move-interrupted' } })] })
		axios.post.mockResolvedValue({ data: { move: moveOf('running', 'code') } })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('The move stopped.')
		expect(wrapper.find('.server-error p').text()).toBe('It was cut off before it finished. Try again: it goes on from where it stopped.')
		expect(wrapper.find('.server-error__details code').text()).toBe('The move stopped before it was finished')
		await buttonNamed(wrapper, 'Try again').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/move/retry`)
	})

	it('says what a stopped move without a known code means, with the server\'s words behind Details', async () => {
		server({ moves: [moveOf('failed', 'identity', { error: 'signPlcOperation answered 400 (InvalidToken)' })] })
		const wrapper = await mountCard()

		expect(wrapper.find('.server-error p').text()).toBe('Something went wrong on the way. Try again; if it happens again, the details say what.')
		expect(wrapper.find('.server-error__details code').text()).toBe('signPlcOperation answered 400 (InvalidToken)')
	})

	it('says what went wrong on the server in a toast, in plain words', async () => {
		server({ moves: [moveOf('waiting', 'code')] })
		axios.post.mockRejectedValue({ response: { status: 500, data: { error: 'Something went wrong on this server', code: 'server-error' } } })
		const wrapper = await mountCard()
		await wrapper.find('.migration__bluesky-in-code input').setValue('ABCDE-12345')

		await buttonNamed(wrapper, 'Confirm the move').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Something went wrong on this server. Try again; if it happens again, ask your administrator.')
	})

	it('leaves a move away to the other card', async () => {
		server({ moves: [moveOf('running', 'repo', { direction: 'away' })] })
		const wrapper = await mountCard()

		expect(wrapper.text()).not.toContain('Bringing')
		expect(inputs(wrapper)).toHaveLength(3)
	})
})
