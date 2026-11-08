/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { cloudAddressProblems } from '../../../src/utils/cloudAddress.js'

// the same cases as tests/Service/CloudAddressRulesTest.php, so the form and
// the server agree on what they refuse
describe('cloudAddressProblems', () => {
	it.each([
		['https://cloud.example', []],
		['https://example.org/nextcloud/', []],
		['https://cloud.example:8443', []],
		['http://cloud.example', ['http']],
		['https://localhost', ['loopback']],
		['https://nc.localhost', ['loopback']],
		['https://127.0.0.1', ['loopback', 'ip']],
		['https://[::1]', ['loopback', 'ip']],
		['https://203.0.113.7', ['ip']],
		['https://cloud.example/index.php', ['index_php']],
		['https://example.org/nc/index.php/', ['index_php']],
		['http://127.0.0.1/index.php', ['http', 'loopback', 'ip', 'index_php']],
		['cloud.example', ['invalid']],
		['ftp://cloud.example', ['invalid']],
		['https://cloud.example/?x=1', ['invalid']],
		['https://me@cloud.example', ['invalid']],
		['', ['invalid']],
	])('%s', (address, expected) => {
		expect(cloudAddressProblems(address)).toEqual(expected)
	})
})
