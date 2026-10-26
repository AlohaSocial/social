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
