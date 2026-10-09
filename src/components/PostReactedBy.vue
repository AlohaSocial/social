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
import Heart from 'vue-material-design-icons/Heart.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import ActorAvatar from './ActorAvatar.vue'
import logger from '../services/logger.js'
import { useSettingsStore } from '../store/settings.js'

/** How many faces are worth showing; the count says how many there are in all. */
const FACES = 12

/** How long after the first look the faces from elsewhere are asked for again. */
export const REFILL_MS = 6000

export default {
	name: 'PostReactedBy',
	components: {
		ActorAvatar,
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
		}
	},

	computed: {
		/** @return {boolean} whether the reader has the like, boost and follower numbers hidden */
		hidesCounts() {
			return useSettingsStore().hidesCounts
		},

		/**
		 * The rows to draw, each one a kind of reaction that happened at least
		 * once and whose accounts have arrived. A count with no faces under it
		 * yet draws nothing rather than an empty row that fills a moment later.
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
						? t('social', 'Favourited by')
						: n('social', 'Favourited by %n person', 'Favourited by %n people', this.status.favourites_count ?? 0),
				},
			].filter((group) => group.accounts.length > 0)
		},
	},

	watch: {
		// a different post, or the same post reacted to while it is on screen:
		// the reader's own boost belongs in the row it just changed the count of
		'status.id': 'load',
		'status.reblogs_count': function() {
			this.loadBoosts()
		},

		'status.favourites_count': function() {
			this.loadFavourites()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		load() {
			this.loadBoosts()
			this.loadFavourites()
		},

		async loadBoosts() {
			this.boosted = await this.fetch('reblogged_by', this.status.reblogs_count, () => this.loadBoosts())
		},

		async loadFavourites() {
			this.favourited = await this.fetch('favourited_by', this.status.favourites_count, () => this.loadFavourites())
		},

		/**
		 * The accounts behind one of the two counts.
		 *
		 * Nothing is asked for when the count is zero — which is most posts —
		 * so the page a reader opens costs the two requests only when there is
		 * something to answer with. A refusal is not worth a message: the
		 * counts are still on the post, and this row is the elaboration.
		 *
		 * Who reacted where the post lives is read by the server in the
		 * background; when it says so, the row is filled in again a little
		 * later, once.
		 *
		 * @param {string} path the endpoint under the status, Mastodon's name for it
		 * @param {number} count how many the post says there are; ignored while the numbers are hidden
		 * @param {() => void} again what reads the row again
		 * @return {Promise<object[]>} the accounts, newest first
		 */
		async fetch(path, count, again) {
			// with the numbers hidden the server sends 0 for every post, so
			// the count says nothing about whether anybody is there to show
			if (!this.hidesCounts && !(count > 0)) {
				return []
			}

			try {
				const { data, headers } = await axios.get(
					generateUrl(`apps/social/api/v1/statuses/${this.status.id}/${path}`),
					{ params: { limit: FACES } },
				)
				if (headers?.['x-social-filling'] === '1') {
					const id = this.status.id
					setTimeout(() => this.status.id === id && again(), REFILL_MS)
				}

				return Array.isArray(data) ? data.slice(0, FACES) : []
			} catch (error) {
				logger.debug('Could not load who reacted to a post', { error, path })

				return []
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
	box-shadow: 0 0 0 2px var(--color-background-hover);
	transition: transform .15s ease;

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
