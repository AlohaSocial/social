/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** how many members one request follows; the server's own ceiling */
export const FOLLOW_BATCH = 25

/**
 * @param {string} pack its bsky.app address or `at://` URI
 * @return {Promise<object>} the pack: name, creator, members, feeds
 */
export async function fetchStarterPack(pack) {
	const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/starter-pack'), { params: { pack } })

	return data.pack
}

/**
 * Follows the members named, a batch at a time, and keeps the pack's feeds
 * with the first batch when asked to.
 *
 * @param {string} pack its bsky.app address or `at://` URI
 * @param {string[]} dids the members to follow
 * @param {boolean} feeds whether to keep its feeds too
 * @param {(done: number) => void} progress told how many have been asked for so far
 * @return {Promise<{followed: string[], failed: string[]}>}
 */
export async function followFromStarterPack(pack, dids, feeds, progress = () => {}) {
	const result = { followed: [], failed: [] }
	for (let start = 0; start < dids.length || (start === 0 && feeds); start += FOLLOW_BATCH) {
		const batch = dids.slice(start, start + FOLLOW_BATCH)
		const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/starter-pack/follow'), {
			pack,
			dids: batch,
			feeds: feeds && start === 0,
		})
		result.followed.push(...(data.followed ?? []))
		result.failed.push(...(data.failed ?? []))
		progress(Math.min(dids.length, start + batch.length))
	}

	return result
}
