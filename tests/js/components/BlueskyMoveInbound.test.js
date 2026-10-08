/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { confirmPassword } from '../../../src/services/externalApi.js'

import BlueskyMoveInbound from '../../../src/components/BlueskyMoveInbound.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/externalApi.js', () => ({ confirmPassword: vi.fn().mockResolvedValue(undefined) }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1/social/bluesky'
const TWIN = { handle: 'alice.social.test.ap.brid.gy', did: 'did:plc:twin' }

const notFound = () => Promise.reject(Object.assign(new Error('404'), { response: { status: 404, data: {} } }))

/** A move here by the other side, as the server keeps it. */
function moveOf(state, step = 'invited', extra = {}) {
	return {
		id: 7,
		direction: 'inbound',
		pds: 'https://atproto.brid.gy',
		handle: TWIN.handle,
		step,
		state,
		progress: {},
		error: '',
		created: 1,
		updated: 2,
		...extra,
	}
}

/** The server: an identity (or none), a twin (or none), and the moves the GET answers one after the other. */
function server({ identity = true, twin = TWIN, moves = [null] } = {}) {
	let polls = 0
	axios.get.mockImplementation((url) => {
		if (url === `${API}/identity`) {
			return identity
				? Promise.resolve({ data: { handle: 'alice.social.test', did: 'did:plc:alice', state: 'active' } })
				: notFound()
		}
		if (url === `${API}/bridgy-twin`) {
			return Promise.resolve({ data: { twin } })
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
	const wrapper = mount(BlueskyMoveInbound, { attachTo: document.body })
	await flushPromises()

	return wrapper
}

const buttonNamed = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)

describe('BlueskyMoveInbound', () => {
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

	it('offers to bring the Bridgy Fed twin, and asks Bridgy after the Nextcloud password', async () => {
		server()
		axios.post.mockResolvedValue({ data: { move: moveOf('waiting'), bridgy: true, code: '' } })
		const wrapper = await mountCard()
		expect(wrapper.text()).toContain('Bridgy Fed bridges your account to Bluesky as alice.social.test.ap.brid.gy.')

		await buttonNamed(wrapper, 'Bring it here').trigger('click')
		expect(wrapper.text()).toContain('It replaces alice.social.test, the Bluesky account this server made for you')
		await buttonNamed(wrapper, 'Ask Bridgy Fed').trigger('click')
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith(`${API}/move-invite`, { account: 'did:plc:twin' })
		expect(wrapper.text()).toContain('Waiting for Bridgy Fed to start')
		expect(wrapper.find('dl').exists()).toBe(false)
	})

	it('shows a migration tool what it needs, the code only now', async () => {
		server({ twin: null })
		axios.post.mockResolvedValue({
			data: {
				move: moveOf('waiting', 'invited', { pds: 'https://pds.example.com', handle: 'alice.example.com' }),
				bridgy: false,
				pds: 'social.test',
				handle: 'alice.social.test',
				email: 'alice@social.test',
				code: 'abcdef-ghijkl-mnopqr-stuvwx',
			},
		})
		const wrapper = await mountCard()
		expect(wrapper.find('details').attributes('open')).toBeDefined()

		await wrapper.find('details input').setValue(' alice.example.com ')
		await buttonNamed(wrapper, 'Invite the account').trigger('click')
		await buttonNamed(wrapper, 'Invite it').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/move-invite`, { account: 'alice.example.com' })
		expect(wrapper.text()).toContain('Waiting for the migration tool to start')
		expect(wrapper.find('dl').text()).toContain('abcdef-ghijkl-mnopqr-stuvwx')
		expect(wrapper.find('dl').text()).toContain('alice@social.test')
	})

	it('says why an invitation was refused', async () => {
		server({ twin: null })
		axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'That Bluesky account is on this server already' } } })
		const wrapper = await mountCard()
		await wrapper.find('details input').setValue('alice.example.com')
		await buttonNamed(wrapper, 'Invite the account').trigger('click')
		await buttonNamed(wrapper, 'Invite it').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('That Bluesky account is on this server already')
	})

	it('follows the move as the posts arrive, and says when it is done', async () => {
		server({ moves: [moveOf('waiting', 'blobs', { progress: { records: 12, blobs: 3 } }), moveOf('done', 'done')] })
		const wrapper = await mountCard()
		expect(wrapper.text()).toContain('12 posts arrived, 3 pictures and videos so far')

		await vi.advanceTimersByTimeAsync(10000)
		await flushPromises()

		expect(wrapper.text()).toContain('Your Bluesky account now lives here, as alice.social.test.')
	})

	it('says when the posts are being put in the timeline', async () => {
		server({ moves: [moveOf('running', 'posts')] })
		const wrapper = await mountCard()

		expect(wrapper.text()).toContain('Putting your posts in your timeline here')
		expect(wrapper.text()).not.toContain('Call it off')
	})

	it('calls a move off that has not finished', async () => {
		server({ moves: [moveOf('waiting')] })
		axios.delete.mockResolvedValue({ data: { move: moveOf('failed', 'invited', { error: 'Called off' }) } })
		const wrapper = await mountCard()

		await buttonNamed(wrapper, 'Call it off').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${API}/move-invite`)
		expect(wrapper.text()).toContain('The move stopped: Called off')
	})

	it('leaves the other moves to their cards', async () => {
		server({ moves: [moveOf('running', 'repo', { direction: 'in' })] })
		const wrapper = await mountCard()

		expect(wrapper.text()).not.toContain('Bringing')
	})
})
