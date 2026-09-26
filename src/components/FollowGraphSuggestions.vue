<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- nothing at all where there is nobody to ask for: this page is also
	     served to a reader with no session, and a "follow more people" hint
	     shown to somebody who cannot follow anybody is noise -->
	<section v-if="available" class="graph">
		<!--
			The whole method needs a starting handful of follows, and saying so
			is the only honest empty state: "no suggestions" to somebody who
			follows nobody reads as a verdict on them rather than as a
			description of an empty graph.
		-->
		<p v-if="needs > 0" class="graph__hint">
			{{ n('social', 'Follow one more account and this can look at who they follow.',
				'Follow {count} more accounts and this can look at who they follow.', needs, { count: needs }) }}
		</p>

		<template v-else>
			<NcButton v-if="!asked" :disabled="loading" @click="load">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<AccountMultiplePlus v-else :size="20" />
				</template>
				{{ t('social', 'Look at who they follow') }}
			</NcButton>

			<NcLoadingIcon v-else-if="loading" class="graph__loading" :size="32" />

			<!-- two ways of looking at the same people: a list to read, or a
			     sky to play with. Same buttons, same labels, same follow. -->
			<template v-else-if="suggestions.length">
				<div class="graph__views" role="radiogroup" :aria-label="t('social', 'Show as')">
					<button
						type="button"
						role="radio"
						class="graph__view"
						:aria-checked="view === 'list'"
						@click="view = 'list'">
						<IconViewList :size="18" />
						{{ t('social', 'List') }}
					</button>
					<button
						type="button"
						role="radio"
						class="graph__view"
						:aria-checked="view === 'sky'"
						@click="view = 'sky'">
						<IconStarFourPoints :size="18" />
						{{ t('social', 'Constellation') }}
					</button>
				</div>

				<FollowConstellation
					v-if="view === 'sky'"
					:suggestions="suggestions"
					:youAvatar="youAvatar"
					:isFollowed="isFollowed"
					:isPending="isPending"
					:reasonFor="why"
					@follow="followFromSky" />

				<ul v-else class="graph__list">
					<PersonCard
						v-for="suggestion in suggestions"
						:key="suggestion.account.acct"
						:account="suggestion.account"
						:reason="why(suggestion)"
						:followed="isFollowed(suggestion.account)"
						:pending="isPending(suggestion.account)"
						@follow="follow" />
				</ul>
			</template>

			<p v-else class="graph__hint">
				{{ t('social', 'Nobody new came out of it. Many servers do not publish who an account follows, so this works better the more people you follow.') }}
			</p>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate, translatePlural } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import IconStarFourPoints from 'vue-material-design-icons/StarFourPoints.vue'
import IconViewList from 'vue-material-design-icons/ViewList.vue'
import PersonCard from './PersonCard.vue'
import { feel } from '../services/senses.js'
import { useAccountStore } from '../store/account.js'
import logger from '../services/logger.js'
import { useFollowByHandle } from '../composables/useFollowByHandle.js'
import { defineAsyncComponent } from 'vue'

/**
 * "Whom to follow", asked of the fediverse rather than of this server.
 *
 * Behind a button rather than loaded with the page: the answer costs one
 * request to every server the reader follows somebody on, and spending that
 * on somebody who came to look at hashtags is not this app's to spend.
 */
export default {
	name: 'FollowGraphSuggestions',

	components: {
		AccountMultiplePlus,
		FollowConstellation: defineAsyncComponent(() => import(/* webpackChunkName: "constellation" */'./FollowConstellation.vue')),
		IconStarFourPoints,
		IconViewList,
		NcButton,
		NcLoadingIcon,
		PersonCard,
	},

	setup() {
		return { ...useFollowByHandle(), accountStore: useAccountStore() }
	},

	data() {
		return {
			/** false once the server says there is no viewer to ask about */
			available: true,
			/** how many more follows it would take to be worth asking */
			needs: 0,
			/** whether the walk has been made */
			asked: false,
			loading: false,
			suggestions: [],
			/** 'list' to read them, 'sky' to see them as a constellation */
			view: 'list',
		}
	},

	computed: {
		/** @return {string} the reader's own face, for the middle of the sky */
		youAvatar() {
			return this.accountStore.currentAccount?.avatar ?? ''
		},
	},

	async mounted() {
		// only to learn whether there is a graph at all, which the server
		// answers without asking anybody anything
		await this.load(false)
	},

	methods: {
		t: translate,
		n: translatePlural,

		/**
		 * A follow from the sky is the same follow the list makes, with the
		 * chime and the tap it has everywhere else.
		 *
		 * @param {object} account the star pressed
		 */
		async followFromSky(account) {
			await this.follow(account)
			if (this.isFollowed(account)) {
				feel('follow')
			}
		},

		/**
		 * @param {boolean} walk whether to accept the cost of asking other
		 *                       servers; false asks the status route instead,
		 *                       which answers from this server alone
		 */
		async load(walk = true) {
			if (this.loading) {
				return
			}

			this.loading = true
			try {
				const { data } = walk
					? await axios.get(generateUrl('apps/social/api/v1/follow_graph'), { params: { limit: 20 } })
					: await axios.get(generateUrl('apps/social/api/v1/follow_graph/status'))
				this.needs = data?.needs ?? 0
				if (walk) {
					this.suggestions = Array.isArray(data?.suggestions) ? data.suggestions : []
					this.asked = true
				}
			} catch (error) {
				const status = error?.response?.status
				if (status === 401 || status === 403) {
					// a public page, or a session that ended; either way there
					// is no "people you follow" to work from
					this.available = false
				} else {
					logger.error('could not read the follow graph', { error })
				}

				if (walk) {
					this.asked = true
					this.suggestions = []
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} suggestion one suggestion as the server sent it
		 * @return {string} who follows this account, of the people the reader follows
		 */
		why(suggestion) {
			const via = Array.isArray(suggestion.via) ? suggestion.via : []
			if (via.length === 0) {
				return this.n(
					'social',
					'Followed by %n person you follow',
					'Followed by %n people you follow',
					suggestion.followed_by,
				)
			}

			const named = via.map((handle) => '@' + handle).join(', ')
			if (suggestion.followed_by > via.length) {
				return this.n(
					'social',
					'Followed by {named} and %n other you follow',
					'Followed by {named} and %n others you follow',
					suggestion.followed_by - via.length,
					{ named },
				)
			}

			return translate('social', 'Followed by {named}', { named })
		},
	},
}
</script>

<style scoped lang="scss">
.graph__views {
	display: flex;
	gap: 6px;
}

.graph__view {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 12px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;

	&[aria-checked="true"] {
		border-color: var(--color-primary-element);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-weight: 600;
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

.graph {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin-block-end: calc(var(--default-grid-baseline) * 4);
}

.graph__hint {
	color: var(--color-text-maxcontrast);
}

.graph__list {
	list-style: none;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: var(--default-grid-baseline);
}

.graph__loading {
	margin-block: calc(var(--default-grid-baseline) * 2);
}
</style>
