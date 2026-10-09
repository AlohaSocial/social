/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Shared lists of accounts to mute or block: somebody else keeps the list,
 * and everybody on it is muted or blocked here for as long as the reader
 * subscribes to it.
 *
 * @typedef {object} ModerationList
 * @property {string} uri the list's address
 * @property {string} name what its owner calls it
 * @property {'mute'|'block'} kind what it does to everybody on it
 * @property {number} accounts how many accounts it mutes or blocks here
 */

/** @return {string} the one URL everything here talks to */
function url() {
	return generateUrl('/apps/social/api/v1/social/moderation_lists')
}

/**
 * @param {unknown} data an answer
 * @return {ModerationList[]}
 */
function listsOf(data) {
	return Array.isArray(data) ? data : []
}

/** @return {Promise<ModerationList[]>} the lists the reader subscribes to */
export async function fetchModerationLists() {
	const { data } = await axios.get(url())

	return listsOf(data)
}

/**
 * @param {string} link the list's link, as its network shows it
 * @param {'mute'|'block'} kind what to do to everybody on it
 * @return {Promise<ModerationList>} the subscription
 */
export async function subscribeModerationList(link, kind) {
	const { data } = await axios.post(url(), { url: link.trim(), kind })

	return data
}

/**
 * @param {string} uri the list's address
 * @return {Promise<ModerationList[]>} the lists left
 */
export async function unsubscribeModerationList(uri) {
	const { data } = await axios.delete(url(), { data: { uri } })

	return listsOf(data)
}
