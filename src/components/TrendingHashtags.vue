<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="trending">
		<NcTextField
			v-model="query"
			class="trending__find"
			type="search"
			:label="t('social', 'Find a hashtag')"
			:placeholder="t('social', 'A word, without the #')"
			trailingButtonIcon="close"
			:showTrailingButton="query !== ''"
			@trailingButtonClick="query = ''">
			<template #icon>
				<Pound :size="20" />
			</template>
		</NcTextField>

		<!-- a search asks other servers for the tag rather than filtering this server's ranking -->
		<template v-if="searching">
			<NcLoadingIcon v-if="finding" class="trending__loading" :size="32" />

			<template v-else-if="found.length > 0">
				<PeerTagRows :tags="found" :followed="followed" @changed="onFollowChanged" />
				<p v-if="quiet.length" class="trending__quiet">
					{{ quietNotice }}
				</p>
			</template>

			<NcEmptyContent
				v-else
				:name="t('social', 'Nobody is using that')"
				:description="t('social', 'Neither this server nor the ones it asks have seen that hashtag lately.')">
				<template #icon>
					<Pound />
				</template>
			</NcEmptyContent>
		</template>

		<template v-else>
			<!-- each window is a separate ranking from the server -->
			<div class="trending__periods" role="tablist" :aria-label="t('social', 'Over what time')">
				<NcButton
					v-for="option in periods"
					:key="option.id"
					role="tab"
					:aria-selected="String(option.id === period)"
					:variant="option.id === period ? 'secondary' : 'tertiary'"
					@click="choose(option.id)">
					{{ option.name }}
				</NcButton>
			</div>

			<div v-if="error" class="trending__error" role="alert">
				<p>{{ error }}</p>
				<NcButton variant="primary" :disabled="loading" @click="load(true)">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-else-if="loading && tags.length === 0" class="trending__loading" :size="32" />

			<ol v-else-if="tags.length > 0" class="trending__list" :class="{ 'trending__list--stale': loading }">
				<li v-for="(tag, index) in tags" :key="tag.name" class="trending__row">
					<router-link class="trending__tag" :to="{ name: 'tags', params: { tag: tag.name } }">
						<span class="trending__rank" aria-hidden="true">{{ index + 1 }}</span>
						<span class="trending__body">
							<span class="trending__name">#{{ tag.name }}</span>
							<span class="trending__count">{{ countText(tag) }}</span>
							<!-- usage relative to the busiest tag on the list; decoration only -->
							<span class="trending__bar" aria-hidden="true">
								<span class="trending__bar-fill" :style="{ width: share(tag) }" />
							</span>
						</span>
					</router-link>
					<HashtagFollowButton
						class="trending__follow"
						:tag="tag.name"
						:known="followed"
						@changed="onFollowChanged" />
				</li>
			</ol>

			<NcEmptyContent
				v-else
				:name="elsewhere.length > 0
					? t('social', 'Nothing is trending on this server')
					: t('social', 'No trending hashtags')"
				:description="emptyDescription">
				<template #icon>
					<Pound />
				</template>
			</NcEmptyContent>

			<!-- what other servers are busy with, for small or new instances whose own list is thin -->
			<section v-if="elsewhere.length > 0" class="trending__elsewhere">
				<h2 class="trending__heading">
					{{ t('social', 'Busy elsewhere in the fediverse') }}
				</h2>
				<p class="trending__hint">
					{{ t('social', 'What other servers are talking about. Following one of these brings its posts into your timeline from the servers this one federates with.') }}
				</p>
				<PeerTagRows :tags="elsewhere" :followed="followed" @changed="onFollowChanged" />
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Pound from 'vue-material-design-icons/Pound.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import HashtagFollowButton from './HashtagFollowButton.vue'
import PeerTagRows from './PeerTagRows.vue'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'
import { nextCursor } from '../utils/linkHeader.js'

/** As many as the server will rank. */
const LIMIT = 20

/** How long the box waits after the last keystroke before it asks anybody. */
const DEBOUNCE = 400

/** The shortest query worth sending to other servers. */
const MIN_LENGTH = 2

/** The most followed tags the server answers in one page. */
const FOLLOWED_PAGE_SIZE = 50

/** Past this many pages of followed tags, each button looks up its own tag instead. */
const FOLLOWED_MAX_PAGES = 5

