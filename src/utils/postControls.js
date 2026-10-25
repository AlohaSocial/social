/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Elements inside a post that perform their own action. */
const POST_CONTROLS = 'a, button, input, textarea, select, label, video, audio, [role="button"], .post-actions, .v-popper'

/**
 * Whether a click targeted a link, control or media player inside a post.
 *
 * @param {Event} event the user action
 * @return {boolean}
 */
export function isPostControl(event) {
	const path = event.composedPath?.() ?? []
	const nodes = path.length > 0 ? path : [event.target]

	return nodes.some((node) => node instanceof Element && node.matches(POST_CONTROLS))
}
