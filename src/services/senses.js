/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { userKey } from '../utils/browserStore.js'

/**
 * Sound and touch: the small confirmations that are felt or heard rather than
 * seen.
 *
 * Both are a property of the device rather than of the account -- sound on the
 * laptop at home, silence on the one at work, vibration on the phone -- so the
 * two switches live in this browser and not on the server. Sound is **off**
 * until somebody turns it on: Nextcloud is used in offices. Vibration is on,
 * because the app already vibrated on a like before these switches existed,
 * and turning it off by default would take something away from everybody who
 * never opens Settings.
 *
 * The sounds are synthesised rather than shipped as files. Each is a few
 * oscillators and an envelope, well under a second, so there is nothing to
 * download, nothing to license, and nothing to decode before the first one can
 * play.
 */

const SOUND_KEY = 'social.sounds'
const VIBRATION_KEY = 'social.vibration'

/**
 * The moments that make a sound or a buzz, and how each one feels.
 *
 * Vibration is milliseconds on, or an on-off-on pattern. They are short on
 * purpose: a buzz longer than a tap reads as a notification, not as "done".
 */
export const PATTERNS = {
	like: 8,
	boost: 8,
	react: [6, 30, 6],
	post: [10, 40, 14],
	follow: 12,
	dm: [10, 60, 10],
	heart: 6,
	roll: [4, 20, 4, 20, 4],
}

/** @return {Storage|null} the store, or null where the browser refuses it */
function store() {
	try {
		return window.localStorage
	} catch {
		return null
	}
}

/**
 * @param {string} name the key
 * @param {boolean} fallback what an unset key means
 * @return {boolean} the switch as it was left
 */
function readSwitch(name, fallback) {
	const value = store()?.getItem(userKey(name))

	return value === null || value === undefined ? fallback : value === '1'
}

/**
 * @param {string} name the key
 * @param {boolean} on the new position
 */
function writeSwitch(name, on) {
	try {
		store()?.setItem(userKey(name), on ? '1' : '0')
	} catch {
		// a private window refuses storage; the switch then lasts the page
	}
}

/** @return {boolean} whether this device makes sounds; off until turned on */
export function soundsEnabled() {
	return readSwitch(SOUND_KEY, false)
}

/** @param {boolean} on whether this device should make sounds */
export function setSoundsEnabled(on) {
	writeSwitch(SOUND_KEY, on)
}

/** @return {boolean} whether this device vibrates; on unless turned off */
export function vibrationEnabled() {
	return readSwitch(VIBRATION_KEY, true)
}

/** @param {boolean} on whether this device should vibrate */
export function setVibrationEnabled(on) {
	writeSwitch(VIBRATION_KEY, on)
}

/**
 * Vibrates for a moment, where the device can and the reader has not said no.
 *
 * A reader who asked their system for less motion is asked here too: a buzz
 * is movement, felt in the hand.
 *
 * @param {string} moment what just happened, one of the keys of PATTERNS
 * @return {boolean} whether the device was asked to vibrate
 */
export function buzz(moment) {
	const pattern = PATTERNS[moment]
	if (pattern === undefined || !vibrationEnabled()) {
		return false
	}

	if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
		return false
	}

	return window.navigator?.vibrate?.(pattern) === true
}

/** @type {AudioContext|null} */
let context = null

/**
 * One audio context for the page, made on first use.
 *
 * Browsers only let a context start from inside a gesture, and every sound
 * here follows a press, so the first press is where it is made.
 *
 * @return {AudioContext|null} the context, or null where there is no audio
 */
function audio() {
	if (context !== null) {
		return context
	}

	// Safari before 14.1 has it only under the prefixed name
	const Context = window.AudioContext ?? /** @type {{ webkitAudioContext?: typeof AudioContext }} */ (window).webkitAudioContext
	if (typeof Context !== 'function') {
		return null
	}

	try {
		context = new Context()
	} catch {
		return null
	}

	return context
}

/**
 * One note: an oscillator through an envelope that rises fast and dies away.
 *
 * @param {AudioContext} ctx the context
 * @param {object} note the note
 * @param {number} note.from the pitch it starts at, in Hz
 * @param {number} [note.to] the pitch it glides to, if it glides
 * @param {number} note.at when it starts, in seconds from now
 * @param {number} note.length how long it rings, in seconds
 * @param {OscillatorType} [note.wave] the waveform
 * @param {number} [note.volume] the peak gain
 */
