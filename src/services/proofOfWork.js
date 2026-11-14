/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { signupUrl } from './externalApi.js'
import { solve } from '../utils/proofOfWork.js'

/**
 * A fresh challenge for the registration form.
 *
 * @return {Promise<{challenge: string, bits: number, expires: number}>}
 */
export async function fetchChallenge() {
	const { data } = await axios.get(signupUrl('/challenge'))

	return data
}

/**
 * Starts solving a challenge: in a Web Worker, so the page stays responsive,
 * or on the page's own thread in batches where no worker can be started.
 *
 * @param {{challenge: string, bits: number}} challenge what the server issued
 * @param {function(number): void} onProgress called with the attempts so far
 * @return {{nonce: Promise<string|null>, cancel: function(): void}} the
 *         nonce, null once cancelled
 */
export function startSolving({ challenge, bits }, onProgress) {
	let worker = null
	try {
		worker = new Worker(/* webpackChunkName: "signup-pow" */ new URL('../workers/proofOfWork.worker.js', import.meta.url))
	} catch {
		worker = null
	}

	if (worker === null) {
		let cancelled = false

		return {
			nonce: solve(challenge, bits, { onProgress, cancelled: () => cancelled }),
			cancel: () => {
				cancelled = true
			},
		}
	}

	/** @type {function((string|null|Promise<string|null>)): void} */
	let settle = () => {}
	/** @type {Promise<string|null>} */
	const nonce = new Promise((resolve) => {
		settle = resolve
	})
	worker.onmessage = ({ data }) => {
		if (data.type === 'progress') {
			onProgress(data.attempts)
			return
		}
		worker.terminate()
		settle(data.nonce)
	}
	worker.onerror = () => {
		worker.terminate()
		let cancelled = false
		settle(solve(challenge, bits, { onProgress, cancelled: () => cancelled }))
		worker = {
			terminate: () => {
				cancelled = true
			},
		}
	}
	worker.postMessage({ challenge, bits })

	return {
		nonce,
		cancel: () => {
			worker.terminate()
			settle(null)
		},
	}
}
