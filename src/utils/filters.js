/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import { fullDateTime } from './relativeTime.js'

/**
 * What a keyword filter is made of, in the words this app uses for the places
 * a filter applies.
 *
 * The five context names are the server's (`Model\Client\Filter::CONTEXTS`)
 * and are what `/api/v2/filters` stores; the labels are where the reader
 * actually finds those posts in this client, because "home" and "account" name
 * nothing on the screen in front of them.
 *
 * Everything is behind a function rather than a constant: `t()` needs the
 * translations to have been loaded, and a module read at import time is read
 * before they are.
 */

/** @type {string[]} the context names the server accepts, in its own order */
export const FILTER_CONTEXTS = ['home', 'notifications', 'public', 'thread', 'account']

/** @type {string[]} what a filter can do to a post it matches */
export const FILTER_ACTIONS = ['warn', 'hide']

/**
 * The contexts, as the form offers them.
 *
 * @return {Array<{id: string, label: string, hint: string}>} one entry per context
 */
export function contextOptions() {
	return [
		{
			id: 'home',
			label: t('social', 'My Feed'),
			// a list timeline is ListController's and never reaches
			// FilterService, so a reader who took "My Feed" to cover their
			// lists would be wrong and have no way of finding out
			hint: t('social', 'The posts of the people and hashtags you follow. Not your lists: a list timeline is never filtered.'),
		},
		{
			id: 'notifications',
			label: t('social', 'Activities'),
			// Stream::exportAsNotification() serialises the nested status
			// without `filtered`, so a folded notification is not something
			// this client could draw even though the server applies the filter
			hint: t('social', 'Mentions, boosts, favourites and the rest of your notifications. Here only taking the post out has any effect: a notification is never folded.'),
		},
		{
			id: 'public',
			label: t('social', 'Local, Global and hashtag timelines'),
			hint: t('social', 'Everything you read that you did not choose to follow.'),
		},
		{
			id: 'thread',
			label: t('social', 'Conversations'),
			hint: t('social', 'The replies above and below a post you have opened.'),
		},
		{
			id: 'account',
			label: t('social', 'Profiles'),
			hint: t('social', 'The posts listed on somebody’s profile.'),
		},
	]
}

/**
 * The label of one context, for the summary of a filter.
 *
 * A name that is not one of the five is shown as it came: the list is the
 * server's, and a filter made by another client is still the reader's to see.
 *
 * @param {string} id a context name as the API spells it
 * @return {string} what to call it on screen
 */
export function contextLabel(id) {
	return contextOptions().find((option) => option.id === id)?.label ?? id
}

/**
 * How long until a filter stops applying, as the form offers it.
 *
 * Mastodon's own six durations. `seconds: null` is "never", which the API is
 * told by sending an empty `expires_in` — not by leaving it out, which on an
 * update means "leave the expiry as it is".
 *
 * @return {Array<{id: string, seconds: (number|null), label: string}>} the durations
 */
export function expiryOptions() {
	return [
		{ id: 'never', seconds: null, label: t('social', 'Never') },
		{ id: '1800', seconds: 1800, label: t('social', '30 minutes') },
		{ id: '3600', seconds: 3600, label: t('social', '1 hour') },
		{ id: '21600', seconds: 21600, label: t('social', '6 hours') },
		{ id: '43200', seconds: 43200, label: t('social', '12 hours') },
		{ id: '86400', seconds: 86400, label: t('social', '1 day') },
		{ id: '604800', seconds: 604800, label: t('social', '1 week') },
	]
}

/**
 * Whether the filter is still doing anything.
 *
 * `expires_at` is null for a filter that never expires; anything else is a
 * moment, and the server stops applying the filter the moment it passes
 * without deleting the row.
 *
 * @param {import('../types/Mastodon.js').Filter} filter a v2 Filter entity, as the wire gave it
 * @param {number} [now] the moment to measure against, for tests
 * @return {boolean} false once it has expired
 */
export function isActive(filter, now = Date.now()) {
	if (!filter?.expires_at) {
		return true
	}

	const expires = new Date(filter.expires_at).getTime()

	return Number.isNaN(expires) || expires > now
}

/**
 * When a filter stops applying, in words.
 *
 * @param {import('../types/Mastodon.js').Filter} filter a v2 Filter entity, as the wire gave it
 * @param {number} [now] the moment to measure against, for tests
 * @return {string} a sentence for the summary line
 */
export function expiryLabel(filter, now = Date.now()) {
	if (!filter?.expires_at) {
		return t('social', 'Never expires')
	}

	const when = fullDateTime(filter.expires_at)
	if (when === '') {
		return t('social', 'Never expires')
	}

	return isActive(filter, now)
		? t('social', 'Until {date}', { date: when })
		: t('social', 'Expired on {date}', { date: when })
}

/**
 * The filters of the reader's own that a status matched.
 *
 * Mastodon's `FilterResult` entities, as `FilterService` builds them: one per
 * filter that matched, each naming the filter and `keyword_matches` — the
 * post's own text that matched it, not the reader's keyword. Only `warn` filters ever arrive — a status a
 * `hide` filter matched is not sent at all — so anything here is something to
 * cover rather than something to drop.
 *
 * @param {import('../types/Mastodon.js').Status} status a status entity, as the wire gave it
 * @return {import('../types/Mastodon.js').FilterResult[]} one entry per filter that matched
 */
export function matchedFilters(status) {
	const results = status?.filtered

	return Array.isArray(results)
		? results.filter((result) => result !== null && typeof result === 'object')
		: []
}

/**
 * @param {import('../types/Mastodon.js').Status} status a status entity, as the wire gave it
 * @return {string[]} the names of the filters that matched, each once. Several
 * can match one post, and naming only the first would send a reader off to
 * change a filter that is not the whole reason the post is covered.
 */
function matchedFilterTitles(status) {
	const titles = matchedFilters(status)
		.map((match) => String(match.filter?.title ?? '').trim())
		.filter((title) => title !== '')

	return [...new Set(titles)]
}

/**
 * What a cover over a filtered post says.
 *
 * The filter's name, never `keyword_matches`: that is the post's own wording,
 * and printing it on the cover would put exactly the words somebody filtered
 * back in front of them. The name is what they have to look for in Settings.
 *
 * @param {import('../types/Mastodon.js').Status} status a status entity, as the wire gave it
 * @return {string} a line for the cover
 */
export function filterCoverLabel(status) {
	const titles = matchedFilterTitles(status)

	return titles.length === 0
		? t('social', 'Filtered')
		: t('social', 'Filtered: {filters}', { filters: titles.join(', ') })
}

/**
 * What a refused request said, when it said anything.
 *
 * `/api/v2/filters` answers a 422 with the thing it would not take — "title is
 * required", "context must name at least one of …" — and swallowing that
 * leaves the form saying only "no". The messages are the API's own English;
 * the form is written so that none of them should ever be reached.
 *
 * @param {{response?: {data?: {error?: string}}}} error what axios threw
 * @param {string} fallback what to say when the server said nothing useful
 * @return {string} the message to show
 */
export function errorSaid(error, fallback) {
	const said = error?.response?.data?.error

	return typeof said === 'string' && said !== '' ? said : fallback
}