export default {
	name: 'TrendingHashtags',
	components: {
		HashtagFollowButton,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		PeerTagRows,
		Pound,
		Refresh,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			tags: [],
			/** the hashtags this reader follows, or null while that is unknown */
			followed: null,
			/**
			 * The read of `followed`, which the rows wait for so that the
			 * buttons do not each look up their own tag.
			 *
			 * @type {Promise<void>}
			 */
			followedRead: Promise.resolve(),
			period: '1d',
			loading: false,
			error: null,
			/** what the reader typed, which is not yet what was asked */
			query: '',
			/** what other servers said, as `PeerTag` entities */
			peers: [],
			/** what each asked server said about itself */
			reports: [],
			/** whether a fan-out is in flight */
			finding: false,
			/** a counter telling apart requests for the same text, so a stale failure does not clear newer results */
			asking: 0,
			timer: null,
		}
	},

	computed: {
		/** @return {object[]} the windows the server ranks by, newest first */
		periods() {
			return [
				{ id: '1h', name: t('social', 'Last hour') },
				{ id: '12h', name: t('social', 'Last 12 hours') },
				{ id: '1d', name: t('social', 'Today') },
				{ id: '3d', name: t('social', 'Last 3 days') },
				{ id: '10d', name: t('social', 'Last 10 days') },
			]
		},

		/** @return {number} how often the busiest tag on the list was used */
		busiest() {
			return this.tags.reduce((most, tag) => Math.max(most, this.uses(tag)), 0)
		},

		/** @return {boolean} whether the screen is showing a search rather than the trend */
		searching() {
			return this.query.trim().length >= MIN_LENGTH
		},

		/**
		 * What a search found: everything, wherever it is busy.
		 *
		 * @return {object[]}
		 */
		found() {
			return this.peers
		},

		/**
		 * What other servers are busy with, without the tags already ranked here.
		 *
		 * @return {object[]}
		 */
		elsewhere() {
			const here = new Set(this.tags.map((tag) => String(tag.name).toLowerCase()))

			return this.peers.filter((tag) => {
				const servers = Array.isArray(tag.servers) ? tag.servers : []

				return servers.length > (tag.local ? 1 : 0) && !here.has(tag.name)
			})
		},

		/** @return {string[]} the servers that were asked and did not answer */
		quiet() {
			return this.reports
				.filter((report) => report.status === 'failed')
				.map((report) => report.label || report.host)
		},

		/** @return {string} who did not answer, for the line under the results */
		quietNotice() {
			return n('social', '{names} did not answer. What is above is the rest.', '{names} did not answer. What is above is the rest.', this.quiet.length, { names: this.quiet.join(', ') })
		},

		/**
		 * An empty list means nothing was posted in this window, not that nobody
		 * here uses hashtags.
		 *
		 * @return {string}
		 */
		emptyDescription() {
			// point at the other servers' list below rather than at a longer window
			if (this.elsewhere.length > 0) {
				return t('social', 'Nobody here has used one in this stretch of time. What other servers are talking about is below.')
			}

			return this.period === '10d'
				? t('social', 'Hashtags people are using will appear here.')
				: t('social', 'Nothing was tagged in this stretch of time. Try a longer one.')
		},
	},

	watch: {
		query() {
			this.schedule()
		},
	},

	beforeMount() {
		this.followedRead = this.loadFollowed()
		this.load()
		this.ask()
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		t,
		n,

		choose(period) {
			if (period === this.period) {
				return
			}

			this.period = period
			this.load()
		},

		/** Waits for the typing to stop, so a word is one fan-out and not eight. */
		schedule() {
			clearTimeout(this.timer)
			const query = this.query.trim()

			// with nothing typed, ask what is trending elsewhere for under the list
			this.timer = setTimeout(
				() => this.ask(query.length >= MIN_LENGTH ? query : ''),
				DEBOUNCE,
			)
		},

		/**
		 * Asks the other servers, through this one, what is trending there or
		 * about one tag. A failure is quiet: this server's own list still works.
		 *
		 * @param {string} query what to look for, or '' for what is trending
		 */
		async ask(query = '') {
			this.finding = true
			this.asking += 1
			const generation = this.asking
			try {
				const { data } = await axios.get(
					generateUrl('apps/social/api/v1/directories/hashtags'),
					{ params: { q: query, limit: LIMIT } },
				)
				await this.followedRead

				// the reader has typed on since this was asked
				const wanted = this.query.trim()
				if (query !== (wanted.length >= MIN_LENGTH ? wanted : '')) {
					return
				}

				this.peers = Array.isArray(data?.tags) ? data.tags : []
				this.reports = Array.isArray(data?.sources) ? data.sources : []
			} catch (error) {
				logger.debug('Could not ask other servers about hashtags', { error })
				// a failure after the reader typed on must not clear newer results
				if (generation === this.asking) {
					this.peers = []
					this.reports = []
				}
			} finally {
				if (generation === this.asking) {
					this.finding = false
				}
			}
		},

		/** @param {boolean} retry whether this is the reader asking again after a failure */
		async load(retry = false) {
			const period = this.period
			this.loading = true
			if (retry) {
				this.error = null
			}

			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/trends/tags'), {
					params: { limit: LIMIT, period },
				})
				await this.followedRead
				// another window was chosen while this was in flight
				if (period !== this.period) {
					return
				}

				this.tags = Array.isArray(data) ? data : []
				this.error = null
			} catch (error) {
				logger.error('Could not load the trending hashtags', { error, period })
				// an outdated window's failure must not cover the current ranking
				if (period === this.period) {
					this.error = t('social', 'Could not load this. The server may be busy.')
				}
			} finally {
				if (period === this.period) {
					this.loading = false
				}
			}
		},

		/**
		 * Reads which tags the reader follows, so the buttons need no lookup each.
		 * The buttons take the list as complete, so it is set only after the last
		 * page; past `FOLLOWED_MAX_PAGES` it stays null and each button asks itself.
		 */
		async loadFollowed() {
			if (this.serverData.public) {
				return
			}

			try {
				const names = []
				let cursor = ''
				for (let page = 0; page < FOLLOWED_MAX_PAGES; page++) {
					const params = { limit: FOLLOWED_PAGE_SIZE }
					if (cursor) {
						params.max_id = cursor
					}
					const { data, headers } = await axios.get(generateUrl('apps/social/api/v1/followed_tags'), { params })
					names.push(...(Array.isArray(data) ? data : []).map((tag) => tag.name))
					cursor = nextCursor(headers)
					if (!cursor) {
						this.followed = names
						return
					}
				}
			} catch (error) {
				// the buttons ask for themselves when this is not known
				logger.debug('Could not read which hashtags are followed', { error })
			}
		},

		/**
		 * Keeps the answer this page holds in step with what the buttons did,
		 * so moving between windows does not undo a follow on screen.
		 *
		 * @param {object} change what the button did: `{ tag, following }`
		 */
		onFollowChanged(change) {
			if (this.followed === null || !change?.tag) {
				return
			}

			const without = this.followed.filter((name) => name !== change.tag)
			this.followed = change.following ? [...without, change.tag] : without
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {number} how often it was used in the window asked for
		 */
		uses(tag) {
			if (!Array.isArray(tag.history)) {
				return 0
			}

			return tag.history.reduce((total, day) => total + Number(day.uses ?? 0), 0)
		},

		/** @param {object} tag a Tag entity @return {string} its count in words */
		countText(tag) {
			return n('social', '%n post', '%n posts', this.uses(tag))
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {string} its share of the busiest tag's count, as a width
		 */
		share(tag) {
			if (this.busiest === 0) {
				return '0%'
			}

			// a floor, so the quietest tag still shows a bar
			return Math.max(6, Math.round((this.uses(tag) / this.busiest) * 100)) + '%'
		},
	},
}
</script>

