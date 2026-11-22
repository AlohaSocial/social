<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="gif-picker">
		<div class="gif-picker__head">
			<NcTextField
				:modelValue="term"
				class="gif-picker__search"
				:label="t('social', 'Search the GIF library')"
				:showTrailingButton="false"
				@update:modelValue="onSearch" />
			<NcButton
				variant="tertiary"
				:title="t('social', 'Close the GIF library')"
				:aria-label="t('social', 'Close the GIF library')"
				@click="$emit('close')">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>

		<p v-if="loading && gifs.length === 0" class="gif-picker__note">
			{{ t('social', 'Loading…') }}
		</p>
		<p v-else-if="gifs.length === 0" class="gif-picker__note">
			{{ emptyMessage }}
		</p>
		<ul v-else class="gif-picker__grid">
			<li v-for="gif in gifs" :key="gif.slug">
				<button
					type="button"
					class="gif-picker__item"
					:disabled="busy"
					:title="gif.title || gif.slug"
					:aria-label="t('social', 'Attach {name}', { name: gif.title || gif.slug })"
					@click="choose(gif)">
					<!-- lazy: most of the library is below the fold, and these are animations -->
					<!-- low priority, so cold pictures the server is still fetching do
					     not hold the connections the next search needs -->
					<img
						class="gif-picker__image"
						:src="gif.url"
						:alt="gif.title || gif.slug"
						loading="lazy"
						decoding="async"
						fetchpriority="low">
				</button>
			</li>
			<!-- loaded a screenful at a time: each picture drawn may be one the
			     server has to fetch -->
			<li v-if="hasMore" class="gif-picker__more">
				<NcButton variant="tertiary" :disabled="loading" @click="loadMore">
					{{ loading ? t('social', 'Loading…') : t('social', 'Show more') }}
				</NcButton>
			</li>
		</ul>

		<!-- the credit the shipped emoji's licence asks for; it comes from the
		     server so it stays with the list -->
		<p v-if="attribution !== '' && gifs.length > 0" class="gif-picker__credit">
			{{ attribution }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import logger from '../../services/logger.js'

/** how long the search waits after the last keystroke */
const DEBOUNCE = 250

/**
 * How many to ask for at a time. Each cold picture is one the server fetches
 * from Google, and the browser opens only six connections to a host, so a
 * larger page queues up and delays the next search.
 */
const PAGE = 24

/**
 * The instance's shared picture library, as a grid in the composer: the same
 * set for everybody, put there by an administrator (the admin page or
 * `occ social:gif`). Choosing one copies the library file into an attachment
 * on the server (`/api/v1/media/from-gif`).
 */
export default {
	name: 'GifPicker',

	components: {
		Close,
		NcButton,
		NcTextField,
	},

	emits: ['close', 'chosen'],

	data() {
		return {
			term: '',
			gifs: [],
			/** how many there are altogether, as the server counted them */
			total: 0,
			/** the credit the shipped pack is shown under */
			attribution: '',
			loading: true,
			/** true while an attachment is being made, so nothing is chosen twice */
			busy: false,
			/** the pending search, while the reader is still typing */
			debounce: null,
		}
	},

	computed: {
		/**
		 * An empty library and a search that found nothing are different
		 * things, and only one of them is something the reader can fix.
		 *
		 * @return {string} what to say
		 */
		emptyMessage() {
			return this.term === ''
				? t('social', 'There are no GIFs on this instance.')
				: t('social', 'Nothing here matches that.')
		},

		/** @return {boolean} whether the server has more than has been drawn */
		hasMore() {
			return this.gifs.length < this.total
		},
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		clearTimeout(this.debounce)
	},

	methods: {
		t,

		/**
		 * @param {string} term what has been typed so far
		 */
		onSearch(term) {
			this.term = term
			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => this.load(), DEBOUNCE)
		},

		/** Reads the first screenful of the library, or of what matches. */
		load() {
			return this.fetch(0)
		},

		/** Reads the next screenful, keeping what is already drawn. */
		loadMore() {
			return this.fetch(this.gifs.length)
		},

		/**
		 * @param {number} offset where to carry on from; 0 starts again
		 */
		async fetch(offset) {
			this.loading = true
			// which search this answer belongs to: a slow answer for a term
			// the reader has moved on from must not land in the grid
			const term = this.term
			try {
				const { data } = await axios.get(generateUrl('/apps/social/api/v1/gifs'), {
					params: { q: term, limit: PAGE, offset },
				})

				if (term !== this.term) {
					return
				}

				const page = Array.isArray(data?.gifs) ? data.gifs : []
				this.gifs = offset === 0 ? page : [...this.gifs, ...page]
				this.total = Number(data?.total) || this.gifs.length
				this.attribution = String(data?.attribution ?? '')
			} catch (error) {
				logger.error('Could not read the picture library', { error })
				if (offset === 0) {
					this.gifs = []
					this.total = 0
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {{slug: string, title: string}} gif the one that was pressed
		 */
		async choose(gif) {
			if (this.busy) {
				return
			}

			this.busy = true
			try {
				this.$emit('chosen', gif)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.gif-picker {
	margin: 6px 0;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-hover);
}

.gif-picker__head {
	display: flex;
	align-items: flex-end;
	gap: 4px;
}

.gif-picker__search {
	flex: 1 1 auto;
}

.gif-picker__note {
	margin: 8px 2px 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.gif-picker__grid {
	display: grid;
	/* as many columns as fit: the composer's width depends on the window and
	   the sidebar, so no media query decides */
	grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
	gap: 4px;
	/* a library of any size is scrolled inside the picker rather than pushing
	   the box the post is being written in off the screen */
	max-height: 260px;
	margin: 8px 0 0;
	padding: 0;
	overflow-y: auto;
	list-style: none;
}

/* the row the "Show more" button sits on, across whatever the grid's columns
   happen to be */
.gif-picker__more {
	grid-column: 1 / -1;
	display: flex;
	justify-content: center;
	padding: 4px 0;
}

.gif-picker__credit {
	margin: 6px 2px 0;
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}

.gif-picker__item {
	display: block;
	width: 100%;
	padding: 0;
	border: 1px solid transparent;
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-dark);
	cursor: pointer;

	&:hover:not(:disabled),
	&:focus-visible {
		border-color: var(--color-primary-element);
	}

	&:disabled {
		opacity: .6;
		cursor: default;
	}
}

.gif-picker__image {
	display: block;
	width: 100%;
	height: 90px;
	border-radius: var(--border-radius, 8px);
	object-fit: cover;
}
</style>
