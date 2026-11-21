/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What is in the composer, kept where a failed post, a reload or a navigation
 * cannot take it away: the composer is unmounted with its page or dialog, so
 * the draft lives outside it.
 *
 * Everything the box holds is kept. Attachments are kept by upload id with
 * their description, focal point and filter, and the composer asks the server
 * about each id again on restore, since an unposted upload may have been
 * cleared. An upload still in flight has no id; the composer's leave guard
 * covers that.
 */

import { forgetUnscoped, userKey } from '../utils/browserStore.js'
import { isFocalPoint } from '../utils/focalPoint.js'

const NAME = 'social.composer.draft'

/**
 * The key drafts had before they were scoped to an account. `localStorage`
 * outlives a logout, so a draft under it is removed rather than adopted:
 * there is no way to tell whose it was.
 */
const UNSCOPED_KEY = NAME

/**
 * What the draft is kept under, for whoever is signed in and for what is
 * being written. Read at each call, as the session can change after the page
 * loads.
 *
 * Each reply or quote has its own draft, apart from a top-level post: the
 * context is what the composer answers or quotes, and '' is a post of its own.
 *
 * @param {string} context what the draft belongs to, '' for a post of its own
 * @return {string} the key
 */
function key(context) {
	return userKey(context === '' ? NAME : `${NAME}.${context}`)
}

/** a draft older than this is stale enough to be somebody else's day */
const MAX_AGE_MS = 7 * 24 * 3600 * 1000

/**
 * @typedef {object} DraftMedia
 * @property {import('../types/Mastodon.js').MediaAttachment} data the upload,
 *   as the server last described it
 * @property {string} description the alt text as typed, which may not be
 *   saved on the server yet
 * @property {?import('../utils/focalPoint.js').FocalPoint} focus where the
 *   subject is, null for none
 * @property {string} filter the filter chosen, '' for none
 */

/**
 * @typedef {object} DraftPoll
 * @property {string[]} options the options as typed, empty ones included
 * @property {boolean} multiple whether more than one may be chosen
 * @property {number} expiresIn how long it runs, in seconds
 */

/**
 * @typedef {object} ComposerDraft
 * @property {string} text what was typed, as plain text
 * @property {string} spoilerText the content warning, '' when there is none
 * @property {string} visibility who it was going to
 * @property {string} postAs the team account it was being written as, '' for
 *   the reader's own
 * @property {DraftMedia[]} media the attachments, in order
 * @property {?DraftPoll} poll the poll, null when there is none
 * @property {?{name: string, start: string, end: string, location: string}} event the event being written
 * @property {string} scheduledAt when it is to go out, as an ISO date, '' for now
 * @property {string} language the language it is written in, '' for the default
 * @property {string} replyPolicy who may reply, '' for the default
 * @property {?object} place where it was taken, as the place picker holds it
 * @property {?{title: string, category: string, licence: string}} video what
 *   a video post is called, null when nothing was written
 * @property {string} card the background of a post sent as a card, '' for a
 *   plain post
 * @property {string} inReplyToId the post a re-draft answers, '' for none
 * @property {string} quoteId the post a re-draft quotes, '' for none
 * @property {number} savedAt when it was last written, epoch milliseconds
 */

/**
 * @param {string} text some text
 * @return {string} it with every run of white space, no-break spaces
 *   included, made one space and the ends trimmed
 */
function squash(text) {
	return (text ?? '').replace(/\s+/g, ' ').trim()
}

/**
 * Whether a reply's words are only the handles it is addressed to, which the
 * composer puts in by itself and nobody wrote.
 *
 * @param {string} text what was typed
 * @return {boolean}
 */
export function isOnlyMentions(text) {
	return /^@[^\s@]+(?:@[^\s@]+)?(?: @[^\s@]+(?:@[^\s@]+)?)*$/u.test(squash(text))
}

