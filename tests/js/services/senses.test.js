/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	buzz,
	feel,
	PATTERNS,
	play,
	resetAudioForTests,
} from '../../../src/services/senses.js'

/** a stand-in AudioContext that records what was asked of it */
function fakeAudio() {
	const node = () => ({
		connect: vi.fn(function() { return this }),
		start: vi.fn(),
		stop: vi.fn(),
		type: '',
		frequency: { value: 0, setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() },
		gain: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() },
		Q: { value: 0 },
		buffer: null,
	})
	const made = []
	class Context {
		constructor() {
			this.currentTime = 0
			this.sampleRate = 8000
			this.state = 'running'
			this.destination = {}
			made.push(this)
		}

		createOscillator() { return node() }
		createGain() { return node() }
		createBiquadFilter() { return node() }
		createBufferSource() { return node() }
		createBuffer(channels, frames) { return { getChannelData: () => new Float32Array(frames) } }
		resume() {}
	}

	return { Context, made }
}

describe('senses', () => {
	let vibrate

	beforeEach(() => {
		resetAudioForTests()
		vibrate = vi.fn(() => true)
		Object.defineProperty(window.navigator, 'vibrate', { value: vibrate, configurable: true })
		window.matchMedia = vi.fn(() => ({ matches: false }))
	})

	afterEach(() => {
		delete window.AudioContext
	})

	it('vibrates in the pattern each moment has', () => {
		expect(buzz('like')).toBe(true)
		expect(vibrate).toHaveBeenCalledWith(PATTERNS.like)

		buzz('dm')
		expect(vibrate).toHaveBeenLastCalledWith(PATTERNS.dm)
	})

	/** a buzz is movement felt in the hand */
	it('does not vibrate for a reader who asked for less motion', () => {
		window.matchMedia = vi.fn((query) => ({ matches: query.includes('reduce') }))

		expect(buzz('like')).toBe(false)
		expect(vibrate).not.toHaveBeenCalled()
	})

	it('answers a moment it does not know with nothing', () => {
		expect(buzz('nonsense')).toBe(false)
		expect(play('nonsense')).toBe(false)
	})

	it('plays every sound from one audio context', () => {
		const { Context, made } = fakeAudio()
		window.AudioContext = Context

		for (const moment of ['like', 'boost', 'react', 'post', 'follow', 'dm', 'heart', 'roll']) {
			expect(play(moment)).toBe(true)
		}
		expect(made).toHaveLength(1)
	})

	it('plays nothing, and does not throw, where there is no audio at all', () => {
		expect(play('like')).toBe(false)
	})

	it('both sounds and vibrates for one moment', () => {
		const { Context, made } = fakeAudio()
		window.AudioContext = Context

		feel('boost')

		expect(made).toHaveLength(1)
		expect(vibrate).toHaveBeenCalledWith(PATTERNS.boost)
	})
})
