<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="first-post" :class="{ 'first-post--leaving': leaving }">
		<!-- decoration, and nothing else: the sentence below is what a screen
		     reader is told -->
		<div v-if="!reducedMotion" class="first-post__confetti" aria-hidden="true">
			<span
				v-for="piece in pieces"
				:key="piece.id"
				class="first-post__piece"
				:style="piece.style" />
		</div>
		<p class="first-post__banner" aria-hidden="true">
			<span class="first-post__emoji">{{ emoji }}</span>
			{{ message }}
		</p>
		<!-- a live region is only reliably announced when it is already on the
		     page before it is filled, which is why the sentence arrives a tick
		     after the region does -->
		<p class="hidden-visually" role="status">
			{{ announcement }}
		</p>
	</div>
</template>

<script>
/**
 * The one-time flourish for the first post a reader ever publishes here.
 *
 * It is decoration only: it is `pointer-events: none` from its root down, so
 * it never takes a click from the post appearing underneath it, it holds no
 * focus and contains nothing focusable, and it takes itself off screen after
 * a couple of seconds. Escape, or a click anywhere, ends it early.
 *
 * Whether it is shown at all is decided in the timeline store; this component
 * only exists while it is being shown.
 */

/** How long the banner stays before it leaves on its own. */
const VISIBLE_MS = 2600
/** How long it takes to fade out, after which the parent may drop it. */
const LEAVING_MS = 300
/** Enough to read as a burst, few enough to stay one paint. */
const PIECE_COUNT = 36

const COLOURS = [
	'var(--color-primary-element, #0082c9)',
	'var(--color-success, #2d7b41)',
	'var(--color-warning, #c28900)',
	'var(--color-favorite, #a08b00)',
	'var(--color-error, #c33)',
]

/**
 * One piece of confetti: where it starts, where it drifts, how it turns.
 *
 * @param {number} id its index, which is also its key
 * @return {object} the piece and the inline style that animates it
 */
function makePiece(id) {
	const random = Math.random
	return {
		id,
		style: {
			left: (random() * 100).toFixed(2) + '%',
			width: (5 + random() * 5).toFixed(1) + 'px',
			height: (8 + random() * 6).toFixed(1) + 'px',
			background: COLOURS[id % COLOURS.length],
			borderRadius: id % 3 === 0 ? '50%' : '1px',
			// staggered, so they do not arrive as one wall
			animationDelay: Math.round(random() * 500) + 'ms',
			animationDuration: Math.round(1400 + random() * 900) + 'ms',
			'--first-post-drift': Math.round(-90 + random() * 180) + 'px',
			'--first-post-spin': Math.round(-540 + random() * 1080) + 'deg',
		},
	}
}