/**
 * Remembers a draft, replacing any previous one in the same context.
 *
 * Kept while it holds anything that would be missed: words, a warning, an
 * attachment or a poll option. The audience or language alone is not a
 * draft, nor are the handles the composer addressed a reply to by itself.
 *
 * @param {Partial<ComposerDraft>} draft the draft to keep; `text` is required
 * @param {string} [context] what it belongs to — see `key()`
 * @param {string} [routingText] the mentions the composer put in by itself;
 *   words that are only these are not a draft
 * @return {boolean} whether it could be stored
 */
export function saveDraft({
	text,
	spoilerText = '',
	visibility = '',
	postAs = '',
	media = [],
	poll = null,
	event = null,
	scheduledAt = '',
	language = '',
	replyPolicy = '',
	place = null,
	video = null,
	card = '',
	inReplyToId = '',
	quoteId = '',
}, context = '', routingText = '') {
	const pollWritten = poll !== null && poll.options.some((option) => option.trim() !== '')
	const words = squash(text)
	const written = words !== '' && words !== squash(routingText)
	if (!written && spoilerText.trim() === '' && media.length === 0 && !pollWritten && event === null) {
		return clearDraft(context)
	}

	try {
		window.localStorage.setItem(key(context), JSON.stringify({
			text,
			spoilerText,
			visibility,
			// so a reload does not turn a team post back into a personal one
			postAs,
			media,
			poll,
			event,
			scheduledAt,
			language,
			replyPolicy,
			place,
			video,
			card,
			inReplyToId,
			quoteId,
			savedAt: Date.now(),
		}))
		return true
	} catch {
		// a private window, or storage that is full
		return false
	}
}

/**
 * @param {string} [context] what the draft belongs to — see `key()`
 * @return {ComposerDraft|null} the kept draft, or null when there is none
 */
export function loadDraft(context = '') {
	let raw
	try {
		// signed out the scoped and the unscoped key are the same, and forgetting
		// it would delete that draft
		if (key('') !== UNSCOPED_KEY) {
			forgetUnscoped(UNSCOPED_KEY)
		}

		raw = window.localStorage.getItem(key(context))
	} catch {
		return null
	}

	if (!raw) {
		return null
	}

	let draft
	try {
		draft = JSON.parse(raw)
	} catch {
		clearDraft(context)
		return null
	}

	if (draft === null || typeof draft !== 'object' || typeof draft.text !== 'string') {
		clearDraft(context)
		return null
	}

	if (typeof draft.savedAt === 'number' && Date.now() - draft.savedAt > MAX_AGE_MS) {
		clearDraft(context)
		return null
	}

	// each field is checked on its own: a draft written by another version
	// of the composer may lack one, which is no reason to lose the words
	return {
		text: draft.text,
		spoilerText: stringOr(draft.spoilerText),
		visibility: stringOr(draft.visibility),
		postAs: stringOr(draft.postAs),
		media: Array.isArray(draft.media) ? draft.media.map(mediaOf).filter((item) => item !== null) : [],
		poll: pollOf(draft.poll),
		event: isObject(draft.event)
			? {
					name: stringOr(draft.event.name),
					start: stringOr(draft.event.start),
					end: stringOr(draft.event.end),
					location: stringOr(draft.event.location),
				}
			: null,
		scheduledAt: stringOr(draft.scheduledAt),
		language: stringOr(draft.language),
		replyPolicy: stringOr(draft.replyPolicy),
		place: isObject(draft.place) ? draft.place : null,
		video: isObject(draft.video)
			? { title: stringOr(draft.video.title), category: stringOr(draft.video.category), licence: stringOr(draft.video.licence) }
			: null,
		card: stringOr(draft.card),
		inReplyToId: idOr(draft.inReplyToId),
		quoteId: idOr(draft.quoteId),
		savedAt: typeof draft.savedAt === 'number' && Number.isFinite(draft.savedAt) && !Number.isNaN(new Date(draft.savedAt).getTime()) ? draft.savedAt : 0,
	}
}

