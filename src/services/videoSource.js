/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import logger from './logger.js'
import { videoAttachmentOf } from '../utils/videoCard.js'

/**
 * Where a video attachment plays from, for every player in the app: a plain
 * file, a remote HLS-only playlist (PeerTube, Bluesky), or a local file with
 * an HLS ladder beside it (`hls_url`). Remote videos are proxied by this server.
 */

/**
 * The attachment a video post is watched as: its first video, else the first
 * file served as one, as the server's `video_card` does.
 *
 * @param {object|null|undefined} post the video post
 * @return {object|null}
 */
export function playedAttachmentOf(post) {
	return videoAttachmentOf(post)
		?? (post?.media_attachments ?? []).find((item) => String(item?.media_type ?? '').startsWith('video/'))
		?? null
}

/**
 * @param {object|null|undefined} attachment the attachment
 * @return {boolean} whether it is an HLS playlist and nothing else
 */
export function isPlaylist(attachment) {
	const type = (attachment?.media_type || '').toLowerCase()

	return type === 'application/x-mpegurl' || type === 'application/vnd.apple.mpegurl'
}

/**
 * The master playlist of a local video's ladder, where there is one. A plain
 * file is beside it to fall back to.
 *
 * @param {object|null|undefined} attachment the attachment
 * @return {string} '' when there is none
 */
export function ladderSourceOf(attachment) {
	return (!isPlaylist(attachment) && attachment?.type === 'video')
		? (attachment?.hls_url || '')
		: ''
}

/**
 * The still to show before anything is played. Some local videos have the
 * file itself as `preview_url`, which would make the browser download it, so
 * only a preview that differs from the source counts.
 *
 * @param {object|null|undefined} attachment the attachment
 * @return {string|undefined} undefined leaves the attribute off
 */
export function posterOf(attachment) {
	const preview = attachment?.preview_url
	if (typeof preview !== 'string' || preview === '' || preview === attachment?.url) {
		return undefined
	}

	return preview
}

/**
 * Points a video element at an HLS playlist, through a lazily loaded hls.js
 * where the browser cannot play one natively. Safari keeps the plain file of a
 * laddered video. A ladder that fails fatally falls back to the plain file and
 * calls `onFallback`.
 *
 * @param {HTMLVideoElement} element the element to play in
 * @param {object} attachment the video attachment
 * @param {object} [options] what to ask and tell the caller
 * @param {() => boolean} [options.isCurrent] whether the element is still wanted once the library has arrived
 * @param {() => void} [options.onFallback] called when a ladder gave way to the plain file
 * @param {(hls: object, Hls: object) => void} [options.onCreated] called with the player before it loads, to listen to it
 * @return {Promise<object|null>} the hls.js player, or null where none was needed or none could be made
 */
export async function attachHls(element, attachment, { isCurrent = () => true, onFallback = () => {}, onCreated = () => {} } = {}) {
	const playlist = isPlaylist(attachment)
	const source = playlist ? attachment.url : ladderSourceOf(attachment)
	if (!element || !source) {
		return null
	}

	if (element.canPlayType('application/vnd.apple.mpegurl')) {
		if (playlist) {
			element.src = source
		}

		return null
	}

	try {
		const { default: Hls } = await import(/* webpackChunkName: "hls" */'hls.js')
		if (!Hls.isSupported() || !isCurrent()) {
			return null
		}

		const hls = new Hls({ enableWorker: false })
		if (!playlist) {
			hls.on(Hls.Events.ERROR, (event, data) => {
				if (data?.fatal) {
					logger.debug('falling back to the plain file', { data })
					hls.destroy()
					element.src = attachment.url
					onFallback()
				}
			})
		}

		onCreated(hls, Hls)
		hls.loadSource(source)
		hls.attachMedia(element)

		return hls
	} catch (error) {
		logger.error('could not start an HLS video', { error })

		return null
	}
}
