<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		ref="frame"
		class="zoomable"
		:class="{ 'zoomable--zoomed': scale > 1, 'zoomable--grabbing': panning }"
		@pointerdown="onPointerDown"
		@pointermove="onPointerMove"
		@pointerup="onPointerUp"
		@pointercancel="onPointerUp"
		@dblclick.prevent="toggleZoom"
		@wheel.prevent="onWheel">
		<img
			class="zoomable__image"
			:src="src"
			:alt="alt"
			:style="imageStyle"
			draggable="false"
			@load="reset">
		<!-- the controls are here rather than left to the gesture: a pointer
		     that is not a finger has no pinch, and a zoom nobody can find is
		     not a feature -->
		<div class="zoomable__controls">
			<button
				type="button"
				class="zoomable__control"
				:disabled="scale <= MIN_SCALE"
				:aria-label="t('social', 'Zoom out')"
				@click.stop="zoomBy(1 / STEP)">
				<MagnifyMinusOutline :size="20" />
			</button>
			<span class="zoomable__level" aria-live="polite">{{ zoomLabel }}</span>
			<button
				type="button"
				class="zoomable__control"
				:disabled="scale >= MAX_SCALE"
				:aria-label="t('social', 'Zoom in')"
				@click.stop="zoomBy(STEP)">
				<MagnifyPlusOutline :size="20" />
			</button>
			<button
				v-if="scale > MIN_SCALE"
				type="button"
				class="zoomable__control"
				:aria-label="t('social', 'Reset zoom')"
				@click.stop="reset">
				<BackupRestore :size="20" />
			</button>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import BackupRestore from 'vue-material-design-icons/BackupRestore.vue'
import MagnifyMinusOutline from 'vue-material-design-icons/MagnifyMinusOutline.vue'
import MagnifyPlusOutline from 'vue-material-design-icons/MagnifyPlusOutline.vue'

/** the image at its own size; there is nothing below this */
const MIN_SCALE = 1

/** far enough in to read the small print on a screenshot */
const MAX_SCALE = 5

/** what one press of a zoom control, or one notch of a wheel, is worth */
const STEP = 1.4

/** what a double press zooms to, when the image is not already zoomed */
const DOUBLE_TAP_SCALE = 2.5

/**
 * How far a finger has to travel across an un-zoomed image before it counts as
 * a swipe to the next picture rather than a press that wandered.
 */
const SWIPE_THRESHOLD = 60

/**
 * One picture in the lightbox, which can be zoomed into and moved around.
 *
 * The viewer could already page between pictures, but only by pressing a
 * control: on a phone — where most of these are looked at — the gesture
 * everybody tries first is a swipe, and a picture that does not zoom is a
 * picture whose detail cannot be seen at all on a small screen.
 *
 * Gestures are pointer events rather than touch events, so a mouse drag pans
 * and a trackpad or wheel zooms by the same code that handles a finger. Pinch
 * needs two pointers and has no mouse equivalent, which is why the controls in
 * the corner exist: every gesture here has something to press instead.
 *
 * Paging is emitted, not done: which pictures there are and which one is
 * showing belongs to the viewer around this one.
 */
