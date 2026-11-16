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

		<!--
			Searching replaces the list rather than filtering it. What is on
			screen otherwise is a ranking of one window on one server, and
			narrowing that to a word answers a question nobody asked: the
			reader who typed wants the tag wherever it is, which is what the
			servers are asked for.
		-->
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
			<!--
			The list answers "what is busy here", so which stretch of time it
			means is part of the answer rather than a setting: the server ranks
			by the window it is asked for, so an hour and ten days are two
			different lists, not two labels on one.
		-->
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
							<!--
							How busy this tag is against the busiest one on the
							list, which is the only comparison the numbers here
							support. Decoration: the count above it is the fact.
						-->
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

			<!--
			What other servers are busy with, and this one is not. A small
			instance's own trending list is a list of what the few people on it
			posted today, and on a new one it is empty; this is the same
			question asked of servers that have an answer to it.
		-->
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

/**
 * The shortest query worth sending to five servers. One letter matches
 * everything and tells the reader nothing.
 */
const MIN_LENGTH = 2

/** The most followed tags the server answers in one page. */
const FOLLOWED_PAGE_SIZE = 50

/**
 * How many pages of followed tags are read before giving up on knowing all of
 * them. Past this many follows, twenty lookups — one per button — is the
 * cheaper way to draw the page.
 */
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
			 * The read of `followed`, which the rows wait for: a button drawn
			 * before it is settled asks about its own tag, and the list would
			 * then have saved nothing.
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
			/**
			 * Which question the peers are being asked. The text alone is not
			 * enough to tell two requests apart — the reader may type back to
			 * what they had — and the failure path has to know as surely as
			 * the answer path does.
			 */
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
		 * What other servers are busy with and this one is not.
		 *
		 * A tag already ranked in the list above is not repeated here: it is
		 * the same tag, and showing it twice would make the second list look
		 * like a second opinion rather than a different question.
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
		 * An empty list means nothing was posted in *this* window, which is a
		 * different thing from an instance where nobody uses hashtags at all.
		 *
		 * @return {string}
		 */
		emptyDescription() {
			// "No trending hashtags" above a list of twenty of them reads as a
			// broken page. On the instances this feature exists for -- small
			// ones, new ones -- that is the normal case, so the empty state
			// points at what is underneath it instead of at a longer window.
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

			// back to nothing typed: the trend is on screen again, and what is
			// busy elsewhere belongs under it
			this.timer = setTimeout(
				() => this.ask(query.length >= MIN_LENGTH ? query : ''),
				DEBOUNCE,
			)
		},

		/**
		 * Asks the other servers, either what is trending there or about one
		 * tag. One request either way: the server fans out, not this.
		 *
		 * A failure here is quiet. The trending list above it is this
		 * instance's own and is already on screen, and an error card about
		 * strangers' servers on top of a page that is working would be this
		 * app apologising for somebody else.
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
				// only where this is still the question being asked: a request
				// that failed after the reader typed on would otherwise clear
				// the results of the one that succeeded
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
				// another window was chosen while this was in flight, and this
				// answer is a ranking of the one that is gone
				if (period !== this.period) {
					return
				}

				this.tags = Array.isArray(data) ? data : []
				this.error = null
			} catch (error) {
				logger.error('Could not load the trending hashtags', { error, period })
				// the same guard the answer above gets: a window that failed
				// after the reader moved to another one would put its error
				// over a ranking that had already arrived and is correct
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
		 * Which of these the reader already follows, in a few requests rather
		 * than one per tag: twenty rows on this page would otherwise be twenty
		 * lookups before anything could be drawn.
		 *
		 * The buttons take this list as the whole truth — a tag not in it is
		 * drawn as not followed — so it is handed to them only once the server
		 * has said there is no further page. A reader who follows more than
		 * `FOLLOWED_MAX_PAGES` pages of tags gets no list at all, and
		 * each button asks about its own tag instead.
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

			// a floor, so the quietest tag on the list is still a bar rather
			// than an empty track that reads as "no data"
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

/* the top three carry the accent, which is what makes a ranking readable at a
   glance rather than a list that happens to be in an order */
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
