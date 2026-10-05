/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What is in the composer, kept where a failed post, a reload or a navigation
 * cannot take it away.
 *
 * The composer used to clear itself in a `finally`, so an offline moment, a
 * 500 or a rate limit meant a toast and an empty box: the text was gone. The
 * app also keys its router-view on the full path, so any navigation unmounts
 * the composer — the draft has to outlive the component, not just the request.
 *
 * Only the words are kept. Attachments are uploaded media the server already
 * holds a handle to, and restoring an id whose upload may have been reaped
 * would fail on the next send; a draft that says what you wrote is the part
 * worth keeping.
 */

import { forgetUnscoped, userKey } from '../utils/browserStore.js'

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
 * @typedef {object} ComposerDraft
 * @property {string} text what was typed, as plain text
 * @property {string} spoilerText the content warning, '' when there is none
 * @property {string} visibility who it was going to
 * @property {'fediverse'|'atproto'|'both'} publicationTarget where it was going to be published
 * @property {string} postAs the team account it was being written as, '' for
 *   the reader's own
 * @property {number} savedAt when it was last written, epoch milliseconds
 */

/**
 * Remembers a draft, replacing any previous one in the same context.
 *
 * @param {object} draft the draft to keep
 * @param {string} draft.text what was typed
 * @param {string} [draft.spoilerText] the content warning
 * @param {string} [draft.visibility] who it is going to
 * @param {string} [draft.postAs] the team account it is being written as
 * @param {'fediverse'|'atproto'|'both'} [draft.publicationTarget] where it is published
 * @param {string} [context] what it belongs to — see `key()`
 * @return {boolean} whether it could be stored
 */
export function saveDraft({ text, spoilerText = '', visibility = '', postAs = '', publicationTarget = 'both' }, context = '') {
	if ((text ?? '').trim() === '' && spoilerText.trim() === '') {
		return clearDraft(context)
	}

	try {
		window.localStorage.setItem(key(context), JSON.stringify({
			text,
			spoilerText,
			visibility,
			publicationTarget,
			// the composer has always sent this and this function has always
			// dropped it, so the line it has that reads it back — "a reload
			// does not quietly turn a team post back into a personal one" —
			// could never be true
			postAs,
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

	return {
		text: draft.text,
		spoilerText: typeof draft.spoilerText === 'string' ? draft.spoilerText : '',
		visibility: typeof draft.visibility === 'string' ? draft.visibility : '',
		publicationTarget: ['fediverse', 'atproto', 'both'].includes(draft.publicationTarget)
			? draft.publicationTarget
			: 'both',
		postAs: typeof draft.postAs === 'string' ? draft.postAs : '',
		savedAt: typeof draft.savedAt === 'number' ? draft.savedAt : 0,
	}
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
