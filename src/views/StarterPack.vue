<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="starter-pack social__wrapper">
		<NcLoadingIcon v-if="loading" class="starter-pack__loading" :size="44" />

		<div v-else-if="error" class="starter-pack__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="pack">
			<header class="starter-pack__head">
				<IconBluesky :size="28" class="starter-pack__icon" />
				<div class="starter-pack__titles">
					<h1 class="starter-pack__title">
						{{ pack.name }}
					</h1>
					<p class="starter-pack__by">
						{{ t('social', 'A Bluesky starter pack by {name}', { name: pack.creator.name || '@' + pack.creator.handle }) }}
						<span v-if="pack.joined > 0">· {{ n('social', '%n person joined with it', '%n people joined with it', pack.joined) }}</span>
					</p>
				</div>
			</header>
			<p v-if="pack.description" class="starter-pack__description">
				{{ pack.description }}
			</p>

			<div class="starter-pack__actions">
				<NcButton variant="primary" :disabled="following || toFollow.length === 0" @click="followAll">
					<template #icon>
						<NcLoadingIcon v-if="following" :size="20" />
						<IconAccountMultiplePlus v-else :size="20" />
					</template>
					{{ following
						? t('social', 'Following {done} of {total} …', { done, total: toFollow.length })
						: toFollow.length === 0
							? t('social', 'You follow everybody in it')
							: n('social', 'Follow %n person', 'Follow all %n people', toFollow.length) }}
				</NcButton>
				<NcCheckboxRadioSwitch v-if="pack.feeds.length > 0" v-model="keepFeeds" :disabled="following">
					{{ n('social', 'Also keep its feed', 'Also keep its %n feeds', pack.feeds.length) }}
				</NcCheckboxRadioSwitch>
			</div>

			<section v-if="pack.feeds.length > 0" class="starter-pack__section">
				<h2 class="starter-pack__caption">
					{{ t('social', 'Feeds') }}
				</h2>
				<ul class="starter-pack__list">
					<li v-for="feed in pack.feeds" :key="feed.uri" class="starter-pack__row">
						<IconBluesky :size="20" class="starter-pack__row-icon" />
						<div class="starter-pack__text">
							<router-link v-if="feedRoute(feed.uri)" class="starter-pack__name" :to="feedRoute(feed.uri)">
								{{ feed.name }}
							</router-link>
							<span v-if="feed.description" class="starter-pack__detail">{{ feed.description }}</span>
						</div>
					</li>
				</ul>
			</section>

			<section class="starter-pack__section">
				<h2 class="starter-pack__caption">
					{{ n('social', '%n person', '%n people', pack.members.length) }}
				</h2>
				<ul class="starter-pack__list">
					<li v-for="member in pack.members" :key="member.did" class="starter-pack__row">
						<ActorAvatar :actor="{ avatar: member.avatar, acct: member.handle, display_name: member.name }" :size="40" :hoverCard="false" />
						<div class="starter-pack__text">
							<router-link class="starter-pack__name" :to="{ name: 'profile', params: { account: member.handle } }">
								{{ member.name || member.handle }}
							</router-link>
							<span class="starter-pack__detail">@{{ member.handle }}</span>
						</div>
						<span v-if="member.following" class="starter-pack__state">
							{{ t('social', 'Following') }}
						</span>
						<NcButton v-else :disabled="following" @click="followOne(member)">
							{{ t('social', 'Follow') }}
						</NcButton>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import IconBluesky from 'vue-material-design-icons/ButterflyOutline.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import ActorAvatar from '../components/ActorAvatar.vue'
import { routeFor } from '../services/blueskyFeeds.js'
import eventBus, { BLUESKY_FEEDS_CHANGED } from '../services/eventBus.js'
import logger from '../services/logger.js'
import { fetchStarterPack, followFromStarterPack } from '../services/starterPacks.js'
import { showError, showSuccess } from '../services/toast.js'
import { starterPackUrl } from '../utils/starterPack.js'

/**
 * A Bluesky starter pack, opened here: who is in it and which feeds come
 * with it, and following them — everybody not followed yet, or one by one.
 * Its address is the one bsky.app gives it, under this app, so a link to
 * one is opened by putting it here.
 */
export default {
	name: 'StarterPack',

	components: {
		ActorAvatar,
		IconAccountMultiplePlus,
		IconBluesky,
		IconRefresh,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
	},

	data() {
		return {
			pack: null,
			loading: true,
			error: '',
			/** whether a follow is under way */
			following: false,
			/** how many of the members asked for have been followed so far */
			done: 0,
			/** whether following everybody keeps the feeds too, as the Bluesky app does */
			keepFeeds: true,
		}
	},

	computed: {
		/** @return {string} the pack's bsky.app address */
		address() {
			return starterPackUrl(this.$route.params)
		},

		/** @return {object[]} the members not followed yet */
		toFollow() {
			return (this.pack?.members ?? []).filter((member) => !member.following)
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
		n,

		/**
		 * @param {string} uri a feed's `at://` URI
		 * @return {object|null} where it is read here
		 */
		feedRoute(uri) {
			return routeFor(uri)
		},

		async load() {
			this.loading = true
			this.error = ''
			try {
				this.pack = await fetchStarterPack(this.address)
			} catch (error) {
				logger.error('Could not load the starter pack', { error })
				this.error = error?.response?.data?.error || t('social', 'Could not load the starter pack')
			} finally {
				this.loading = false
			}
		},

		async followAll() {
			await this.follow(this.toFollow, this.keepFeeds)
		},

		/**
		 * @param {object} member one member of the pack
		 */
		async followOne(member) {
			await this.follow([member], false)
		},

		/**
		 * @param {object[]} members whom to follow
		 * @param {boolean} feeds whether to keep the feeds too
		 */
		async follow(members, feeds) {
			this.following = true
			this.done = 0
			try {
				const { followed, failed } = await followFromStarterPack(this.address, members.map((member) => member.did), feeds, (done) => {
					this.done = done
				})
				for (const member of this.pack.members) {
					if (followed.includes(member.did)) {
						member.following = true
					}
				}
				if (feeds) {
					eventBus.emit(BLUESKY_FEEDS_CHANGED)
				}
				if (failed.length > 0) {
					showError(n('social', '%n account could not be followed', '%n accounts could not be followed', failed.length))
				} else if (members.length > 1) {
					showSuccess(n('social', 'You now follow %n person from this pack', 'You now follow %n people from this pack', followed.length))
				}
			} catch (error) {
				logger.error('Following from the starter pack failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not follow from the starter pack'))
			} finally {
				this.following = false
			}
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

	&__head {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-top: 16px;
	}

	&__icon,
	&__row-icon {
		flex-shrink: 0;
		color: var(--color-text-maxcontrast);
	}

	&__title {
		margin: 0;
		font-size: 1.5em;
	}

	&__by,
	&__detail {
		color: var(--color-text-maxcontrast);
	}

	&__by {
		margin: 0;
	}

	&__description {
		margin: 12px 0;
		white-space: pre-line;
	}

	&__actions {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 12px;
		margin: 16px 0;
	}

	&__section {
		margin-top: 16px;
	}

	&__caption {
		font-size: 1em;
		font-weight: bold;
		margin: 0 0 4px;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 52px;
		padding: 6px 0;
		border-top: 1px solid var(--color-border);
	}

	&__text {
		display: flex;
		flex-direction: column;
		flex: 1 1 auto;
		min-width: 0;
	}

	&__name {
		font-weight: bold;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__detail {
		font-size: 13px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__state {
		color: var(--color-text-maxcontrast);
	}
}
</style>