export default {
	name: 'ZoomableImage',

	components: {
		BackupRestore,
		MagnifyMinusOutline,
		MagnifyPlusOutline,
	},

	props: {
		/** the full-size image */
		src: {
			type: String,
			required: true,
		},

		/** its description, or '' for one whose author gave it none */
		alt: {
			type: String,
			default: '',
		},
	},

	emits: ['previous', 'next'],

	// Returned from setup() rather than data() so that none of it is made
	// reactive: it changes on every pointermove and nothing renders from it.
	setup() {
		return {
			/**
			 * The pointers currently down, by id.
			 *
			 * @type {Map<number, {x: number, y: number}>}
			 */
			pointers: new Map(),
			/** where the gesture started, for deciding a swipe from a press */
			gesture: null,
			/** the distance and scale a two-finger pinch started from */
			pinch: null,
		}
	},

	data() {
		return {
			scale: MIN_SCALE,
			offsetX: 0,
			offsetY: 0,
			/** whether a drag is moving the picture rather than the page */
			panning: false,
			MIN_SCALE,
			MAX_SCALE,
			STEP,
		}
	},

	computed: {
		/** @return {object} how the picture is currently placed */
		imageStyle() {
			return {
				transform: `translate(${this.offsetX}px, ${this.offsetY}px) scale(${this.scale})`,
			}
		},

		/** @return {string} the zoom, for the readout between the controls */
		zoomLabel() {
			return `${Math.round(this.scale * 100)}%`
		},
	},

	watch: {
		// a new picture is looked at from the beginning, not at whatever zoom
		// and corner the last one was left in
		src() {
			this.reset()
		},
	},

	methods: {
		t,

		/**
		 * The distance between the two pointers of a pinch.
		 *
		 * @return {number} in pixels, or 0 when there are not two
		 */
		pinchDistance() {
			const [a, b] = [...this.pointers.values()]
			if (a === undefined || b === undefined) {
				return 0
			}

			return Math.hypot(a.x - b.x, a.y - b.y)
		},

		/** @param {PointerEvent} event the pointer going down */
		onPointerDown(event) {
			this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

			if (this.pointers.size === 1) {
				this.gesture = {
					x: event.clientX,
					y: event.clientY,
					offsetX: this.offsetX,
					offsetY: this.offsetY,
					// a swipe is only a swipe if it was one from the start; a
					// drag that begins zoomed is a pan however far it goes
					swipeable: this.scale === MIN_SCALE,
				}
				this.panning = this.scale > MIN_SCALE
			} else if (this.pointers.size === 2) {
				// the second finger ends whatever the first was doing
				this.gesture = null
				this.panning = false
				this.pinch = { distance: this.pinchDistance(), scale: this.scale }
			}

			// keeps the moves coming once the pointer has left the picture,
			// which is where a pan towards the edge ends up. It throws for a
			// pointer that is already gone by the time this runs, and a
			// capture that could not be taken is not worth failing the gesture
			// over — the drag simply ends at the edge instead of following.
			try {
				/** @type {Element|null} */ (event.target)?.setPointerCapture?.(event.pointerId)
			} catch {
				// no capture; the gesture still works within the frame
			}
		},

		/** @param {PointerEvent} event the pointer that moved */
		onPointerMove(event) {
			if (!this.pointers.has(event.pointerId)) {
				return
			}

			this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

			if (this.pointers.size >= 2 && this.pinch) {
				const distance = this.pinchDistance()
				if (distance > 0 && this.pinch.distance > 0) {
					this.setScale(this.pinch.scale * (distance / this.pinch.distance))
				}
				return
			}

			if (this.gesture === null || this.scale === MIN_SCALE) {
				return
			}

			this.offsetX = this.gesture.offsetX + (event.clientX - this.gesture.x)
			this.offsetY = this.gesture.offsetY + (event.clientY - this.gesture.y)
			this.clamp()
		},

		/** @param {PointerEvent} event the pointer coming up */
		onPointerUp(event) {
			const start = this.gesture
			this.pointers.delete(event.pointerId)

			if (this.pointers.size < 2) {
				this.pinch = null
			}

			if (this.pointers.size > 0 || start === null) {
				this.panning = false
				return
			}

			this.panning = false
			this.gesture = null

			if (!start.swipeable) {
				return
			}

			const dx = event.clientX - start.x
			const dy = event.clientY - start.y

			// across, not down: a vertical drag on a picture is a page scroll
			// that happened to start here
			if (Math.abs(dx) >= SWIPE_THRESHOLD && Math.abs(dx) > Math.abs(dy)) {
				this.$emit(dx > 0 ? 'previous' : 'next')
			}
		},

		/** @param {WheelEvent} event a wheel or a trackpad pinch */
		onWheel(event) {
			this.zoomBy(event.deltaY < 0 ? STEP : 1 / STEP)
		},

		/** Between the image's own size and a readable zoom, on a double press. */
		toggleZoom() {
			if (this.scale > MIN_SCALE) {
				this.reset()
			} else {
				this.setScale(DOUBLE_TAP_SCALE)
			}
		},

		/** @param {number} factor what to multiply the current zoom by */
		zoomBy(factor) {
			this.setScale(this.scale * factor)
		},

		/**
		 * @param {number} scale the zoom asked for, before it is held to the
		 *                       range the viewer allows
		 */
		setScale(scale) {
			this.scale = Math.min(Math.max(scale, MIN_SCALE), MAX_SCALE)

			if (this.scale === MIN_SCALE) {
				this.offsetX = 0
				this.offsetY = 0
			} else {
				this.clamp()
			}
		},

		/**
		 * Keeps the picture over its frame.
		 *
		 * Without this a pan can push the whole picture off the screen and
		 * leave the reader with an empty lightbox and no way back but the
		 * reset button.
		 */
		clamp() {
			const frame = /** @type {HTMLElement|undefined} */ (this.$refs.frame)
			if (!frame) {
				return
			}

			// how far the picture may move before its edge comes inside the
			// frame: half of what the zoom added, in each direction
			const limitX = Math.max(0, (frame.clientWidth * (this.scale - 1)) / 2)
			const limitY = Math.max(0, (frame.clientHeight * (this.scale - 1)) / 2)

			this.offsetX = Math.min(Math.max(this.offsetX, -limitX), limitX)
			this.offsetY = Math.min(Math.max(this.offsetY, -limitY), limitY)
		},

		/** Back to the picture as it arrived. */
		reset() {
			this.scale = MIN_SCALE
			this.offsetX = 0
			this.offsetY = 0
		},
	},
}
</script>

<style lang="scss" scoped>
.zoomable {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	overflow: hidden;
	/* the browser's own panning and zooming would fight every gesture here */
	touch-action: none;
}

.zoomable__image {
	max-width: 100%;
	max-height: 100%;
	/* a pan follows the finger, so it must not be animated; only the steps
	   from the controls and the double press are worth easing, and those set
	   the same property, so the transition is kept short enough not to lag a
	   drag noticeably */
	transition: transform .12s ease-out;
	transform-origin: center center;
	user-select: none;
}

.zoomable--zoomed .zoomable__image {
	cursor: grab;
}

.zoomable--grabbing .zoomable__image {
	cursor: grabbing;
	transition: none;
}

.zoomable__controls {
	position: absolute;
	inset-block-end: 12px;
	inset-inline-end: 12px;
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px;
	border-radius: var(--border-radius-pill, 20px);
	background: rgba(0, 0, 0, .55);
	color: #fff;
}

.zoomable__control {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: inherit;
	cursor: pointer;

	&:hover:not(:disabled),
	&:focus-visible {
		background: rgba(255, 255, 255, .2);
	}

	&:disabled {
		opacity: .4;
		cursor: default;
	}
}

.zoomable__level {
	min-width: 44px;
	font-size: 12px;
	font-variant-numeric: tabular-nums;
	text-align: center;
}

@media (prefers-reduced-motion: reduce) {
	.zoomable__image {
		transition: none;
	}
}
</style>
