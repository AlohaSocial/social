<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- a plumeria that opens as the list is pulled down past its top on a
	     touch screen, and turns while the newest posts are asked for.
	     Decoration only: the list says what arrived itself -->
	<div
		class="pull-refresh"
		:class="{ 'pull-refresh--held': startY !== null, 'pull-refresh--ready': ready, 'pull-refresh--refreshing': refreshing }"
		:style="{ '--pull-height': `${height}px`, '--pull-turn': `${turn}deg` }"
		aria-hidden="true">
		<svg
			class="pull-refresh__flower"
			viewBox="-24 -24 48 48"
			width="28"
			height="28"
			focusable="false">
			<path
				v-for="angle in PETALS"
				:key="angle"
				class="pull-refresh__petal"
				:transform="`rotate(${angle})`"
				d="M0 0C-8-4-10-20-1-23C7-23 7-7 0 0Z" />
			<circle class="pull-refresh__heart" r="4" />
		</svg>
	</div>
</template>

<script>
import { scroller } from '../utils/scroller.js'

/** how far the list has to be pulled, in pixels on screen, to refresh */
export const PULL_THRESHOLD = 64

/** the furthest the flower comes down, however far the finger goes */
const MAX_PULL = 96

/** how much of the finger's travel the flower follows: a pull meets resistance */
const RESISTANCE = 0.5

/** how far up the finger may go before the gesture is a scroll, not a pull */
const SCROLL_SLOP = 8

/** how tall the flower's row stays while the refresh runs */
const REFRESHING_HEIGHT = 48

/** the plumeria's five petals, a fifth of a turn apart, as in AlohaScene */
const PETALS = [0, 72, 144, 216, 288]

/**
 * Pull to refresh, for a feed read on a touch screen.
 *
 * It listens to the content column, which is what scrolls (see
 * utils/scroller.js), and only ever passively: it never cancels a touch, so
 * scrolling is the browser's as it always was. A pull only starts from the
 * very top of the column, and a finger that moves up first is scrolling.
 */
export default {
	name: 'PullToRefresh',

	props: {
		/** whether the refresh the pull asked for is still running */
		refreshing: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['refresh'],

	data() {
		return {
			PETALS,
			/** where the finger came down, or null while there is no pull */
			startY: null,
			/** how far the flower has come down, in pixels */
			distance: 0,
			/** the column being listened to */
			column: null,
		}
	},

	computed: {
		/** @return {boolean} whether letting go now refreshes */
		ready() {
			return this.distance >= PULL_THRESHOLD
		},

		/** @return {number} how tall the flower's row is */
		height() {
			return this.refreshing ? Math.max(this.distance, REFRESHING_HEIGHT) : this.distance
		},

		/** @return {number} how far the flower has turned as it comes down */
		turn() {
			return Math.round(this.distance / PULL_THRESHOLD * 144)
		},
	},

	mounted() {
		this.column = scroller()
		this.column?.addEventListener('touchstart', this.onStart, { passive: true })
		this.column?.addEventListener('touchmove', this.onMove, { passive: true })
		this.column?.addEventListener('touchend', this.onEnd, { passive: true })
		this.column?.addEventListener('touchcancel', this.onCancel, { passive: true })
	},

	unmounted() {
		this.column?.removeEventListener('touchstart', this.onStart)
		this.column?.removeEventListener('touchmove', this.onMove)
		this.column?.removeEventListener('touchend', this.onEnd)
		this.column?.removeEventListener('touchcancel', this.onCancel)
	},

	methods: {
		/** @param {TouchEvent} event a finger coming down */
		onStart(event) {
			if (event.touches.length !== 1 || this.refreshing || this.column.scrollTop > 0) {
				this.onCancel()

				return
			}

			this.startY = event.touches[0].clientY
		},

		/** @param {TouchEvent} event the finger moving */
		onMove(event) {
			if (this.startY === null) {
				return
			}

			const travel = event.touches[0].clientY - this.startY
			if (travel < -SCROLL_SLOP || this.column.scrollTop > 0) {
				this.onCancel()

				return
			}

			this.distance = Math.min(Math.max(travel, 0) * RESISTANCE, MAX_PULL)
		},

		/** The finger let go: far enough, and the list is refreshed. */
		onEnd() {
			if (this.startY !== null && this.ready) {
				this.$emit('refresh')
			}
			this.onCancel()
		},

		onCancel() {
			this.startY = null
			this.distance = 0
		},
	},
}
</script>

<style scoped lang="scss">
.pull-refresh {
	display: flex;
	align-items: center;
	justify-content: center;
	block-size: var(--pull-height, 0);
	overflow: hidden;
	// springs back once let go; follows the finger exactly while held
	transition: block-size .2s ease;

	&--held {
		transition: none;
	}
}

.pull-refresh__flower {
	--aloha-coral: #e6604a;
	--aloha-gold: #f0b23c;
	transform: rotate(var(--pull-turn, 0deg)) scale(.85);
	transition: transform .2s ease;
}

.pull-refresh__petal {
	fill: color-mix(in oklab, #fffaf2 90%, var(--color-main-background));
	stroke: color-mix(in oklab, var(--color-main-text) 70%, #138a86);
	stroke-width: 2;
	stroke-linejoin: round;
}

.pull-refresh__heart {
	fill: var(--aloha-gold);
}

// far enough: the flower opens fully, so the reader knows letting go counts
.pull-refresh--ready .pull-refresh__flower {
	transform: rotate(var(--pull-turn, 0deg)) scale(1);
}

.pull-refresh--ready .pull-refresh__petal,
.pull-refresh--refreshing .pull-refresh__petal {
	fill: color-mix(in oklab, var(--aloha-coral) 30%, var(--color-main-background));
}

.pull-refresh--refreshing .pull-refresh__flower {
	animation: pull-refresh-turn 1.2s linear infinite;
}

@keyframes pull-refresh-turn {
	from { transform: rotate(0); }
	to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
	.pull-refresh,
	.pull-refresh__flower {
		transition: none;
	}

	// the flower stays open and still while the refresh runs
	.pull-refresh--refreshing .pull-refresh__flower {
		animation: none;
		transform: none;
	}
}

@media (forced-colors: active) {
	.pull-refresh__petal {
		fill: Canvas;
		stroke: CanvasText;
	}

	.pull-refresh__heart {
		fill: CanvasText;
	}
}
</style>