<style scoped lang="scss">
.trending {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

/* the box is the page's first line, so it keeps the page's own width */
.trending__find {
	max-inline-size: 420px;
}

.trending__elsewhere {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-block-start: calc(var(--default-grid-baseline) * 2);
	border-block-start: 1px solid var(--color-border);
}

.trending__heading {
	font-size: 1.6em;
	font-weight: bold;
}

.trending__hint,
.trending__quiet {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.85em);
}

.trending__periods {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.trending__error {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 4);
}

.trending__loading {
	margin: 32px auto;
}

.trending__list {
	list-style: none;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: var(--default-grid-baseline);
	transition: opacity var(--social-motion-fast) var(--social-ease);
}

/* the previous window's ranking, while the next one is on its way */
.trending__list--stale {
	opacity: .55;
}

.trending__row {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding-inline-end: calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);

	&:hover,
	&:focus-within {
		background-color: var(--color-background-dark);
	}
}

.trending__tag {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
	flex: 1 1 auto;
	color: inherit;
}

.trending__rank {
	flex: 0 0 auto;
	width: 24px;
	text-align: center;
	font-weight: bold;
	font-size: 16px;
	color: var(--color-text-maxcontrast);
}

/* the top three carry the accent */
.trending__row:nth-child(-n + 3) .trending__rank {
	color: var(--color-primary-element);
}

.trending__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1 1 auto;
}

.trending__name {
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.trending__count {
	font-size: var(--font-size-small, 0.85em);
	color: var(--color-text-maxcontrast);
}

.trending__bar {
	display: block;
	height: 4px;
	margin-top: 2px;
	border-radius: 2px;
	background-color: var(--color-border);
	overflow: hidden;
}

.trending__bar-fill {
	display: block;
	height: 100%;
	border-radius: 2px;
	background-color: var(--color-primary-element);
	transition: width var(--social-motion-slow) var(--social-ease);
}

.trending__follow {
	flex: 0 0 auto;
}

@media (prefers-reduced-motion: reduce) {
	.trending__bar-fill,
	.trending__list {
		transition: none;
	}
}
</style>
