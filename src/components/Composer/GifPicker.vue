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
				:label="t('social', 'Search the picture library')"
				:showTrailingButton="false"
				@update:modelValue="onSearch" />
			<NcButton
				variant="tertiary"
				:title="t('social', 'Close the picture library')"
				:aria-label="t('social', 'Close the picture library')"
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
					<!-- `loading="lazy"`: the whole library is in this grid and
					     most of it is below the fold, and these are animations -->
					<!-- `fetchpriority="low"`: a cold picture holds one of the
					     browser's six connections to this host while the server
					     fetches it, and what must not be stuck behind them is
					     the reader's next search -->
					<img
						class="gif-picker__image"
						:src="gif.url"
						:alt="gif.title || gif.slug"
						loading="lazy"
						decoding="async"
						fetchpriority="low">
				</button>
			</li>
			<!-- the rest arrives when the reader reaches the bottom of the
			     grid rather than all at once: the library is 881 pictures and
			     drawing them all is 881 the server would go and fetch -->
			<li v-if="hasMore" class="gif-picker__more">
				<NcButton variant="tertiary" :disabled="loading" @click="loadMore">
					{{ loading ? t('social', 'Loading…') : t('social', 'Show more') }}
				</NcButton>
			</li>
		</ul>

		<!-- what the licence of the shipped emoji asks for, wherever they are
		     shown; the server says it, so regenerating the list cannot leave
		     the credit behind -->
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
 * How many to ask for at a time.
 *
 * Every picture in the grid is a request, and for one of the shipped emoji
 * this instance has not shown before it is a request the server answers by
 * fetching it from Google. A screenful at a time keeps that to what somebody
 * is actually looking at.
 *
 * Twenty-four rather than sixty, which is what a grid this tall shows without
 * scrolling. The number that matters is not how many are drawn but how many
 * cold pictures are in flight at once: a browser opens six connections to a
 * host over HTTP/1.1, so sixty of them queue four deep and the search that
 * followed them waited half a minute for a connection. Measured on a cold
 * instance: sixty took 33s, twenty-four a few.
 */
const PAGE = 24

/**
 * The instance's shared picture library, as a grid in the composer.
 *
 * The pictures are ones an administrator put there (the GIF library card of
 * the admin page, or `occ social:gif add`), so this is the same set for
 * everybody on the instance — which is what makes it the same joke in the
 * same team rather than one person's collection, and is the whole reason it
 * is not simply the Files picker that sits beside it.
 *
 * Choosing one does not download and re-upload it: the server copies the
 * library file into an attachment, through the same path an upload takes. See
 * `/api/v1/media/from-gif`.
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
				? t('social', 'There are no pictures on this instance.')
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
	/* as many as fit, each at least this wide; no media query decides how many
	   columns there are, because the composer is in a column whose width
	   depends on the window and on the sidebar */
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
