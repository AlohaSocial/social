/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import debounce from 'debounce'
import logger from '../services/logger.js'
import { escapeHtml, hashtagChip, mentionChip, mentionMenuItem } from './mentionTemplates.js'

/**
 * @param {string} text what was typed after the @
 * @return {Promise<object>} the accounts this server and its peers know by it
 */
function searchAccounts(text) {
	return axios.get(generateUrl('apps/social/api/v1/global/accounts/search'), { params: { search: text } })
}

/**
 * @param {string} text what was typed after the #
 * @return {Promise<object>} the hashtags that start with it
 */
function searchHashtags(text) {
	return axios.get(generateUrl('apps/social/api/v1/global/tags/search'), { params: { search: text } })
}

/**
 * Whether an actor id is a Bluesky account's, `https://bsky.app/profile/<did>`.
 *
 * @param {string} id the actor id
 * @return {boolean}
 */
function isBlueskyId(id) {
	return typeof id === 'string' && id.startsWith('https://bsky.app/profile/did:')
}

/**
 * The composer's @ and # autocomplete, as tributejs is configured.
 *
 * What the menus draw comes from other servers, so every value goes through
 * `mentionTemplates.js`, which escapes it. The template functions are called
 * with tributejs's own objects as `this`, which is why they are not arrows.
 *
 * @return {object} the options for `new Tribute()`
 */
export function mentionTributeOptions() {
	return {
		spaceSelectsMatch: true,
		collection: [
			{
				trigger: '@',
				lookup(item) {
					return item.key + item.value
				},

				menuItemTemplate(item) {
					return mentionMenuItem(item.original)
				},

				selectTemplate(item) {
					return mentionChip(item.original)
				},

				values: debounce(async (text, populate) => {
					if (text.length < 1) {
						populate([])
					}

					const response = await searchAccounts(text)

					// a Bluesky account the picker offers is not stored here, so
					// the route that serves cached avatars has none for it; the
					// picture the result carries is the one to show. Every other
					// remote account keeps the route, which serves the cached copy
					const users = response.data.result.accounts.map((user) => ({
						key: user.preferredUsername,
						value: user.account,
						url: user.url,
						avatar: user.local
							? generateUrl('/avatar/{user}/32', { user: user.preferredUsername })
							: (isBlueskyId(user.id) && user.icon?.url) || generateUrl('apps/social/api/v1/global/actor/avatar?id={id}', { id: user.id }),
					}))

					logger.debug('Found accounts for a mention', { count: users.length })
					populate(users)
				}, 200),
			},
			{
				trigger: '#',
				menuItemTemplate(item) {
					return escapeHtml(item.original.value)
				},

				selectTemplate(item) {
					let tag
					if (typeof item === 'undefined') {
						tag = this.currentMentionTextSnapshot
					} else {
						tag = item.original.value
					}
					return hashtagChip(tag, generateUrl('/timeline/tags/{tag}', { tag }))
				},

				values: debounce(async (text, populate) => {
					if (text.length < 1) {
						populate([])
					}

					const response = await searchHashtags(text)
					const tags = [
						...(response.data.result.exact && !Array.isArray(response.data.result.exact) ? [{ key: response.data.result.exact, value: response.data.result.exact }] : []),
						...response.data.result.tags.map(({ hashtag }) => ({ key: hashtag, value: hashtag })),
					]

					logger.debug('Found hashtags for a mention', { count: tags.length })
					populate(tags)
				}, 200),
			},
		],

		noMatchTemplate() {
			if (this.current.collection.trigger === '#') {
				if (this.current.mentionText === '') {
					return undefined
				} else {
					return '<li data-index="0">#' + escapeHtml(this.current.mentionText) + '</li>'
				}
			}
		},
	}
}
