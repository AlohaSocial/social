/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Administration → Social.
 *
 * One entry for the whole page, where there used to be three scripts that each
 * drew a part of it out of plain DOM calls. They were written that way because
 * the page was server-rendered PHP and mounting Vue on it would have pulled the
 * runtime into a page that had none; the app ships a shared `social-framework`
 * chunk now, every other page it has is Vue, and this one had stopped looking
 * like the rest of the administration settings.
 */
import { createApp } from 'vue'
import { generateFilePath } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import AdminSettings from './components/admin/AdminSettings.vue'
import './styles/motion.css'

const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}

__webpack_public_path__ = generateFilePath('social', '', 'js/')

/**
 * Mounts the page on the element the template leaves for it.
 *
 * @return {boolean} whether this was the administration page
 */
export function mount() {
	const element = document.getElementById('social-admin-settings')
	if (element === null) {
		return false
	}

	const app = createApp(AdminSettings)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.config.globalProperties.OC = window.OC
	app.config.globalProperties.OCA = window.OCA
	app.mount(element)

	return true
}

// the bundle is deferred, so DOMContentLoaded may already have gone by the
// time it runs; waiting for an event that has fired leaves the page empty
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount)
} else {
	mount()
}
