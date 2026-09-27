/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether the post's own server says an interaction is allowed.
 *
 * GoToSocial defined `interactionPolicy` and Mastodon 4.5 reads it: an author
 * can say their post may not be replied to, boosted or liked. The server sends
 * what it understood as `interaction_policy`, and only for a remote post that
 * carries one — so an absent key is "the author said nothing", which is most
 * posts and means yes.
 *
 * @param {import('../types/Mastodon.js').Status} item the post, as the client API sends one
 * @param {string} interaction one of reply, boost, like, quote
 * @return {boolean} whether to offer it
 */
export function allowedByAuthor(item, interaction) {
	return item?.interaction_policy?.[interaction] !== false
}

/**
 * Whether a post may be passed on at all, before its author is asked.
 *
 * A boost and a quote both put the post in front of the booster's own
 * audience, so the server grants either one only for a post that was already
 * everybody's. Offering the action on anything narrower is offering a refusal.
 *
 * @param {import('../types/Mastodon.js').Status} item the post
 * @return {boolean} whether its audience allows it
 */
export function isShareable(item) {
	return item?.visibility === 'public' || item?.visibility === 'unlisted'
}
