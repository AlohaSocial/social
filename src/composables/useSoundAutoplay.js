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
 * Playing a video with its sound on wherever the browser allows it, and with
 * one tap otherwise.
 *
 * No page can start sound without a user gesture. Opening Shorts or a story
 * by a click counts as one; a pasted link or a reload does not, and the
 * browser then rejects `play()` with a `NotAllowedError`. That rejection is
 * the autoplay rule answering rather than a fault: the video plays muted
 * instead, and `soundHeld` says so, for the "Tap for sound" hint beside the
 * speaker.
 *
 * Two things are kept apart on purpose. `muted` is what the reader chose, and
 * lasts for the tab in sessionStorage; `soundHeld` is what the browser refused
 * for the video playing now, and is asked again for the next one. A refusal
 * therefore never turns into a choice: one refused video does not silence
 * every video after it for somebody who wanted sound.
 *
 * Unmuting from the speaker is a gesture, which is what lets the element play
 * with sound from then on — in WebKit per element, which is why a player that
 * follows the reader reuses one element rather than making a new one per
 * video.
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
