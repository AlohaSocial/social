/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed } from 'vue'

import { useSettingsStore } from '../store/settings.js'

/**
 * What `CheckService::checkDefault()` found, for an administrator's page.
 *
 * @typedef {object} SetupChecks
 * @property {boolean} success - Whether none of the checks failed
 * @property {{wellknown: ?boolean, cloudAddress: boolean, clientApi: boolean}} checks - Each check's result; null where there was nothing to check
 * @property {{configured: string, expected: string}} addresses - The address ids are built from, and the one the server reports
 * @property {Array<{base: string, status: number, reason: string}>} clientApi - What a failed client-API probe saw; empty where it passed
 */

/**
 * The sections this instance offers (`SectionsService::current()`).
 *
 * @typedef {object} Sections
 * @property {boolean} stories - Whether 24-hour shorts are offered
 * @property {boolean} section_photos - Whether the Photos section is offered
 * @property {boolean} section_videos - Whether the Videos section is offered
 * @property {string[]} group_lists - The groups offered as lists, by id
 */

/**
 * @typedef {object} ServerData
 * @property {string} account - The account that the user wants to follow (Only in 'OStatus.vue')
 * @property {SetupChecks} [checks] - The setup checks, only for an administrator
 * @property {{enabled: boolean, learning: boolean, paused: boolean, noticeAcknowledged: boolean, profile?: boolean}} [interests] - For you: whether the feature is on for this instance, whether this
 *   reader learns, and whether they have paused it (`InterestService::pageState()`)
 * @property {string} cliUrl - The URL the background jobs address this instance by
 * @property {string} cloudAddress - This instance's own address
 * @property {boolean} firstrun - Whether the reader has not used the app before
 * @property {boolean} [introductionDismissed] - Whether this account has dismissed Social's introduction
 * @property {boolean} isAdmin - Whether the reader administers this instance
 * @property {string} [linkedHandle] - The fediverse handle the reader's profile names, or '' (only with needsAccount)
 * @property {string} local - The local part of the account that the user wants to follow
 * @property {boolean} needsAccount - Whether the reader has no account in this app yet
 * @property {boolean} [hideCounts] - Whether the reader hides how many likes, dislikes, boosts and followers things have
 * @property {''|'show_all'|'default'|'hide_all'} [nsfwChoice] - What the reader chose for sensitive media; '' follows the instance (not on a public page)
 * @property {'show_all'|'default'|'hide_all'} [nsfwPolicy] - What happens to sensitive media for this reader (not on a public page)
 * @property {boolean} public - False when the page is accessed by an authenticated user. True otherwise
 * @property {{quota: number, used: number}|null} [externalMedia] - A self-registered external user's media quota in MB (0 is none) and bytes used; null for everybody else
 * @property {boolean} [externalInvites] - Whether people may invite others to register on this server
 * @property {Sections} [sections] - The sections this instance offers (not on a public page)
 * @property {boolean} setup - Whether the instance still needs its address confirmed
 * @property {string} [suggestedHandle] - The handle offered to somebody creating an account (only with needsAccount)
 */

/**
 * What the server told the page about itself.
 *
 * @return {{serverData: import('vue').ComputedRef<ServerData>, hostname: import('vue').ComputedRef<string>}}
 */
export function useServerData() {
	const settingsStore = useSettingsStore()

	/** @type {import('vue').ComputedRef<ServerData>} */
	const serverData = computed(() => settingsStore.getServerData)

	/**
	 * The host of this instance, as the browser parses its address — so a
	 * cloud address with a scheme, a port or a path still gives the bare host
	 * that a handle is built from.
	 */
	const hostname = computed(() => {
		const url = document.createElement('a')
		url.setAttribute('href', serverData.value.cloudAddress)

		return url.hostname
	})

	return { serverData, hostname }
}
