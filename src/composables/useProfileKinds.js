/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import IconFolderMultipleImage from 'vue-material-design-icons/FolderMultipleImage.vue'
import IconImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import IconTextBoxMultiple from 'vue-material-design-icons/TextBoxMultiple.vue'
import { t } from '@nextcloud/l10n'

/**
 * What of an account can be read: its posts three ways, its collections, and
 * the photographs somebody else took of it.
 *
 * One list for the two views that draw the switcher, so that a profile's tabs
 * cannot differ depending on which of them is on screen. The three kinds of
 * post stay on the profile route with the kind in the query, as they were;
 * collections are a page of their own under the account, because they are
 * not a filter of the posts but a thing the account made out of them. Tagged
 * is a page of its own for the opposite reason: the posts on it are not the
 * account's at all — they are everybody else's photographs that it is named
 * in, which is why it is the last of the five rather than beside Photos.
 *
 * @param {string} account the handle in the route
 * @param {object} [layout] public presentation preferences
 * @param {string} [selected] the explicitly opened tab, retained for keyboard navigation
 * @return {Array<{value: string, label: string, icon: object, to: object}>}
 */
export function profileKinds(account, layout = {}, selected = '') {
	const to = (media) => ({
		name: 'profile',
		params: { account },
		query: { media: media === '' ? 'posts' : media },
	})

	const tabs = Array.isArray(layout.tabs) ? layout.tabs : ['posts', 'photos', 'videos', 'tagged', 'collections']
	const names = { '': 'posts', image: 'photos', video: 'videos', tagged: 'tagged', collections: 'collections' }
	return [
		{ value: '', label: t('social', 'Posts'), icon: IconTextBoxMultiple, to: to('') },
		{ value: 'image', label: t('social', 'Photos'), icon: IconImageMultiple, to: to('image') },
		{ value: 'video', label: t('social', 'Videos'), icon: IconPlayBoxMultiple, to: to('video') },
		{
			value: 'tagged',
			label: t('social', 'Tagged'),
			icon: IconAccountBoxMultiple,
			to: { name: 'profile.tagged', params: { account } },
		},
		{
			value: 'collections',
			label: t('social', 'Collections'),
			icon: IconFolderMultipleImage,
			to: { name: 'profile.collections', params: { account } },
		},
	].filter((tab) => tab.value === '' || tab.value === selected || tabs.includes(names[tab.value]))
}
