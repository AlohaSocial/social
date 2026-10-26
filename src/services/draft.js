/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What is in the composer, kept where a failed post, a reload or a navigation
 * cannot take it away.
 *
 * The composer is part of the page it sits on — a timeline, a profile, a
 * post's own page, the search results — or of the New post dialog, and it is
 * unmounted when the reader goes to a page without one, closes the dialog or
 * reloads. The draft therefore lives outside the component: a post the server
 * refused leaves the box as it was, and this copy is what puts the box back
 * after any of those.
 *
 * Everything the box holds is kept: the words, the warning, the audience, the
 * language, who it is written as and who may reply, the poll, the time it is
 * scheduled for, the place, a video's title and the card. Attachments are kept
 * by the id of their upload, with what was written about them, where their
 * subject is and the filter chosen for them; the composer asks the server
 * about each id again when the draft comes back, since an upload nobody posted
 * may have been cleared away in the meantime. An upload that has not come
 * back yet has no id to keep, which is what the composer's leave guard is for.
 */

import { forgetUnscoped, userKey } from '../utils/browserStore.js'
import { isFocalPoint } from '../utils/focalPoint.js'

const NAME = 'social.composer.draft'

/**
 * The key a draft was kept under before drafts were scoped to an account.
 * `localStorage` outlives a logout, so one account's unsent words were offered
 * to the next account to sign in on the same computer. It is removed rather
 * than adopted: there is no way to tell whose it was.
 */
const UNSCOPED_KEY = NAME

/**
 * What the draft is kept under, for whoever is signed in and for what is
 * being written.
 *
 * Read at each call rather than once: the module is evaluated when the page
 * loads, and the same page is not necessarily the same session by the time
 * somebody is typing in it.
 *
 * A reply and a top-level post are two drafts, not one slot: with one, a
 * half-written reply opened over a half-written post took its place, and
 * sending either threw the other away. The context is what the composer is
 * answering or quoting; '' is a post of its own, under the key drafts have
 * always had.
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
 * @property {number} savedAt when it was last written, epoch milliseconds
 */

/**
 * Remembers a draft, replacing any previous one in the same context.
 *
 * Kept while it holds anything that would be missed: words, a warning, an
 * attachment or a poll option. The audience or the language alone is a
 * setting, not a draft.
 *
 * @param {Partial<ComposerDraft>} draft the draft to keep; `text` is required
 * @param {string} [context] what it belongs to — see `key()`
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
}, context = '') {
	const pollWritten = poll !== null && poll.options.some((option) => option.trim() !== '')
	if ((text ?? '').trim() === '' && spoilerText.trim() === '' && media.length === 0 && !pollWritten && event === null) {
		return clearDraft(context)
	}

	try {
		window.localStorage.setItem(key(context), JSON.stringify({
			text,
			spoilerText,
			visibility,
			// the composer has always sent this and this function has always
			// dropped it, so the line it has that reads it back — "a reload
			// does not quietly turn a team post back into a personal one" —
			// could never be true
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
			savedAt: Date.now(),
		}))
		return true
	} catch {
		// a private window, or storage that is full: losing the draft is bad,
		// but not being able to write a post at all would be worse
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
		// signed out there is nothing to separate, and the old key is the
		// one a post of its own is kept under — forgetting it there would
		// delete that draft, whichever context is being read
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
	// of the composer may lack one or hold it in another shape, and that is
	// no reason to throw away the words that came with it
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
 * Lists this reader's existing composer slots. Snapshot keys first because
 * loadDraft can prune expired/corrupt entries while validating them.
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
		if (draft !== null && (draft.text.trim() !== '' || draft.spoilerText.trim() !== '' || draft.media.length > 0 || draft.poll?.options.some((option) => option.trim() !== '') || draft.event !== null)) {
			drafts.push({ context, draft })
		}
	}
	return drafts.sort((a, b) => b.draft.savedAt - a.draft.savedAt || a.context.localeCompare(b.context))
}

/**
 * Forgets the draft, which is what a post that actually went out means.
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
