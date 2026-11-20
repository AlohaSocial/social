/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import { fullDateTime } from './relativeTime.js'

/**
 * Keyword filter options. The context names are the server's
 * (`Model\Client\Filter::CONTEXTS`); the labels name where the reader finds
 * those posts in this client. Functions, not constants, because `t()` needs the
 * translations loaded, which they are not at import time.
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
			// a list timeline never reaches FilterService
			hint: t('social', 'The posts of the people and hashtags you follow. Not your lists: a list timeline is never filtered.'),
		},
		{
			id: 'notifications',
			label: t('social', 'Activities'),
			// Stream::exportAsNotification() drops `filtered`, so a folded
			// notification cannot be drawn here
			hint: t('social', 'Mentions, boosts, favorites and the rest of your activities. Here only taking the post out has any effect: an activity is never folded.'),
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
 * The label of one context. An unknown name, e.g. from another client, is
 * shown as it came.
 *
 * @param {string} id a context name as the API spells it
 * @return {string} what to call it on screen
 */
export function contextLabel(id) {
	return contextOptions().find((option) => option.id === id)?.label ?? id
}

/**
 * Mastodon's six filter durations. `seconds: null` is "never", sent as an
 * empty `expires_in`: leaving it out on an update keeps the expiry as it is.
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
 * Whether the filter still applies. `expires_at` is null for one that never
 * expires; the server keeps an expired row but stops applying it.
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
 * The reader's filters a status matched, as `FilterResult` entities;
 * `keyword_matches` is the post's text, not the reader's keyword. Only `warn`
 * filters arrive (a `hide` match is not sent at all), so each is a cover.
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
 * @return {string[]} the names of all the filters that matched, each once
 */
function matchedFilterTitles(status) {
	const titles = matchedFilters(status)
		.map((match) => String(match.filter?.title ?? '').trim())
		.filter((title) => title !== '')

	return [...new Set(titles)]
}

/**
 * What a cover over a filtered post says: the filter's name, never
 * `keyword_matches`, which would show the filtered words again.
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
