/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * @param {{tag: string|null, reason?: string, text: string, label: string}} reason base visible and spoken explanation
 * @param {{also?: string[], protocol?: string, rerank_note?: string}|null|undefined} snapshot immutable server-side explanation
 * @return {{tag: string|null, reason?: string, text: string, label: string}} the localized explanation
 */
function explainSnapshot(reason, snapshot) {
	if (!snapshot || typeof snapshot !== 'object') {
		return reason
	}

	const details = []
	const labels = {
		FOLLOWED_AUTHOR: t('social', 'From an account you follow'),
		BEHAVIORAL_PROFILE: t('social', 'Your recent activity also influenced this ranking'),
		SIMILARITY_FEEDBACK: t('social', 'Your feedback about similar posts also influenced this ranking'),
		AUTHOR_FEEDBACK: t('social', 'Your feedback about this author also influenced this ranking'),
		FORMAT_FEEDBACK: t('social', 'Your format preference also influenced this ranking'),
		AUTOMATIC_PROFILE: t('social', 'Your activity and interests also influenced this ranking'),
		CACHED_CANDIDATE: t('social', 'Selected from posts available on this server'),
	}
	for (const key of Array.isArray(snapshot.also) ? snapshot.also : []) {
		if (Object.hasOwn(labels, key) && !details.includes(labels[key])) {
			details.push(labels[key])
		}
	}
	if (snapshot.protocol === 'atproto') {
		details.push(t('social', 'Bluesky post'))
	} else if (snapshot.protocol === 'activitypub') {
		details.push(t('social', 'Fediverse post'))
	}
	if (snapshot.rerank_note === 'DIVERSITY_PLACEMENT') {
		details.push(t('social', 'Placed here to mix authors and topics'))
	} else if (snapshot.rerank_note === 'EXPLORATION_SLOT' && reason.reason !== 'exploration') {
		details.push(t('social', 'Included to broaden the sources in your feed'))
	}
	if (details.length === 0) {
		return reason
	}

	const detailsLabel = `${details.join('. ')}. ${t('social', 'Explanation recorded when the feed was loaded')}.`
	const visibleDetail = details[0]
	return {
		...reason,
		text: `${reason.text} · ${visibleDetail}`,
		label: `${reason.label}. ${detailsLabel}`,
	}
}

/**
 * What the chip over a post in For you says: why the post is there.
 *
 * One matching hashtag is said as a sentence; two or more are only listed, to
 * keep the chip short, while the accessible name always gives the reason. A
 * `popular` post matched no hashtag of the reader's, so its chip links nowhere.
 *
 * @param {{tags?: string[], reason?: string, topics?: {id?: string, labels?: Record<string, string>}[], explanation?: {also?: string[], protocol?: string, rerank_note?: string}}|null|undefined} interest the post's `interest` field
 * @return {{tag: string|null, reason?: string, text: string, label: string}|null} the tag the chip links to
 *                                                                (null for none) and its
 *                                                                words, or null for no chip
 */
export function interestReason(interest) {
	if (interest?.reason === 'popular') {
		return {
			tag: null,
			text: t('social', 'Popular right now'),
			label: t('social', 'Why you\'re seeing this: popular right now'),
		}
	}

	if (interest?.reason === 'automatic') {
		const locale = typeof document === 'undefined'
			? 'en'
			: (document.documentElement.lang || 'en').split(/[-_]/)[0].toLowerCase()
		const topics = (interest.topics ?? [])
			.map((topic) => topic?.labels?.[locale] ?? topic?.labels?.en ?? topic?.id)
			.filter((topic) => typeof topic === 'string' && topic !== '')
		const detail = topics.length > 0
			? t('social', 'Based on your interests in {topics}', { topics: topics.slice(0, 3).join(', ') })
			: t('social', 'Based on what you read and interact with')
		return explainSnapshot({
			tag: null,
			reason: 'automatic',
			text: detail,
			label: t('social', "Why you're seeing this: {reason}", { reason: detail }),
		}, interest.explanation)
	}

	if (interest?.reason === 'followed_author') {
		return {
			tag: null,
			reason: 'followed_author',
			text: t('social', 'From an account you follow'),
			label: t('social', "Why you're seeing this: from an account you follow"),
		}
	}

	if (interest?.reason === 'discovery') {
		return {
			tag: null,
			reason: 'discovery',
			text: t('social', 'Selected by your For You feed'),
			label: t('social', "Why you're seeing this: selected by your For You feed from current posts and local discovery"),
		}
	}

	if (interest?.reason === 'exploration') {
		const locale = typeof document === 'undefined'
			? 'en'
			: (document.documentElement.lang || 'en').split(/[-_]/)[0].toLowerCase()
		const topics = (interest.topics ?? [])
			.map((topic) => topic?.labels?.[locale] ?? topic?.labels?.en ?? topic?.id)
			.filter((topic) => typeof topic === 'string' && topic !== '')
		if (topics.length === 0) {
			return null
		}
		const listed = topics.slice(0, 2).join(', ')
		const rest = topics.length - 2
		return explainSnapshot({
			tag: null,
			reason: 'exploration',
			text: t('social', 'Discover another voice on {topics}', { topics: rest > 0 ? `${listed} +${rest}` : listed }),
			label: t('social', "Why you're seeing this: included to broaden your sources, related to {topics}", { topics: topics.join(', ') }),
		}, interest.explanation)
	}

	const tags = (interest?.tags ?? []).map((tag) => String(tag).replace(/^#/, '')).filter((tag) => tag !== '')
	if (tags.length === 0) {
		return null
	}

	const first = '#' + tags[0]
	const reason = ['interest', 'followed', 'related', 'trending'].includes(interest.reason) ? interest.reason : 'interest'
	const rest = tags.length - 2
	const listed = tags.slice(0, 2).map((tag) => '#' + tag).join(', ')

	let text
	let spoken
	if (tags.length === 1) {
		text = {
			interest: t('social', 'Because you\'re interested in {tag}', { tag: first }),
			followed: t('social', 'Because you follow {tag}', { tag: first }),
			related: t('social', 'Related to {tag}', { tag: first }),
			trending: t('social', 'Trending here: {tag}', { tag: first }),
		}[reason]
		spoken = first
	} else if (rest > 0) {
		text = t('social', '{tags} +{count}', { tags: listed, count: rest })
		spoken = n('social', '{tags} and %n more', '{tags} and %n more', rest, { tags: listed })
	} else {
		text = listed
		spoken = listed
	}

	const why = {
		interest: t('social', 'Why you\'re seeing this: interested in {tags}', { tags: spoken }),
		followed: t('social', 'Why you\'re seeing this: you follow {tags}', { tags: spoken }),
		related: t('social', 'Why you\'re seeing this: related to {tags}', { tags: spoken }),
		trending: t('social', 'Why you\'re seeing this: trending here, {tags}', { tags: spoken }),
	}[reason]

	return {
		tag: tags[0],
		text,
		label: why,
	}
}
