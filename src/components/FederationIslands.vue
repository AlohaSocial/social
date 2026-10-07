<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="islands">
		<svg
			class="islands__map"
			:viewBox="`0 0 ${MAP.width} ${MAP.height}`"
			role="img"
			:aria-label="summary">
			<rect
				class="islands__sea"
				:width="MAP.width"
				:height="MAP.height"
				rx="18" />
			<path
				class="islands__swell"
				:d="`M24 ${MAP.height - 26}q14-8 28 0t28 0t28 0M${MAP.width - 110} 30q14-8 28 0t28 0t28 0`" />

			<g class="islands__routes">
				<path
					v-for="island in placed"
					:key="`route-${island.host}`"
					class="islands__route"
					:d="island.route" />
			</g>

			<g
				v-for="island in placed"
				:key="island.host"
				class="islands__island">
				<title>{{ islandTitle(island) }}</title>
				<ellipse
					class="islands__shallows"
					:cx="island.x"
					:cy="island.y"
					:rx="island.r * 1.35"
					:ry="island.r * 0.42" />
				<path class="islands__sand" :d="islandPath(island.x, island.y, island.r)" />
				<text
					class="islands__label"
					:x="island.x"
					:y="island.y + 20">
					{{ shortHost(island.host) }}
				</text>
				<text
					class="islands__count"
					:x="island.x"
					:y="island.y + 35">
					{{ n('social', '%n person', '%n people', island.count) }}
				</text>
			</g>

			<!-- the busiest route has a canoe on it, on its way home -->
			<path
				v-if="placed.length"
				class="islands__canoe"
				:d="`M${placed[0].canoe.x - 9} ${placed[0].canoe.y}h18q-3 6-9 6t-9-6Z`" />

			<g class="islands__home">
				<title>{{ homeTitle }}</title>
				<ellipse
					class="islands__shallows"
					:cx="MAP.width / 2"
					:cy="MAP.height / 2"
					rx="56"
					ry="16" />
				<path class="islands__sand islands__sand--home" :d="islandPath(MAP.width / 2, MAP.height / 2, 42)" />
				<path class="islands__trunk" :d="`M${MAP.width / 2 + 16} ${MAP.height / 2 - 18}q-2-14 4-26`" />
				<path
					class="islands__frond"
					:d="palm(MAP.width / 2 + 20, MAP.height / 2 - 44)" />
				<clipPath :id="avatarClip">
					<circle :cx="MAP.width / 2 - 8" :cy="MAP.height / 2 - 20" r="12" />
				</clipPath>
				<circle
					class="islands__you"
					:cx="MAP.width / 2 - 8"
					:cy="MAP.height / 2 - 20"
					r="13" />
				<image
					v-if="avatar"
					:href="avatar"
					:x="MAP.width / 2 - 20"
					:y="MAP.height / 2 - 32"
					width="24"
					height="24"
					:clip-path="`url(#${avatarClip})`"
					preserveAspectRatio="xMidYMid slice" />
				<text
					class="islands__label islands__label--home"
					:x="MAP.width / 2"
					:y="MAP.height / 2 + 22">
					{{ shortHost(homeHost) }}
				</text>
			</g>
		</svg>

		<p class="islands__summary">
			{{ summary }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import logger from '../services/logger.js'
import { MAP, islandPath, islandsFrom, placeIslands } from '../services/islands.js'
import { useAccountStore } from '../store/account.js'

/** the largest page the following route hands out */
const PAGE = 50
/** enough pages for a picture; nobody reads a map of a thousand islands */
const MAX_PAGES = 6

let instances = 0

export default {
	name: 'FederationIslands',

	data() {
		instances += 1
		return {
			MAP,
			/** everybody the reader follows, as far as it was read */
			following: [],
			loaded: false,
			failed: false,
			avatarClip: `islands-avatar-${instances}`,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {object|null} */
		me() {
			return this.accountStore.currentAccount ?? null
		},

		/** @return {string} */
		avatar() {
			return this.me?.avatar ?? ''
		},

		/**
		 * The reader's own server, as their address spells it.
		 *
		 * @return {string}
		 */
		homeHost() {
			try {
				return new URL(this.me?.url ?? '').host || window.location.host
			} catch {
				return window.location.host
			}
		},

		/** @return {{islands: Array<{host: string, count: number}>, more: number, local: number}} */
		grouped() {
			return islandsFrom(this.following, this.homeHost)
		},

		/** @return {Array<object>} */
		placed() {
			return placeIslands(this.grouped.islands)
		},

		/** @return {string} */
		homeTitle() {
			return t('social', '{host}, your island', { host: this.homeHost })
		},

		/**
		 * The map in one sentence, which is also what a screen reader hears
		 * for it.
		 *
		 * @return {string}
		 */
		summary() {
			if (!this.loaded) {
				return t('social', 'Charting your islands …')
			}
			if (this.failed) {
				return t('social', 'Could not read who you follow, so only your own island is drawn.')
			}

			const count = this.grouped.islands.length + this.grouped.more
			if (count === 0) {
				return t('social', 'Nobody you follow lives on another island yet. Find somebody with the search below, and a canoe route opens to theirs.')
			}

			const islands = n('social', 'You follow people on %n other island.', 'You follow people on %n other islands.', count)
			return this.grouped.more > 0
				? `${islands} ${n('social', 'The %n smallest is not drawn.', 'The %n smallest are not drawn.', this.grouped.more)}`
				: islands
		},
	},

	watch: {
		'me.id': {
			immediate: true,
			handler(id) {
				if (id) {
					this.load(id)
				}
			},
		},
	},

	methods: {
		t,
		n,
		islandPath,

		/**
		 * Reads the reader's follows a page at a time.
		 *
		 * @param {string} id the reader's account id
		 */
		async load(id) {
			const following = []
			try {
				let maxId = ''
				for (let page = 0; page < MAX_PAGES; page++) {
					const params = { limit: PAGE }
					if (maxId) {
						params.max_id = maxId
					}
					const { data } = await axios.get(generateUrl(`apps/social/api/v1/accounts/${id}/following`), { params })
					const accounts = Array.isArray(data) ? data : []
					following.push(...accounts)
					if (accounts.length < PAGE) {
						break
					}
					maxId = accounts[accounts.length - 1].id
				}
				this.failed = false
			} catch (error) {
				logger.error('Could not read the accounts followed for the islands map', { error })
				this.failed = true
			}
			this.following = following
			this.loaded = true
		},

		/**
		 * @param {string} host a server
		 * @return {string} short enough to sit under its island
		 */
		shortHost(host) {
			return host.length > 22 ? `${host.slice(0, 21)}…` : host
		},

		/**
		 * @param {{host: string, count: number}} island one island
		 * @return {string}
		 */
		islandTitle(island) {
			return n('social', 'You follow %n person on {host}', 'You follow %n people on {host}', island.count, { host: island.host })
		},

		/**
		 * @param {number} x where the fronds start
		 * @param {number} y where the fronds start
		 * @return {string} path data for four fronds
		 */
		palm(x, y) {
			return `M${x} ${y}q-11-4-17 4M${x} ${y}q11-6 17 2M${x} ${y}q-5-9-13-10M${x} ${y}q6-9 14-8`
		},
	},
}
</script>

<style scoped>
/* the same sunset palette as the empty-page scenes in illustrations/AlohaScene.vue */
.islands {
	--aloha-coral: #e6604a;
	--aloha-gold: #f0b23c;
	--aloha-teal: #138a86;
	--aloha-ground: var(--color-main-background);
	--aloha-ink: color-mix(in oklab, var(--color-main-text) 70%, var(--aloha-teal));
}

.islands__map {
	display: block;
	width: 100%;
	height: auto;
	max-width: 760px;
}

.islands__sea {
	fill: color-mix(in oklab, var(--aloha-teal) 22%, var(--aloha-ground));
}

.islands__swell {
	fill: none;
	stroke: var(--aloha-ink);
	stroke-width: 2;
	stroke-linecap: round;
	opacity: .3;
}

.islands__shallows {
	fill: color-mix(in oklab, var(--aloha-teal) 10%, var(--aloha-ground));
}

.islands__sand {
	fill: color-mix(in oklab, var(--aloha-gold) 38%, var(--aloha-ground));
	stroke: var(--aloha-ink);
	stroke-width: 2;
	stroke-linejoin: round;
}

.islands__sand--home {
	fill: color-mix(in oklab, var(--aloha-gold) 60%, var(--aloha-ground));
}

/* the routes: dashes travelling from each island towards home */
.islands__route {
	fill: none;
	stroke: var(--aloha-ink);
	stroke-width: 2.2;
	stroke-linecap: round;
	stroke-dasharray: 1 9;
	opacity: .75;
	animation: islands-travel 1.6s linear infinite;
}

.islands__canoe {
	fill: var(--aloha-coral);
	stroke: var(--aloha-ink);
	stroke-width: 1.6;
	stroke-linejoin: round;
	animation: islands-bob 3.6s ease-in-out infinite;
	transform-box: fill-box;
	transform-origin: center;
}

.islands__trunk,
.islands__frond {
	fill: none;
	stroke-linecap: round;
}

.islands__trunk {
	stroke: var(--aloha-ink);
	stroke-width: 2.4;
}

.islands__frond {
	stroke: color-mix(in oklab, var(--aloha-teal) 80%, var(--color-main-text));
	stroke-width: 2.6;
}

.islands__you {
	fill: var(--aloha-coral);
	stroke: var(--aloha-ground);
	stroke-width: 2;
}

.islands__label,
.islands__count {
	text-anchor: middle;
	font-family: var(--font-face);
}

.islands__label {
	font-size: 13px;
	font-weight: 600;
	fill: var(--color-main-text);
	paint-order: stroke;
	stroke: color-mix(in oklab, var(--aloha-teal) 22%, var(--aloha-ground));
	stroke-width: 4px;
	stroke-linejoin: round;
}

.islands__label--home {
	font-size: 14px;
}

.islands__count {
	font-size: 11px;
	fill: var(--color-text-maxcontrast);
}

.islands__summary {
	margin-block-start: calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
}

@keyframes islands-travel {
	to { stroke-dashoffset: -20; }
}

@keyframes islands-bob {
	0%, 100% { transform: translateY(0) rotate(0); }
	50% { transform: translateY(2px) rotate(3deg); }
}

@media (prefers-reduced-motion: reduce) {
	.islands__route,
	.islands__canoe {
		animation: none;
	}
}
</style>
