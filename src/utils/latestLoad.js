/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Loads of which only the newest may say anything: the router reuses a view
 * when a parameter changes, so an older answer can arrive last. Each load
 * takes a ticket and checks after every await whether it is still the newest.
 *
 * @return {{begin: () => (() => boolean), current: () => (() => boolean)}}
 */
export function latestLoad() {
	let newest = 0

	/**
	 * @param {number} ticket the generation a load belongs to
	 * @return {() => boolean} whether that generation is still the newest
	 */
	const check = (ticket) => () => ticket === newest

	return {
		/** @return {() => boolean} a new generation, which outdates every earlier one */
		begin: () => check(++newest),
		/** @return {() => boolean} the generation already running, for work that continues it */
		current: () => check(newest),
	}
}
