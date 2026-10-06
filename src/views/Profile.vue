<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div :class="{'icon-loading': !accountLoaded}" class="social__wrapper">
		<ProfileInfo v-if="accountLoaded && accountInfo" :uid="uid" />

		<!-- your own profile is a page you post from, the way the home
		     timeline is. Somebody else's is a page you read -->
		<Composer v-if="isOwnProfile" />

		<router-view v-if="accountLoaded && accountInfo" name="details" />
		<!-- the lookup is what says an account is missing: `accountLoaded` only
		     says the store has it (see useAccount), so it cannot say it has not -->
		<NcEmptyContent
			v-if="lookupFinished && !accountInfo"
			:name="t('social', 'User not found')"
			:description="t('social', 'Sorry, we could not find the account of {userId}', { userId: uid })">
			<template #icon>
				<img
					:src="emptyContentImage"
					class="icon-illustration"
					alt="">
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { generateFilePath } from '@nextcloud/router'
import ProfileInfo from './../components/ProfileInfo.vue'
import { defineAsyncComponent, ref } from 'vue'
import logger from '../services/logger.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'
import { useAccount } from '../composables/useAccount.js'
import { useServerData } from '../composables/useServerData.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'Profile',
	components: {
		NcEmptyContent,
		ProfileInfo,
		Composer,
	},

	setup() {
		const { serverData } = useServerData()
		/** the handle on screen: the route's, or the one a public page carries */
		const uid = ref(null)
		const { profileAccount, accountInfo, accountLoaded } = useAccount(uid)

		return { serverData, uid, profileAccount, accountInfo, accountLoaded }
	},

	data() {
		return {
			state: [],
			/** whether a lookup for the handle on screen has come back, either way */
			lookupFinished: false,
		}
	},

	computed: {
		...mapStores(useAccountStore, useTimelineStore),
		/** @return {import('../types/Mastodon').Status[]} */
		timeline() {
			return this.timelineStore.getTimeline
		},

		/** @return {string} */
		emptyContentImage() {
			return generateFilePath('social', 'img', 'undraw/profile.svg')
		},

		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.accountStore.currentAccount
		},

		/**
		 * Whether this profile is the reader's own.
		 *
		 * Every profile used to carry a composer. On somebody else's it came
		 * pre-filled with a mention of them and set to a direct message, which
		 * made a page for reading an account look like a page for writing to
		 * them — and put a box asking "what would you like to share?" on a
		 * stranger's profile.
		 *
		 * The sub-routes are excluded with it: a list of followers is not a
		 * place to post from either.
		 *
		 * @return {boolean}
		 */
		isOwnProfile() {
			return Boolean(this.accountInfo)
				&& Boolean(this.currentAccount)
				&& this.accountInfo.acct === this.currentAccount.acct
				&& this.$route.name === 'profile'
		},
	},

	watch: {
		'$route.params.account': 'fetchProfileData',
	},

	// Start fetching account information before mounting the component
	async beforeMount() {
		this.fetchProfileData()
	},

	methods: {
		async fetchProfileData() {
			this.uid = this.$route.params.account || this.serverData.account
			this.lookupFinished = false

			if (!this.uid) {
				return
			}

			let fetchMethod
			if (this.serverData.public) {
				const requestedHandle = this.$route.params.account || this.serverData.account || ''
				fetchMethod = requestedHandle.includes('@') ? 'fetchAccountInfo' : 'fetchPublicAccountInfo'
			} else {
				fetchMethod = 'fetchAccountInfo'
			}

			const response = await this.accountStore[fetchMethod](this.profileAccount)
			this.lookupFinished = true
			if (response) {
				this.uid = response.acct
				const infoId = this.accountInfo?.nid || this.accountInfo?.id
				if (infoId && !this.serverData.public) {
					await this.accountStore.fetchAccountRelationshipInfo([infoId])
				} else {
					logger.debug('Not asking for a relationship', { known: Boolean(infoId), isPublic: this.serverData.public })
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.social__wrapper {
	max-width: var(--social-column);
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 4);

	&.icon-loading {
		margin-top: 50vh;
	}
}
</style>
