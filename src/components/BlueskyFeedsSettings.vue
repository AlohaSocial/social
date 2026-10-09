<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="bluesky-feeds">
		<form class="bluesky-feeds__add" @submit.prevent="add(typed)">
			<NcTextField
				v-model="typed"
				class="bluesky-feeds__address"
				:label="t('social', 'Address of a feed or list')"
				placeholder="https://bsky.app/profile/…/feed/…" />
			<NcButton type="submit" variant="primary" :disabled="typed.trim() === '' || busy">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<IconPlus v-else :size="20" />
				</template>
				{{ t('social', 'Add') }}
			</NcButton>
		</form>

		<p v-if="loading" class="bluesky-feeds__hint">
			{{ t('social', 'Loading your Bluesky feeds …') }}
		</p>
		<p v-else-if="feeds.length === 0" class="bluesky-feeds__hint">
			{{ t('social', 'You keep no Bluesky feeds yet. Paste the address of one from bsky.app, or pick one Bluesky suggests below.') }}
		</p>
		<ul v-else class="bluesky-feeds__list">
			<li v-for="feed in feeds" :key="feed.uri" class="bluesky-feeds__item">
				<IconFormatListBulleted v-if="feed.type === 'list'" :size="20" class="bluesky-feeds__icon" />
				<IconBluesky v-else :size="20" class="bluesky-feeds__icon" />
				<div class="bluesky-feeds__text">
					<router-link class="bluesky-feeds__name" :to="routeFor(feed.uri)">
						{{ feed.name }}
					</router-link>
					<span v-if="feed.creator" class="bluesky-feeds__creator">
						{{ t('social', 'by {creator}', { creator: feed.creator }) }}
					</span>
				</div>
				<NcButton
					variant="tertiary"
					:disabled="busy"
					:aria-label="t('social', 'Stop keeping {feed}', { feed: feed.name })"
					@click="forget(feed)">
					<template #icon>
						<IconClose :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>

		<template v-if="suggestionsToShow.length > 0">
			<h5 class="bluesky-feeds__caption">
				{{ t('social', 'Suggested by Bluesky') }}
			</h5>
			<ul class="bluesky-feeds__list">
				<li v-for="feed in suggestionsToShow" :key="feed.uri" class="bluesky-feeds__item">
					<IconBluesky :size="20" class="bluesky-feeds__icon" />
					<div class="bluesky-feeds__text">
						<span class="bluesky-feeds__name">{{ feed.name }}</span>
						<span v-if="feed.description" class="bluesky-feeds__creator">{{ feed.description }}</span>
					</div>
					<NcButton :disabled="busy" @click="add(feed.uri)">
						{{ t('social', 'Add') }}
					</NcButton>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconBluesky from 'vue-material-design-icons/ButterflyOutline.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconFormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import { translate as t } from '@nextcloud/l10n'
import eventBus, { BLUESKY_FEEDS_CHANGED } from '../services/eventBus.js'
import { fetchSavedFeeds, fetchSuggestedFeeds, forgetFeed, routeFor, saveFeed } from '../services/blueskyFeeds.js'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

/**
 * The Bluesky feeds and lists the reader keeps, a section of Settings: added
 * by their bsky.app address or from Bluesky's suggestions, and dropped. They
 * are the account's Bluesky preference, so a Bluesky app signed in to this
 * account shows the same ones. The sidebar lists them under Explore and
 * fetches them itself, so every change is announced on the event bus.
 */
export default {
	name: 'BlueskyFeedsSettings',

	components: {
		IconBluesky,
		IconClose,
		IconFormatListBulleted,
		IconPlus,
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			/** @type {object[]} the kept feeds and lists, pinned first */
			feeds: [],
			/** @type {object[]} what Bluesky suggests */
			suggestions: [],
			loading: true,
			typed: '',
			/** whether a request that changes the kept ones is in flight */
			busy: false,
		}
	},

	computed: {
		/** @return {object[]} the suggestions not kept already */
		suggestionsToShow() {
			const kept = new Set(this.feeds.map((feed) => feed.uri))

			return this.suggestions.filter((feed) => !kept.has(feed.uri))
		},
	},

	mounted() {
		this.fetch()
	},

	methods: {
		t,
		routeFor,

		async fetch() {
			this.loading = true
			try {
				const [feeds, suggestions] = await Promise.all([fetchSavedFeeds(), fetchSuggestedFeeds().catch(() => [])])
				this.feeds = feeds
				this.suggestions = suggestions
			} catch (error) {
				logger.error('Could not load the Bluesky feeds', { error })
				showError(t('social', 'Could not load your Bluesky feeds'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} feed an address or an `at://` URI
		 */
		async add(feed) {
			this.busy = true
			try {
				const kept = await saveFeed(feed.trim())
				if (!this.feeds.some((one) => one.uri === kept.uri)) {
					this.feeds = [kept, ...this.feeds]
				}
				this.typed = ''
				eventBus.emit(BLUESKY_FEEDS_CHANGED)
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not add the feed'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} feed one of the kept ones
		 */
		async forget(feed) {
			this.busy = true
			try {
				await forgetFeed(feed.uri)
				this.feeds = this.feeds.filter((one) => one.uri !== feed.uri)
				eventBus.emit(BLUESKY_FEEDS_CHANGED)
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not remove the feed'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.bluesky-feeds {
	&__add {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		margin-bottom: 16px;
		flex-wrap: wrap;
	}

	&__address {
		flex: 1 1 240px;
		max-width: 420px;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 4px 0;
	}

	&__caption {
		margin: 16px 0 4px;
		font-weight: bold;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__item {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 44px;
		padding: 6px 0;
		border-top: 1px solid var(--color-border);
	}

	&__icon {
		flex-shrink: 0;
		color: var(--color-text-maxcontrast);
	}

	&__text {
		display: flex;
		flex-direction: column;
		flex: 1 1 auto;
		min-width: 0;
	}

	&__name {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-weight: bold;
	}

	&__creator {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
}
</style>
