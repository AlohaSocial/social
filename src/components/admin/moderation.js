/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * What suspending costs, shared by the reports table and the account browser.
 * A function because the translation is not loaded when the module is.
 *
 * @return {string} the warning
 */
export function suspensionWarning() {
	return t(
		'social',
		'Suspending deletes every post this account has here and refuses anything it sends afterwards. '
		+ 'Lifting the suspension later will not bring the posts back.',
	)
}

/**
 * What stands against an account, in the moderator's words.
 *
 * @param {string} level 'silence', 'suspend' or '' for nothing
 * @return {string} what the table shows
 */
export function stateOf(level) {
	if (level === 'suspend') {
		return t('social', 'Suspended')
	}

	return level === 'silence' ? t('social', 'Silenced') : t('social', 'Nothing')
}
