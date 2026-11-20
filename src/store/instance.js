/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'

import { afterFirstTimeline } from '../services/boot.js'
import { DEFAULT_LIMITS, loadLimits } from '../services/instanceLimits.js'

/**
 * The server's limits, reactive, for the composer. The request itself is in
 * `services/instanceLimits.js`, which the Files action uses without a store.
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
		 * Whether this Nextcloud can translate a post at all. Typed
		 * explicitly: `DEFAULT_LIMITS` is frozen, so it would infer `false`.
		 *
		 * @type {boolean}
		 */
		translation: DEFAULT_LIMITS.translation,
		/** whether the server has been asked on this page */
		loaded: false,
	}),

	actions: {
		/**
		 * Reads the limits from the server once per page, after the
		 * timeline; until then the composer uses the fallback numbers.
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
