/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createHash, randomBytes } from 'node:crypto'
import { describe, expect, it } from 'vitest'
import { leadingZeroBits, progressOf, sha256, solve } from './proofOfWork.js'

function hex(bytes) {
	return Buffer.from(bytes).toString('hex')
}

describe('the proof-of-work solver', () => {
	it('hashes as SHA-256 does, across every padding boundary', () => {
		expect(hex(sha256(new TextEncoder().encode('abc')))).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
		expect(hex(sha256(new Uint8Array(0)))).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
		for (let length = 1; length < 200; length++) {
			const bytes = new Uint8Array(randomBytes(length))
			expect(hex(sha256(bytes)), `length ${length}`).toBe(createHash('sha256').update(bytes).digest('hex'))
		}
	})

	it('counts leading zero bits', () => {
		expect(leadingZeroBits(Uint8Array.of(0x80))).toBe(0)
		expect(leadingZeroBits(Uint8Array.of(0x00, 0x1f))).toBe(11)
		expect(leadingZeroBits(Uint8Array.of(0x01))).toBe(7)
		expect(leadingZeroBits(Uint8Array.of(0, 0))).toBe(16)
	})

	// challenges of the length the server issues, whose last block the
	// solver hashes on its own, and a short one inside the first block
	it.each([
		'1763050000.12.' + 'a'.repeat(32) + '.' + 'f'.repeat(64),
		'1763050000.12.' + 'b'.repeat(32) + '.' + 'e'.repeat(64) + 'xyz',
		'short',
	])('finds a nonce the server accepts for %s', async (challenge) => {
		const nonce = await solve(challenge, 12, { batch: 1000 })

		expect(nonce).toMatch(/^[0-9a-z]{1,32}$/)
		const digest = createHash('sha256').update(challenge + nonce).digest()
		expect(leadingZeroBits(new Uint8Array(digest))).toBeGreaterThanOrEqual(12)
	})

	it('reports progress and stops when cancelled', async () => {
		const reported = []
		const nonce = await solve('never', 256, {
			batch: 100,
			onProgress: (attempts) => reported.push(attempts),
			cancelled: () => reported.length >= 3,
		})

		expect(nonce).toBeNull()
		expect(reported).toEqual([100, 200, 300])
	})

	it('says how likely a solution was by now, never 100', () => {
		expect(progressOf(0, 20)).toBe(0)
		expect(progressOf(2 ** 20, 20)).toBe(63)
		expect(progressOf(2 ** 30, 20)).toBe(99)
	})
})
