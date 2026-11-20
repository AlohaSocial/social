/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'
import { computed } from 'vue'

import { useServerData } from './useServerData.js'

/**
 * Who is reading: the Nextcloud user, and their account here. The account's
 * username need not be the user id, which is only for what Nextcloud itself
 * answers to (avatars, its profile page).
 *
 * @return {{currentUser: import('vue').ComputedRef<object>, username: import('vue').ComputedRef<string>, cloudId: import('vue').ComputedRef<string>, socialId: import('vue').ComputedRef<string>, isOwnHandle: (handle: unknown) => boolean}}
 */
export function useCurrentUser() {
	const { serverData, hostname } = useServerData()

	/** the Nextcloud user, as @nextcloud/auth reads it off the page */
	const currentUser = computed(() => getCurrentUser())

	/** the account's username, or '' where there is no account */
	const username = computed(() => serverData.value.viewer?.username ?? '')

	/** the account's own address, `username@host`, or '' */
	const cloudId = computed(() => serverData.value.viewer?.address ?? '')

	/** the same address as a mention, or '' */
	const socialId = computed(() => cloudId.value === '' ? '' : '@' + cloudId.value)

	/**
	 * Whether a handle names the reader's own account: its username alone,
	 * as a local account's profile route has it, or with this server's host
	 * or the host its addresses end in.
	 *
	 * @param {unknown} handle a handle, with or without a leading `@`
	 * @return {boolean}
	 */
	const isOwnHandle = (handle) => {
		const own = username.value.toLowerCase()
		const asked = String(handle ?? '').replace(/^@/, '').toLowerCase()
		if (own === '' || asked === '') {
			return false
		}

		return asked === own
			|| asked === own + '@' + hostname.value.toLowerCase()
			|| asked === cloudId.value.toLowerCase()
	}

	return { currentUser, username, cloudId, socialId, isOwnHandle }
}
