<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="groups.length > 0" class="reacted-by">
		<div v-for="group in groups" :key="group.key" class="reacted-by__group">
			<component :is="group.icon" :size="16" class="reacted-by__icon" />
			<span class="reacted-by__label">{{ group.label }}</span>
			<ul class="reacted-by__faces">
				<li v-for="account in group.accounts" :key="account.id" class="reacted-by__face">
					<ActorAvatar :actor="account" :size="24" />
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import FormatQuoteClose from 'vue-material-design-icons/FormatQuoteClose.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import ActorAvatar from './ActorAvatar.vue'
import logger from '../services/logger.js'
import { useSettingsStore } from '../store/settings.js'

/** How many faces are worth showing; the count says how many there are in all. */
const FACES = 12

/** How long after the first look the faces from elsewhere are asked for again. */
export const REFILL_MS = 2000
/** Bound retries when a remote profile stays unavailable. */
const MAX_REFILLS = 12

export default {
	name: 'PostReactedBy',
	components: {
		ActorAvatar,
		FormatQuoteClose,
		Heart,
		Repeat,
	},

	props: {
		status: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},
	},

	data() {
		return {
			boosted: [],
			favourited: [],
			quoted: [],
			refills: {},
			requestVersions: {},
			componentActive: true,
		}
	},

	computed: {
		/** @return {boolean} whether the reader has the like, boost and follower numbers hidden */
		hidesCounts() {
			return useSettingsStore().hidesCounts
		},

		/**
		 * The rows to draw: each kind of reaction that happened at least once and
		 * whose accounts have arrived, so no empty row fills in a moment later.
		 *
		 * @return {object[]}
		 */
		groups() {
			return [
				{
					key: 'boosts',
					icon: 'Repeat',
					accounts: this.boosted,
					label: this.hidesCounts
						? t('social', 'Boosted by')
						: n('social', 'Boosted by %n person', 'Boosted by %n people', this.status.reblogs_count ?? 0),
				},
				{
					key: 'favourites',
					icon: 'Heart',
					accounts: this.favourited,
					label: this.hidesCounts
						? t('social', 'Liked by')
						: n('social', 'Liked by %n person', 'Liked by %n people', this.status.favourites_count ?? 0),
					visible: this.favourited.length > 0,
				},
				{
					key: 'quotes',
					icon: 'FormatQuoteClose',
					accounts: this.quoted,
					label: this.hidesCounts
						? t('social', 'Quoted by')
						: n('social', 'Quoted %n time', 'Quoted %n times', this.status.quotes_count ?? 0),
					visible: this.hidesCounts
						? this.quoted.length > 0
						: (this.status.quotes_count ?? 0) > 0,
				},
			].filter((group) => group.visible ?? group.accounts.length > 0)
		},
	},

	watch: {
		// a different post, or the same post reacted to while it is on screen:
		// the reader's own boost belongs in the row it just changed the count of
		'status.id': 'load',
		'status.reblogs_count': function() {
			this.refills = { ...this.refills, reblogged_by: 0 }
			this.loadBoosts()
		},

		'status.favourites_count': function() {
			this.refills = { ...this.refills, favourited_by: 0 }
			this.loadFavourites()
		},

		'status.quotes_count': function() {
			this.refills = { ...this.refills, quotes: 0 }
			this.loadQuotes()
		},
	},

	mounted() {
		this.load()
	},

	unmounted() {
		this.componentActive = false
	},

	methods: {
		load() {
			this.refills = {}
			this.boosted = []
			this.favourited = []
			this.quoted = []
			this.loadBoosts()
			this.loadFavourites()
			this.loadQuotes()
		},

		async loadBoosts() {
			const accounts = await this.fetch('reblogged_by', this.status.reblogs_count, () => this.loadBoosts())
			if (accounts !== null) {
				this.boosted = accounts
			}
		},

		async loadFavourites() {
			const accounts = await this.fetch('favourited_by', this.status.favourites_count, () => this.loadFavourites())
			if (accounts !== null) {
				this.favourited = accounts
			}
		},

		async loadQuotes() {
			const accounts = await this.fetch('quotes', this.status.quotes_count, () => this.loadQuotes(), (posts) => {
				const seen = new Set()
				return posts.flatMap((post) => {
					const account = post?.account
					if (!account?.id || seen.has(account.id)) {
						return []
					}
					seen.add(account.id)
					return [account]
				})
			})
			if (accounts !== null) {
				this.quoted = accounts
			}
		},

		/**
		 * The accounts behind one of the counts. Nothing is asked for at a count of
		 * zero, and a refusal is not reported: the counts are still on the post.
		 * When the server says it is still reading remote reactions, the row is
		 * read again once, a little later.
		 *
		 * @param {string} path the endpoint under the status, Mastodon's name for it
		 * @param {number} count how many the post says there are; ignored while the numbers are hidden
		 * @param {() => void} again what reads the row again
		 * @param {(data: object[]) => object[]} [accountsOf] turns a quotes page into its authors
		 * @return {Promise<object[]>} the accounts, newest first
		 */
		async fetch(path, count, again, accountsOf = (data) => data) {
			const id = String(this.status.id)
			const requestVersion = (this.requestVersions[path] ?? 0) + 1
			this.requestVersions = { ...this.requestVersions, [path]: requestVersion }
			const isCurrent = () => this.componentActive
				&& String(this.status.id) === id
				&& this.requestVersions[path] === requestVersion

			// with the numbers hidden the server sends 0 for every post, so
			// the count says nothing about whether anybody is there to show
			if (!this.hidesCounts && !(count > 0)) {
				return []
			}

			try {
				const { data, headers } = await axios.get(
					generateUrl(`apps/social/api/v1/statuses/${id}/${path}`),
					{ params: { limit: FACES, _social_refresh: Date.now() } },
				)
				if (!isCurrent()) {
					return null
				}
				if (headers?.['x-social-filling'] === '1' && (this.refills[path] ?? 0) < MAX_REFILLS) {
					this.refills = { ...this.refills, [path]: (this.refills[path] ?? 0) + 1 }
					setTimeout(() => isCurrent() && again(), REFILL_MS)
				} else if (headers?.['x-social-filling'] !== '1') {
					this.refills = { ...this.refills, [path]: 0 }
				}

				return Array.isArray(data) ? accountsOf(data).slice(0, FACES) : []
			} catch (error) {
				if (isCurrent()) {
					logger.debug('Could not load who reacted to a post', { error, path })
				}

				return isCurrent() ? [] : null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.reacted-by {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 8px 0 0;
	padding: 10px 12px;
	border-radius: 8px;
	background: var(--color-background-hover);
}

.reacted-by__group {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.reacted-by__icon,
.reacted-by__label {
	color: var(--color-text-maxcontrast);
}

.reacted-by__label {
	font-size: 13px;
}

.reacted-by__faces {
	display: flex;
	/* they overlap, as a row of faces does, and the first one is on top */
	padding-inline-start: 4px;
}

.reacted-by__face {
	margin-inline-start: -4px;
	border-radius: 50%;
	/* the ring is what keeps two overlapping faces apart */
	box-shadow: none;
	transition: transform var(--social-motion-fast) var(--social-ease);

	&:hover {
		transform: translateY(-2px);
		z-index: 1;
	}
}

@media (prefers-reduced-motion: reduce) {
	.reacted-by__face {
		transition: none;

		&:hover {
			transform: none;
		}
	}
}
</style>
