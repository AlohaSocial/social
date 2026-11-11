/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { useSettingsStore } from '../store/settings.js'

/**
 * What the page says the app need not ask for (`StartupHintsService`):
 * `has_announcements`, `has_stories`, `recap_enabled`, `has_memories` and
 * `has_teams`, each `false` only where the answer is certainly empty.
 *
 * @param {string} name the hint
 * @return {boolean} whether to ask the server; true where the page said nothing
 */
export function mayHave(name) {
	return useSettingsStore().getServerData?.startup?.[name] !== false
}

/**
 * Corrects a hint after something in this session changed what it says.
 *
 * @param {string} name the hint
 * @param {boolean} value what it is now
 */
export function setHint(name, value) {
	const startup = useSettingsStore().getServerData?.startup
	if (startup) {
		startup[name] = value
	}
}
