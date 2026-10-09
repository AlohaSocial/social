<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="social__wrapper social__search"
		:class="{ 'social__search--refreshing': loading && !isEmpty }"
		:aria-busy="loading ? 'true' : 'false'">
		<!-- Reply used to emit `composer-reply` on the event bus with nothing
		     on this page listening, so it did nothing at all. Kept mounted the
		     way the single-post view keeps it, because the listener lives in
		     the Composer's mounted(). -->
		<Composer v-show="composerDisplayStatus" />
		<h1 class="social__search-heading">
			{{ t('social', 'Search results for “{term}”', { term: query }) }}
		</h1>

		<!-- only while there is nothing to show yet: a refined term replacing
		     results with the spinner and back was a flicker, and the results
		     that survive the new term should stay where they are -->
		<div v-if="loading && isEmpty" class="social__search-loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('social', 'Searching …') }}</span>
		</div>

		<div v-else-if="error !== null" class="social__search-error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="search">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else>
			<transition name="empty">
				<NcEmptyContent
					v-if="isEmpty"
					:name="t('social', 'No results found')"
					:description="t('social', 'Nothing on this server matches “{term}”. Searching for a full handle like @user@example.org can find somebody this server has not met yet.', { term: query })">
					<template #icon>
						<Magnify :size="20" />
					</template>
				</NcEmptyContent>
			</transition>

			<!--
			  Results arrive the way timeline entries do. Keyed on what the
			  server calls each result, never on the index, so refining a term
			  leaves the results both terms found exactly where they are and
			  only moves what actually changed.

			  `:css` keeps the last term's results quiet: while the request
			  for what is in the search box is still in flight, what is on
			  screen is swapped in without motion rather than being announced
			  as the answer.
			-->
			<section v-if="accounts.length > 0" class="social__search-section">
				<h2>{{ t('social', 'People') }}</h2>
				<transition-group
					name="result"
					tag="div"
					class="social__search-accounts"
					appear
					:css="resultsAreCurrent">
					<UserEntry v-for="account in accounts" :key="account.id" :item="account" />
				</transition-group>
			</section>

			<section v-if="hashtags.length > 0" class="social__search-section">
				<h2>{{ t('social', 'Hashtags') }}</h2>
				<transition-group
					name="result"
					tag="ul"
					class="social__search-tags"
					appear
					:css="resultsAreCurrent">
					<li v-for="tag in hashtags" :key="tag.name" class="tag">
						<router-link :to="{ name: 'tags', params: { tag: tag.name } }">
							<span>#{{ tag.name }}</span>
						</router-link>
					</li>
				</transition-group>
			</section>

			<section v-if="statuses.length > 0" class="social__search-section">
				<h2>{{ t('social', 'Posts') }}</h2>
				<transition-group
					name="result"
					tag="ul"
					class="social__search-statuses"
					appear
					:css="resultsAreCurrent">
					<TimelineEntry
						v-for="status in statuses"
						:key="status.id"
						:item="status"
						type="search" />
				</transition-group>
			</section>
		</template>
	</div>
</template>

<script>

import { defineAsyncComponent } from 'vue'
import UserEntry from './UserEntry.vue'
import TimelineEntry from './TimelineEntry.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import logger from '../services/logger.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'./Composer/Composer.vue'))

/** how long to wait for the typing to stop before asking the server */
const DEBOUNCE_MS = 300

/** How long after a search the posts found beyond this server are asked for again. */
export const SEARCH_REFILL_MS = 6000

