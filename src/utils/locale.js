/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale, getLanguage } from '@nextcloud/l10n'

/**
 * The locale `Intl` formats in: the reader's Nextcloud locale, else their
 * interface language, else the browser's. A tag Intl rejects is skipped.
 *
 * @return {string|undefined} a locale tag, or nothing for the browser's
 */
export function userLocale() {
	for (const read of [getCanonicalLocale, getLanguage]) {
		try {
			const locale = read()
			if (locale && Intl.getCanonicalLocales(locale).length > 0) {
				return locale
			}
		} catch {
			// not set on this page, or not a tag Intl reads: the next one
		}
	}

	return undefined
}
