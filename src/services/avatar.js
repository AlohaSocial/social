/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

/**
 * Where to find the reader's own face. The avatar route is cached as immutable
 * for a day, so the version Nextcloud bumps on every change goes in the
 * address; `NcAvatar` does this for a `user` but trusts a `url` as it stands.
 *
 * @param {number} size how many pixels across, as the route wants it
 * @return {string} the address, or an empty string when nobody is logged in
 */
export function ownAvatarUrl(size = 64) {
	const uid = getCurrentUser()?.uid
	if (!uid) {
		return ''
	}

	// 0 for an account that never changed its picture, which is what the
	// server reports for one and is a perfectly good cache key.
	const version = window.oc_userconfig?.avatar?.version ?? 0

	return generateUrl('/avatar/{uid}/{size}', { uid, size }) + '?v=' + version
}