export default {
	name: 'Search',
	components: {
		Composer,
		Magnify,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Refresh,
		TimelineEntry,
		UserEntry,
	},

	props: {
		term: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			accounts: [],
			/**
			 * Ids, not the statuses themselves. Held locally they were invisible
			 * to every mutation in store/timeline.js — each one is guarded by
			 * `state.statuses[id] !== undefined` — so liking, boosting,
			 * bookmarking or pinning a result sent its request and then changed
			 * nothing on screen, and deleting one left it in the list.
			 */
			statusIds: [],
			hashtags: [],
			loading: false,
			error: null,
			debounceTimer: null,
			/**
			 * Which request the results on screen belong to. Responses are not
			 * ordered — a webfinger lookup for "ali" can outlive the cached
			 * answer for "alice" — so anything that is not the newest request
			 * is dropped rather than rendered.
			 */
			requestSequence: 0,
			/**
			 * The term the results on screen actually answer, which is not
			 * always the term in the search box: see `resultsAreCurrent`.
			 *
			 * @type {?string}
			 */
			renderedTerm: null,
		}
	},

	computed: {
		...mapStores(useAccountStore, useTimelineStore),
		/** @return {string} the term, as somebody typed it rather than as a URL */
		query() {
			try {
				return decodeURIComponent(this.term)
			} catch {
				return this.term
			}
		},

		/**
		 * The found posts, read back out of the store so that acting on one
		 * shows. A status the store no longer holds — a deleted one — drops
		 * out of the list on its own.
		 *
		 * @return {object[]}
		 */
		statuses() {
			return this.statusIds
				.map((id) => this.timelineStore.getStatus(id))
				.filter(Boolean)
		},

		/** @return {boolean} whether the reply composer is open */
		composerDisplayStatus() {
			return this.timelineStore.getComposerDisplayStatus
		},

		/**
		 * Whether what is on screen answers what is in the search box.
		 *
		 * The results always answer *some* term the reader typed, but the
		 * request for the one they have finished typing may still be in
		 * flight: those older results are put on screen without any motion,
		 * so nothing announces them as the answer.
		 *
		 * @return {boolean}
		 */
		resultsAreCurrent() {
			return this.renderedTerm === this.query.trim()
		},

		/** @return {boolean} */
		isEmpty() {
			return this.accounts.length === 0 && this.statuses.length === 0 && this.hashtags.length === 0
		},
	},

	watch: {
		// typing re-enters the route on every keystroke; one request per word
		// is enough, and re-sorting the whole store per letter was the old cost
		term: 'searchDebounced',
	},

	beforeMount() {
		this.search()
	},

	unmounted() {
		if (this.debounceTimer !== null) {
			window.clearTimeout(this.debounceTimer)
		}
	},

	methods: {
		t: translate,
		searchDebounced() {
			if (this.debounceTimer !== null) {
				window.clearTimeout(this.debounceTimer)
			}
			this.debounceTimer = window.setTimeout(() => {
				this.debounceTimer = null
				this.search()
			}, DEBOUNCE_MS)
		},

		/**
		 * Asks the server, rather than filtering the handful of posts that
		 * happen to be loaded. `/api/v2/search` answers with accounts, posts
		 * and hashtags in one response.
		 */
		async search() {
			const term = this.query.trim()
			// whatever is still in flight answers a term that is no longer
			// the one being asked about
			const sequence = ++this.requestSequence

			if (term === '') {
				this.accounts = []
				this.statusIds = []
				this.hashtags = []
				this.error = null
				this.loading = false
				this.renderedTerm = term
				return
			}

			this.loading = true
			this.error = null
			try {
				const { data, headers } = await axios.get(generateUrl('apps/social/api/v2/search'), {
					params: { q: term, limit: 20 },
				})
				if (sequence !== this.requestSequence) {
					return
				}
				// the posts beyond this server that match are being read: the
				// same search once more, a little later, while it is still the one asked
				if (headers?.['x-social-filling'] === '1') {
					setTimeout(() => sequence === this.requestSequence && this.search(), SEARCH_REFILL_MS)
				}

				this.accounts = Array.isArray(data?.accounts) ? data.accounts : []
				this.hashtags = Array.isArray(data?.hashtags) ? data.hashtags : []

				// so the follow buttons beside the results know where they stand
				for (const account of this.accounts) {
					if (account?.url) {
						this.accountStore.addAccount({ actorId: account.url, data: account })
					}
				}

				// through the store, not local data: see `statusIds`
				const found = Array.isArray(data?.statuses) ? data.statuses : []
				for (const status of found) {
					this.timelineStore.addToStatuses(status)
				}
				this.statusIds = found.map((status) => status.id)
				// what is on screen from here on answers this term, whether or
				// not it is still the one being typed
				this.renderedTerm = term
			} catch (error) {
				logger.error('Failed to perform the search', { error })
				if (sequence === this.requestSequence) {
					this.error = translate('social', 'The search could not be run. Please try again.')
				}
			} finally {
				// the newer request owns `loading` now
				if (sequence === this.requestSequence) {
					this.loading = false
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
	.social__search {
		max-width: var(--social-column);
		margin: 0 auto;
		padding: calc(var(--default-grid-baseline) * 2);
	}

	.social__search-heading {
		font-size: 20px;
		font-weight: 700;
		margin: calc(var(--default-grid-baseline) * 3) 0;
		overflow-wrap: break-word;
	}

	.social__search-section {
		margin-bottom: calc(var(--default-grid-baseline) * 6);
		/* dimmed while a refined term is being searched: see --refreshing */
		transition: opacity .2s ease;

		h2 {
			font-size: 15px;
			font-weight: 700;
			color: var(--color-text-lighter);
			margin-bottom: calc(var(--default-grid-baseline) * 2);
		}
	}

	.social__search-loading,
	.social__search-error {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		padding: 32px 20px;
		color: var(--color-text-lighter);
		text-align: center;
	}

	.social__search-tags,
	.social__search-statuses {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	/**
	 * Results arrive the way timeline entries do: the same short fade and
	 * rise as the shared `list` transition in App.vue, in the direction
	 * `timeline-entry-rise` uses. Only the arrival is animated — a result
	 * that a refined term no longer matches goes without ceremony, so a
	 * replaced set never overlaps itself on the way out.
	 */
	.result-enter-active,
	.result-move {
		transition: opacity .2s ease, transform .2s ease;
	}

	.result-enter-from {
		opacity: 0;
		transform: translateY(6px);
	}

	/* a refined term is still running: the results on screen are the previous
	   answer, and say so rather than pretending to be the new one */
	.social__search--refreshing .social__search-section {
		opacity: .55;
	}

	.empty-enter-active {
		transition: opacity .2s ease, transform .2s ease;
	}

	.empty-enter-from,
	.empty-leave-to {
		opacity: 0;
		transform: translateY(-6px);
	}

	.empty-leave-active {
		transition: opacity .15s ease;
	}

	@media (prefers-reduced-motion: reduce) {
		.result-enter-active,
		.result-move,
		.social__search-section,
		.empty-enter-active,
		.empty-leave-active {
			transition: none;
		}
	}

	.tag {
		border-bottom: 1px solid var(--color-background-dark);

		a {
			display: flex;

			span {
				display: inline-block;
				padding: 12px;
				font-weight: 500;
				flex-grow: 1;
			}
		}
	}
</style>
