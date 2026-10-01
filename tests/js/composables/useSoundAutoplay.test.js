/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { forgetMuteChoice, useSoundAutoplay } from '../../../src/composables/useSoundAutoplay.js'

vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))
const device = vi.hoisted(() => ({ sound: true }))
vi.mock('../../../src/services/senses.js', () => ({ videoSoundEnabled: () => device.sound }))

const refused = () => Object.assign(new Error('no'), { name: 'NotAllowedError' })

/**
 * @param {Function} play what the element's play() does
 * @return {{muted: boolean, paused: boolean, play: Function}} a stand-in <video>
 */
function element(play = vi.fn().mockResolvedValue(undefined)) {
	return { muted: false, paused: true, play }
}

/**
 * No page can start sound without a gesture: a refusal is answered with a
 * muted video and a hint, and never turned into the reader's choice.
 */
describe('useSoundAutoplay', () => {
	beforeEach(() => {
		device.sound = true
		window.sessionStorage.clear()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('starts with the sound on and plays with it where the browser allows', async () => {
		const sound = useSoundAutoplay()
		const video = element()

		await sound.playWithSound(video)

		expect(video.muted).toBe(false)
		expect(video.play).toHaveBeenCalledTimes(1)
		expect(sound.soundHeld.value).toBe(false)
		expect(sound.silent.value).toBe(false)
	})

	it('falls back to muted once when the sound is refused, and says so', async () => {
		const sound = useSoundAutoplay()
		const video = element(vi.fn().mockRejectedValueOnce(refused()).mockResolvedValue(undefined))

		await sound.playWithSound(video)

		expect(video.play).toHaveBeenCalledTimes(2)
		expect(video.muted).toBe(true)
		expect(sound.soundHeld.value).toBe(true)
		expect(sound.silent.value).toBe(true)
		// a refusal is not the reader's choice
		expect(sound.muted.value).toBe(false)
	})

	it('does not ask a third time when even the muted start is refused', async () => {
		const sound = useSoundAutoplay()
		const video = element(vi.fn().mockRejectedValue(refused()))

		await sound.playWithSound(video)

		expect(video.play).toHaveBeenCalledTimes(2)
	})

	it('does not mute for a refusal that was not about the sound', async () => {
		const sound = useSoundAutoplay()
		const video = element(vi.fn().mockRejectedValue(Object.assign(new Error('gone'), { name: 'NotSupportedError' })))

		await sound.playWithSound(video)

		expect(video.play).toHaveBeenCalledTimes(1)
		expect(sound.soundHeld.value).toBe(false)
	})

	/** one refused video must not silence every video after it */
	it('asks with sound again for the next video', async () => {
		const sound = useSoundAutoplay()
		const video = element(vi.fn().mockRejectedValueOnce(refused()).mockResolvedValue(undefined))

		await sound.playWithSound(video)
		expect(sound.soundHeld.value).toBe(true)

		await sound.playWithSound(video)
		expect(video.muted).toBe(false)
		expect(sound.soundHeld.value).toBe(false)
	})

	it('turns the sound on with one tap after a refusal', async () => {
		const sound = useSoundAutoplay()
		const video = element(vi.fn().mockRejectedValueOnce(refused()).mockResolvedValue(undefined))
		await sound.playWithSound(video)

		sound.toggleMute(video)

		expect(video.muted).toBe(false)
		expect(sound.soundHeld.value).toBe(false)
		expect(sound.muted.value).toBe(false)
	})

	it('flips the choice when nothing was refused', () => {
		const sound = useSoundAutoplay()
		const video = element()

		sound.toggleMute(video)
		expect(video.muted).toBe(true)
		expect(sound.muted.value).toBe(true)

		sound.toggleMute(video)
		expect(video.muted).toBe(false)
	})

	it('keeps the choice for the rest of the session', async () => {
		useSoundAutoplay().toggleMute(null)

		const next = useSoundAutoplay()
		const video = element()
		await next.playWithSound(video)

		expect(next.muted.value).toBe(true)
		expect(video.muted).toBe(true)
	})

	it('starts muted on a device switched to that, until the tab says otherwise', () => {
		device.sound = false
		expect(useSoundAutoplay().muted.value).toBe(true)

		useSoundAutoplay().toggleMute(null)
		expect(useSoundAutoplay().muted.value).toBe(false)

		forgetMuteChoice()
		expect(useSoundAutoplay().muted.value).toBe(true)
	})

	/** a private window may refuse storage outright */
	it('still works when the session store throws', async () => {
		vi.spyOn(window.sessionStorage, 'getItem').mockImplementation(() => {
			throw new Error('SecurityError')
		})
		vi.spyOn(window.sessionStorage, 'setItem').mockImplementation(() => {
			throw new Error('QuotaExceededError')
		})
		vi.spyOn(window.sessionStorage, 'removeItem').mockImplementation(() => {
			throw new Error('SecurityError')
		})

		const sound = useSoundAutoplay()
		expect(sound.muted.value).toBe(false)

		const video = element()
		expect(() => sound.toggleMute(video)).not.toThrow()
		expect(sound.muted.value).toBe(true)
		expect(() => forgetMuteChoice()).not.toThrow()
		await sound.playWithSound(video)
		expect(video.muted).toBe(true)
	})

	it('still works when the session store cannot even be reached', () => {
		const store = window.sessionStorage
		Object.defineProperty(window, 'sessionStorage', {
			configurable: true,
			get: () => {
				throw new Error('SecurityError')
			},
		})
		try {
			const sound = useSoundAutoplay()
			expect(sound.muted.value).toBe(false)
			expect(() => sound.toggleMute(null)).not.toThrow()
			expect(sound.muted.value).toBe(true)
		} finally {
			Object.defineProperty(window, 'sessionStorage', { value: store, configurable: true, writable: true })
		}
	})
})
