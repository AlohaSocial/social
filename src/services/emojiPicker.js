/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

let picker = null

/**
 * The one place the emoji picker is imported from, fetched on first use: its
 * chunk is most of a megabyte. Keep this the only `import()` of it, since a
 * second import site makes webpack copy the shared l10n chunk out of
 * `social-framework` into every entry bundle.
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
