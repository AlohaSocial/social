/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { humanSize } from '../../../src/utils/humanSize.js'

describe('humanSize', () => {
	it('names a size in the unit a person reads', () => {
		expect(humanSize(0)).toBe('0 B')
		expect(humanSize(512)).toBe('512 B')
		expect(humanSize(1536)).toBe('1.5 KiB')
		expect(humanSize(5 * 1048576)).toBe('5.0 MiB')
		expect(humanSize(300 * 1048576)).toBe('300 MiB')
		expect(humanSize(2 * 1024 ** 4)).toBe('2.0 TiB')
	})

	it('reads nonsense as nothing', () => {
		expect(humanSize(-5)).toBe('0 B')
		expect(humanSize(undefined)).toBe('0 B')
	})
})
