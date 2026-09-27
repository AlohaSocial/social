/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The registration page of self-registered external users, drawn in the
 * guest layout the login page uses. No store: it is one form and what
 * became of it.
 */
import { createApp } from 'vue'
import { generateFilePath } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import Signup from './views/Signup.vue'

const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}

__webpack_public_path__ = generateFilePath('social', '', 'js/')

/**
 * Mounts the page on the element the template leaves for it.
 *
 * @return {boolean} whether this was the registration page
 */
export function mount() {
	const element = document.getElementById('social-signup')
	if (element === null) {
		return false
	}

	const app = createApp(Signup)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(element)

	return true
}

// the bundle is deferred, so DOMContentLoaded may already have gone by
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount)
} else {
	mount()
}
