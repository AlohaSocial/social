/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { reactive } from 'vue'

/**
 * The small player a video goes on playing in when the reader leaves its
 * watch page while it plays.
 *
 * Held outside any component so it outlives the watch pages that hand videos
 * over and take them back. `App.vue` draws it (`MiniPlayer.vue`) while
 * `status` is set.
 *
 * @type {{status: object|null, position: number, playing: boolean}}
 */
export const miniPlayer = reactive({
	/** the video post playing small, or null when nothing is */
	status: null,
	/** where in it, in seconds, as the small player last said */
	position: 0,
	/** whether it is playing rather than paused */
	playing: false,
})

/**
 * Takes a video that was playing on its watch page into the small player.
 *
 * @param {object} status the video post
 * @param {number} position where it had got to, in seconds
 */
export function handOff(status, position) {
	miniPlayer.status = status
	miniPlayer.position = Math.max(0, Number(position) || 0)
	miniPlayer.playing = true
}

/**
 * Where the small player has got to, as it plays.
 *
 * @param {number} position in seconds
 * @param {boolean} playing whether it is playing
 */
export function movedTo(position, playing) {
	miniPlayer.position = Math.max(0, Number(position) || 0)
	miniPlayer.playing = playing
}

/**
 * Gives a video back to its watch page, which goes on from where the small
 * player was.
 *
 * @param {string} id the post the watch page is showing
 * @return {{position: number, playing: boolean}|null} where to go on from and whether it was playing; null when the small player does not hold that video
 */
export function takeBack(id) {
	if (miniPlayer.status === null || String(miniPlayer.status.id) !== String(id)) {
		return null
	}

	const taken = { position: miniPlayer.position, playing: miniPlayer.playing }
	closeMiniPlayer()

	return taken
}

/** Closes the small player, which stops the video. */
export function closeMiniPlayer() {
	miniPlayer.status = null
	miniPlayer.position = 0
	miniPlayer.playing = false
}
