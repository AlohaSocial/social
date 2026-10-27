<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		ref="root"
		class="video-browse"
		:style="{ '--video-columns': columns }"
		@keydown="onKeydown">
		<div class="video-browse__bar">
			<div class="video-browse__chips" role="group" :aria-label="t('social', 'Show only')">
				<button
					v-for="chip in chips"
					:key="chip.value"
					type="button"
					class="video-browse__chip"
					:class="{ 'video-browse__chip--active': chip.value === chosen }"
					:aria-pressed="chip.value === chosen ? 'true' : 'false'"
					@click="choose(chip.value)">
					{{ chipLabel(chip) }}
				</button>
			</div>
			<MediaUploadButton v-if="signedIn" />
		</div>

		<template v-for="section in sections" :key="section.key">
			<ul
				v-if="section.kind === 'cards'"
				class="video-browse__grid"
				:aria-label="section.start === 0 ? t('social', 'Videos') : undefined">
				<li v-for="(entry, offset) in section.items" :key="entry.id" class="video-browse__cell">
					<VideoCard :entry="entry" :data-card-index="section.start + offset" />
				</li>
			</ul>
			<section
				v-else
				class="video-browse__shelf"
				:class="{ 'video-browse__shelf--short': section.shelf.upright }"
				:aria-labelledby="'video-shelf-' + section.shelf.id">
				<h2 :id="'video-shelf-' + section.shelf.id" class="video-browse__shelf-title">
					{{ section.shelf.title }}
				</h2>
				<p v-if="section.shelf.state === 'loading'" class="video-browse__shelf-note">
					{{ t('social', 'Loading…') }}
				</p>
				<p v-else-if="section.shelf.state === 'empty'" class="video-browse__shelf-note">
					{{ t('social', 'Videos you start watching show up here so you can pick them up again.') }}
				</p>
				<div v-else-if="section.shelf.state === 'failed'" class="video-browse__shelf-note">
					<span>{{ t('social', 'Could not read what you were watching.') }}</span>
					<NcButton variant="tertiary" @click="loadContinuing">
						{{ t('social', 'Retry') }}
					</NcButton>
				</div>
				<ul v-else class="video-browse__shelf-row">
					<li v-for="entry in section.shelf.items" :key="entry.id" class="video-browse__shelf-item">
						<VideoCard
							:entry="entry"
							:upright="section.shelf.upright"
							:to="section.shelf.routeOf ? section.shelf.routeOf(entry) : null" />
					</li>
				</ul>
			</section>
		</template>

		<ul v-if="loading && posts.length === 0" class="video-browse__grid" aria-hidden="true">
			<li v-for="index in columns * 2" :key="index" class="video-browse__cell video-browse__bone">
				<span class="video-browse__bone-thumb" />
				<span class="video-browse__bone-line" />
				<span class="video-browse__bone-line video-browse__bone-line--short" />
			</li>
		</ul>

		<p v-if="filteredOut" class="video-browse__none">
			{{ t('social', 'No videos of this kind in what has been loaded.') }}
		</p>
		<NcButton
			v-if="offerMore"
			class="video-browse__more"
			:disabled="loading"
			@click="askForMore">
			{{ t('social', 'Look further') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { mapStores } from 'pinia'
import VideoCard from './VideoCard.vue'
import MediaUploadButton from './MediaUploadButton.vue'
import { loadUnfinished } from '../services/videoProgress.js'
import { useSettingsStore } from '../store/settings.js'
import { useTimelineStore } from '../store/timeline.js'
import { chipsOf, filterByChip, videoPostOf } from '../utils/videoCard.js'

/** Below this many cards, a chip asks for another page by itself. */
export const FILL_TARGET = 12

/** How many pages a chip asks for by itself before it waits to be asked. */
export const MAX_FILL_PAGES = 5

/**
 * @typedef {object} Shelf
 * @property {string} id - `continue`, `shorts` or `followed`
 * @property {string} title - its heading
 * @property {import('../types/Mastodon.js').Status[]} items - what is on it
 * @property {boolean} upright - drawn as Shorts are
 * @property {'loading'|'empty'|'failed'|'ready'|'hidden'} [state] - what Continue watching is doing, when it has nothing to draw yet
 * @property {(entry: import('../types/Mastodon.js').Status) => object} [routeOf] - where a card leads, when not to the video's own page
 */

/** How many posts a shelf holds. */
export const SHELF_SIZE = 12

/** After how many rows of cards each shelf stands, when it has anything. */
const SHELF_AFTER_ROWS = { continue: 1, shorts: 2, followed: 4 }

/**
 * @param {number} width the page's width, in pixels
 * @return {number} how many cards fit in a row: one on a phone, two on a tablet, three or four on a desk
 */
export function columnsFor(width) {
	if (width <= 0) {
		return 4
	}
	if (width < 560) {
		return 1
	}
	if (width < 880) {
		return 2
	}

	return width < 1180 ? 3 : 4
}

/**
 * The Videos page drawn as a video site's front page: large cards in rows,
 * chips over them to narrow what is shown, and shelves between the rows —
 * Continue watching, Shorts, and From channels you follow.
 *
 * The timeline itself stays `TimelineList`'s: paging, new posts arriving, the
 * pill, the errors and the empty state are all there. This only draws it, and
 * says when it needs another page (`more`).
 *
 * The chips choose among the videos the page has loaded, and the categories
 * on offer are the ones those videos have. A chip that leaves the grid short
 * asks for further pages, up to `MAX_FILL_PAGES` of them, and then offers to
 * look further rather than paging through the whole timeline on its own.
 *
 * The shelves are each asked for once per page view, beside the timeline
 * rather than in it, and are only drawn when they hold something.
 */
export default {
	name: 'VideoBrowse',

	components: {
		NcButton,
		VideoCard,
		MediaUploadButton,
	},

	props: {
		/** The timeline, as `TimelineList` holds it. */
		posts: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status[]>} */ (Array),
			default: () => [],
		},

		loading: {
			type: Boolean,
			default: false,
		},

		/** Whether the timeline has no further page. */
		allLoaded: {
			type: Boolean,
			default: false,
		},

		/** Whether the last page failed: then nothing is asked for by itself. */
		failed: {
			type: Boolean,
			default: false,
		},
	},

	/** `more` asks the list for its next page. */
	emits: ['more'],

	data() {
		return {
			/** the chip chosen, by its value */
			chosen: 'all',
			/** how many pages the chosen chip has asked for by itself */
			filled: 0,
			/** a page has been asked for and has not arrived yet */
			asking: false,
			/** cards in a row, from the page's width */
			columns: 4,
			/** @type {ResizeObserver|null} */
			resizer: null,
			/** @type {import('../types/Mastodon.js').Status[]} */
			shorts: [],
			/** @type {import('../types/Mastodon.js').Status[]} */
			followed: [],
			/** @type {import('../types/Mastodon.js').Status[]} the videos the reader is in the middle of */
			continuing: [],
			/** whether the list the reader is in the middle of is still on its way */
			continuingLoading: true,
			/** whether reading that list failed, so that it can be asked again */
			continuingFailed: false,
			/** which scope the shelves were asked for, so they are asked once */
			shelvesFor: '',
		}
	},

	computed: {
		...mapStores(useSettingsStore, useTimelineStore),

		/** @return {boolean} */
		signedIn() {
			return !this.settingsStore.getServerData.public
		},

		/** @return {string} which circle of people the page is showing */
		scope() {
			return String(this.timelineStore.params?.scope ?? 'home')
		},

		chips() {
			return chipsOf(this.posts)
		},

		/** @return {import('../types/Mastodon.js').Status[]} the cards the chosen chip keeps */
		shown() {
			return filterByChip(this.posts, this.chosen)
		},

		/** @return {boolean} a chip left nothing, and there is nothing more to look through */
		filteredOut() {
			return this.chosen !== 'all' && this.shown.length === 0 && this.posts.length > 0 && !this.loading && !this.offerMore
		},

		/** @return {boolean} a chip has asked for all the pages it may by itself */
		offerMore() {
			return this.chosen !== 'all' && !this.allLoaded && !this.failed && this.filled >= MAX_FILL_PAGES && this.shown.length < FILL_TARGET
		},

		/** @return {'loading'|'empty'|'failed'|'ready'|'hidden'} what Continue watching has to say */
		continueState() {
			if (!this.signedIn) {
				return 'hidden'
			}
			if (this.continuingLoading) {
				return 'loading'
			}
			if (this.continuingFailed) {
				return 'failed'
			}

			return this.continuing.length > 0 ? 'ready' : 'empty'
		},

		/** @return {Shelf[]} the shelves with something to say, in the order they stand */
		shelves() {
			const scope = this.scope
			const shelves = [
				{
					id: 'continue',
					title: t('social', 'Continue watching'),
					items: this.continuing,
					upright: false,
					state: this.continueState,
				},
				{
					id: 'shorts',
					title: t('social', 'Shorts'),
					items: this.shorts,
					upright: true,
					// the Shorts player at that short, in the same circle of people
					routeOf: (entry) => ({ name: 'shorts', query: { scope, start: String(videoPostOf(entry).id) } }),
				},
				{ id: 'followed', title: t('social', 'From channels you follow'), items: this.followed, upright: false },
			]

			return shelves.filter((shelf) => shelf.state === undefined
				? shelf.items.length > 0
				: shelf.state !== 'hidden')
		},

		/**
		 * The grid cut into runs of whole rows, with a shelf after each run.
		 * Not one grid with the shelves inside it: a shelf in the middle of a
		 * grid either leaves a hole in the row before it or pulls a later
		 * card up into the hole, out of the order the keyboard and a screen
		 * reader go through them in. A chip shows only cards.
		 *
		 * @return {Array<{key: string, kind: string, items?: import('../types/Mastodon.js').Status[], start?: number, shelf?: Shelf}>}
		 */
		sections() {
			const shelves = this.chosen === 'all' ? this.shelves : []
			const sections = []
			let start = 0
			for (const shelf of shelves) {
				// a shelf with nothing on it yet says its piece without cutting
				// the grid of cards short before it has drawn a row
				const rows = shelf.state !== undefined && shelf.state !== 'ready' ? 0 : SHELF_AFTER_ROWS[shelf.id]
				const end = Math.min(this.shown.length, Math.max(start, rows * this.columns))
				if (end > start) {
					sections.push({ key: 'cards:' + start, kind: 'cards', items: this.shown.slice(start, end), start })
					start = end
				}
				sections.push({ key: 'shelf:' + shelf.id, kind: 'shelf', shelf })
			}
			if (start < this.shown.length) {
				sections.push({ key: 'cards:' + start, kind: 'cards', items: this.shown.slice(start), start })
			}

			return sections
		},
	},

	watch: {
		// a chip whose videos have gone, with a new scope say, is no chip
		chips(chips) {
			if (!chips.some((chip) => chip.value === this.chosen)) {
				this.choose('all')
			}
		},

		scope: {
			immediate: true,
			handler() {
				this.loadShelves()
			},
		},

		shown() {
			this.fill()
		},

		loading(now) {
			if (!now) {
				this.asking = false
				this.fill()
			}
		},
	},

	mounted() {
		this.measure()
		this.loadContinuing()
		if (typeof ResizeObserver !== 'undefined' && this.$refs.root) {
			this.resizer = new ResizeObserver(() => this.measure())
			this.resizer.observe(/** @type {Element} */ (this.$refs.root))
		}
	},

	beforeUnmount() {
		this.resizer?.disconnect()
	},

	methods: {
		t,

		/**
		 * @param {{value: string, kind: string, label: string}} chip one of `chips`
		 * @return {string} what it says
		 */
		chipLabel(chip) {
			switch (chip.kind) {
				case 'all':
					return t('social', 'All')
				case 'live':
					return t('social', 'Live')
				case 'recent':
					return t('social', 'Recently uploaded')
				default:
					return chip.label
			}
		},

		/** @param {string} value the chip to show */
		choose(value) {
			this.chosen = value
			this.filled = 0
			this.fill()
		},

		/**
		 * Asks for another page while a chip leaves the grid short, up to
		 * `MAX_FILL_PAGES` of them; past that the reader is asked.
		 */
		fill() {
			if (this.chosen === 'all' || this.asking || this.loading || this.allLoaded || this.failed) {
				return
			}
			if (this.shown.length >= FILL_TARGET || this.filled >= MAX_FILL_PAGES) {
				return
			}

			this.filled += 1
			this.asking = true
			this.$emit('more')
		},

		/** The reader asked to look further: another round of pages. */
		askForMore() {
			this.filled = 0
			this.asking = false
			this.fill()
		},

		measure() {
			const width = /** @type {HTMLElement|undefined} */ (this.$refs.root)?.clientWidth ?? 0
			this.columns = columnsFor(width)
		},

		/**
		 * The shelves beside the grid, once for each scope the page is read
		 * at. From channels you follow is the grid itself on My Feed, and
		 * there is nobody followed without a session.
		 */
		async loadShelves() {
			const scope = this.scope
			if (this.shelvesFor === scope) {
				return
			}
			this.shelvesFor = scope
			this.shorts = []
			this.followed = []

			const wantsFollowed = this.signedIn && scope !== 'home'
			const [shorts, followed] = await Promise.all([
				this.timelineStore.fetchSideList({ type: 'shorts', params: { scope } }, SHELF_SIZE),
				wantsFollowed
					? this.timelineStore.fetchSideList({ type: 'videos', params: { scope: 'home' } }, SHELF_SIZE)
					: Promise.resolve([]),
			])
			// the reader may have moved on while the server was answering
			if (this.shelvesFor !== scope) {
				return
			}
			this.shorts = shorts
			this.followed = followed.filter((entry) => videoPostOf(entry)?.video_card)
		},

		/**
		 * What the reader is in the middle of, once per page view: the same
		 * whichever circle of people the page is showing. Nobody without a
		 * session has watched anything here.
		 */
		async loadContinuing() {
			if (!this.signedIn) {
				return
			}

			this.continuingLoading = true
			this.continuingFailed = false
			try {
				const statuses = await loadUnfinished(SHELF_SIZE)
				this.continuing = statuses.filter((entry) => videoPostOf(entry)?.video_card)
			} catch {
				this.continuing = []
				this.continuingFailed = true
			} finally {
				this.continuingLoading = false
			}
		},

		/**
		 * The arrow keys move between the cards of the grid: a column to the
		 * side, a row up or down, Home and End to the first and last. Left
		 * and right follow the reading direction, so they swap in a
		 * right-to-left language.
		 *
		 * @param {KeyboardEvent} event the key
		 */
		onKeydown(event) {
			const card = /** @type {HTMLElement|null} */ (/** @type {HTMLElement} */ (event.target)?.closest?.('[data-card-index]'))
			if (card === null || event.altKey || event.ctrlKey || event.metaKey) {
				return
			}

			const rtl = getComputedStyle(/** @type {Element} */ (this.$refs.root)).direction === 'rtl'
			const steps = {
				ArrowRight: rtl ? -1 : 1,
				ArrowLeft: rtl ? 1 : -1,
				ArrowDown: this.columns,
				ArrowUp: -this.columns,
			}
			const index = Number(card.dataset.cardIndex)
			let next
			if (event.key === 'Home') {
				next = 0
			} else if (event.key === 'End') {
				next = this.shown.length - 1
			} else if (steps[event.key] !== undefined) {
				next = index + steps[event.key]
			} else {
				return
			}
			if (next < 0 || next >= this.shown.length) {
				return
			}

			event.preventDefault()
			const target = /** @type {HTMLElement|null} */ (/** @type {HTMLElement} */ (this.$refs.root).querySelector(`[data-card-index="${next}"] .video-card__link`))
			target?.focus()
			target?.scrollIntoView?.({ block: 'nearest' })
		},
	},
}
</script>

