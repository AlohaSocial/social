<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="switcher"
		:class="[`switcher--${fit}`, { 'switcher--more-left': overflowLeft, 'switcher--more-right': overflowRight, 'switcher--compact': compact, 'switcher--beside-toggle': besideToggle }]"
		role="radiogroup"
		:aria-label="label"
		@keydown="onKeydown"
		@scroll.passive="updateOverflow">
		<div ref="track" class="switcher__track">
			<!-- the pill that slides between the options -->
			<span
				class="switcher__glider"
				:style="gliderStyle"
				aria-hidden="true" />

			<button
				v-for="(option, index) in options"
				:key="option.value"
				ref="options"
				class="switcher__option"
				:class="{ 'switcher__option--active': option.value === value, 'switcher__option--short': Boolean(option.short) }"
				role="radio"
				type="button"
				:aria-checked="option.value === value"
				:tabindex="option.value === value ? 0 : -1"
				:title="compact || fit === 'icons' ? option.label : undefined"
				@click="show(index)"
				@pointerenter="$emit('intent', option.value)"
				@focus="$emit('intent', option.value)"
				@touchstart.passive="$emit('intent', option.value)">
				<component
					:is="option.icon"
					class="switcher__icon"
					:size="18" />
				<span class="switcher__label">{{ option.label }}</span>
				<!-- shown on a phone instead of the icon, for choices an icon
				     cannot tell apart; the label stays the name -->
				<span v-if="option.short" class="switcher__short" aria-hidden="true">{{ option.short }}</span>
			</button>
		</div>
	</div>
</template>

<script>
/**
 * A row of choices with a pill that travels between them: the feed scopes,
 * a profile's Posts, Photos and Videos, Discover's lists.
 *
 * Hand-built rather than `NcCheckboxRadioSwitch` for the travelling pill,
 * so the accessibility is done here: a real `radiogroup`, arrow keys and a
 * roving tabindex. An option with a route pushes it; one without emits
 * `update:value` for the page to act on.
 */

/** an option's inline padding with room to spare, and with less; see the styles */
const ROOMY_PADDING = 18
const TIGHT_PADDING = 12
/** the icon and the gap after it, which an option gives up before its label */
const ICON_ROOM = 18 + 8
/** how far the fade at either end reaches; see the styles */
const FADE = 28

