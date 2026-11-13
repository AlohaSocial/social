/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Solves the registration form's proof of work off the page's thread.
 * Receives `{challenge, bits}`, posts `{type: 'progress', attempts}` as it
 * goes and `{type: 'done', nonce}` at the end. The page terminates it to
 * stop it.
 */
import { solve } from '../utils/proofOfWork.js'

self.onmessage = async ({ data }) => {
	const nonce = await solve(data.challenge, data.bits, {
		onProgress: (attempts) => self.postMessage({ type: 'progress', attempts }),
	})
	self.postMessage({ type: 'done', nonce })
}
