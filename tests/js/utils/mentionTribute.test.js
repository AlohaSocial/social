/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mentionTributeOptions } from '../../../src/utils/mentionTribute.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/' + path.replace(/\{(\w+)\}/g, (_, key) => encodeURIComponent(params[key])),
}))

/**
 * Runs one menu's search the way tributejs does, past its debounce.
 *
 * @param {object} menu one entry of the collection
 * @param {string} text what was typed after the trigger
 * @return {Promise<object[]>} what the menu was given to show
 */
async function lookUp(menu, text) {
	const populate = vi.fn()
	menu.values(text, populate)
	await vi.advanceTimersByTimeAsync(250)

	return populate.mock.calls.at(-1)?.[0] ?? []
}

describe('mentionTributeOptions', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.mocked(axios.get).mockReset()
	})

	it('offers accounts with the picture this server has for each', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				result: {
					accounts: [
						{ id: '1', username: 'alice', acct: 'alice', url: 'https://cloud.example/users/alice', avatar: 'https://cloud.example/avatar/alice/128' },
						{ id: '2', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/users/bob', avatar: 'https://cloud.example/apps/social/media/b0b.png' },
					],
				},
			},
		})
		const [mention] = mentionTributeOptions().collection

		const shown = await lookUp(mention, 'a')

		expect(axios.get).toHaveBeenCalledWith('/apps/social/api/v1/global/accounts/search', { params: { search: 'a' } })
		expect(shown).toEqual([
			{ key: 'alice', value: 'alice', url: 'https://cloud.example/users/alice', avatar: 'https://cloud.example/avatar/alice/128' },
			{ key: 'bob', value: 'bob@remote.example', url: 'https://remote.example/users/bob', avatar: 'https://cloud.example/apps/social/media/b0b.png' },
		])
	})

	it('draws each account in the menu from its thumbnail where it has one', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				result: {
					accounts: [
						{ id: '2', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/users/bob', avatar: 'https://cloud.example/apps/social/media/b0b', avatar_small: 'https://cloud.example/apps/social/media/b0b-small' },
					],
				},
			},
		})
		const [mention] = mentionTributeOptions().collection

		const shown = await lookUp(mention, 'b')

		expect(shown[0].avatar).toBe('https://cloud.example/apps/social/media/b0b-small')
	})

	it('mentions a Bluesky account by its bare handle', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				result: {
					accounts: [
						{ id: '3', username: 'dana.bsky.social', acct: 'dana.bsky.social', url: 'https://bsky.app/profile/did:plc:d', avatar: 'https://cloud.example/apps/social/img/avatar-missing.png' },
					],
				},
			},
		})
		const [mention] = mentionTributeOptions().collection

		const shown = await lookUp(mention, 'dana.b')

		expect(mention.selectTemplate({ original: shown[0] })).toContain('@dana.bsky.social</a>')
		expect(mention.selectTemplate({ original: shown[0] })).toContain('src="https://cloud.example/apps/social/img/avatar-missing.png"')
	})

	it('offers the exact hashtag first, then the others', async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: { result: { exact: 'jazz', tags: [{ hashtag: 'jazzfunk' }] } } })
		const [, hashtag] = mentionTributeOptions().collection

		expect(await lookUp(hashtag, 'jazz')).toEqual([{ key: 'jazz', value: 'jazz' }, { key: 'jazzfunk', value: 'jazzfunk' }])
	})

	it('offers no exact hashtag when the server has none', async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: { result: { exact: [], tags: [{ hashtag: 'jazzfunk' }] } } })
		const [, hashtag] = mentionTributeOptions().collection

		expect(await lookUp(hashtag, 'jaz')).toEqual([{ key: 'jazzfunk', value: 'jazzfunk' }])
	})

	it('offers what was typed as a new hashtag, escaped', () => {
		const options = mentionTributeOptions()
		const tribute = { current: { collection: { trigger: '#' }, mentionText: '<b>new' } }

		expect(options.noMatchTemplate.call(tribute)).toBe('<li data-index="0">#&lt;b&gt;new</li>')
		expect(options.noMatchTemplate.call({ current: { collection: { trigger: '#' }, mentionText: '' } })).toBeUndefined()
		expect(options.noMatchTemplate.call({ current: { collection: { trigger: '@' }, mentionText: 'x' } })).toBeUndefined()
	})
})
