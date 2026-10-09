<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__followers">
		<UserEntry v-for="user in users" :key="user.id" :item="user" />
		<div ref="sentinel" class="list-sentinel" />
		<div v-if="loading" class="loading-indicator">
			<NcLoadingIcon :size="24" />
			<span>{{ t('social', 'Loading …') }}</span>
		</div>
		<!-- a list that could not be fetched is not a list with nobody in it,
		     and saying "No followers yet" for a server error tells the reader
		     something about this account that is not true -->
		<div v-else-if="failed && users.length === 0" class="followers-error" role="alert">
			<p class="followers-error__message">
				{{ isFollowers
					? t('social', 'The followers could not be loaded.')
					: t('social', 'The followed accounts could not be loaded.') }}
			</p>
			<NcButton variant="primary" @click="fetchData">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>
		<!-- a finished list with nobody in it used to be a blank panel -->
		<NcEmptyContent
			v-else-if="users.length === 0"
			:name="isFollowers ? t('social', 'No followers yet') : t('social', 'Not following anyone yet')"
			:description="isFollowers
				? t('social', 'People who follow this account will show up here.')
				: t('social', 'Accounts this account follows will show up here.')">
			<template #icon>
				<AccountMultipleOutline :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import UserEntry from '../components/UserEntry.vue'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useServerData } from '../composables/useServerData.js'
import { isBlueskyAccount } from '../utils/accountLocality.js'

/** How long after the first look the accounts from where the account lives are asked for again. */
export const REFILL_MS = 6000

export default {
	name: 'ProfileFollowers',
	components: {
		AccountMultipleOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Refresh,
		UserEntry,
	},

	setup() {
		const { serverData, hostname } = useServerData()

		return { serverData, hostname }
	},

	data() {
		return {
			observer: null,
			refill: null,
		}
	},

	computed: {
		...mapStores(useAccountStore),
		/** @return {string} */
		profileAccount() {
			const account = String(this.$route.params.account ?? '')
			if (account === '') {
				return ''
			}
			// a Bluesky handle is filed as it is, see useAccount
			if (account.includes('@') || isBlueskyAccount(this.accountStore.getAccount(account))) {
				return account
			}
			return account + '@' + this.hostname
		},

		/** @return {string} */
		storeKey() {
			return this.accountStore.getActorIdForAccount(this.profileAccount) || this.profileAccount
		},

		/** @return {import('../types/Mastodon.js').Account[]} */
		users() {
			if (!this.profileAccount) {
				return []
			}
			if (this.$route.name === 'profile.followers') {
				return this.accountStore.getAccountFollowers(this.profileAccount)
			} else {
				return this.accountStore.getAccountFollowing(this.profileAccount)
			}
		},

		isFollowers() {
			return this.$route.name === 'profile.followers'
		},

		loading() {
			if (!this.profileAccount) {
				return false
			}
			if (this.isFollowers) {
				return !!this.accountStore.accountsFollowersLoading[this.storeKey]
			} else {
				return !!this.accountStore.accountsFollowingsLoading[this.storeKey]
			}
		},

		/** @return {boolean} whether the last attempt at this list failed */
		failed() {
			if (!this.profileAccount) {
				return false
			}
			if (this.isFollowers) {
				return !!this.accountStore.accountsFollowersFailed[this.storeKey]
			} else {
				return !!this.accountStore.accountsFollowingsFailed[this.storeKey]
			}
		},

		allLoaded() {
			if (!this.profileAccount) {
				return true
			}
			if (this.isFollowers) {
				return !!this.accountStore.accountsFollowersAllLoaded[this.storeKey]
			} else {
				return !!this.accountStore.accountsFollowingsAllLoaded[this.storeKey]
			}
		},

		/** @return {boolean} whether the server is reading more of this list from where the account lives */
		filling() {
			if (!this.profileAccount) {
				return false
			}
			return this.isFollowers
				? !!this.accountStore.accountsFollowersFilling[this.storeKey]
				: !!this.accountStore.accountsFollowingsFilling[this.storeKey]
		},

		maxId() {
			if (!this.profileAccount) {
				return 0
			}
			if (this.isFollowers) {
				return this.accountStore.accountsFollowersMaxId[this.storeKey] || 0
			} else {
				return this.accountStore.accountsFollowingsMaxId[this.storeKey] || 0
			}
		},
	},

	watch: {
		'$route.params.account': 'fetchData',
		'$route.name': 'fetchData',
		loading(val) {
			if (!val) {
				this.$nextTick(() => {
					if (this.isSentinelVisible()) {
						this.loadMoreIfNeeded()
					}
				})
			}
		},
	},

	beforeMount() {
		this.fetchData()
	},

	mounted() {
		this.$nextTick(() => this.setupIntersectionObserver())
	},

	unmounted() {
		if (this.observer) {
			this.observer.disconnect()
		}
		clearTimeout(this.refill)
	},

	methods: {
		t: translate,
		setupIntersectionObserver() {
			this.observer = new IntersectionObserver((entries) => {
				if (entries[0].isIntersecting && !this.allLoaded) {
					this.loadMoreIfNeeded()
				}
			}, { rootMargin: '300px' })
			if (this.$refs.sentinel) {
				this.observer.observe(this.$refs.sentinel)
			}
		},

		/**
		 * The first page of the list. Who follows an account elsewhere, or whom
		 * it follows, is read by the server in the background; when it says so,
		 * the first page is asked for again a little later, once.
		 */
		async fetchData() {
			clearTimeout(this.refill)
			const account = this.profileAccount
			if (!account) {
				return
			}
			const followers = this.isFollowers
			await this.fetchFirstPage(account, followers)
			if (this.filling && account === this.profileAccount && followers === this.isFollowers) {
				this.refill = setTimeout(() => {
					if (account === this.profileAccount && followers === this.isFollowers) {
						this.fetchFirstPage(account, followers)
					}
				}, REFILL_MS)
			}
		},

		/**
		 * @param {string} account whose list
		 * @param {boolean} followers the followers, or the followed accounts
		 */
		fetchFirstPage(account, followers) {
			return followers
				? this.accountStore.fetchAccountFollowers({ account })
				: this.accountStore.fetchAccountFollowing({ account })
		},

		loadMoreIfNeeded() {
			if (this.loading || this.allLoaded || !this.maxId) {
				return
			}
			if (this.isFollowers) {
				this.accountStore.fetchAccountFollowers({ account: this.profileAccount, maxId: this.maxId })
			} else {
				this.accountStore.fetchAccountFollowing({ account: this.profileAccount, maxId: this.maxId })
			}
		},

		isSentinelVisible() {
			const sentinel = /** @type {HTMLElement|undefined} */ (this.$refs.sentinel)
			if (!sentinel) {
				return false
			}
			const rect = sentinel.getBoundingClientRect()
			return rect.top <= window.innerHeight + 300
		},
	},
}
</script>

<style scoped>
	.social__followers {
		width: 100%;
		max-width: var(--social-column);
		margin: 15px auto;
		display: flex;
		flex-direction: column;
	}

	.list-sentinel {
		height: 1px;
	}

	.loading-indicator {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		padding: 16px;
		color: var(--color-text-lighter);
	}

	.followers-error {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		margin: 20px 0;
		padding: 24px 20px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: var(--color-main-background);
		text-align: center;
	}

	.followers-error__message {
		margin: 0;
		color: var(--color-text-lighter);
		line-height: 1.5;
	}
</style>
