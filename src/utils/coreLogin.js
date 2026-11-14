/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

/**
 * Logs in through Nextcloud's own login form, as if the person had typed into
 * it: a top-level POST from this origin with this session's request token,
 * so core's checks, brute-force protection, two-factor login and session
 * handling all apply, and its redirect to `redirect_url` follows.
 *
 * @param {object} login what to log in with
 * @param {string} login.user the login name
 * @param {string} login.password the password
 * @param {string} [login.redirectUrl] a path on this server to continue at, as core's login form takes it
 * @param {Document} [doc] the document to submit the form from
 * @return {HTMLFormElement} the form, already submitted
 */
export function submitCoreLogin({ user, password, redirectUrl = '' }, doc = document) {
	const form = doc.createElement('form')
	form.method = 'post'
	form.action = generateUrl('/login')
	form.hidden = true
	const fields = {
		user,
		password,
		requesttoken: getRequestToken() ?? '',
		timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? '',
		timezone_offset: String(-new Date().getTimezoneOffset() / 60),
	}
	if (redirectUrl) {
		fields.redirect_url = redirectUrl
	}
	for (const [name, value] of Object.entries(fields)) {
		const input = doc.createElement('input')
		input.type = 'hidden'
		input.name = name
		input.value = value
		form.appendChild(input)
	}
	doc.body.appendChild(form)
	form.submit()

	return form
}