export default {
	name: 'FirstPostCelebration',

	props: {
		/**
		 * Which moment this is: `first-post`, `first-follower`, `hundred-posts`
		 * or `anniversary`. Anything else is treated as the first post, because a
		 * flourish that fails should still be a flourish.
		 */
		occasion: {
			type: String,
			default: 'first-post',
		},
	},

	emits: ['done'],
	data() {
		// asked once, at mount: a reader who wants no motion gets the sentence,
		// still and just as brief, instead of nothing at all
		const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
		return {
			reducedMotion,
			announcement: '',
			leaving: false,
			pieces: reducedMotion ? [] : Array.from({ length: PIECE_COUNT }, (unused, id) => makePiece(id)),
			hideTimer: null,
			doneTimer: null,
		}
	},

	computed: {
		/** @return {string} the one sentence this whole component says */
		/**
		 * What is being celebrated.
		 *
		 * The first post was the only moment this app marked, and it is the one
		 * moment that by definition happens before anybody has any reason to
		 * care. These are the others: the first person who chose to read you,
		 * the hundredth thing you wrote, and the day you have been here a year.
		 * Each is once, and each is over in two seconds.
		 *
		 * @return {string} the sentence
		 */
		message() {
			const messages = {
				'first-post': t('social', 'Your first post is out there. Welcome to the fediverse!'),
				'first-follower': t('social', 'Somebody is reading you. You have your first follower!'),
				'hundred-posts': t('social', 'A hundred posts. That is a body of work.'),
				anniversary: t('social', 'One year here today. Thank you for sticking around.'),
			}

			return messages[this.occasion] ?? messages['first-post']
		},

		/** @return {string} the one character in front of it */
		emoji() {
			const emoji = {
				'first-post': '🎉',
				'first-follower': '👋',
				'hundred-posts': '💯',
				anniversary: '🎂',
			}

			return emoji[this.occasion] ?? '🎉'
		},
	},

	mounted() {
		this.$nextTick(() => {
			this.announcement = this.message
		})
		this.hideTimer = window.setTimeout(this.dismiss, VISIBLE_MS)
		// skippable, without ever standing in the way: these only listen, they
		// do not preventDefault and they do not stop what the reader was doing
		window.addEventListener('keydown', this.onKeydown)
		window.addEventListener('pointerdown', this.onPointerDown)
	},

	beforeUnmount() {
		this.stop()
	},

	methods: {
		/**
		 * Ends it early on Escape, the key that means "not now" everywhere else.
		 *
		 * @param {KeyboardEvent} event the key the reader pressed
		 */
		onKeydown(event) {
			if (event.key === 'Escape') {
				this.dismiss()
			}
		},

		onPointerDown() {
			this.dismiss()
		},

		/** Fades out, then tells the parent it may drop this component. */
		dismiss() {
			if (this.leaving) {
				return
			}
			this.leaving = true
			window.clearTimeout(this.hideTimer)
			this.hideTimer = null
			if (this.reducedMotion) {
				// nothing to fade: it is done the moment it is over
				this.$emit('done')
				return
			}
			this.doneTimer = window.setTimeout(() => {
				this.doneTimer = null
				this.$emit('done')
			}, LEAVING_MS)
		},

		/** Every timer and every listener this put on the page, taken back. */
		stop() {
			window.clearTimeout(this.hideTimer)
			window.clearTimeout(this.doneTimer)
			this.hideTimer = null
			this.doneTimer = null
			window.removeEventListener('keydown', this.onKeydown)
			window.removeEventListener('pointerdown', this.onPointerDown)
		},
	},
}
</script>

<style scoped lang="scss">
.first-post {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: flex;
	align-items: flex-end;
	justify-content: center;
	padding-bottom: calc(var(--default-grid-baseline, 4px) * 8);
	/* the post underneath is the point: nothing here ever takes a click */
	pointer-events: none;
	opacity: 1;
	transition: opacity var(--social-motion-base) var(--social-ease);
}

.first-post--leaving {
	opacity: 0;
}

.first-post__confetti {
	position: absolute;
	inset: 0;
	overflow: hidden;
}

.first-post__piece {
	position: absolute;
	top: -12vh;
	display: block;
	animation-name: first-post-fall;
	animation-timing-function: cubic-bezier(.25, .6, .35, 1);
	animation-fill-mode: both;
}

@keyframes first-post-fall {
	0% {
		opacity: 0;
		transform: translate3d(0, 0, 0) rotate(0);
	}

	12% {
		opacity: 1;
	}

	100% {
		opacity: 0;
		transform: translate3d(var(--first-post-drift, 0), 92vh, 0) rotate(var(--first-post-spin, 180deg));
	}
}

.first-post__banner {
	position: relative;
	max-width: 90vw;
	margin: 0;
	padding: 10px 18px;
	border-radius: var(--border-radius-pill, 20px);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	box-shadow: var(--social-elevation-raised);
	color: var(--color-main-text);
	font-weight: 600;
	text-align: center;
	/* the same curve the empty-content artwork settles on */
	animation: first-post-settle var(--social-motion-slow) var(--social-ease) both;
}

.first-post__emoji {
	margin-inline-end: 6px;
}

@keyframes first-post-settle {
	from {
		opacity: 0;
		transform: translateY(10px) scale(.94);
	}

	to {
		opacity: 1;
		transform: none;
	}
}

/* asked for no motion: the same words, held just as briefly, held still */
@media (prefers-reduced-motion: reduce) {
	.first-post {
		transition: none;
	}

	.first-post__banner {
		animation: none;
	}

	.first-post__piece {
		display: none;
	}
}
</style>
