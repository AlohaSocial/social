<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="starter-pack social__wrapper">
		<NcLoadingIcon v-if="loading" class="starter-pack__loading" :size="44" />

		<div v-else-if="error" class="starter-pack__error" role="alert">
			<ServerError :error="error" :alert="false" />
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<StarterPackDetail
			v-else-if="pack"
			:key="address"
			class="starter-pack__detail"
			:pack="detail"
			:canFollow="!visitor"
			:followMembers="followMembers"
			:followMember="followMember" />
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import ServerError from '../components/ServerError.vue'
import StarterPackDetail from '../components/StarterPackDetail.vue'
import { routeFor } from '../services/blueskyFeeds.js'
import eventBus, { BLUESKY_FEEDS_CHANGED } from '../services/eventBus.js'
import logger from '../services/logger.js'
import { fetchStarterPack, followFromStarterPack } from '../services/starterPacks.js'
import { showError } from '../services/toast.js'
import { useSettingsStore } from '../store/settings.js'
import { errorOf } from '../utils/serverError.js'
import { starterPackUrl } from '../utils/starterPack.js'

/**
 * A Bluesky starter pack, opened here under its bsky.app address: its members
 * and feeds, followed all at once or one by one.
 */
export default {
	name: 'StarterPack',

	components: {
		IconRefresh,
		NcButton,
		NcLoadingIcon,
		ServerError,
		StarterPackDetail,
	},

	data() {
		return {
			pack: null,
			loading: true,
			/** @type {{text: string, details: string}|null} why the pack could not be shown */
			error: null,
		}
	},

	computed: {
		/** @return {string} the pack's bsky.app address */
		address() {
			return starterPackUrl(this.$route.params)
		},

		/** @return {boolean} whether the reader is signed out, and so cannot follow */
		visitor() {
			return useSettingsStore().getServerData?.public === true
		},

		/** @return {object} the pack as `StarterPackDetail` draws one */
		detail() {
			const pack = this.pack
			return {
				name: pack.name,
				description: pack.description,
				curator: pack.creator.name || '@' + pack.creator.handle,
				url: pack.url || this.address,
				joined: pack.joined,
				feeds: pack.feeds.map((feed) => ({ ...feed, route: routeFor(feed.uri) })),
				members: pack.members.map((member) => ({
					key: member.did,
					acct: member.handle,
					name: member.name,
					actor: { avatar: member.avatar, acct: member.handle, display_name: member.name },
					following: member.following,
				})),
			}
		},
	},

	watch: {
		address() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		async load() {
			this.loading = true
			this.error = null
			try {
				this.pack = await fetchStarterPack(this.address)
			} catch (error) {
				logger.error('Could not load the starter pack', { error })
				this.error = errorOf(error, t('social', 'Could not load the starter pack'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object[]} members whom to follow, as `StarterPackDetail` names them
		 * @param {boolean} feeds whether to keep the feeds too
		 * @param {(done: number) => void} progress told how many have been asked for so far
		 * @return {Promise<{followed: string[], failed: string[]}>} the members' keys
		 */
		async followMembers(members, feeds, progress) {
			const result = await followFromStarterPack(this.address, members.map((member) => member.key), feeds, progress)
			if (feeds) {
				eventBus.emit(BLUESKY_FEEDS_CHANGED)
			}

			return result
		},

		/**
		 * @param {object} member one member, as `StarterPackDetail` names them
		 * @return {Promise<boolean>} whether they are followed now
		 */
		async followMember(member) {
			try {
				const { followed } = await followFromStarterPack(this.address, [member.key], false)
				if (followed.includes(member.key)) {
					return true
				}
			} catch (error) {
				logger.error('Following from the starter pack failed', { error })
			}
			showError(t('social', 'Could not follow {account}', { account: member.acct }))

			return false
		},
	},
}
</script>

<style scoped lang="scss">
.starter-pack {
	&__loading {
		margin: 40px auto;
	}

	&__error {
		text-align: center;
		margin: 40px auto;
	}

	&__detail {
		margin-top: 16px;
	}
}
</style>
