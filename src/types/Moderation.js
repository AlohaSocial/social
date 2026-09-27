/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A row of the moderation table, as `Report::moderationRow()` sends it.
 *
 * @typedef {object} ModerationReport
 * @property {number} id - the report's own id
 * @property {string} account_id - the reported actor's id
 * @property {string} account - their handle, '' when the actor could not be loaded
 * @property {string} reporter - the reporting actor's id
 * @property {boolean} local - whether the report was made on this instance
 * @property {string} category - spam, legal, violation or other
 * @property {string} comment - what the reporter wrote
 * @property {string[]} status_ids - the posts it is about
 * @property {number} creation - when it was made, in seconds since the epoch
 * @property {boolean} resolved - whether a moderator has dealt with it
 * @property {string} level - the decision standing against the account, '' for none
 */

/**
 * A post waiting in the review queue, as `HeldPost::jsonSerialize()` sends it.
 *
 * @typedef {object} HeldPost
 * @property {string} id - the held post's id in the queue
 * @property {string} account_id - the author's actor id
 * @property {string} username - the author's handle, their actor id when it could not be read
 * @property {string} reason - why it was held
 * @property {string} text - what was written
 * @property {string} spoiler_text - the content warning, '' for none
 * @property {string} visibility - who it will reach once it is published
 * @property {number} media_count - how many attachments it carries
 * @property {string} created_at - when it was written
 */

export default {}