function tone(ctx, { from, to = from, at, length, wave = 'sine', volume = 0.08 }) {
	const start = ctx.currentTime + at
	const oscillator = ctx.createOscillator()
	const gain = ctx.createGain()

	oscillator.type = wave
	oscillator.frequency.setValueAtTime(from, start)
	if (to !== from) {
		oscillator.frequency.exponentialRampToValueAtTime(to, start + length)
	}

	gain.gain.setValueAtTime(0.0001, start)
	gain.gain.exponentialRampToValueAtTime(volume, start + 0.01)
	gain.gain.exponentialRampToValueAtTime(0.0001, start + length)

	oscillator.connect(gain).connect(ctx.destination)
	oscillator.start(start)
	oscillator.stop(start + length + 0.02)
}

/**
 * A breath of air: white noise swept through a band-pass filter.
 *
 * @param {AudioContext} ctx the context
 * @param {number} length how long, in seconds
 */
function whoosh(ctx, length) {
	const frames = Math.floor(ctx.sampleRate * length)
	const buffer = ctx.createBuffer(1, frames, ctx.sampleRate)
	const samples = buffer.getChannelData(0)
	for (let i = 0; i < frames; i++) {
		samples[i] = (Math.random() * 2 - 1) * (1 - i / frames)
	}

	const source = ctx.createBufferSource()
	source.buffer = buffer

	const filter = ctx.createBiquadFilter()
	filter.type = 'bandpass'
	filter.Q.value = 1.2
	filter.frequency.setValueAtTime(400, ctx.currentTime)
	filter.frequency.exponentialRampToValueAtTime(2400, ctx.currentTime + length)

	const gain = ctx.createGain()
	gain.gain.setValueAtTime(0.12, ctx.currentTime)
	gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + length)

	source.connect(filter).connect(gain).connect(ctx.destination)
	source.start()
}

/**
 * What each moment sounds like.
 *
 * @type {Record<string, (ctx: AudioContext) => void>}
 */
const SOUNDS = {
	// a soft tick with a little lift at the end
	like: (ctx) => tone(ctx, { from: 880, to: 1320, at: 0, length: 0.07 }),
	// two notes up: passed along
	boost: (ctx) => {
		tone(ctx, { from: 660, at: 0, length: 0.06, wave: 'triangle' })
		tone(ctx, { from: 990, at: 0.06, length: 0.08, wave: 'triangle' })
	},
	react: (ctx) => tone(ctx, { from: 520, to: 1040, at: 0, length: 0.09, wave: 'triangle' }),
	// air, then a small bright note as it lands
	post: (ctx) => {
		whoosh(ctx, 0.22)
		tone(ctx, { from: 1568, at: 0.18, length: 0.12, volume: 0.05 })
	},
	follow: (ctx) => {
		tone(ctx, { from: 523, at: 0, length: 0.08, wave: 'triangle' })
		tone(ctx, { from: 659, at: 0.07, length: 0.08, wave: 'triangle' })
		tone(ctx, { from: 784, at: 0.14, length: 0.14, wave: 'triangle' })
	},
	// a two-note chime, the one sound here that is not an answer to a press
	dm: (ctx) => {
		tone(ctx, { from: 1319, at: 0, length: 0.25, wave: 'triangle', volume: 0.06 })
		tone(ctx, { from: 988, at: 0.12, length: 0.35, wave: 'triangle', volume: 0.06 })
	},
	heart: (ctx) => tone(ctx, { from: 740, to: 1110, at: 0, length: 0.06, volume: 0.05 }),
	// a rattle of short clicks, for a die or a coin in the air
	roll: (ctx) => {
		for (let i = 0; i < 6; i++) {
			tone(ctx, { from: 300 + Math.random() * 500, at: i * 0.045, length: 0.03, wave: 'square', volume: 0.025 })
		}
	},
}

/**
 * Plays the sound for a moment, if this device has sound switched on.
 *
 * Never throws: a sound is a decoration, and a press must not fail because an
 * audio context would not start.
 *
 * @param {string} moment what just happened, one of the keys of SOUNDS
 * @param {object} [options] options
 * @param {boolean} [options.force] play even with sound off, for the button
 *                                  that lets somebody hear them before choosing
 * @return {boolean} whether a sound was started
 */
export function play(moment, { force = false } = {}) {
	const sound = SOUNDS[moment]
	if (sound === undefined || (!force && !soundsEnabled())) {
		return false
	}

	const ctx = audio()
	if (ctx === null) {
		return false
	}

	try {
		if (ctx.state === 'suspended') {
			ctx.resume()
		}
		sound(ctx)

		return true
	} catch {
		return false
	}
}

/**
 * Both at once, which is what almost every moment wants.
 *
 * @param {string} moment what just happened
 */
export function feel(moment) {
	play(moment)
	buzz(moment)
}

/** For tests: forget the audio context so the next play makes a new one. */
export function resetAudioForTests() {
	context = null
}
