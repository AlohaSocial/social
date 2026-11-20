/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getLanguage } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import { userKey } from './browserStore.js'

/**
 * The language a post is written in, as the composer offers it, so that
 * readers' language filters work on it. The server would fill an absent one
 * in from the poster's Nextcloud language; the composer shows that guess so
 * it can be corrected.
 */

const NAME = 'social.lastLanguage'

/**
 * Two-letter ISO 639-1 codes: the languages Nextcloud itself is translated
 * into, which is the set of people who can be writing here at all. Named at
 * run time in the reader's own language by `Intl.DisplayNames`, so the list
 * carries no names of its own to translate.
 */
export const POST_LANGUAGES = 'ar bg ca cs da de el en eo es et eu fa fi fr ga gl he hi hr hu id is it ja ka ko lt lv mk nb nl nn pl pt ro ru sk sl sq sr sv th tr uk vi zh'.split(' ')

/**
 * @param {unknown} code anything that claims to be a language code
 * @return {boolean} whether it is a two- or three-letter language tag
 */
export function isLanguageCode(code) {
	return typeof code === 'string' && /^[a-z]{2,3}$/.test(code)
}

/**
 * The language the composer starts with: the reader's Nextcloud language,
 * without its region, since Mastodon's language filters use the plain `de`.
 *
 * @return {string} a two-letter code, `en` when Nextcloud has none
 */
export function defaultLanguage() {
	const language = (getLanguage() || '').toLowerCase().slice(0, 2)

	return isLanguageCode(language) ? language : 'en'
}

/**
 * The language the last post went out with, when there was one.
 *
 * Reading localStorage throws in a private window and where site data is
 * blocked.
 *
 * @return {string} the code, or '' when nothing is remembered
 */
export function rememberedLanguage() {
	let remembered
	try {
		remembered = window.localStorage.getItem(userKey(NAME)) ?? ''
	} catch {
		return ''
	}

	return isLanguageCode(remembered) ? remembered : ''
}

/**
 * @param {string} code the language the reader chose
 */
export function rememberLanguage(code) {
	if (!isLanguageCode(code)) {
		return
	}

	try {
		window.localStorage.setItem(userKey(NAME), code)
	} catch (error) {
		logger.debug('Could not remember the language', { error })
	}
}

/**
 * A language named in the reader's own language: `de` is "German" to an
 * English reader and "Deutsch" to a German one. A code no browser knows is
 * shown as it is.
 *
 * @param {string} code a language code
 * @return {string}
 */
export function languageName(code) {
	try {
		return new Intl.DisplayNames([getLanguage() || 'en'], { type: 'language' }).of(code) ?? code
	} catch {
		return code
	}
}
