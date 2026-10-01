/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * @typedef LocalAttachment - one attachment in the composer, before the post goes out
 * @property {?File} file - the file being uploaded; null for one picked from Files, the picture library or a re-draft
 * @property {string} [path] - what it is called in the preview when it has no file: a path or a title
 * @property {?import('./Mastodon.js').MediaAttachment} data - the server's answer once it is stored; null while it uploads or when it failed
 * @property {boolean} failed - the upload was refused
 * @property {string} [description] - the alt text as typed
 * @property {string} [saved] - the alt text the server already holds, so an unchanged one is not written again
 * @property {?import('../utils/focalPoint.js').FocalPoint} [focus] - the focal point; absent until one is set
 * @property {string} [filter] - the picture filter chosen, 'none' or absent for the picture as it was
 * @property {string} [bakedFilter] - the filter `data` was uploaded with, absent for none; the filter is baked in as the post is sent
 * @property {?import('./Mastodon.js').MediaAttachment} [unfiltered] - the upload made on attaching, kept once a filtered copy has replaced it
 */

export {}
