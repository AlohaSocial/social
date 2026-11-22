<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="profile-saved">
		<TimelineSwitcher
			:options="kinds"
			value="saved"
			:label="t('social', 'Which of their posts to show')" />
		<TimelineSwitcher
			class="profile-saved__lists"
			:options="lists"
			:value="list"
			:label="t('social', 'Which saved posts to show')" />
		<TimelineList :type="list" />
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import IconBookmark from 'vue-material-design-icons/Bookmark.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import TimelineList from '../components/TimelineList.vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import { isOwnProfile, profileKinds } from '../composables/useProfileKinds.js'
import { useTimelineStore } from '../store/timeline.js'

/** The posts the owner liked or bookmarked, on their own profile. */
export default {
	name: 'ProfileSaved',

	components: {
		TimelineList,
		TimelineSwitcher,
	},

	computed: {
		...mapStores(useTimelineStore),

		account() {
			return String(this.$route.params.account ?? '')
		},

		kinds() {
			return profileKinds(this.account)
		},

		/** @return {string} `favourites` or `bookmarks`, as the address says */
		list() {
			return this.$route.query.list === 'bookmarks' ? 'bookmarks' : 'favourites'
		},

		lists() {
			const to = (list) => ({ name: 'profile.saved', params: { account: this.account }, query: list === 'favourites' ? {} : { list } })
			return [
				{ value: 'favourites', label: t('social', 'Favorites'), icon: IconHeart, to: to('favourites') },
				{ value: 'bookmarks', label: t('social', 'Bookmarks'), icon: IconBookmark, to: to('bookmarks') },
			]
		},
	},

	watch: {
		list: 'load',
	},

	beforeMount() {
		if (!isOwnProfile(this.account)) {
			this.$router.replace({ name: 'profile', params: { account: this.account } })
			return
		}
		this.load()
	},

	methods: {
		t,

		load() {
			this.timelineStore.changeTimelineType({ type: this.list, params: {} })
		},
	},
}
</script>

<style scoped lang="scss">
.profile-saved__lists {
	margin-block-start: 4px;
}
</style>
