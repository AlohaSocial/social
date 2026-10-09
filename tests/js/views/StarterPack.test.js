/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import StarterPack from '../../../src/views/StarterPack.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

const member = (did, following = false) => ({ did, handle: `${did.slice(8)}.test`, name: '', avatar: '', description: '', following })

function pack(members) {
	return {
		uri: 'at://did:plc:bob/app.bsky.graph.starterpack/3ks',
		url: 'https://bsky.app/starter-pack/bob.test/3ks',
		name: 'Start here',
		description: 'Good people',
		joined: 3,
		creator: { did: 'did:plc:bob', handle: 'bob.test', name: 'Bob', avatar: '' },
		members,
		feeds: [{ uri: 'at://did:plc:bob/app.bsky.feed.generator/cats', type: 'feed', name: 'Cats', description: '', avatar: '', creator: 'bob.test' }],
	}
}

function mountPage() {
	return mount(StarterPack, {
		global: {
			mocks: { $route: { params: { actor: 'bob.test', rkey: '3ks' } } },
			stubs: { ActorAvatar: true, RouterLink: { props: ['to'], template: '<a><slot /></a>' } },
		},
	})
}

describe('StarterPack', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('reads the pack by the address bsky.app gives it', async () => {
		get.mockResolvedValue({ data: { pack: pack([member('did:plc:a'), member('did:plc:b', true)]) } })

		const wrapper = mountPage()
		await flushPromises()

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/social/bluesky/starter-pack'), { params: { pack: 'https://bsky.app/starter-pack/bob.test/3ks' } })
		expect(wrapper.find('.starter-pack__title').text()).toBe('Start here')
		expect(wrapper.findAll('.starter-pack__state')).toHaveLength(1)
	})

	it('follows everybody not followed yet, in batches, with the feeds once', async () => {
		const members = Array.from({ length: 30 }, (_, i) => member(`did:plc:m${i}`))
		get.mockResolvedValue({ data: { pack: pack(members) } })
		post.mockImplementation((url, body) => Promise.resolve({ data: { followed: body.dids, failed: [] } }))

		const wrapper = mountPage()
		await flushPromises()
		await wrapper.find('.starter-pack__actions button').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledTimes(2)
		expect(post.mock.calls[0][1].dids).toHaveLength(25)
		expect(post.mock.calls[0][1].feeds).toBe(true)
		expect(post.mock.calls[1][1].dids).toHaveLength(5)
		expect(post.mock.calls[1][1].feeds).toBe(false)
		expect(wrapper.findAll('.starter-pack__state')).toHaveLength(30)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('says what could not be followed', async () => {
		get.mockResolvedValue({ data: { pack: pack([member('did:plc:a'), member('did:plc:b')]) } })
		post.mockResolvedValue({ data: { followed: ['did:plc:a'], failed: ['did:plc:b'] } })

		const wrapper = mountPage()
		await flushPromises()
		await wrapper.find('.starter-pack__actions button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.findAll('.starter-pack__state')).toHaveLength(1)
	})
})
