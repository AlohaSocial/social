/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineCustomElement, h } from 'vue'
import pinia from './store/index.js'
import ProfilePageIntegration from './views/ProfilePageIntegration.vue'
import { generateFilePath } from '@nextcloud/router'
import { configureSocialProfileApp, registerProfileSection } from './services/profileSections.js'
import './styles/motion.css'

const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}

__webpack_public_path__ = generateFilePath('social', '', 'js/')

const profileSectionTagName = 'social-profile-section'

const SocialProfileSectionElement = defineCustomElement({
	props: {
		user: {
			type: String,
			default: '',
		},
	},
	render() {
		return h(ProfilePageIntegration, { userId: this.user })
	},
}, {
	// The Profile page owns the surrounding layout and uses Nextcloud's global
	// theme styles. Keep this integration in the light DOM so Social's existing
	// timeline styles and native Nextcloud controls can style its entries too.
	shadowRoot: false,
	// the custom element runs an app of its own, and the posts it renders read
	// the store like every other timeline entry does
	configureApp(app) {
		// Custom elements have their own Vue app, outside the app created by
		// `main.js`. Install the same Nextcloud globals that its templates use.
		configureSocialProfileApp(app, { OC: window.OC, OCA: window.OCA })
		app.use(pinia)
	},
})

if (!customElements.get(profileSectionTagName)) {
	customElements.define(profileSectionTagName, SocialProfileSectionElement)
}

// `ProfileSections` is created by the Profile app's module entry, which runs
// after this classic Social entry. Register on its first assignment so the
// Profile page's initial read sees Social before it mounts.
const profileNamespace = window.OCA?.Profile ?? (window.OCA.Profile = {})
registerProfileSection(profileNamespace, {
	id: 'social-profile-section',
	order: 0,
	tagName: profileSectionTagName,
})
