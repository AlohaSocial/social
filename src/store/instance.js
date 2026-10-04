/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'

import { afterFirstTimeline } from '../services/boot.js'
import { DEFAULT_LIMITS, loadLimits } from '../services/instanceLimits.js'

/**
 * The server's limits, reactive: the composer's counter and its attachment
 * ceiling read these, and they change once when the server has answered.
 * The asking itself lives in `services/instanceLimits.js`, which the Files
 * action shares without a store.
 */
export const useInstanceStore = defineStore('instance', {
	state: () => ({
		/** @type {number} */
		maxCharacters: DEFAULT_LIMITS.maxCharacters,
		/** @type {number} */
		maxAttachments: DEFAULT_LIMITS.maxAttachments,
		/**
		 * The most bytes an upload that is not a video may have.
		 *
		 * @type {number}
		 */
		imageSizeLimit: DEFAULT_LIMITS.imageSizeLimit,
		/**
		 * The most bytes a video may have.
		 *
		 * @type {number}
		 */
		videoSizeLimit: DEFAULT_LIMITS.videoSizeLimit,
		/**
		 * Whether this Nextcloud can translate a post at all.
		 *
		 * Typed rather than inferred: `DEFAULT_LIMITS` is frozen, so the
		 * inferred type of this field would be the literal `false` and the
		 * server's answer could never be written into it.
		 *
		 * @type {boolean}
		 */
		translation: DEFAULT_LIMITS.translation,
		/** whether the server has been asked on this page */
		loaded: false,
	}),

	actions: {
		/**
		 * Reads the limits from the server, once per page; every later call
		 * is answered from what the first one brought.
		 *
		 * Behind the timeline, like the sidebar's own requests: the composer
		 * opens on the fallback numbers and corrects itself, which nobody can
		 * type fast enough to notice, where a request racing the timeline for
		 * a PHP worker is something everybody notices.
		 */
		load() {
			if (this.loaded) {
				return
			}

			this.loaded = true
			afterFirstTimeline(async () => {
				const limits = await loadLimits()
				this.maxCharacters = limits.maxCharacters
				this.maxAttachments = limits.maxAttachments
				this.imageSizeLimit = limits.imageSizeLimit
				this.videoSizeLimit = limits.videoSizeLimit
				this.translation = limits.translation
			})
		},
	},
})
