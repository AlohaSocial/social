/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { uuid } from './uuid.js'

/**
 * The Idempotency-Key of a post being sent, kept across retries.
 *
 * A retry after a lost answer reuses the key, so the server returns the post
 * it already made. A changed body or a sent post gets a new key.
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
