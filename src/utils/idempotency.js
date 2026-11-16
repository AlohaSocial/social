/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { uuid } from './uuid.js'

/**
 * The Idempotency-Key of a post being sent, kept across retries.
 *
 * A post whose answer was lost (a timeout, a proxy error after the server
 * had already created it) is sent again with the same key, and the server
 * answers with the post it made the first time instead of making a second.
 * The key belongs to what is sent: a changed post is a new post and gets a
 * new key, and a post that went out lets the next one start afresh.
 *
 * @return {{keyFor: function(object): string, sent: function(): void}} the keeper
 */
export function idempotencyKeeper() {
	/** @type {{body: string, key: string}|null} */
	let pending = null

	return {
		keyFor(body) {
			const serialized = JSON.stringify(body)
			if (pending === null || pending.body !== serialized) {
				pending = { body: serialized, key: uuid() }
			}
			return pending.key
		},
		sent() {
			pending = null
		},
	}
}
