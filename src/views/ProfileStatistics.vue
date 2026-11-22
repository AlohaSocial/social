<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="profile-statistics">
		<TimelineSwitcher
			:options="kinds"
			value="statistics"
			:label="t('social', 'Which of their posts to show')" />
		<WeeklyRecap />
		<Statistics embedded />
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { defineAsyncComponent } from 'vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import WeeklyRecap from '../components/WeeklyRecap.vue'
import { isOwnProfile, profileKinds } from '../composables/useProfileKinds.js'

/** How the owner's posts did, on their own profile. */
export default {
	name: 'ProfileStatistics',

	components: {
		Statistics: defineAsyncComponent(() => import(/* webpackChunkName: "statistics" */'./Statistics.vue')),
		TimelineSwitcher,
		WeeklyRecap,
	},

	computed: {
		account() {
			return String(this.$route.params.account ?? '')
		},

		kinds() {
			return profileKinds(this.account)
		},
	},

	beforeMount() {
		if (!isOwnProfile(this.account)) {
			this.$router.replace({ name: 'profile', params: { account: this.account } })
		}
	},

	methods: {
		t,
	},
}
</script>
