/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What the server will make a link of, found in the browser.
 *
 * This is a mirror of `lib/Service/LinkifyService.php`, which is what actually
 * publishes a post: it finds the URLs, mentions and hashtags in the typed text
 * and builds the outbound HTML around them. The composer cannot ask the server
 * what a half-typed post will look like on every keystroke, so the same rules
 * are applied here to show the writer what is about to become a link.
 *
 * Because it is a mirror, it must not drift: the two are pinned together by
 * `tests/js/utils/linkify.test.js`, which runs the same cases the PHP unit
 * test runs. If the server's rules change, this changes with them — a preview
 * that promises a mention the server will not send is worse than no preview.
 *
 * What this does **not** do is decide whether a mention resolves to somebody.
 * The server only links an entity the `tag` array vouches for, and it can only
 * build that array by looking accounts up. A mention shown here is therefore
 * "this is a mention", not "this account exists".
 */

/**
 * The one pass over the text, the same alternation the server uses.
 *
 * URLs come first so that everything inside one belongs to it: a `#section`
 * fragment is part of the link and not a hashtag, and `/@bob` in a path names
 * no account. A mention is not preceded by a word character or `@`, which
 * keeps `frank@example.org` an email address; a hashtag is not preceded by a
 * word character (`issue#42`) nor by `&`, which would make an HTML entity into
 * one.
 */
const PATTERN = new RegExp(
	'(?<url>\\bhttps?://[^\\s<>"]+)'
	+ '|(?<![\\w@])@(?<mention>[\\w\\-]+(?:\\.[\\w\\-]+)*(?:@[\\w\\-]+(?:\\.[\\w\\-]+)+)?)'
	+ '|(?<![\\w&])#(?<hashtag>[\\p{L}\\p{N}_]+)',
	'gu',
)

/** Trailing characters that end a sentence rather than a URL. */
const URL_TRAILING = '.,;:!?\'"'

/** Closing brackets that belong to the URL only if it opened them. */
const URL_BRACKETS = { ')': '(', ']': '[', '}': '{' }

/**
 * How many times one character occurs in a string.
 *
 * @param {string} haystack the string to look in
 * @param {string} needle a single character
 * @return {number} the count
 */
function occurrences(haystack, needle) {
	let count = 0
	for (const character of haystack) {
		if (character === needle) {
			count++
		}
	}

	return count
}

/**
 * A URL without the punctuation that ended the sentence rather than the link.
 *
 * @param {string} url the matched run
 * @return {string} the link itself
 */
function withoutTrailingPunctuation(url) {
	let result = url

	while (result !== '') {
		const last = result.slice(-1)

		if (URL_TRAILING.includes(last)) {
			result = result.slice(0, -1)
			continue
		}

		if (Object.hasOwn(URL_BRACKETS, last)
			&& occurrences(result, URL_BRACKETS[last]) < occurrences(result, last)) {
			result = result.slice(0, -1)
			continue
		}

		break
	}

	return result
}

/**
 * Every URL, mention and hashtag in the text, in the order they appear.
 *
 * `text` is the matched substring as it was typed, `name` is it without its
 * leading sigil, and `offset` is an index into the string — a JS index, in
 * UTF-16 code units, where the PHP side counts bytes. Nothing here compares
 * the two, and the callers slice the same string the offsets came from.
 *
 * @param {string} text what was typed
 * @return {Array<{type: 'url'|'mention'|'hashtag', text: string, name: string, offset: number}>} the entities
 */
export function entitiesIn(text) {
	/** @type {Array<{type: 'url'|'mention'|'hashtag', text: string, name: string, offset: number}>} */
	const entities = []

	for (const match of String(text ?? '').matchAll(PATTERN)) {
		const groups = match.groups ?? {}

		if (groups.url !== undefined) {
			const name = withoutTrailingPunctuation(groups.url)
			entities.push({ type: 'url', name, text: name, offset: match.index })
		} else if (groups.mention !== undefined) {
			entities.push({ type: 'mention', name: groups.mention, text: '@' + groups.mention, offset: match.index })
		} else if (groups.hashtag !== undefined) {
			entities.push({ type: 'hashtag', name: groups.hashtag, text: '#' + groups.hashtag, offset: match.index })
		}
	}

	return entities
}

/** What every URL counts as towards the length of a post, whatever its length. */
export const URL_LENGTH = 23

/**
 * The length of a post as the server measures it against `max_characters`
 * (`LinkifyService::countableLength()`): characters, not UTF-16 units; every
 * URL as `URL_LENGTH`; a mention of an account elsewhere as its `@name` alone.
 *
 * @param {string} text what was typed
 * @return {number} the length that counts
 */
export function countableLength(text) {
	const source = String(text ?? '')
	let length = [...source].length
	for (const entity of entitiesIn(source)) {
		if (entity.type === 'url') {
			length += URL_LENGTH - [...entity.text].length
		} else if (entity.type === 'mention') {
			const at = entity.name.indexOf('@')
			if (at !== -1) {
				length -= [...entity.name].length - [...entity.name.slice(0, at)].length
			}
		}
	}

	return length
}

/**
 * The text broken into the runs it is drawn as: plain stretches and entities.
 *
 * One list rather than two, so a preview can lay the post out in order without
 * having to interleave anything itself.
 *
 * @param {string} text what was typed
 * @return {Array<{type: 'text'|'url'|'mention'|'hashtag', text: string, name?: string}>} the runs
 */
export function linkifyRuns(text) {
	const source = String(text ?? '')
	/** @type {Array<{type: 'text'|'url'|'mention'|'hashtag', text: string, name?: string}>} */
	const runs = []
	let cursor = 0

	for (const entity of entitiesIn(source)) {
		if (entity.offset > cursor) {
			runs.push({ type: 'text', text: source.slice(cursor, entity.offset) })
		}

		runs.push({ type: entity.type, text: entity.text, name: entity.name })
		cursor = entity.offset + entity.text.length
	}

	if (cursor < source.length) {
		runs.push({ type: 'text', text: source.slice(cursor) })
	}

	return runs
}
