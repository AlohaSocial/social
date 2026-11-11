<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="switcher"
		:class="{ 'switcher--more-left': overflowLeft, 'switcher--more-right': overflowRight }"
		role="radiogroup"
		:aria-label="label"
		@keydown="onKeydown"
		@scroll.passive="updateOverflow">
		<div ref="track" class="switcher__track">
			<!-- the pill that slides: one element that moves, rather than several
			     that light up, so the eye follows the change instead of finding it -->
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
				@click="show(index)"
				@pointerenter="$emit('intent', option.value)"
				@focus="$emit('intent', option.value)"
				@touchstart.passive="$emit('intent', option.value)">
				<component
					:is="option.icon"
					class="switcher__icon"
					:size="18" />
				<span class="switcher__label">{{ option.label }}</span>
				<!-- what a phone shows where there is no room for the label, for
				     choices an icon cannot tell apart; the label stays the name -->
				<span v-if="option.short" class="switcher__short" aria-hidden="true">{{ option.short }}</span>
			</button>
		</div>
	</div>
</template>

<script>
/**
 * A row of places to be, with a pill that travels between them.
 *
 * It was written for the three timelines a reader moves between all day, which
 * were a sidebar entry each — and a sidebar entry is where you go for a place
 * you *visit*. Those three are the same place seen from three distances, and
 * switching between them is something you do while reading rather than
 * something you navigate to; Mastodon and every client of it put them side by
 * side above the posts for that reason. A profile's Posts, Photos and Videos
 * are the same shape of choice, so they are the same control.
 *
 * Hand-built rather than `NcCheckboxRadioSwitch`, for the one thing that
 * component cannot do: a single indicator that *travels* between the options.
 * Several buttons that light up tell you where you landed; one pill that
 * slides tells you where you came from, which is what makes a switch feel like
 * a switch. The cost is the accessibility, which is therefore done here in
 * full — a real `radiogroup`, arrow keys, a roving tabindex — rather than left
 * to a set of links dressed up as tabs.
 *
 * It decides nothing itself. An option that carries a route pushes it, so the
 * page it sits on stays the one answer to what is being shown; an option with
 * no route is a choice the page holds itself, and the pick is handed back as
 * `update:value` for that page to act on — Discover's four lists are one
 * screen and not four, so they are state there rather than four routes. The
 * words in the routes are the route's own — `timeline` for the local feed,
 * `federated` for the global one, `image` and `video` for the kinds of
 * attachment the API knows — rather than the labels beside them, because two
 * vocabularies would be one more place for the two to disagree.
 */
