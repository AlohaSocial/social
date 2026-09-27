/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

let picker = null

/**
 * The one place the emoji picker is imported from.
 *
 * It carries the whole emoji set — the built chunk is most of a megabyte — so
 * it is fetched when somebody first asks for one rather than by every reader
 * of every page. Two components want it: the composer, and the reaction bar on
 * every card.
 *
 * **One `import()` specifier, deliberately.** Writing the same dynamic import
 * in both components gave webpack two import sites for the same module, and
 * its chunking then moved `@nextcloud/vue`'s shared l10n chunk out of the
 * common `social-framework` bundle and copied it into each entry instead —
 * the app's own entry went from 327 KB to 600 KB and the dashboard's from 66
 * KB to 248 KB, for a picker neither of them loads up front. Importing it
 * through this module keeps it one site and one chunk.
 *
 * @return {Promise<object>} the picker module, fetched once per page
 */
export function emojiPickerModule() {
	if (picker === null) {
		picker = import('@nextcloud/vue/components/NcEmojiPicker').catch((error) => {
			// A failed chunk request can be transient (offline, proxy timeout,
			// stale cache). Do not memoize the rejection: the composer keeps its
			// button available so the reader can try again.
			picker = null
			throw error
		})
	}

	return picker
}
