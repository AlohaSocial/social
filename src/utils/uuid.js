/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @return {string} a cryptographically random UUID v4 */
export function uuid() {
	const cryptoApi = globalThis.crypto
	if (typeof cryptoApi?.randomUUID === 'function') {
		return cryptoApi.randomUUID()
	}
	if (typeof cryptoApi?.getRandomValues !== 'function') {
		throw new Error('Secure random values are unavailable')
	}

	const bytes = cryptoApi.getRandomValues(new Uint8Array(16))
	bytes[6] = (bytes[6] & 0x0f) | 0x40
	bytes[8] = (bytes[8] & 0x3f) | 0x80
	const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

	return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
