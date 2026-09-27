/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The `max_id` the server put in its `Link: …; rel="next"` header.
 *
 * A list whose cursor is not the id of the last row it answered with has to
 * page from here rather than from what is on screen: a conversation's id is
 * its thread root and its cursor a message nid, a follow request is paged on
 * the request rather than the account, a list member's cursor is the
 * membership row and not the account, and a page of tagged photos can be
 * short because the reader may not see some of them. The absence of the
 * header is also the only reliable "this was the last page".
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
