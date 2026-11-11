/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { isNewerId } from '../utils/snowflake.js'

/**
 * Every notification type the server can serve. The filters below are
 * expressed as what to leave *out*, which is the parameter the API takes
 * (`exclude_types`), so a filter is this list minus what it keeps.
 */
export const NOTIFICATION_TYPES = [
	'mention',
	'status',
	'reblog',
	'follow',
	'follow_request',
	'favourite',
	'poll',
	'update',
	'moderation_warning',
	'severed_relationships',
	'story:react',
	'story:comment',
	'tagged',
	'bluesky:repost_liked',
	'bluesky:repost_reposted',
	'bluesky:verified',
	'bluesky:unverified',
	'bluesky:starterpack_joined',
]

/**
 * The filters the notifications page offers, in the order it offers them, and
 * the types each one keeps. `all` keeps everything and sends nothing.
 */
export const NOTIFICATION_FILTERS = {
	all: NOTIFICATION_TYPES,
	mentions: ['mention', 'story:comment', 'tagged'],
	favourites: ['favourite', 'story:react', 'bluesky:repost_liked'],
	boosts: ['reblog', 'bluesky:repost_reposted'],
	follows: ['follow', 'follow_request', 'bluesky:starterpack_joined'],
	polls: ['poll'],
	edits: ['update'],
}

/** where the browser remembers which filter was last chosen */
export const FILTER_KEY = 'social.notificationsFilter'

/**
 * @param {string} filter one of the keys of NOTIFICATION_FILTERS
 * @return {string[]} what to hand the server as `exclude_types`; empty for `all`
 */
export function excludeTypesFor(filter) {
	const kept = NOTIFICATION_FILTERS[filter]
	if (kept === undefined || filter === 'all') {
		return []
	}

	return NOTIFICATION_TYPES.filter((type) => !kept.includes(type))
}

/**
 * The filter the reader last chose, or `all`.
 *
 * Reading localStorage throws in a private window and wherever site data is
 * blocked; a filter the browser cannot remember is simply `all` again.
 *
 * @return {string} a key of NOTIFICATION_FILTERS
 */
export function rememberedFilter() {
	try {
		const stored = window.localStorage.getItem(FILTER_KEY) ?? 'all'

		return stored in NOTIFICATION_FILTERS ? stored : 'all'
	} catch {
		return 'all'
	}
}

/**
 * @param {string} filter the filter to remember for next time
 */
export function rememberFilter(filter) {
	try {
		window.localStorage.setItem(FILTER_KEY, filter)
	} catch {
		// nothing to do about it: the page keeps the filter for as long as
		// it is open, and next time starts at `all`
	}
}

/**
 * How many faces a grouped card shows before it says "and N others".
 */
export const GROUP_FACES = 5

/**
 * @param {import("../types/Mastodon").Notification} notification a notification
 * @return {string} what two notifications must share to be one card, or ''
 *                  for a kind that is never grouped
 */
function groupKey(notification) {
	if (notification.type === 'follow') {
		return 'follow'
	}
	if ((notification.type === 'favourite' || notification.type === 'reblog') && notification.status?.id) {
		return notification.type + ':' + notification.status.id
	}

	return ''
}

/**
 * One row of the notifications page: a Notification, or several folded into
 * one card.
 *
 * A card keeps the shape of the newest member it stands for and adds the two
 * fields that make it a group — everybody in it, and every row it covers, so
 * that marking it read covers all of them. A single notification is returned
 * untouched and simply has neither.
 *
 * @typedef {import("../types/Mastodon").Notification & {accounts?: import("../types/Mastodon").Account[], ids?: string[], nid?: string}} NotificationCard
 */

/**
 * Folds the same reaction into one card.
 *
 * Twelve favourites of one post were twelve cards quoting the same post twelve
 * times, which is a page of the same thing. Every favourite or boost of one
 * status, and every follow, becomes a single card that names the first few
 * people and counts the rest.
 *
 * Across the page rather than only consecutive runs: the folding used to stop
 * at the first notification of another kind, which on any real page is
 * immediately -- twelve likes of one post arrive interleaved with everything
 * else that happened, so the page showed twelve cards anyway. The card keeps
 * the place of its *newest* member, so nothing moves above something that
 * happened after it; what is folded into it is only ever older.
 *
 * A grouped card keeps the shape of a Notification -- `id`, `type`,
 * `created_at`, `account`, `status` are those of the newest member -- and adds
 * `accounts`, everyone in it, and `ids`, every row it stands for, so that
 * marking the card read can cover all of them. One member on its own is
 * returned as it came, untouched.
 *
 * @param {import("../types/Mastodon").Notification[]} entries newest first
 * @return {NotificationCard[]} the cards, newest first
 */
export function groupNotifications(entries) {
	const cards = []
	const openByKey = new Map()

	for (const entry of entries) {
		const key = groupKey(entry)
		const open = key === '' ? undefined : openByKey.get(key)
		if (open !== undefined) {
			open.members.push(entry)
			continue
		}

		const card = { key, members: [entry] }
		if (key !== '') {
			openByKey.set(key, card)
		}
		cards.push(card)
	}

	return cards.map(({ members }) => {
		if (members.length === 1) {
			return members[0]
		}

		const newest = members[0]
		const seen = new Set()
		const accounts = []
		for (const member of members) {
			const id = member.account?.id ?? member.account?.acct
			if (member.account && !seen.has(id)) {
				seen.add(id)
				accounts.push(member.account)
			}
		}

		return {
			...newest,
			accounts,
			ids: members.map((member) => member.id),
		}
	})
}