<style scoped lang="scss">
.video-browse {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 6);
	padding-block-end: calc(var(--default-grid-baseline) * 4);

	&__bar {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__chips {
		display: flex;
		flex: 1 1 auto;
		gap: calc(var(--default-grid-baseline) * 3);
		min-inline-size: 0;
		overflow-x: auto;
		scrollbar-width: none;
	}

	&__chip {
		flex: 0 0 auto;
		min-block-size: var(--default-clickable-area);
		margin: 0;
		padding: 0 calc(var(--default-grid-baseline) * 3);
		border: none;
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-background-dark);
		color: var(--color-main-text);
		font-weight: 600;
		white-space: nowrap;
		cursor: pointer;

		&:hover {
			background: var(--color-background-darker);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__chip--active,
	&__chip--active:hover {
		background: var(--color-main-text);
		color: var(--color-main-background);
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(var(--video-columns, 4), minmax(0, 1fr));
		gap: calc(var(--default-grid-baseline) * 10) calc(var(--default-grid-baseline) * 4);
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__shelf {
		padding-block: calc(var(--default-grid-baseline) * 2);
		border-block: 1px solid var(--color-border);
	}

	&__shelf-title {
		margin: 0 0 calc(var(--default-grid-baseline) * 3);
		font-size: 20px;
		font-weight: 700;
	}

	&__shelf-note {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__shelf-row {
		display: grid;
		grid-auto-columns: minmax(240px, calc((100% - 3 * var(--default-grid-baseline) * 4) / var(--video-columns, 4)));
		grid-auto-flow: column;
		gap: calc(var(--default-grid-baseline) * 4);
		margin: 0;
		padding: 0 0 calc(var(--default-grid-baseline) * 2);
		overflow-x: auto;
		list-style: none;
		scroll-snap-type: x mandatory;
	}

	&__shelf--short &__shelf-row {
		grid-auto-columns: minmax(140px, 180px);
	}

	&__shelf-item {
		scroll-snap-align: start;
	}

	&__bone-thumb {
		display: block;
		aspect-ratio: 16 / 9;
		border-radius: var(--border-radius-large);
		background: var(--color-background-dark);
	}

	&__bone-line {
		display: block;
		block-size: 12px;
		margin-block-start: calc(var(--default-grid-baseline) * 3);
		border-radius: 4px;
		background: var(--color-background-dark);
	}

	&__bone-line--short {
		inline-size: 60%;
	}

	&__none {
		color: var(--color-text-maxcontrast);
		text-align: center;
	}

	&__more {
		align-self: center;
	}
}

@media (prefers-reduced-motion: reduce) {
	.video-browse__shelf-row {
		scroll-snap-type: none;
	}
}
</style>
