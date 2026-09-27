/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The shape of the box a picture is given before it has loaded.
 *
 * The space has to be reserved from the metadata alone: a timeline that lets
 * every image size itself jumps under the reader's thumb as each one arrives.
 *
 * The box is the shape the media actually is. There used to be a floor of 3:4
 * and a ceiling of 16:9 here, and anything outside them was reshaped to fit: a
 * 9:16 short -- which is what a phone records, and what the comment beside the
 * floor claimed to allow -- was given a 3:4 box and had its top and bottom cut
 * off by the `cover` the frame is drawn with. A tall frame still must not take
 * over a timeline, so `GalleryMedia` caps how tall one may be *drawn*. That
 * bounds the frame without reshaping the picture: what will not fit is made
 * smaller, never cropped.
 */

/**
 * What an attachment that never reported its size is given.
 *
 * Only a picture whose dimensions are unknown gets a shape it did not ask
 * for. Everything else keeps its own: a short shot on a phone is 9:16, and
 * it is shown 9:16.
 */
export const DEFAULT_RATIO = 4 / 3

/**
 * How wide an attachment is for its height.
 *
 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the attachment
 * @param {number} [fallback] what to use when its size is not known
 * @return {number} width divided by height
 */
export function ratioOf(attachment, fallback = DEFAULT_RATIO) {
	// `meta` is absent for a remote attachment this instance has not cached
	// yet, and AttachmentMeta::jsonSerialize() drops zero width and height
	const size = attachment?.meta?.original ?? attachment?.meta?.small ?? null
	const width = Number(size?.width)
	const height = Number(size?.height)

	if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
		return fallback
	}

	return width / height
}