/**
 * @param {unknown} value anything
 * @return {string} it when it is a string, '' otherwise
 */
function stringOr(value) {
	return typeof value === 'string' ? value : ''
}

/**
 * @param {unknown} value anything
 * @return {string} a status id, '' when it is none
 */
function idOr(value) {
	return (typeof value === 'string' || typeof value === 'number') && /^[0-9]+$/.test(String(value)) ? String(value) : ''
}

/**
 * @param {unknown} value anything
 * @return {value is Record<string, unknown>} whether it is a plain object rather
 *   than null or a list
 */
function isObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * @param {unknown} item one kept attachment
 * @return {DraftMedia|null} it, or null when it names no upload
 */
function mediaOf(item) {
	if (!isObject(item) || !isObject(item.data)) {
		return null
	}
	const id = item.data.id
	if ((typeof id !== 'string' && typeof id !== 'number') || String(id) === '') {
		return null
	}

	return {
		data: /** @type {import('../types/Mastodon.js').MediaAttachment} */ (item.data),
		description: stringOr(item.description),
		focus: isFocalPoint(item.focus) ? /** @type {import('../utils/focalPoint.js').FocalPoint} */ (item.focus) : null,
		filter: stringOr(item.filter),
	}
}

/**
 * @param {unknown} poll the kept poll
 * @return {DraftPoll|null} it, or null when there is none
 */
function pollOf(poll) {
	if (!isObject(poll) || !Array.isArray(poll.options)) {
		return null
	}

	const expiresIn = typeof poll.expiresIn === 'number' && Number.isFinite(poll.expiresIn) && poll.expiresIn > 0
		? poll.expiresIn
		: 86400

	return {
		options: poll.options.map(stringOr),
		multiple: poll.multiple === true,
		expiresIn,
	}
}

/**
 * Lists this reader's existing composer slots. The keys are read first, as
 * loadDraft can prune expired or corrupt entries while validating them.
 *
 * @return {Array<{context: string, draft: ComposerDraft}>} newest first
 */
export function listDrafts() {
	const scoped = key('')
	if (scoped === NAME) {
		return []
	}
	const suffix = scoped.slice(NAME.length)
	let keys
	try {
		keys = Array.from({ length: window.localStorage.length }, (_, index) => window.localStorage.key(index))
	} catch {
		return []
	}
	const drafts = []
	for (const stored of keys) {
		if (typeof stored !== 'string' || !stored.endsWith(suffix)) {
			continue
		}
		const name = stored.slice(0, -suffix.length)
		const context = name === NAME ? '' : name.startsWith(NAME + '.') ? name.slice(NAME.length + 1) : null
		if (context === null || (context !== '' && !/^(reply|quote|redraft)\.[0-9]+$/.test(context))) {
			continue
		}
		if (key(context) !== stored) {
			continue
		}
		const draft = loadDraft(context)
		// a reply holding only the handles it was addressed to was saved by
		// opening the post, not by writing anything
		const words = context.startsWith('reply.') && isOnlyMentions(draft?.text ?? '') ? '' : (draft?.text ?? '')
		if (draft !== null && (words.trim() !== '' || draft.spoilerText.trim() !== '' || draft.media.length > 0 || draft.poll?.options.some((option) => option.trim() !== '') || draft.event !== null)) {
			drafts.push({ context, draft })
		}
	}
	return drafts.sort((a, b) => b.draft.savedAt - a.draft.savedAt || a.context.localeCompare(b.context))
}

/**
 * Removes a draft after publication or when the reader explicitly deletes it.
 *
 * @param {string} [context] what the draft belongs to — see `key()`
 * @return {boolean} whether the store could be reached
 */
export function clearDraft(context = '') {
	try {
		window.localStorage.removeItem(key(context))
		return true
	} catch {
		return false
	}
}
