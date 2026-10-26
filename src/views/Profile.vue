<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div :class="{'icon-loading': !accountLoaded && !awaitingLookUp}" class="social__wrapper">
		<ProfileInfo v-if="accountLoaded && accountInfo" :uid="uid" />

		<!-- your own profile is a page you post from, the way the home
		     timeline is. Somebody else's is a page you read -->
		<Composer v-if="isOwnProfile" compactOptions />

		<div class="profile-sections">
			<router-view v-if="accountLoaded && accountInfo && profileRouteReady" name="details" :profileLayout="accountInfo.profile_layout" />
		</div>
		<!-- a follow link from another server, for an account this one does
		     not hold: looked up when the reader asks, not when a link opens -->
		<NcEmptyContent
			v-if="awaitingLookUp"
			class="profile-look-up"
			:name="serverData.resolve"
			:description="t('social', 'This account is not known here yet. Look it up to see it and follow it.')">
			<template #action>
				<NcButton variant="primary" :disabled="lookingUp" @click="lookUp">
					{{ lookingUp ? t('social', 'Looking up…') : t('social', 'Look up') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<!-- the lookup is what says an account is missing: `accountLoaded` only
		     says the store has it (see useAccount), so it cannot say it has not -->
		<NcEmptyContent
			v-if="lookupFinished && !accountInfo"
			class="profile-missing"
			:name="t('social', 'User not found')"
			:description="t('social', 'Sorry, we could not find the account of {userId}', { userId: uid })">
			<template #icon>
				<AlohaScene scene="tracks" class="profile-missing__scene" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { generateUrl } from '@nextcloud/router'
import AlohaScene from '../components/illustrations/AlohaScene.vue'
import ProfileInfo from './../components/ProfileInfo.vue'
import { defineAsyncComponent, ref } from 'vue'
import logger from '../services/logger.js'
import { setPageTitle } from '../services/pageTitle.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'
import { useAccount } from '../composables/useAccount.js'
import { useServerData } from '../composables/useServerData.js'
import { isBlueskyHandle } from '../utils/accountLocality.js'
import { recordRecommendationProfileVisit } from '../services/interests.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'Profile',
	components: {
		AlohaScene,
		NcButton,
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
			/** the account the server found for `serverData.resolve`, once asked */
			resolvedAccount: '',
			lookingUp: false,
			profileVisitFor: '',
		}
	},

	computed: {
		profileRouteReady() {
			return this.$route.name !== 'profile' || this.$route.query?.media !== undefined
				|| !['photos', 'videos'].includes(this.accountInfo?.profile_layout?.default_tab)
		},

		...mapStores(useAccountStore, useTimelineStore),
		/** @return {import('../types/Mastodon').Status[]} */
		timeline() {
			return this.timelineStore.getTimeline
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

		/**
		 * Whether the page is a follow link for an account this server does
		 * not hold, waiting for the reader to have it looked up.
		 *
		 * @return {boolean}
		 */
		awaitingLookUp() {
			return Boolean(this.serverData.resolve)
				&& !this.$route.params.account
				&& !this.serverData.account
				&& this.resolvedAccount === ''
				&& !this.lookupFinished
		},

		/** @return {string} what the tab calls this page */
		pageTitle() {
			const handle = String(this.uid ?? this.$route.params.account ?? '')

			return this.accountInfo?.display_name || this.accountInfo?.acct || handle
		},
	},

	watch: {
		accountInfo: { immediate: true, handler: 'applyDefaultProfileTab' },
		'$route.fullPath': 'applyDefaultProfileTab',

		'$route.params.account': 'fetchProfileData',

		// the handle until the account arrives, then the name it goes by
		pageTitle: {
			immediate: true,
			handler(title) {
				setPageTitle(title)
			},
		},
	},

	// Start fetching account information before mounting the component
	async beforeMount() {
		this.fetchProfileData()
	},

	methods: {
		applyDefaultProfileTab() {
			if (this.$route.name !== 'profile' || this.$route.query?.media !== undefined) {
				return
			}
			const tab = this.accountInfo?.profile_layout?.default_tab
			const media = tab === 'photos' ? 'image' : tab === 'videos' ? 'video' : ''
			if (media) {
				this.$router.replace({ ...this.$route, query: { ...this.$route.query, media } })
			}
		},

		async fetchProfileData() {
			this.uid = this.$route.params.account || this.serverData.account || this.resolvedAccount
			this.lookupFinished = false

			if (!this.uid) {
				return
			}

			// a handle with a dot and no host is asked for as it is: it is a
			// Bluesky handle, or a local user id the server answers for either
			// way — qualifying it with this host would make it neither
			const requestedHandle = String(this.$route.params.account || this.serverData.account || this.resolvedAccount || '')
			const remote = requestedHandle.includes('@') || isBlueskyHandle(requestedHandle)
			const fetchMethod = this.serverData.public && !remote ? 'fetchPublicAccountInfo' : 'fetchAccountInfo'
			const account = (this.serverData.public && !remote) || isBlueskyHandle(requestedHandle)
				? requestedHandle
				: this.profileAccount

			const response = await this.accountStore[fetchMethod](account)
			this.lookupFinished = true
			if (response) {
				this.uid = response.acct
				const profileId = this.accountInfo?.uri || this.accountInfo?.url || response.uri || response.url
				const visitedHandle = this.accountInfo?.acct || response.acct
				const isOwnAccount = Boolean(this.currentAccount?.acct && visitedHandle === this.currentAccount.acct)
				if (!this.serverData.public && !isOwnAccount
					&& this.serverData.interests?.recommendationsEnabled === true
					&& typeof profileId === 'string' && profileId !== this.profileVisitFor) {
					this.profileVisitFor = profileId
					recordRecommendationProfileVisit(profileId).catch((error) => logger.debug('Could not record a For You profile visit', { error }))
				}
				const infoId = this.accountInfo?.nid || this.accountInfo?.id
				if (infoId && !this.serverData.public) {
					await this.accountStore.fetchAccountRelationshipInfo([infoId])
				} else {
					logger.debug('Not asking for a relationship', { known: Boolean(infoId), isPublic: this.serverData.public })
				}
			}
		},

		/**
		 * Has the server look up the account a follow link names, which goes
		 * to that account's own server, and then shows it.
		 */
		async lookUp() {
			this.lookingUp = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/ostatus/resolve'), { uri: this.serverData.resolve })
				this.resolvedAccount = String(data?.result?.account ?? '')
			} catch (error) {
				logger.error('Could not look up the account to follow', { error })
			} finally {
				this.lookingUp = false
			}

			if (this.resolvedAccount !== '') {
				await this.fetchProfileData()
				return
			}
			this.uid = this.serverData.resolve
			this.lookupFinished = true
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

.profile-sections {
	min-width: 0;
	min-height: 280px;
	width: 100%;
}

/* NcEmptyContent pins any svg in its icon slot to 64px, with !important */
.profile-missing :deep(.empty-content__icon) {
	width: auto;
	height: auto;
	opacity: 1;
	margin-bottom: 20px;
}

.profile-missing .profile-missing__scene {
	width: 256px !important;
	height: auto !important;
	max-width: 100% !important;
	max-height: none !important;
}
</style>
