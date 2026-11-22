/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The shape of the box a picture is given before it has loaded, reserved from
 * the metadata so the timeline does not jump as images arrive. The box is the
 * media's own shape; `GalleryMedia` caps how tall a frame is drawn, so a tall
 * picture is made smaller, never cropped.
 */

/** What an attachment that never reported its size is given. */
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
