/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Update the distinct content played by a video. Browsers expose this as
 * `played` ranges; a short forward-time fallback covers the interval before
 * those ranges become available.
 *
 * @param {{lastTime: number, watchedSeconds: number}} playback accumulated state
 * @param {HTMLVideoElement} video the player
 * @return {number} distinct seconds played
 */
export function accumulateVideoPlayback(playback, video) {
	const currentTime = Number(video?.currentTime)
	const duration = Number(video?.duration)
	const ranges = playedRangesSeconds(video?.played, duration)

	if (ranges !== null) {
		playback.watchedSeconds = ranges
	} else if (!video?.seeking && Number.isFinite(currentTime) && Number.isFinite(playback.lastTime)) {
		const elapsed = currentTime - playback.lastTime
		if (elapsed > 0 && elapsed <= 2) {
			playback.watchedSeconds += elapsed
			if (Number.isFinite(duration) && duration > 0) {
				playback.watchedSeconds = Math.min(duration, playback.watchedSeconds)
			}
		}
	}

	if (Number.isFinite(currentTime)) {
		playback.lastTime = currentTime
	}

	return playback.watchedSeconds
}

/**
 * @param {TimeRanges|undefined} ranges what the browser says has played
 * @param {number} duration clip duration in seconds
 * @return {number|null} distinct played seconds, or null while unavailable
 */
function playedRangesSeconds(ranges, duration) {
	if (!ranges || !Number.isInteger(ranges.length) || ranges.length <= 0) {
		return null
	}

	const intervals = []
	for (let index = 0; index < ranges.length; index++) {
		try {
			const start = Number(ranges.start(index))
			const end = Number(ranges.end(index))
			if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
				continue
			}
			const clippedStart = Math.max(0, Number.isFinite(duration) && duration > 0 ? Math.min(duration, start) : start)
			const clippedEnd = Number.isFinite(duration) && duration > 0 ? Math.min(duration, end) : end
			if (clippedEnd > clippedStart) {
				intervals.push([clippedStart, clippedEnd])
			}
		} catch {
			return null
		}
	}
	if (intervals.length === 0) {
		return null
	}

	intervals.sort((left, right) => left[0] - right[0])
	let total = 0
	let start = intervals[0][0]
	let end = intervals[0][1]
	for (const [nextStart, nextEnd] of intervals.slice(1)) {
		if (nextStart <= end) {
			end = Math.max(end, nextEnd)
			continue
		}
		total += end - start
		start = nextStart
		end = nextEnd
	}
	total += end - start

	return Number.isFinite(duration) && duration > 0 ? Math.min(duration, total) : total
}
