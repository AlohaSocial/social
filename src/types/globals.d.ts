/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The globals the Nextcloud server puts on the page. They are not imported,
 * so the type checker has to be told they exist; eslint.config.mjs declares
 * the same set for the linter.
 *
 * `OC` and `OCA` are the server's own namespaces, which have no types this app
 * can import; `any` is what they are, so the rule against it is off here rather
 * than worked around with a shape that would only pretend to be accurate.
 */
/* eslint-disable @typescript-eslint/no-explicit-any */

declare function t(app: string, text: string, vars?: Record<string, unknown>, count?: number): string
declare function n(app: string, singular: string, plural: string, count: number, vars?: Record<string, unknown>): string

declare const appName: string
declare const OC: any
declare const OCA: any

// set by each entry point before anything loads a chunk, as webpack's own
// typings declare them
declare let __webpack_nonce__: string
declare let __webpack_public_path__: string

interface Window {
	OC: any
	OCA: any
	_oc_webroot: string
	/**
	 * What the server says about the reader's own avatar. The version is
	 * bumped whenever the picture changes, and goes in the address of every
	 * avatar this app builds by hand; see services/avatar.js. Absent on a page
	 * nobody is signed in to.
	 */
	oc_userconfig?: {
		avatar?: {
			version?: number
			generated?: boolean
		}
	}
}

/**
 * A stylesheet imported for its side effect, which webpack turns into a
 * `<style>` and TypeScript has nothing to resolve: `@nextcloud/dialogs` ships
 * the rules its toasts are drawn with in one, and `services/toast.js` pulls it
 * into the same chunk as the library.
 */
declare module '*.css'
