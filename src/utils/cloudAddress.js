/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What is wrong with an address every id would be built from, by the same
 * rules as `CloudAddressRules` on the server, which has the last word.
 *
 * `invalid` cannot be overridden; the others can, for a test instance.
 *
 * @param {string} address what the administrator typed
 * @return {string[]} problem codes, empty when there is none
 */
export function cloudAddressProblems(address) {
	const trimmed = String(address ?? '').trim().replace(/\/+$/, '')
	let url
	try {
		url = new URL(trimmed)
	} catch {
		return ['invalid']
	}
	if (!['http:', 'https:'].includes(url.protocol) || url.hostname === ''
		|| url.search !== '' || url.hash !== '' || url.username !== '' || url.password !== ''
		|| trimmed.includes('?') || trimmed.includes('#')) {
		return ['invalid']
	}

	const problems = []
	if (url.protocol === 'http:') {
		problems.push('http')
	}

	const host = url.hostname.toLowerCase().replace(/^\[|\]$/g, '')
	const isIp = /^\d{1,3}(\.\d{1,3}){3}$/.test(host) || host.includes(':')
	if (host === 'localhost' || host.endsWith('.localhost')
		|| (isIp && (host.startsWith('127.') || host === '::1' || host === '0.0.0.0'))) {
		problems.push('loopback')
	}
	if (isIp) {
		problems.push('ip')
	}

	if (/\/index\.php(\/|$)/i.test(url.pathname)) {
		problems.push('index_php')
	}

	return problems
}