export default {
	name: 'TimelineSwitcher',

	props: {
		/**
		 * What to choose between: `{ value, label, icon, to, short }` each.
		 * `to` is the route the option pushes; without it the option emits
		 * `update:value`. `short` is optional words a phone shows instead of
		 * the icon, for choices whose icons look alike.
		 */
		options: {
			type: /** @type {import('vue').PropType<Array<{ value: string, label: string, icon: object, to?: object, short?: string }>>} */ (Array),
			required: true,
		},

		/** Which option's page is on screen, by its `value`. */
		value: {
			type: String,
			required: true,
		},

		/** What the group is choosing, for a screen reader. */
		label: {
			type: String,
			required: true,
		},

		/** Icons only, the labels back on hover or focus, while the page scrolls under it. */
		compact: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether this is the first row of a page with no visible title, to
		 * sit beside Nextcloud's navigation toggle rather than below it.
		 */
		besideToggle: {
			type: Boolean,
			default: false,
		},
	},

	/**
	 * `intent`: the reader is pointing at, focusing or touching an option, by
	 * its `value`, so what it shows can be fetched before the click.
	 */
	emits: ['update:value', 'intent'],

	data() {
		return {
			/**
			 * Where the active option is, in pixels from the inside of the
			 * track, or null while there is nothing to measure.
			 *
			 * @type {?{offset: number, width: number}}
			 */
			measured: null,
			/** re-measures when the track or an option changes size */
			observer: null,
			/** whether the track runs past the control on either side */
			overflowLeft: false,
			overflowRight: false,
			/**
			 * How much of each option there is room for: its icon and label,
			 * its label alone, or its icon alone.
			 *
			 * @type {'roomy'|'tight'|'icons'}
			 */
			fit: 'roomy',
			/** how wide the track is with every label and the full padding */
			roomyWidth: 0,
		}
	},

	computed: {
		/** @return {number} which option is on screen, 0 when none is */
		activeIndex() {
			return Math.max(0, this.options.findIndex((option) => option.value === this.value))
		},

		/**
		 * Where the pill is, and how wide, measured off the option it sits
		 * under since options are as wide as their own words. Until there is
		 * something to measure (first paint, jsdom) it takes an equal share of
		 * the track by index.
		 *
		 * @return {object} the inline style
		 */
		gliderStyle() {
			if (this.measured === null) {
				return {
					'--switcher-count': this.options.length,
					transform: `translateX(${this.activeIndex * 100}%)`,
				}
			}

			return {
				'--switcher-count': this.options.length,
				width: `${this.measured.width}px`,
				transform: `translateX(${this.measured.offset}px)`,
			}
		},
	},

	watch: {
		value() {
			this.$nextTick(() => {
				this.measure()
				this.bringIntoView(this.activeIndex)
			})
		},

		// the options change width with the fit, and the pill sits on one
		fit() {
			this.$nextTick(() => {
				this.measure()
				this.updateOverflow()
			})
		},

		options() {
			// new labels are measured in full before the fit is chosen again
			this.fit = 'roomy'
			this.$nextTick(() => {
				this.updateFit()
				this.measure()
				this.updateOverflow()
			})
		},
	},

	mounted() {
		this.updateFit()
		this.measure()
		this.updateOverflow()
		this.$nextTick(() => this.bringIntoView(this.activeIndex))

		// an option can change width without the control changing size (a
		// translation, a font, a breakpoint), so the options are observed too
		if (typeof ResizeObserver === 'function') {
			this.observer = new ResizeObserver(() => {
				// measuring inside the observer's callback would re-enter it
				window.requestAnimationFrame(() => {
					this.updateFit()
					this.measure()
					this.updateOverflow()
				})
			})
			this.observer.observe(this.$el)
			const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
			if (track) {
				this.observer.observe(track)
			}
			for (const option of this.optionElements()) {
				this.observer.observe(option)
			}
		}
	},

	unmounted() {
		this.observer?.disconnect()
	},

	methods: {
		/**
		 * Reads where the active option is, so the pill can sit on it.
		 * A width of 0 means nothing is laid out yet and the fallback stands.
		 */
		measure() {
			const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
			const elements = this.optionElements()
			const option = elements[this.activeIndex]
			const first = elements[0]
			const width = option?.offsetWidth ?? 0
			if (!track || !option || !first || width === 0) {
				this.measured = null

				return
			}

			// `offsetLeft` is physical and the pill is anchored to the inline
			// start, so in right-to-left the distance is measured from the other
			// edge and the travel is negative
			const rightToLeft = window.getComputedStyle(track).direction === 'rtl'
			const inlineStart = (element) => (rightToLeft
				? track.offsetWidth - (element.offsetLeft + element.offsetWidth)
				: element.offsetLeft)

			// measured from the first option, so the track's padding, which the
			// pill already sits after, is not counted twice
			const distance = inlineStart(option) - inlineStart(first)

			this.measured = {
				offset: rightToLeft ? -distance : distance,
				width,
			}
		},

		/**
		 * Marks the side of the control that has options beyond it, by
		 * comparing boxes rather than `scrollLeft`, whose sign differs between
		 * browsers in right-to-left.
		 */
		updateOverflow() {
			const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
			if (!track) {
				this.overflowLeft = false
				this.overflowRight = false

				return
			}

			const control = this.$el.getBoundingClientRect()
			const content = track.getBoundingClientRect()
			// the track's own padding may stay beyond the edge: scrolled to the
			// end, the last option is whole and needs no fade
			const edge = 4
			this.overflowLeft = content.left < control.left - edge
			this.overflowRight = content.right > control.right + edge
		},

		/**
		 * Chooses how much of each option there is room for. The track's
		 * width is read while every option is drawn in full, so
		 * it is known while the control shows less of them; the page's own
		 * folding (`compact`) is not a width to remember.
		 */
		updateFit() {
			const track = /** @type {HTMLElement|undefined} */ (this.$refs.track)
			const room = this.$el?.clientWidth ?? 0
			if (!track || room === 0) {
				return
			}

			if (this.fit === 'roomy' && !this.compact) {
				this.roomyWidth = track.offsetWidth
			}
			if (this.roomyWidth === 0) {
				return
			}

			const tightWidth = this.roomyWidth - this.options.length * (2 * (ROOMY_PADDING - TIGHT_PADDING) + ICON_ROOM)
			if (room >= this.roomyWidth) {
				this.fit = 'roomy'
			} else {
				this.fit = room >= tightWidth ? 'tight' : 'icons'
			}
		},

		/**
		 * Keeps the chosen option on screen without moving the page.
		 *
		 * @param {number} index the option to bring into view
		 */
		bringIntoView(index) {
			// the control is scrolled by hand: `scrollIntoView` would scroll the
			// page as well whenever the control is below the fold
			const option = this.optionElements()[index]
			const control = /** @type {HTMLElement|undefined} */ (this.$el)
			if (!option || typeof control?.scrollBy !== 'function') {
				return
			}

			const box = control.getBoundingClientRect()
			const target = option.getBoundingClientRect()
			if (target.left < box.left + FADE) {
				control.scrollBy({ left: target.left - box.left - FADE })
			} else if (target.right > box.right - FADE) {
				control.scrollBy({ left: target.right - box.right + FADE })
			}
		},

		/** @return {HTMLElement[]} the option buttons, in order */
		optionElements() {
			const options = this.$refs.options

			return Array.isArray(options) ? options.filter(Boolean) : []
		},

		/**
		 * @param {number} index the option to show
		 */
		show(index) {
			this.focus(index)

			const option = this.options[index]
			if (option === undefined || option.value === this.value) {
				// choosing the page you are already on is not a navigation
				return
			}

			// an option with nowhere to go is one the page decides for itself
			if (option.to === undefined) {
				this.$emit('update:value', option.value)

				return
			}

			this.$router.push(option.to)
		},

		/**
		 * Arrow keys move through the group, with a roving tabindex.
		 *
		 * @param {KeyboardEvent} event the key
		 */
		onKeydown(event) {
			const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key]
			if (step === undefined) {
				return
			}

			event.preventDefault()
			// wraps, so the end of the group is never a dead stop
			const next = (this.activeIndex + step + this.options.length) % this.options.length
			this.show(next)
		},

		/**
		 * @param {number} index the option to put the focus on
		 */
		focus(index) {
			const options = this.$refs.options
			const option = Array.isArray(options) ? options[index] : undefined
			option?.focus?.()
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

/*
 * The control scrolls rather than squeezes, so every option stays as wide as
 * its words; a fade on the edge says there is more.
 */
.switcher {
	width: 100%;
	max-width: 100%;
	margin: 0 auto 14px;
	overflow-x: auto;
	overflow-y: hidden;
	// a scrollbar would change the pill's height; the fades say the same
	scrollbar-width: none;
	scroll-behavior: smooth;
	overscroll-behavior-x: contain;
	-webkit-overflow-scrolling: touch;

	&::-webkit-scrollbar {
		display: none;
	}

	// the mask is transparent on the side that has more options beyond it
	&.switcher--more-left {
		mask-image: linear-gradient(to right, transparent, #000 28px);
	}

	&.switcher--more-right {
		mask-image: linear-gradient(to left, transparent, #000 28px);
	}

	&.switcher--more-left.switcher--more-right {
		mask-image: linear-gradient(to right, transparent, #000 28px, #000 calc(100% - 28px), transparent);
	}
}

/*
 * Where the sidebar is folded away, a page without a visible title puts the
 * switcher on the row of the floating toggle: narrowed by the toggle's width
 * on both sides to stay centred, and lifted onto the toggle's line. The
 * selector outranks the room Timeline.vue makes for a notice shown first.
 */
@include layout.below(layout.$folded) {
	.social__wrapper > .switcher:first-child,
	.switcher.switcher--beside-toggle {
		width: auto;
		margin-block-start: -4px;
		margin-inline: var(--default-clickable-area, 44px);
	}
}

/* the track is as wide as all of its options and is what the pill measures
   against; the control above is the window onto it */
.switcher__track {
	position: relative;
	display: flex;
	align-items: stretch;
	width: max-content;
	margin-inline: auto;
	padding: 3px;
	border-radius: var(--border-radius-pill, 100px);
	// a well rather than a surface, so only the pill carries the accent
	background: var(--color-background-dark);
}

/* the pill, behind the labels and travelling between them */
.switcher__glider {
	position: absolute;
	z-index: 0;
	top: 3px;
	bottom: 3px;
	inset-inline-start: 3px;
	// the track's own 3px of padding is not the options' to share
	width: calc((100% - 6px) / var(--switcher-count, 3));
	border-radius: var(--border-radius-pill, 100px);
	background: var(--color-primary-element);
	box-shadow: none;
	// overshoots and settles, so it reads as something that moved
	transition: transform var(--social-motion-slow) var(--social-ease-spring);
}

/* `.switcher &` outranks Nextcloud's
   `button:not(.button-vue, [class^="vs__"]):hover`, which would otherwise
   paint over the pill; what is not restated here comes from the server. */
.switcher .switcher__option {
	position: relative;
	z-index: 1;
	/* as wide as its own words, never narrower; what does not fit scrolls */
	flex: 0 0 auto;
	display: flex;
	gap: 8px;
	align-items: center;
	justify-content: center;
	/* 30px plus the track's 3px of padding either side is a 36px control */
	min-height: 30px;
	/* the only separation between options is this padding doubled, sized
	   for the crowded case; Nextcloud's buttons bring a margin besides */
	margin: 0;
	padding: 0 18px;
	border: none;
	border-radius: var(--border-radius-pill, 100px);
	color: var(--color-text-maxcontrast);
	font-size: inherit;
	font-weight: 500;
	white-space: nowrap;
	cursor: pointer;
	transition: color var(--social-motion-base) var(--social-ease), background-color var(--social-motion-base) var(--social-ease), transform var(--social-motion-base) var(--social-ease-spring);

	// the pill is the only background in the control, in every state
	&,
	&:hover,
	&:focus,
	&:active {
		background: transparent;
	}

	&:active {
		transform: scale(.96);
	}

	// the ring belongs to the option, not to the track it sits in
	&:focus-visible {
		outline: 2px solid var(--color-main-text);
		outline-offset: 1px;
	}
}

/* the options not chosen show a ghost of the pill under the pointer */
.switcher .switcher__option:not(.switcher__option--active) {
	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
		color: var(--color-main-text);
		transform: translateY(-1px);
	}
}

.switcher .switcher__option--active {
	color: var(--color-primary-element-text);
}

.switcher__icon {
	display: flex;
	// a fixed glyph: squeezed, it ends up drawn over the words beside it
	flex: 0 0 auto;
	transition: transform var(--social-motion-base) var(--social-ease-spring);
}

/* the icon of the one you just chose gives a little kick */
.switcher__option--active .switcher__icon {
	animation: switcher-pop var(--social-motion-slow) var(--social-ease-spring);
}

/* and the globe turns, because it is a globe */
.switcher__option--active :deep(.earth-icon) {
	animation: switcher-spin .6s cubic-bezier(.4, 0, .2, 1);
}

@keyframes switcher-pop {
	0% { transform: scale(.6); }
	60% { transform: scale(1.18); }
	100% { transform: scale(1); }
}

@keyframes switcher-spin {
	0% { transform: rotate(-160deg) scale(.7); }
	100% { transform: rotate(0) scale(1); }
}

/* the stand-in for the label when only icons fit; see the block below */
.switcher__short {
	display: none;
}

/* with less room the options keep their words, which tell them apart
   better than icons do, and give up the icons and some padding */
.switcher--tight {
	.switcher__option {
		padding: 0 12px;
	}

	.switcher__icon {
		display: none;
	}
}

/*
 * With less still the labels give way to the icons, as they do while the
 * page folds the control as it scrolls; there a pointer over the row or focus
 * in it brings the words back. The labels stay in the accessibility tree as
 * the options' names.
 */
.switcher--icons,
.switcher--compact:not(:hover, :focus-within) {
	.switcher__option {
		padding: 0 12px;
	}

	.switcher__label {
		@include layout.visually-hidden;
	}

	// unless the icons are all alike, and the option brought words of its own
	.switcher__option--short .switcher__icon {
		display: none;
	}

	.switcher__short {
		display: inline;
	}

	// a word is wider than its icon, so these get less padding
	.switcher__option--short {
		padding: 0 10px;
	}
}

@media (prefers-reduced-motion: reduce) {
	// the option being brought into view jumps rather than slides
	.switcher {
		scroll-behavior: auto;
	}

	.switcher__glider,
	.switcher .switcher__option,
	.switcher__icon {
		transition: none;
	}

	.switcher__option--active .switcher__icon,
	.switcher__option--active :deep(.earth-icon) {
		animation: none;
	}

	// the same specificity the states above needed, for the same reason
	.switcher .switcher__option:not(.switcher__option--active):hover,
	.switcher .switcher__option:active {
		transform: none;
	}
}
</style>
