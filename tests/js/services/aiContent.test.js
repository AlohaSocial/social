/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import {
	AI_MARK_TAG,
	addAiMark,
	fetchAiContent,
	hasAiMark,
	removeAiMark,
	saveAiContent,
} from '../../../src/services/aiContent.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), patch: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1/social/ai_content'

describe('the mark an author puts on a post made with AI', () => {
	it('is the hashtag every server already carries', () => {
		expect(AI_MARK_TAG).toBe('AIgenerated')
	})

	it('is recognised in any case, as a whole hashtag', () => {
		expect(hasAiMark('A sunset #AIgenerated')).toBe(true)
		expect(hasAiMark('#aigenerated')).toBe(true)
		expect(hasAiMark('#AIGENERATED first')).toBe(true)
		expect(hasAiMark('one\n#AIgenerated')).toBe(true)
		expect(hasAiMark('(#AIgenerated)')).toBe(true)
		expect(hasAiMark('#AIgenerated, honestly')).toBe(true)
	})

	it('is not the tail of a longer tag, a plain word or an entity', () => {
		expect(hasAiMark('')).toBe(false)
		expect(hasAiMark('AIgenerated')).toBe(false)
		expect(hasAiMark('#notAIgenerated')).toBe(false)
		expect(hasAiMark('#AIgeneratedArt')).toBe(false)
		expect(hasAiMark('#AIgenerated_too')).toBe(false)
		expect(hasAiMark('#AIgenerated2')).toBe(false)
		expect(hasAiMark('a&#AIgenerated')).toBe(false)
	})

	describe('adding it', () => {
		it('goes on the end after one space', () => {
			expect(addAiMark('A sunset')).toBe('A sunset #AIgenerated')
		})

		it('does not double a space the text already ends in', () => {
			expect(addAiMark('A sunset ')).toBe('A sunset #AIgenerated')
		})

		it('starts the next line when the text ends in one', () => {
			expect(addAiMark('A sunset\n')).toBe('A sunset\n#AIgenerated')
		})

		it('is the whole of an empty post', () => {
			expect(addAiMark('')).toBe('#AIgenerated')
		})

		it('leaves a text that carries it alone, whatever its case', () => {
			expect(addAiMark('A sunset #AIgenerated')).toBe('A sunset #AIgenerated')
			expect(addAiMark('#aigenerated sunset')).toBe('#aigenerated sunset')
			expect(addAiMark(addAiMark('twice'))).toBe('twice #AIgenerated')
		})
	})

	describe('removing it', () => {
		it('takes the mark and the space that carried it off the end', () => {
			expect(removeAiMark('A sunset #AIgenerated')).toBe('A sunset')
			expect(removeAiMark('A sunset\n#AIgenerated')).toBe('A sunset')
			expect(removeAiMark('A sunset  #AIgenerated')).toBe('A sunset')
		})

		it('takes it off the start with the space after it', () => {
			expect(removeAiMark('#AIgenerated A sunset')).toBe('A sunset')
			expect(removeAiMark('#AIgenerated\nA sunset')).toBe('A sunset')
		})

		it('leaves one space between the words either side of it', () => {
			expect(removeAiMark('A #AIgenerated sunset')).toBe('A sunset')
			expect(removeAiMark('A, #AIgenerated sunset')).toBe('A, sunset')
			expect(removeAiMark('A,#AIgenerated sunset')).toBe('A, sunset')
		})

		it('ignores the case it was typed in', () => {
			expect(removeAiMark('A sunset #aigenerated')).toBe('A sunset')
			expect(removeAiMark('A sunset #AIGENERATED')).toBe('A sunset')
		})

		it('takes every copy, however they stand', () => {
			expect(removeAiMark('#AIgenerated #AIgenerated')).toBe('')
			expect(removeAiMark('#AIgenerated A sunset #aigenerated')).toBe('A sunset')
		})

		it('leaves a longer tag, a plain word and a text without the mark alone', () => {
			expect(removeAiMark('#AIgeneratedArt is a tag')).toBe('#AIgeneratedArt is a tag')
			expect(removeAiMark('AIgenerated is a word')).toBe('AIgenerated is a word')
			expect(removeAiMark('A sunset')).toBe('A sunset')
			expect(removeAiMark('')).toBe('')
		})

		it('undoes adding', () => {
			for (const text of ['A sunset', 'A sunset\n', '', 'Two lines\nof it']) {
				expect(removeAiMark(addAiMark(text))).toBe(text.replace(/\s+$/, ''))
			}
		})
	})
})

describe('the setting that hides posts made with AI', () => {
	beforeEach(() => {
		axios.get.mockReset().mockResolvedValue({ data: { hide: false } })
		axios.patch.mockReset().mockResolvedValue({ data: { hide: true } })
	})

	it('reads it', async () => {
		expect(await fetchAiContent()).toEqual({ hide: false })
		expect(axios.get).toHaveBeenCalledWith(API)
	})

	it('reads an answer that says to hide', async () => {
		axios.get.mockResolvedValue({ data: { hide: true } })

		expect(await fetchAiContent()).toEqual({ hide: true })
	})

	/** An older server, or a thin fixture, answers less than the contract. */
	it('reads anything that is not plainly true as false', async () => {
		axios.get.mockResolvedValue({ data: {} })
		expect(await fetchAiContent()).toEqual({ hide: false })

		axios.get.mockResolvedValue({ data: { hide: 'yes' } })
		expect(await fetchAiContent()).toEqual({ hide: false })

		axios.get.mockResolvedValue({ data: null })
		expect(await fetchAiContent()).toEqual({ hide: false })
	})

	it('saves it and takes the answer back', async () => {
		expect(await saveAiContent(true)).toEqual({ hide: true })
		expect(axios.patch).toHaveBeenCalledWith(API, { hide: true })

		axios.patch.mockResolvedValue({ data: { hide: false } })
		expect(await saveAiContent(false)).toEqual({ hide: false })
		expect(axios.patch).toHaveBeenLastCalledWith(API, { hide: false })
	})

	/** The server's refusal is the caller's to show, so it is not swallowed here. */
	it('lets a refusal through', async () => {
		const refusal = Object.assign(new Error('422'), { response: { status: 422, data: { error: 'hide must be a boolean' } } })
		axios.patch.mockRejectedValue(refusal)

		await expect(saveAiContent(true)).rejects.toBe(refusal)
	})
})
