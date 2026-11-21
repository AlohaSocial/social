/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * Install the Nextcloud globals used by Social templates in the profile app.
 * A custom element owns a separate Vue app, so it cannot inherit the globals
 * installed by the main Social entry.
 *
 * @param {{config: {globalProperties: Record<string, unknown>}}} app Vue app created by `defineCustomElement`
 * @param {{OC?: unknown, OCA?: unknown}} globals Nextcloud's `OC` and `OCA` globals
 */
export function configureSocialProfileApp(app, globals) {
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.config.globalProperties.OC = globals.OC
	app.config.globalProperties.OCA = globals.OCA
}

/**
 * Register a Social section with the Nextcloud Profile app. The Profile app
 * creates its `ProfileSections` registry just before mounting and reads it
 * during the mount, after this classic script has run and too soon for any
 * deferral, so the registry's first assignment is intercepted and the section
 * registered synchronously. An existing registry is used at once.
 *
 * @param {{ProfileSections?: {registerSection?: (section: object) => void}}} profile the `OCA.Profile` namespace
 * @param {object} section section descriptor accepted by `registerSection`
 * @return {boolean} whether registration is immediate or has been queued
 */
export function registerProfileSection(profile, section) {
	if (profile.ProfileSections?.registerSection) {
		profile.ProfileSections.registerSection(section)
		return true
	}

	const descriptor = Object.getOwnPropertyDescriptor(profile, 'ProfileSections')
	if (descriptor && descriptor.configurable === false) {
		return false
	}

	let registry
	Object.defineProperty(profile, 'ProfileSections', {
		configurable: true,
		enumerable: descriptor?.enumerable ?? true,
		get: () => registry,
		set(value) {
			// an ordinary data property again, as the Profile app expects
			Object.defineProperty(profile, 'ProfileSections', {
				configurable: true,
				enumerable: descriptor?.enumerable ?? true,
				writable: true,
				value,
			})
			registry = value
			value?.registerSection?.(section)
		},
	})

	return true
}
