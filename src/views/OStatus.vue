<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="account">
		<div v-if="!serverData.local">
			<h2>{{ t('social', 'Follow on Nextcloud Social') }}</h2>
			<p>{{ t('social', 'Hello') }} <ActorAvatar :actor="currentUserActor" :size="16" :link="false" />{{ currentUser.displayName }}</p>
			<p v-if="!isFollowing">
				{{ t('social', 'Please confirm that you want to follow this account:') }}
			</p>

			<NcAvatar
				:url="avatarUrl"
				:disableMenu="true"
				:disableTooltip="true"
				:size="128" />
			<h2>{{ displayName }}</h2>
			<form v-if="!isFollowing" @submit.prevent="follow">
				<input type="submit" class="primary" :value="t('social', 'Follow')">
			</form>
			<p v-else>
				<span class="icon icon-checkmark-white" />
				{{ t('social', 'You are following this account') }}
			</p>

			<div v-if="isFollowing">
				<NcButton @click="close">
					{{ t('social', 'Close') }}
				</NcButton>
			</div>
		</div>
		<!-- Some unauthenticated user wants to follow a local account -->
		<div v-if="serverData.local">
			<p>{{ t('social', 'You are going to follow:') }}</p>
			<NcAvatar
				:user="serverData.local"
				:disableMenu="true"
				:disableTooltip="true"
				:size="128" />
			<h2>{{ displayName }}</h2>
			<form @submit.prevent="followRemote">
				<input
					v-model="remote"
					type="text"
					:aria-label="t('social', 'Your account, as name@domain')"
					:placeholder="t('social', 'name@domain of your federation account')">
				<input type="submit" class="primary" :value="t('social', 'Continue')">
			</form>
			<p>{{ t('social', 'This step is needed as the user is probably not registered on the same server as you are. We will redirect you to your homeserver to follow this account.') }}</p>
		</div>
	</div>
	<div v-else :class="{ 'icon-loading-dark': !account }" />
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import axios from '@nextcloud/axios'
import ActorAvatar from '../components/ActorAvatar.vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { useServerData } from '../composables/useServerData.js'

export default {
	name: 'OStatus',
	components: {
		ActorAvatar,
		NcAvatar,
		NcButton,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			remote: '',
			account: {},
			/**
			 * The signed-in reader. This page is public, so the reader is not
			 * the page's user: the server puts whoever is signed in into the
			 * initial state, and the handle is built from that rather than
			 * from @nextcloud/auth.
			 *
			 * @type {{uid?: string, displayName?: string}}
			 */
			currentUser: {},
		}
	},

	computed: {
		...mapStores(useAccountStore, useSettingsStore),
		isFollowing() {
			return this.accountStore.isFollowingUser(this.account.id)
		},

		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.account.id)
		},

		/**
		 * The logged-in user rendered as a (local) actor for ActorAvatar.
		 *
		 * @return {Partial<import('../types/Mastodon.js').Account>}
		 */
		currentUserActor() {
			const uid = this.currentUser?.uid ?? ''
			return { username: uid, acct: uid }
		},

		displayName() {
			if (typeof this.account.id === 'undefined') {
				return (this.serverData.account ? this.serverData.account : this.serverData.local)
			}

			return (this.account.display_name ? this.account.display_name : this.account.acct)
		},
	},

	beforeMount() {
		// importing server data into the store and fetching viewed account's information
		try {
			const serverData = loadState('social', 'serverData')
			if (serverData.currentUser) {
				this.currentUser = serverData.currentUser
			}
			this.settingsStore.setServerData(serverData)
			if (this.serverData.account && !this.serverData.local) {
				this.accountStore.fetchAccountInfo(this.serverData.account).then((result) => {
					this.account = result
				})
			}
			if (this.serverData.local) {
				this.accountStore.fetchPublicAccountInfo(this.serverData.local).then((result) => {
					this.account = result
				})
			}
		} catch {
			/* empty */
		}
	},

	methods: {
		follow() {
			this.accountStore.followAccount({ accountToFollow: this.account.acct }).then(() => {

			})
		},

		followRemote() {
			axios.get(generateUrl(`/apps/social/api/v1/ostatus/link/${this.serverData.local}/` + encodeURI(this.remote))).then((a) => {
				window.location = a.data.result.url
			})
		},

		close() {
			window.close()
		},
	},
}
</script>

<style scoped>
	h2, p {
		color: var(--color-primary-text);
	}

	p .icon {
		display: inline-block;
	}

	.avatardiv {
		vertical-align: -4px;
		margin-inline-end: 3px;
		filter: drop-shadow(0 0 0.5rem #333);
		margin-top: 10px;
		margin-bottom: 20px;
	}
</style>

<style>
	.wrapper {
		margin-top: 20px;
	}
</style>
