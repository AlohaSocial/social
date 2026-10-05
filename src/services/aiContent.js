/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Posts made with AI: the mark an author puts on their own, and the one
 * setting a reader has about everybody else's.
 *
 * On the wire the author's mark is a hashtag, `#AIgenerated`, because a tag
 * is the one thing every server already carries and every reader can already
 * see. The server holds one boolean per account — whether to hide such posts
 * — and answers the whole of it to every read and every change.
 */

/** The hashtag, without its `#`, in the case it is written with. */
export const AI_MARK_TAG = 'AIgenerated'

/**
 * The mark as a whole hashtag, any case: not the tail of a longer word, not
 * followed by more tag characters, and not the fragment of an entity. The
 * character classes are the ones `linkify.js` recognises a hashtag by.
 */
const MARK = /(?<![\p{L}\p{N}_&#])#aigenerated(?![\p{L}\p{N}_])/iu

/** The same, with the whitespace on either side, for taking it out. */
const MARK_WITH_SPACE = /(^|[^\p{L}\p{N}_&#])(\s*)#aigenerated(?![\p{L}\p{N}_])(\s*)/giu

/** @return {string} the one URL everything here talks to */
function url() {
	return generateUrl('/apps/social/api/v1/social/ai_content')
}

/**
 * Whether a text carries the mark.
 *
 * @param {string} text what was typed
 * @return {boolean}
 */
export function hasAiMark(text) {
	return MARK.test(text)
}

/**
 * The text with the mark on the end: after a single space, or at the start of
 * the next line when the text already ends in one. A text that carries the
 * mark comes back as it was.
 *
 * @param {string} text what was typed
 * @return {string}
 */
export function addAiMark(text) {
	if (hasAiMark(text)) {
		return text
	}

	const separator = text === '' || /\s$/.test(text) ? '' : ' '

	return `${text}${separator}#${AI_MARK_TAG}`
}

/**
 * The text without the mark and without the whitespace that carried it: the
 * space before a mark at the end, the space after one at the start, and one
 * of the two around a mark in the middle.
 *
 * @param {string} text what was typed
 * @return {string}
 */
export function removeAiMark(text) {
	let result = text
	let previous
	// a mark straight after another is only seen once the first has gone
	do {
		previous = result
		result = result.replace(MARK_WITH_SPACE, (match, before, leading, trailing) => {
			if (before.trim() === '') {
				// nothing but whitespace before the mark: keep one side of it
				// when the text goes on, and neither at an edge
				const space = before + leading
				return space !== '' && trailing !== '' ? space : ''
			}

			// punctuation before the mark: the space after it is the one
			// that separates the words either side
			return before + trailing
		})
	} while (result !== previous)

	return result
}

/** @return {Promise<{hide: boolean}>} the one setting */
export async function fetchAiContent() {
	const { data } = await axios.get(url())

	return { hide: data?.hide === true }
}

/**
 * @param {boolean} hide whether posts made with AI are to be hidden
 * @return {Promise<{hide: boolean}>} the setting as saved
 */
export async function saveAiContent(hide) {
	const { data } = await axios.patch(url(), { hide })

	return { hide: data?.hide === true }
}
