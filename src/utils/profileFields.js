/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The metadata rows under a profile, as text and an optional link, for the
 * profile page and the hover card.
 *
 * A field's `value` is HTML from somebody else, so it is parsed, not rendered:
 * the text is shown, and the anchor's `href` is kept only when it is `http(s)`.
 * A row without a link is never marked verified, whatever `verified_at` says.
 *
 * @param {Array<{name: string, value: string, verified_at?: string|null}>|undefined} fields as the account entity carries them
 * @param {number} [limit] how many to keep; Mastodon's own ceiling is four
 * @return {Array<{name: string, text: string, href: string, verified: boolean, verifiedAt: string}>} href is '' when the row is not a link
 */
export function profileFields(fields, limit = Infinity) {
	const parser = new DOMParser()

	return (fields ?? [])
		.slice(0, limit)
		.map((field) => {
			const doc = parser.parseFromString(field.value || '', 'text/html')
			const text = doc.body.textContent.trim()
			const anchor = doc.body.querySelector('a[href]')
			const href = anchor ? anchor.getAttribute('href') : text

			const safeHref = /^https?:\/\//.test(href) ? href : ''

			return {
				name: field.name,
				text,
				href: safeHref,
				verified: safeHref !== '' && Boolean(field.verified_at),
				verifiedAt: typeof field.verified_at === 'string' ? field.verified_at : '',
			}
		})
}

/**
 * The web address a typed field value names, or '' when it names none.
 *
 * Mirrors the plain-text branch of `ProfileLinkVerifier::linkOf()` on the
 * server, so a bare `example.org` is text and is never verified.
 *
 * @param {string} value a field value as it is typed
 * @return {string} the address, or ''
 */
export function fieldLink(value) {
	const match = /https?:\/\/[^\s<>"']+/i.exec(value ?? '')
	if (match === null) {
		return ''
	}

	try {
		// the parse is the check, as FILTER_VALIDATE_URL is on the server
		new URL(match[0])
	} catch {
		return ''
	}

	return match[0]
}
