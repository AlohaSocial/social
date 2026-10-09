/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { ruleLabel, ruleParts, withPart } from '../../../src/utils/replyPolicy.js'

describe('reply rules', () => {
	it('are anybody, nobody or a combination of parts', () => {
		expect(ruleParts('everyone')).toEqual([])
		expect(ruleParts('nobody')).toEqual(['nobody'])
		expect(ruleParts('followers,list:3')).toEqual(['followers', 'list:3'])
	})

	it('take a part in or out, in the server\'s order, and nothing left is anybody', () => {
		expect(withPart('everyone', 'mentioned', true)).toBe('mentioned')
		expect(withPart('mentioned', 'followers', true)).toBe('followers,mentioned')
		expect(withPart('nobody', 'list:3', true)).toBe('list:3')
		expect(withPart('followers', 'followers', false)).toBe('everyone')
	})

	it('say what they mean', () => {
		expect(ruleLabel('everyone')).toBe('Anybody')
		expect(ruleLabel('nobody')).toBe('Nobody')
		expect(ruleLabel('followers,list:3', [{ id: '3', title: 'Team' }])).toBe('People who follow me, People on Team')
	})
})
