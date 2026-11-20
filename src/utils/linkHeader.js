/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The `max_id` the server put in its `Link: …; rel="next"` header.
 *
 * Lists whose cursor is not the id of their last row (conversations, follow
 * requests, list members, tagged photos) page from this, and a missing header
 * is the only reliable sign of the last page.
 *
 * @param {Record<string, unknown>} headers the response headers, as axios hands them back
 * @return {string} the cursor, or '' when the server said this is the last page
 */
export function nextCursor(headers) {
	const link = headers?.link ?? headers?.Link ?? ''
	for (const part of String(link).split(',')) {
		if (!/;\s*rel\s*=\s*"?next"?/.test(part)) {
			continue
		}

		const url = part.match(/<([^>]*)>/)?.[1]
		const cursor = url?.match(/[?&]max_id=([^&]*)/)?.[1]
		if (cursor) {
			return decodeURIComponent(cursor)
		}
	}

	return ''
}
