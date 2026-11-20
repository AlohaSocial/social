/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import IconFolderMultipleImage from 'vue-material-design-icons/FolderMultipleImage.vue'
import IconImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import IconBookmarkMultipleOutline from 'vue-material-design-icons/BookmarkMultipleOutline.vue'
import IconChartBox from 'vue-material-design-icons/ChartBox.vue'
import IconFileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import IconTextBoxMultiple from 'vue-material-design-icons/TextBoxMultiple.vue'
import { t } from '@nextcloud/l10n'
import { useAccountStore } from '../store/account.js'

/**
 * What of an account can be read: its posts three ways, the photos others
 * tagged it in, and its collections. One list for both views that draw the
 * switcher, so a profile's tabs are the same in each.
 *
 * Posts, Photos and Videos stay on the profile route with the kind in the
 * query; Tagged and Collections are pages of their own, since they are not
 * filters of the account's posts.
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
		.concat(isOwnProfile(account) ? ownTabs(account) : [])
}

/**
 * Whether the profile on screen is the signed-in reader's own.
 *
 * @param {string} account the handle the address names
 * @return {boolean}
 */
export function isOwnProfile(account) {
	const current = useAccountStore().currentAccount
	if (!current?.acct || account === '') {
		return false
	}
	if (account === current.acct) {
		return true
	}
	try {
		return account === current.acct + '@' + new URL(current.url).hostname
	} catch {
		return false
	}
}

/**
 * The tabs only the owner sees: what they saved, what they have not posted yet, and how their posts did.
 *
 * @param {string} account the handle the address names
 * @return {object[]}
 */
function ownTabs(account) {
	return [
		{ value: 'saved', label: t('social', 'Saved'), icon: IconBookmarkMultipleOutline, to: { name: 'profile.saved', params: { account } } },
		{ value: 'drafts', label: t('social', 'Drafts'), icon: IconFileDocumentEditOutline, to: { name: 'profile.drafts', params: { account } } },
		{ value: 'statistics', label: t('social', 'Statistics'), icon: IconChartBox, to: { name: 'profile.statistics', params: { account } } },
	]
}
