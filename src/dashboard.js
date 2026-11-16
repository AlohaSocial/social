/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import pinia from './store/index.js'
import Dashboard from './views/Dashboard.vue'
import './styles/motion.css'

const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}

__webpack_public_path__ = window.OC?.linkTo('social', 'js/') ?? '/apps/social/js/'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('social_notifications', (el, { widget }) => {
		const app = createApp(Dashboard, { title: widget.title })
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
		app.config.globalProperties.OC = window.OC
		app.config.globalProperties.OCA = window.OCA
		app.use(pinia)
		app.mount(el)
	})
})
