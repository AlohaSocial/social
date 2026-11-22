/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { isNewerId } from '../utils/snowflake.js'

/**
 * Every notification type the server can serve. The API takes filters as
 * `exclude_types`, so a filter is this list minus what it keeps.
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
 * The filters Activities offers, in order, and the types each one keeps.
 * `all` keeps everything and sends nothing.
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
 * The filter the reader last chose, or `all` when the browser cannot
 * remember it (localStorage throws in private windows).
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
		// the filter then lasts only while the page is open
	}
}

/** How many faces a grouped card shows before it says "and N others". */
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
 * One row of Activities: a Notification, or several folded into one card
 * that also carries `accounts` (everybody in it) and `ids` (every row it
 * covers, so marking it read covers all of them).
 *
 * @typedef {import("../types/Mastodon").Notification & {accounts?: import("../types/Mastodon").Account[], ids?: string[], nid?: string}} NotificationCard
 */

/**
 * Folds every favourite or boost of one status, and every follow, into one
 * card across the whole page, not only consecutive runs. The card takes the
 * place and fields of its newest member, so nothing moves above something
 * newer; a lone member is returned untouched.
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
 * The newest row id a card stands for: the read marker is "up to", so a
 * grouped card must report the newest of its `ids`.
 *
 * Compared as a BigInt, never as a Number (see `utils/snowflake.js`): a
 * rounded id would set the marker behind the notification it should cover.
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
 * Whether this is a direct message: Mastodon delivers one as a mention whose
 * status is `direct`, and Activities shows it as a private message.
 *
 * @param {import("../types/Mastodon").Notification} notification a notification
 * @return {boolean}
 */
export function isDirectMessage(notification) {
	return notification?.type === 'mention' && notification.status?.visibility === 'direct'
}

/**
 * @param {import("../types/Mastodon").Notification} notification a notification,
 * or a card from groupNotifications() with its extra `accounts`
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
			return t('social', '{account} reacted to your short', { account })
		case 'story:comment':
			return t('social', '{account} replied to your short', { account })
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

/**
 * Where a card falls on the reader's local calendar: today, the six days
 * before, or earlier. An unreadable date counts as earlier.
 *
 * @param {string|undefined} createdAt when it happened, as the API sends it
 * @param {Date} now the moment the page is drawn
 * @return {'today'|'week'|'earlier'}
 */
export function dayOf(createdAt, now = new Date()) {
	const when = new Date(createdAt ?? '')
	if (Number.isNaN(when.getTime())) {
		return 'earlier'
	}

	const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
	if (when >= today) {
		return 'today'
	}
	const weekAgo = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6)

	return when >= weekAgo ? 'week' : 'earlier'
}

/**
 * The headings over Activities, by the index of the card each stands above:
 * "New" over the unread cards at the top, then Today, This week, Earlier.
 *
 * @param {NotificationCard[]} cards the cards, newest first
 * @param {number} newCount how many cards at the top are under "New"; 0 for none
 * @param {Date} now the moment the page is drawn
 * @return {Object<number, 'new'|'today'|'week'|'earlier'>}
 */
export function dayHeadings(cards, newCount = 0, now = new Date()) {
	/** @type {Object<number, 'new'|'today'|'week'|'earlier'>} */
	const headings = {}
	let previous = ''
	cards.forEach((card, index) => {
		/** @type {'new'|'today'|'week'|'earlier'} */
		const run = index < newCount ? 'new' : dayOf(card.created_at, now)
		if (run !== previous) {
			headings[index] = run
		}
		previous = run
	})

	return headings
}

/**
 * @param {'new'|'today'|'week'|'earlier'} run a key from dayHeadings()
 * @return {string} what the heading says
 */
export function dayHeadingLabel(run) {
	switch (run) {
		case 'new':
			return t('social', 'New')
		case 'today':
			return t('social', 'Today')
		case 'week':
			return t('social', 'This week')
		default:
			return t('social', 'Earlier')
	}
}
