/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getCurrentUser } from '@nextcloud/auth'

const key = () => `social:photo-layout:${getCurrentUser()?.uid || 'guest'}`

/** @return {'list'|'grid'} the reader's local photo-feed preference */
export function photoLayout() {
	try {
		return localStorage.getItem(key()) === 'grid' ? 'grid' : 'list'
	} catch {
		return 'list'
	}
}

/** @param {string} layout the supported display mode */
export function rememberPhotoLayout(layout) {
	if (!['list', 'grid'].includes(layout)) {
		return
	}
	try {
		localStorage.setItem(key(), layout)
	} catch {
		// Presentation still works when browser storage is unavailable.
	}
}
