/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getCurrentUser } from '@nextcloud/auth'

const sessionDismissed = new Set()
const keyFor = (id) => `social:info-card:${getCurrentUser()?.uid || 'guest'}:${id}`

/** @param {string} id the versioned card identity @return {boolean} whether it was closed */
export function infoCardDismissed(id) {
	const key = keyFor(id)
	if (sessionDismissed.has(key)) {
		return true
	}
	try {
		return localStorage.getItem(key) === '1'
	} catch {
		return false
	}
}

/** @param {string} id the card to hide for this reader */
export function dismissInfoCard(id) {
	const key = keyFor(id)
	try {
		localStorage.setItem(key, '1')
	} catch {
		sessionDismissed.add(key)
	}
}

/** the claims of a browser that will not keep them in session storage */
const sessionPages = new Map()

/**
 * Claims a one-time notice for one page of this browser session.
 *
 * The first page to ask gets the notice, and keeps it across a reload; every
 * other page is told no until the session ends, so a notice that waits for an
 * answer is not repeated above each feed the reader opens meanwhile.
 *
 * @param {string} id the versioned notice identity
 * @param {string} page what identifies the page asking
 * @return {boolean} whether this page may show the notice
 */
export function claimNoticeForPage(id, page) {
	const key = keyFor(id)
	let owner
	try {
		owner = sessionStorage.getItem(key)
		if (owner === null) {
			sessionStorage.setItem(key, page)
		}
	} catch {
		owner = sessionPages.get(key) ?? null
		if (owner === null) {
			sessionPages.set(key, page)
		}
	}

	return owner === null || owner === page
}
