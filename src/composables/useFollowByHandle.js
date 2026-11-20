/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'
import { useAccountStore } from '../store/account.js'

/**
 * Following somebody from a list of strangers, by handle. `FollowButton`
 * waits for a relationship the store never fetches for such accounts, so it
 * renders nothing in search results or suggestions; and a remote account has
 * no local id until following makes this instance hear of it.
 *
 * @return {{following: import('vue').Ref<string>, followed: import('vue').Ref<string[]>, follow: (account: object) => Promise<void>, isFollowed: (account: object) => boolean, isPending: (account: object) => boolean}}
 *         the state a row needs to draw its own button
 */
export function useFollowByHandle() {
	const accountStore = useAccountStore()
	/** the handle a follow is in flight for, so only that row shows a spinner */
	const following = ref('')
	/** the handles taken during this visit; the list itself is not refetched */
	const followed = ref([])

	/**
	 * @param {object} account the row's account, as the server described it
	 * @return {Promise<void>} when the request has settled either way
	 */
	async function follow(account) {
		const handle = account?.acct ?? ''
		if (handle === '' || following.value === handle) {
			return
		}

		following.value = handle
		try {
			const response = await accountStore.followAccount({ accountToFollow: handle })
			if (response) {
				followed.value = [...followed.value, handle]
			}
		} finally {
			// whatever happened, this row is no longer waiting
			following.value = ''
		}
	}

	/**
	 * @param {object} account the row's account
	 * @return {boolean} whether this visit followed them
	 */
	function isFollowed(account) {
		const handle = account?.acct ?? ''

		return handle !== '' && followed.value.includes(handle)
	}

	/**
	 * A row with no handle is never the one waiting: idle is also the empty
	 * string, and such a row would otherwise spin forever.
	 *
	 * @param {object} account the row's account
	 * @return {boolean} whether their request is in flight
	 */
	function isPending(account) {
		const handle = account?.acct ?? ''

		return handle !== '' && following.value === handle
	}

	return { following, followed, follow, isFollowed, isPending }
}
