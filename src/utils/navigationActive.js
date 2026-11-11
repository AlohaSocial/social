/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The timeline types My Feed stays lit for: Local and Global are scopes of
 * its page, set by the switcher on it, rather than pages of their own.
 */
export const MY_FEED_COVERS = Object.freeze(['', 'timeline', 'federated'])

/**
 * Whether a navigation entry is the page on screen. An entry matches its own
 * route and any dot-namespaced child of it, so Profile stays lit on
 * `profile.followers`, while a sibling that differs only by a param does not
 * match. The sidebar and the phone bar both ask this, so the two never
 * disagree about where the reader is.
 *
 * @param {{name?: unknown, params?: object}} route the current route
 * @param {{to: string|{name: string, params?: object}, covers?: readonly string[]}} item a menu entry
 * @return {boolean} whether it is the current page
 */
export function isEntryActive(route, item) {
	const to = item.to
	if (typeof to === 'string') {
		return false
	}
	const name = String(route?.name ?? '')
	if (name !== to.name && !name.startsWith(to.name + '.')) {
		return false
	}

	// an entry whose page is read at more than one scope owns all of them:
	// the type says which, and the switcher on the page says which one of the
	// entry's own scopes is being read
	if (item.covers !== undefined) {
		return item.covers.includes(String(route.params?.type ?? ''))
	}

	// An optional param the URL leaves out still arrives as '', so the two
	// sides are compared value by value across both key sets: counting keys
	// makes /timeline, whose params are {type: ''}, look different from the
	// My Feed entry, which carries no params at all.
	const wanted = to.params ?? {}
	const actual = route.params ?? {}
	for (const key of new Set([...Object.keys(wanted), ...Object.keys(actual)])) {
		if (String(wanted[key] ?? '') !== String(actual[key] ?? '')) {
			return false
		}
	}

	return true
}
