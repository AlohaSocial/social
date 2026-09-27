/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The routes of self-registered external users: the registration page, the
 * External users cards of the settings page, and the invitations a person may
 * send. Spelled here once, so a component cannot drift from its endpoint.
 */

/**
 * An administration route of the External users cards.
 *
 * @param {string} [path] appended below /apps/social/admin/external
 * @return {string} the whole URL
 */
export function externalUrl(path = '') {
	return generateUrl('/apps/social/admin/external' + path)
}

/**
 * The registration routes.
 *
 * @param {string} [path] appended below /apps/social/signup
 * @return {string} the whole URL
 */
export function signupUrl(path = '') {
	return generateUrl('/apps/social/signup' + path)
}

/**
 * The invitations a person sends.
 *
 * @param {string} [path] appended below /apps/social/invites
 * @return {string} the whole URL
 */
export function invitesUrl(path = '') {
	return generateUrl('/apps/social/invites' + path)
}

/**
 * Asks for the password again where Nextcloud wants it recently confirmed.
 *
 * Nextcloud's own dialog, through the global it keeps for pages that do not
 * bundle `@nextcloud/password-confirmation`. Resolves at once when the
 * password was confirmed a moment ago, rejects when the dialog is dismissed.
 *
 * @return {Promise<void>}
 */
export function confirmPassword() {
	return new Promise((resolve, reject) => {
		const confirmation = window.OC?.PasswordConfirmation
		if (!confirmation) {
			resolve()
			return
		}

		confirmation.requirePasswordConfirmation(() => resolve(), {}, () => reject(new Error('password confirmation dismissed')))
	})
}
