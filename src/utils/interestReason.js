/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * What the chip over a post in For you says: why the post is there.
 *
 * One matching hashtag is said as a reason ("Because you follow #film"); two
 * or more are listed instead, because a sentence naming three tags is longer
 * than the post it sits over. The accessible name always gives the reason, so
 * a screen reader hears why rather than a list of tags.
 *
 * A `popular` post matched none of the reader's hashtags: it filled a ranking
 * that was too short, from what is trending in the same kind.
 * Its chip says exactly that and links nowhere, since no tag put it there.
 *
 * @param {{tags?: string[], reason?: string}|null|undefined} interest the post's `interest` field
 * @return {{tag: string|null, text: string, label: string}|null} the tag the chip links to
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
