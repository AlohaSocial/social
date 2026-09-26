/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What the short composer does to a video in the browser: record one, cut it
 * to length, and take a still out of it for the cover.
 *
 * All of it happens here rather than on the server so that what is uploaded
 * is what the writer saw -- the server stores and transcodes a video as it
 * always has, and needs to know nothing about trimming. The pure parts (the
 * arithmetic of a trim, which recording format a browser can make) are kept
 * apart from the parts that need a real `<video>` and a canvas, so they can be
 * tested without either.
 */

/** the shortest a trimmed short may be, in seconds */
export const MIN_LENGTH = 1

/** the recording limits on offer, in seconds, as TikTok offers them */
export const RECORD_LIMITS = [15, 60, 180]

/**
 * @param {number} seconds a time
 * @return {string} it as `m:ss`
 */
export function formatTime(seconds) {
	const whole = Math.max(0, Math.floor(Number(seconds) || 0))

	return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`
}

/**
 * A trim kept inside the video and never shorter than `MIN_LENGTH`.
 *
 * @param {{ start: number, end: number }} trim what the handles say
 * @param {number} duration how long the video is
 * @param {'start'|'end'} [moved] which handle moved, so that one gives way
 * @return {{ start: number, end: number }} a trim that can be cut
 */
export function clampTrim(trim, duration, moved = 'end') {
	const length = Math.max(0, Number(duration) || 0)
	const shortest = Math.min(MIN_LENGTH, length)
	let start = Math.min(Math.max(0, Number(trim.start) || 0), length)
	let end = Math.min(Math.max(0, Number(trim.end) || 0), length)

	if (end - start < shortest) {
		if (moved === 'start') {
			start = Math.max(0, end - shortest)
		} else {
			end = Math.min(length, start + shortest)
			start = Math.min(start, end - shortest)
		}
	}

	return { start, end }
}

/**
 * @param {{ start: number, end: number }} trim the trim
 * @param {number} duration how long the video is
 * @return {boolean} whether it cuts anything off
 */
export function isTrimmed(trim, duration) {
	return trim.start > 0.05 || trim.end < duration - 0.05
}

/**
 * The times the filmstrip under the trim bar shows a frame at: evenly
 * spaced, each in the middle of its own slice.
 *
 * @param {number} duration how long the video is
 * @param {number} count how many frames
 * @return {number[]} seconds
 */
export function filmstripTimes(duration, count) {
	if (!(duration > 0) || !(count > 0)) {
		return []
	}

	return Array.from({ length: count }, (_, index) => ((index + 0.5) / count) * duration)
}

/**
 * The best format this browser can record in: MP4 where it can (Safari, and
 * Chrome since it learnt to), WebM otherwise. The server takes both.
 *
 * @param {(mime: string) => boolean} isSupported `MediaRecorder.isTypeSupported`
 * @return {string} a mime type, '' where the browser offers nothing we know
 */
export function recorderMimeType(isSupported) {
	const candidates = [
		'video/mp4;codecs=avc1,mp4a.40.2',
		'video/mp4',
		'video/webm;codecs=vp9,opus',
		'video/webm;codecs=vp8,opus',
		'video/webm',
	]

	for (const mime of candidates) {
		try {
			if (isSupported(mime)) {
				return mime
			}
		} catch {
			// a browser that throws for a type it does not know has told us the same thing
		}
	}

	return ''
}

/**
 * @param {string} mime a recording's mime type
 * @return {{ type: string, extension: string }} the plain type and a file extension for it
 */
export function fileTypeFor(mime) {
	const type = String(mime || 'video/webm').split(';')[0]

	return { type, extension: type === 'video/mp4' ? 'mp4' : 'webm' }
}

/**
 * Waits for a media element to reach a point in time.
 *
 * @param {HTMLVideoElement} video the element
 * @param {number} time where to go, in seconds
 * @return {Promise<void>} once the frame there can be drawn
 */
export function seekTo(video, time) {
	return new Promise((resolve) => {
		const done = () => {
			video.removeEventListener('seeked', done)
			resolve()
		}
		video.addEventListener('seeked', done)
		video.currentTime = Math.max(0, time)
	})
}

/**
 * How long a video is, including one that does not say.
 *
 * A WebM written by `MediaRecorder` has no duration in its header, and a
 * browser reports it as `Infinity` until something makes it read to the
 * end: asking for a time past the end does, and `durationchange` then
 * carries the real length.
 *
 * @param {HTMLVideoElement} video an element whose metadata has loaded
 * @param {number} [wait] how long to give it, in milliseconds
 * @return {Promise<number>} seconds, 0 where it could not be found out
 */
export function knownDuration(video, wait = 3000) {
	if (Number.isFinite(video.duration)) {
		return Promise.resolve(video.duration)
	}

	return new Promise((resolve) => {
		const finish = (seconds) => {
			window.clearTimeout(timer)
			video.removeEventListener('durationchange', changed)
			video.currentTime = 0
			resolve(seconds)
		}
		const changed = () => {
			if (Number.isFinite(video.duration)) {
				finish(video.duration)
			}
		}
		const timer = window.setTimeout(() => finish(0), wait)
		video.addEventListener('durationchange', changed)
		video.currentTime = Number.MAX_SAFE_INTEGER
	})
}

/**
 * The frame on screen, as a JPEG.
 *
 * @param {HTMLVideoElement} video an element showing the frame wanted
 * @param {number} [maxEdge] the longest side of the picture, in pixels
 * @return {Promise<Blob|null>} the picture, or null where it could not be drawn
 */
export async function captureFrame(video, maxEdge = 1080) {
	try {
		const width = video.videoWidth
		const height = video.videoHeight
		if (!width || !height) {
			return null
		}

		const scale = Math.min(1, maxEdge / Math.max(width, height))
		const canvas = document.createElement('canvas')
		canvas.width = Math.round(width * scale)
		canvas.height = Math.round(height * scale)
		const context = canvas.getContext('2d')
		if (context === null) {
			return null
		}
		context.drawImage(video, 0, 0, canvas.width, canvas.height)

		return await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.86))
	} catch {
		return null
	}
}

/**
 * Small stills across a video, for the strip under the trim handles.
 *
 * A second element does the seeking so the preview the writer is watching
 * does not jump about while the strip is made.
 *
 * @param {string} url an object URL for the video
 * @param {number} count how many stills
 * @return {Promise<string[]>} data URLs, [] where they could not be made
 */
export async function filmstrip(url, count) {
	const video = document.createElement('video')
	video.muted = true
	video.preload = 'auto'
	video.playsInline = true
	video.src = url

	try {
		await new Promise((resolve, reject) => {
			video.addEventListener('loadeddata', resolve, { once: true })
			video.addEventListener('error', reject, { once: true })
		})

		const frames = []
		for (const time of filmstripTimes(await knownDuration(video), count)) {
			await seekTo(video, time)
			const canvas = document.createElement('canvas')
			canvas.height = 96
			canvas.width = Math.max(1, Math.round((video.videoWidth / video.videoHeight) * 96))
			canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height)
			frames.push(canvas.toDataURL('image/jpeg', 0.6))
		}

		return frames
	} catch {
		return []
	} finally {
		video.removeAttribute('src')
		video.load()
	}
}

/**
 * Cuts a video down to a stretch of it, in the browser.
 *
 * There is no way to cut an MP4 or a WebM without re-encoding it, and no
 * encoder in a browser other than `MediaRecorder`, so the stretch is played
 * once through a canvas and recorded as it plays: a 20-second short takes 20
 * seconds to prepare, which the composer shows as progress. The sound is
 * routed through Web Audio into the recording and not to the speakers, so
 * preparing a short is silent.
 *
 * @param {File} file the video as chosen or recorded
 * @param {number} start where the short begins, in seconds
 * @param {number} end where it ends, in seconds
 * @param {object} [options] options
 * @param {(share: number) => void} [options.onProgress] told how far along it is, 0 to 1
 * @return {Promise<File|null>} the short, or null where this browser cannot cut it
 */
export async function trimVideo(file, start, end, { onProgress = () => {} } = {}) {
	const Recorder = window.MediaRecorder
	const mime = Recorder ? recorderMimeType((type) => Recorder.isTypeSupported(type)) : ''
	const Context = window.AudioContext ?? /** @type {{ webkitAudioContext?: typeof AudioContext }} */ (window).webkitAudioContext
	if (!Recorder || mime === '' || typeof Context !== 'function') {
		return null
	}

	const url = URL.createObjectURL(file)
	const video = document.createElement('video')
	video.src = url
	video.playsInline = true
	video.preload = 'auto'
	const audio = new Context()

	try {
		await new Promise((resolve, reject) => {
			video.addEventListener('loadeddata', resolve, { once: true })
			video.addEventListener('error', reject, { once: true })
		})

		const canvas = document.createElement('canvas')
		canvas.width = video.videoWidth
		canvas.height = video.videoHeight
		const context = canvas.getContext('2d')
		if (context === null || typeof canvas.captureStream !== 'function') {
			return null
		}

		const destination = audio.createMediaStreamDestination()
		audio.createMediaElementSource(video).connect(destination)
		const stream = new MediaStream([
			...canvas.captureStream(30).getVideoTracks(),
			...destination.stream.getAudioTracks(),
		])

		const recorder = new Recorder(stream, { mimeType: mime, videoBitsPerSecond: 6_000_000 })
		const chunks = []
		recorder.addEventListener('dataavailable', (event) => {
			if (event.data?.size > 0) {
				chunks.push(event.data)
			}
		})
		const stopped = new Promise((resolve) => recorder.addEventListener('stop', resolve, { once: true }))

		await seekTo(video, start)
		recorder.start(250)
		await video.play()

		await new Promise((resolve) => {
			const draw = () => {
				context.drawImage(video, 0, 0, canvas.width, canvas.height)
				onProgress(Math.min(1, (video.currentTime - start) / Math.max(0.001, end - start)))
				if (video.currentTime >= end || video.ended) {
					resolve()
					return
				}
				window.requestAnimationFrame(draw)
			}
			draw()
		})

		video.pause()
		recorder.stop()
		await stopped
		onProgress(1)

		const { type, extension } = fileTypeFor(mime)
		const base = (file.name || 'short').replace(/\.[^.]+$/, '')

		return new File(chunks, `${base}-short.${extension}`, { type, lastModified: Date.now() })
	} catch {
		return null
	} finally {
		video.pause()
		video.removeAttribute('src')
		video.load()
		URL.revokeObjectURL(url)
		audio.close?.()
	}
}
