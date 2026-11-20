/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether the post's own server says an interaction is allowed. The server
 * sends GoToSocial's `interactionPolicy` as `interaction_policy` only for a
 * remote post that has one; an absent key means yes.
 *
 * @param {import('../types/Mastodon.js').Status} item the post, as the client API sends one
 * @param {string} interaction one of reply, boost, like, quote
 * @return {boolean} whether to offer it
 */
export function allowedByAuthor(item, interaction) {
	return item?.interaction_policy?.[interaction] !== false
}

/**
 * Whether a post may be boosted or quoted at all: the server grants either
 * only for a post that is already everybody's.
 *
 * @param {import('../types/Mastodon.js').Status} item the post
 * @return {boolean} whether its audience allows it
 */
export function isShareable(item) {
	return item?.visibility === 'public' || item?.visibility === 'unlisted'
}
