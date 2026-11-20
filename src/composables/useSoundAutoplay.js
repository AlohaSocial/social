/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref } from 'vue'
import logger from '../services/logger.js'
import { videoSoundEnabled } from '../services/senses.js'
import { userKey } from '../utils/browserStore.js'

/** Where the reader's mute choice is kept for the rest of the tab's life. */
const SESSION_KEY = 'social.videoMuted'

/** @return {Storage|null} the session store, or null where the browser refuses it */
function session() {
	try {
		return window.sessionStorage
	} catch {
		return null
	}
}

/**
 * @return {boolean|null} whether the reader muted videos in this tab, or null
 *         when they have not said
 */
function rememberedMute() {
	try {
		const value = session()?.getItem(userKey(SESSION_KEY))

		return value === '1' ? true : (value === '0' ? false : null)
	} catch {
		return null
	}
}

/** @param {boolean} muted the reader's choice */
function rememberMute(muted) {
	try {
		session()?.setItem(userKey(SESSION_KEY), muted ? '1' : '0')
	} catch {
		// a private window refuses storage; the choice then lasts the page
	}
}

/**
 * Forgets the choice made in this tab, so that the device's own switch in
 * Settings is what the next video starts with.
 */
export function forgetMuteChoice() {
	try {
		session()?.removeItem(userKey(SESSION_KEY))
	} catch {
		// nothing was kept, so there is nothing to forget
	}
}

/**
 * Plays a video with its sound on wherever the browser allows it, and with
 * one tap otherwise.
 *
 * Without a user gesture (a pasted link, a reload) `play()` with sound rejects
 * with `NotAllowedError`; the video then plays muted and `soundHeld` drives the
 * "Tap for sound" hint. `muted` is the reader's choice, kept for the tab in
 * sessionStorage; `soundHeld` is the browser's refusal for the current video
 * only, so one refusal never silences the videos after it. WebKit allows sound
 * per element after an unmute, which is why a player that follows the reader
 * reuses one element.
 *
 * @return {{muted: import('vue').Ref<boolean>, soundHeld: import('vue').Ref<boolean>, silent: import('vue').ComputedRef<boolean>, playWithSound: (video: HTMLMediaElement|null|undefined) => Promise<void>, toggleMute: (video: HTMLMediaElement|null|undefined) => void}}
 *         the state a player draws its speaker from, and the two things it does
 */
export function useSoundAutoplay() {
	/** the reader's choice: this tab's, or else the device's */
	const muted = ref(rememberedMute() ?? !videoSoundEnabled())
	/** the browser would not start the current video with sound */
	const soundHeld = ref(false)
	/** whether what is playing is actually silent, for the speaker to show */
	const silent = computed(() => muted.value || soundHeld.value)

	/**
	 * Plays the video as the reader chose, and falls back to muted once when
	 * the browser refuses the sound. Muted and still refused, the poster stays
	 * up and the reader can press it.
	 *
	 * @param {HTMLMediaElement|null|undefined} video the element to play
	 * @return {Promise<void>} when the browser has answered
	 */
	async function playWithSound(video) {
		if (!video) {
			return
		}

		soundHeld.value = false
		video.muted = muted.value
		try {
			await video.play?.()
		} catch (error) {
			logger.debug('autoplay refused', { error })
			if (error?.name !== 'NotAllowedError' || muted.value) {
				return
			}

			soundHeld.value = true
			video.muted = true
			try {
				await video.play?.()
			} catch (again) {
				logger.debug('muted autoplay refused', { error: again })
			}
		}
	}

	/**
	 * The speaker. Pressed while the browser held the sound back, it turns the
	 * sound on, since that is what the hint promised; otherwise it flips the
	 * choice. Either way the choice is kept for the tab.
	 *
	 * @param {HTMLMediaElement|null|undefined} video the element playing now
	 */
	function toggleMute(video) {
		muted.value = soundHeld.value ? false : !muted.value
		soundHeld.value = false
		rememberMute(muted.value)
		if (video) {
			video.muted = muted.value
		}
	}

	return { muted, soundHeld, silent, playWithSound, toggleMute }
}
