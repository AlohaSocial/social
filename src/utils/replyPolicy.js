/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The parts a reply rule is made of, besides lists, in the server's order. */
export const RULE_PARTS = ['followers', 'following', 'mentioned']

/**
 * The parts of a reply rule as the server keeps it: none for everybody,
 * `nobody` alone, or any of the followers, the accounts followed, the
 * accounts mentioned and `list:<id>`, comma-separated.
 *
 * @param {string} rule the post's `reply_policy`
 * @return {string[]} its parts
 */
export function ruleParts(rule) {
	if (!rule || rule === 'everyone') {
		return []
	}

	return rule.split(',').filter((part) => part !== '')
}

/**
 * The rule with one part added or taken away: nothing left is everybody.
 *
 * @param {string} rule the rule as it is
 * @param {string} part `followers`, `following`, `mentioned` or `list:<id>`
 * @param {boolean} on whether the part is in it now
 * @return {string} the new rule, in the server's order
 */
export function withPart(rule, part, on) {
	const parts = ruleParts(rule).filter((one) => one !== 'nobody' && one !== part)
	if (on) {
		parts.push(part)
	}
	const known = RULE_PARTS.filter((one) => parts.includes(one))
	const lists = parts.filter((one) => one.startsWith('list:'))
	const kept = [...known, ...lists].slice(0, 5)

	return kept.length === 0 ? 'everyone' : kept.join(',')
}

/**
 * @param {string} part one part of a rule
 * @param {Array<{id: string|number, title: string}>} lists the reader's lists
 * @return {string} what it is called
 */
export function partLabel(part, lists = []) {
	switch (part) {
		case 'followers':
			return t('social', 'People who follow me')
		case 'following':
			return t('social', 'People I follow')
		case 'mentioned':
			return t('social', 'People I mention')
		default: {
			const list = lists.find((one) => `list:${one.id}` === part)

			return list ? t('social', 'People on {list}', { list: list.title }) : t('social', 'People on one of my lists')
		}
	}
}

/**
 * What a rule says, in a few words.
 *
 * @param {string} rule the post's `reply_policy`
 * @param {Array<{id: string|number, title: string}>} lists the reader's lists
 * @return {string} its label
 */
export function ruleLabel(rule, lists = []) {
	if (!rule || rule === 'everyone') {
		return t('social', 'Anybody')
	}
	if (rule === 'nobody') {
		return t('social', 'Nobody')
	}

	return ruleParts(rule).map((part) => partLabel(part, lists)).join(', ')
}

/**
 * The reader's own lists, the ones a rule may name.
 *
 * @return {Promise<Array<{id: string, title: string}>>} their lists
 */
export async function ownLists() {
	const { data } = await axios.get(generateUrl('apps/social/api/v1/lists'))

	return Array.isArray(data) ? data : []
}
