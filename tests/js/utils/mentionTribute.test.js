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

	it('offers accounts with the picture each one has', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				result: {
					accounts: [
						{ preferredUsername: 'alice', account: 'alice@cloud.example', url: 'https://cloud.example/@alice', local: true, id: 'https://cloud.example/users/alice' },
						{ preferredUsername: 'bob', account: 'bob@remote.example', url: 'https://remote.example/@bob', local: false, id: 'https://remote.example/users/bob' },
					],
				},
			},
		})
		const [mention] = mentionTributeOptions().collection

		const shown = await lookUp(mention, 'a')

		expect(axios.get).toHaveBeenCalledWith('/apps/social/api/v1/global/accounts/search', { params: { search: 'a' } })
		expect(shown).toEqual([
			{ key: 'alice', value: 'alice@cloud.example', url: 'https://cloud.example/@alice', avatar: '//avatar/alice/32' },
			{ key: 'bob', value: 'bob@remote.example', url: 'https://remote.example/@bob', avatar: '/apps/social/api/v1/global/actor/avatar?id=https%3A%2F%2Fremote.example%2Fusers%2Fbob' },
		])
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