export default {
	name: 'TimelineSwitcher',

	props: {
		/**
		 * What to choose between: `{ value, label, icon, to, short }` each,
		 * where `to` is the route the option pushes when it is chosen. Leave
		 * `to` off and the option emits `update:value` instead, for a page
		 * whose choice is its own state. `short` is optional: a few words a
		 * phone shows instead of the icon, for choices whose icons cannot tell
		 * them apart -- four time windows are four calendars.
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
	},

	/**
	 * `intent`: the reader is pointing at, focusing or touching an option, by
	 * its `value` -- the moment to fetch what it shows, before the click.
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
		}
	},

	computed: {
		/** @return {number} which option is on screen, 0 when none is */
		activeIndex() {
			return Math.max(0, this.options.findIndex((option) => option.value === this.value))
		},

		/**
		 * Where the pill is, and how wide.
		 *
		 * Measured off the option it sits under, because the options are no
		 * longer all the same width: an option is as wide as its own words,
		 * which is what lets "All" and "Polls" leave room for "Favourites"
		 * instead of every option being as wide as the longest one. A share of
		 * the track is only right while they are equal.
		 *
		 * `measured` is null until there is something to measure — the first
		 * paint, and jsdom, where nothing has a width. The fallback is the old
		 * behaviour: an equal share of the track, positioned by index, with the
		 * width left to the stylesheet (which takes the track's padding off
		 * first, or the pill would stick out past the option it is under).
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

		options() {
			this.$nextTick(() => {
				this.measure()
				this.updateOverflow()
			})
		},
	},

	mounted() {
		this.measure()
		this.updateOverflow()
		this.$nextTick(() => this.bringIntoView(this.activeIndex))

		// the options change width without the control changing size — a
		// translation arriving, a font loading, the labels giving way to their
		// icons at a breakpoint — so the observer watches an option rather
		// than only the scroll container
		if (typeof ResizeObserver === 'function') {
			this.observer = new ResizeObserver(() => {
				// off the observer's own callback: measuring inside it would
				// re-enter it on the next frame
				window.requestAnimationFrame(() => {
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
		 *
		 * `offsetLeft` is relative to the track, which is the pill's containing
		 * block, so the two agree without either knowing where the control is
		 * on the page. A width of 0 means there is nothing laid out yet — the
		 * first paint, or jsdom — and the share-of-the-track fallback stands.
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
			// start, so in a right-to-left interface the two run in opposite
			// directions: the distance is measured from the other edge and the
			// travel is negative.
			const rightToLeft = window.getComputedStyle(track).direction === 'rtl'
			const inlineStart = (element) => (rightToLeft
				? track.offsetWidth - (element.offsetLeft + element.offsetWidth)
				: element.offsetLeft)

			// measured against the first option rather than against the track,
			// which takes the track's padding out of both sides of the
			// subtraction: the pill already sits after that padding, so
			// travelling it again would push the pill off its option by
			// exactly that much
			const distance = inlineStart(option) - inlineStart(first)

			this.measured = {
				offset: rightToLeft ? -distance : distance,
				width,
			}
		},

		/**
		 * Marks the side of the control that has options beyond it.
		 *
		 * The track is physically wider than the scroll container when there
		 * are more options than fit; comparing the two boxes says which edges
		 * are cut off without relying on `scrollLeft`, whose sign differs
		 * between browsers in a right-to-left interface.
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
			const edge = 1
			this.overflowLeft = content.left < control.left - edge
			this.overflowRight = content.right > control.right + edge
		},

		/**
		 * Keeps the chosen option on screen without moving the page.
		 *
		 * @param {number} index the option to bring into view
		 */
		bringIntoView(index) {
			const option = this.optionElements()[index]
			option?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
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
		 * Arrow keys move through the group, as they do in every radio group —
		 * a roving tabindex puts one stop on the control, not one per option.
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
 * The control scrolls rather than squeezes. Seven translated labels do not fit
 * a reading column, and letting the options share the width makes each of them
 * narrower than the words in it; the words then run into the next option.
 * Natural widths and a scroll container keep every option readable, and a fade
 * on the edge says there is more than what is on screen.
 */
.switcher {
	// the width to ask about is the column the control is drawn in, not the
	// window: a wide window still has a reading column, and the labels give
	// way at the same point in both
	container-type: inline-size;
	container-name: timeline-switcher;
	width: 100%;
	max-width: 100%;
	margin: 0 auto 14px;
	overflow-x: auto;
	overflow-y: hidden;
	// a scrollbar inside the pill would change its height; the fades say the
	// same thing without taking room
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

/* the track is as wide as all of its options, and is what the pill measures
   against; the control above is only the window onto it */
.switcher__track {
	position: relative;
	display: flex;
	align-items: stretch;
	width: max-content;
	margin-inline: auto;
	padding: 3px;
	border-radius: var(--border-radius-pill, 100px);
	// the track is a well rather than a surface: darker than the page, with no
	// shadow of its own, so the only thing casting one is the pill in it
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
	// the app's one shadow, as every other raised thing in the timeline uses:
	// a hand-rolled rgba here and the page stops looking like one surface
	box-shadow: var(--social-elevation-resting);
	// overshoots and settles, which is what makes it read as a thing that
	// moved rather than a colour that changed
	transition: transform .42s cubic-bezier(.22, 1.4, .36, 1);
}

/* Written from inside the track, and every state of it said out loud.
   Nextcloud styles bare `button` elements, and its rule for the hovered and
   focused ones is `button:not(.button-vue, [class^="vs__"]):hover` — more
   specific than one class, so a plain `.switcher__option:hover` loses and the
   server paints its own colour straight over the pill. `.switcher &` outranks
   it, and what is not restated here is inherited from the server. */
.switcher .switcher__option {
	position: relative;
	z-index: 1;
	/* As wide as its own words, and never narrower. Sharing the width made
	   every option as wide as the longest of them; shrinking into the column
	   then made each of them narrower than the words in it, so the words ran
	   into the next option. What does not fit scrolls instead. */
	flex: 0 0 auto;
	display: flex;
	gap: 8px;
	align-items: center;
	justify-content: center;
	/* 30, with the track's 3px of padding either side, is a 36px control. It
	   was 36 — a 48px control, taller than a Nextcloud button and the loudest
	   thing above a timeline it only labels */
	min-height: 30px;
	/* What separates one option from the next is this padding doubled, and
	   nothing else: there is no rule between them and the pill sits under only
	   one. Three roomy options and seven tight ones are the same control, so
	   the figure is the one that reads well at the crowded end. */
	padding: 0 18px;
	border: none;
	border-radius: var(--border-radius-pill, 100px);
	color: var(--color-text-maxcontrast);
	font-size: inherit;
	font-weight: 500;
	white-space: nowrap;
	cursor: pointer;
	transition: color .2s ease, background-color .2s ease, transform .2s cubic-bezier(.22, 1.2, .48, 1);

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

/* the two you did not choose warm up under the pointer: a ghost of the pill,
   so hovering shows you where it would land */
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
	// an icon is a fixed glyph: squeezing it to fit is how it ends up drawn
	// over the words beside it
	flex: 0 0 auto;
	transition: transform .2s cubic-bezier(.22, 1.2, .48, 1);
}

/* the icon of the one you just chose gives a little kick */
.switcher__option--active .switcher__icon {
	animation: switcher-pop .45s cubic-bezier(.34, 1.56, .64, 1);
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

/* a phone's stand-in for the label; see the block below */
.switcher__short {
	display: none;
}

/*
 * Five of these do not fit a phone.
 *
 * The control is barely wider than the thumb there, so the labels give way to
 * the icons they sit beside. The query is the control's own width rather than
 * the window's: a narrow column in a wide window keeps its labels and scrolls
 * under the fades instead. The labels stay in the accessibility tree, because
 * the label is the option's name and a radiogroup of five unnamed buttons is
 * not a control anybody can use.
 */
@container timeline-switcher (max-width: #{layout.$phone}) {
	.switcher .switcher__option {
		padding: 0 14px;
	}

	// the icon says the same thing, and there is no longer room for both
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

	// a word is wider than the icon it stands in for, so five of them fit
	// only with less room around each
	.switcher .switcher__option--short {
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