/**
 * The newest row id a card stands for.
 *
 * A Notification carries the row id as `id`, a string; a status carries the
 * same number again as `nid`. A grouped card carries `ids`, everyone it
 * folded in — and the read marker is "up to", so a card that stands for nine
 * favourites has to report the newest of the nine or eight of them stay
 * unread for ever.
 *
 * Carried as a string and compared as a BigInt, never as a Number: see
 * `utils/snowflake.js`. A marker set from a rounded id sits *behind* the
 * notification it was meant to cover, so the badge came back however often the
 * reader cleared it.
 *
 * @param {NotificationCard} card a notification, or a card from groupNotifications()
 * @return {string} the id, '0' when there is none to read
 */
export function newestIdOf(card) {
	const ids = Array.isArray(card.ids) ? card.ids : [card.id ?? card.nid]

	return ids.reduce((highest, id) => (isNewerId(id, highest) ? String(id) : highest), '0')
}

/**
 * How a card names a person: the display name they chose, else the handle.
 *
 * @param {import("../types/Mastodon").Account|null|undefined} account the account
 * @return {string}
 */
export function nameOf(account) {
	return account?.display_name || account?.acct || ''
}

/**
 * A grouped card's first line: who, and what they all did.
 *
 * @param {NotificationCard} notification a card from groupNotifications() with `accounts`
 * @return {string}
 */
function groupedSummary(notification) {
	const accounts = notification.accounts
	const named = accounts.slice(0, 2).map(nameOf)
	const others = accounts.length - named.length

	let who
	if (others > 0) {
		who = n(
			'social',
			'{first}, {second} and %n other',
			'{first}, {second} and %n others',
			others,
			{ first: named[0], second: named[1] },
		)
	} else if (named.length === 2) {
		who = t('social', '{first} and {second}', { first: named[0], second: named[1] })
	} else {
		who = named[0] ?? ''
	}

	switch (notification.type) {
		case 'favourite':
			return t('social', '{who} liked your post', { who })
		case 'reblog':
			return t('social', '{who} boosted your post', { who })
		case 'follow':
			return t('social', '{who} started to follow you', { who })
		default:
			return who
	}
}

/**
 * A direct message reaches the bell as the mention it carries: Mastodon has
 * no notification kind of its own for one, and the status says `direct`.
 * Activities shows it as a private message rather than as a mention beside
 * the public ones.
 *
 * @param {import("../types/Mastodon").Notification} notification a notification
 * @return {boolean}
 */
export function isDirectMessage(notification) {
	return notification?.type === 'mention' && notification.status?.visibility === 'direct'
}

/**
 * @param {import("../types/Mastodon").Notification} notification a notification,
 * or a card from groupNotifications() — which carries the extra `accounts`
 * this reads, and is otherwise shaped exactly like one
 * @return {string}
 */
export function notificationSummary(notification) {
	const card = /** @type {{accounts?: object[]}} */ (notification)
	if (Array.isArray(card.accounts) && card.accounts.length > 1) {
		return groupedSummary(notification)
	}

	const account = nameOf(notification.account)
	switch (notification.type) {
		case 'mention':
			if (isDirectMessage(notification)) {
				return t('social', '{account} sent you a private message', { account })
			}

			return t('social', '{account} mentioned you', { account })
		case 'status':
			return t('social', '{account} posted a status', { account })
		case 'reblog':
			return t('social', '{account} boosted your post', { account })
		case 'follow':
			return t('social', '{account} started to follow you', { account })
		case 'follow_request':
			return t('social', '{account} requested to follow you', { account })
		case 'favourite':
			return t('social', '{account} liked your post', { account })
		case 'poll':
			return t('social', '{account} ended the poll', { account })
		case 'update':
			return t('social', '{account} edited a status', { account })
		case 'moderation_warning':
			return t('social', 'A moderator of this server has acted on your account')
		case 'severed_relationships':
			return t('social', 'Some of your follows were removed because a server was blocked')
		case 'story:react':
			return t('social', '{account} reacted to your story', { account })
		case 'story:comment':
			return t('social', '{account} replied to your story', { account })
		case 'tagged':
			return t('social', '{account} tagged you in a photo', { account })
		case 'admin.sign_up':
			return t('social', '{account} signed up', { account })
		case 'admin.report':
			return t('social', '{account} filed a report', { account })
		case 'bluesky:repost_liked':
			return t('social', '{account} liked your boost', { account })
		case 'bluesky:repost_reposted':
			return t('social', '{account} boosted your boost', { account })
		case 'bluesky:verified':
			return t('social', '{account} verified your account on Bluesky', { account })
		case 'bluesky:unverified':
			return t('social', '{account} no longer verifies your account on Bluesky', { account })
		case 'bluesky:starterpack_joined':
			return t('social', '{account} joined Bluesky with your starter pack', { account })
		default:
			return ''
	}
}
