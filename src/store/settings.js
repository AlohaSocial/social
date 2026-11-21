/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'

/**
 * What this store holds, for the getter that is handed it.
 *
 * @typedef {object} SettingsState
 * @property {import('../composables/useServerData.js').ServerData} serverData what the server put in the page
 */

/**
 * What the server told the page about itself, as `templates/main.php` sent it.
 */
export const useSettingsStore = defineStore('settings', {
	state: () => ({
		serverData: {},
	}),

	getters: {
		/**
		 * @param {SettingsState} state the store state
		 * @return {import('../composables/useServerData.js').ServerData} the whole block
		 */
		getServerData(state) {
			return state.serverData
		},

		/**
		 * Whether the reader hides how many likes, dislikes, boosts and followers
		 * things have. The page needs to know so that it does not count up its own
		 * like to 1 where the server sent zero.
		 *
		 * @param {SettingsState} state the store state
		 * @return {boolean}
		 */
		hidesCounts(state) {
			return state.serverData?.hideCounts === true
		},

		/**
		 * Whether standalone replies are omitted from the feeds.
		 *
		 * @param {SettingsState} state the store state
		 * @return {boolean}
		 */
		hidesRepliesInFeeds(state) {
			return state.serverData?.hideRepliesInFeeds === true
		},

		/**
		 * Whether the one-key shortcuts are on; they are unless turned off.
		 *
		 * @param {SettingsState} state the store state
		 * @return {boolean}
		 */
		shortcutsEnabled(state) {
			return state.serverData?.keyboardShortcuts !== false
		},
	},

	actions: {
		setServerData(data) {
			this.serverData = data
		},
		setServerDataEntry({ key, value }) {
			this.serverData[key] = value
		},
	},
})
