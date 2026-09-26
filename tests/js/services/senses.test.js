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
	setSoundsEnabled,
	setVibrationEnabled,
	soundsEnabled,
	vibrationEnabled,
} from '../../../src/services/senses.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))

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
		window.localStorage.clear()
		resetAudioForTests()
		vibrate = vi.fn(() => true)
		Object.defineProperty(window.navigator, 'vibrate', { value: vibrate, configurable: true })
		window.matchMedia = vi.fn(() => ({ matches: false }))
	})

	afterEach(() => {
		delete window.AudioContext
	})

	/** Nextcloud is used in offices: nothing makes a noise until asked to */
	it('starts with sound off and vibration on', () => {
		expect(soundsEnabled()).toBe(false)
		expect(vibrationEnabled()).toBe(true)
	})

	it('remembers each switch in this browser, for this account', () => {
		setSoundsEnabled(true)
		setVibrationEnabled(false)

		expect(soundsEnabled()).toBe(true)
		expect(vibrationEnabled()).toBe(false)
		expect(window.localStorage.getItem('social.sounds::alice')).toBe('1')
		expect(window.localStorage.getItem('social.vibration::alice')).toBe('0')
	})

	it('vibrates in the pattern each moment has', () => {
		expect(buzz('like')).toBe(true)
		expect(vibrate).toHaveBeenCalledWith(PATTERNS.like)

		buzz('dm')
		expect(vibrate).toHaveBeenLastCalledWith(PATTERNS.dm)
	})

	it('does not vibrate when switched off', () => {
		setVibrationEnabled(false)

		expect(buzz('like')).toBe(false)
		expect(vibrate).not.toHaveBeenCalled()
	})

	/** a buzz is movement felt in the hand */
	it('does not vibrate for a reader who asked for less motion', () => {
		window.matchMedia = vi.fn((query) => ({ matches: query.includes('reduce') }))

		expect(buzz('like')).toBe(false)
		expect(vibrate).not.toHaveBeenCalled()
	})

	it('answers a moment it does not know with nothing', () => {
		expect(buzz('nonsense')).toBe(false)
		expect(play('nonsense', { force: true })).toBe(false)
	})

	it('plays nothing while sound is off', () => {
		const { Context, made } = fakeAudio()
		window.AudioContext = Context

		expect(play('like')).toBe(false)
		expect(made).toHaveLength(0)
	})

	it('plays every sound once sound is on, from one audio context', () => {
		const { Context, made } = fakeAudio()
		window.AudioContext = Context
		setSoundsEnabled(true)

		for (const moment of ['like', 'boost', 'react', 'post', 'follow', 'dm', 'heart', 'roll']) {
			expect(play(moment)).toBe(true)
		}
		expect(made).toHaveLength(1)
	})

	/** the Listen button in Settings plays them before anybody has chosen */
	it('plays with sound off when asked to', () => {
		const { Context } = fakeAudio()
		window.AudioContext = Context

		expect(play('like', { force: true })).toBe(true)
	})

	it('plays nothing, and does not throw, where there is no audio at all', () => {
		setSoundsEnabled(true)

		expect(play('like')).toBe(false)
	})

	it('both sounds and vibrates for one moment', () => {
		const { Context, made } = fakeAudio()
		window.AudioContext = Context
		setSoundsEnabled(true)

		feel('boost')

		expect(made).toHaveLength(1)
		expect(vibrate).toHaveBeenCalledWith(PATTERNS.boost)
	})
})
