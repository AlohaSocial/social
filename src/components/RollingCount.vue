<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- mounted at zero too, so that the first "1" can roll in like any other change -->
	<span
		v-if="count > 0"
		class="post-action-count rolling-count"
		:title="fullCount"
		:aria-label="fullCount"
		:class="rollClass">
		<span :key="count" class="rolling-count__value rolling-count__value--current">{{ compactCount(count) }}</span>
		<!-- the digit on its way out; never read aloud, and gone again once
		     the roll is over, so the element still reads as one number -->
		<span
			v-if="leaving !== null"
			:key="`leaving-${leaving}`"
			class="rolling-count__value rolling-count__value--leaving"
			aria-hidden="true">{{ compactCount(leaving) }}</span>
	</span>
</template>

<script>
import { formatCompactCount, formatCount } from '../utils/number.js'

/** how long one roll takes; the same .28s the stylesheet animates over */
const ROLL_MS = 280

export default {
	name: 'RollingCount',
	props: {
		/** what the counter says now */
		count: {
			type: Number,
			default: 0,
		},
	},

	data() {
		return {
			/** 'up', 'down' or '' when the number is at rest */
			direction: '',
			/** the value being sent away, null when there is none */
			leaving: null,
			/** when the roll comes to rest */
			timer: null,
		}
	},

	computed: {
		/** @return {string} the full value for assistive technology and hover */
		fullCount() {
			return formatCount(this.count)
		},

		/** @return {string|null} */
		rollClass() {
			return this.direction === '' ? null : `rolling-count--${this.direction}`
		},
	},

	watch: {
		count(value, previous) {
			this.roll(value, previous)
		},
	},

	beforeUnmount() {
		window.clearTimeout(this.timer)
	},

	methods: {
		/** @param {number} value the count @return {string} */
		compactCount(value) {
			return formatCompactCount(value)
		},

		/**
		 * @param {number} value what the count became
		 * @param {number} previous what it was
		 */
		roll(value, previous) {
			window.clearTimeout(this.timer)

			// nothing is rendered at zero, so an unlike that empties the
			// counter has nowhere to roll to: it simply goes
			if (value <= 0 || this.prefersReducedMotion()) {
				this.direction = ''
				this.leaving = null
				return
			}

			this.direction = value > previous ? 'up' : 'down'
			// above zero the old digit slides out under the new one; the very
			// first one has no predecessor to send away, so it rolls in alone
			this.leaving = previous > 0 ? previous : null
			this.timer = window.setTimeout(() => {
				this.direction = ''
				this.leaving = null
			}, ROLL_MS)
		},

		/** @return {boolean} */
		prefersReducedMotion() {
			return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
		},
	},
}
</script>

<style scoped lang="scss">
/* the digits finish the gesture the icon started: one short slide, upwards
   when the number grew and downwards when it shrank */
@keyframes rolling-count-enter-up {
	0% { transform: translateY(90%); opacity: 0; }
	100% { transform: translateY(0); opacity: 1; }
}

@keyframes rolling-count-leave-up {
	0% { transform: translateY(0); opacity: 1; }
	100% { transform: translateY(-90%); opacity: 0; }
}

@keyframes rolling-count-enter-down {
	0% { transform: translateY(-90%); opacity: 0; }
	100% { transform: translateY(0); opacity: 1; }
}

@keyframes rolling-count-leave-down {
	0% { transform: translateY(0); opacity: 1; }
	100% { transform: translateY(90%); opacity: 0; }
}

.rolling-count {
	position: relative;
	display: inline-flex;
	flex: 0 0 auto;
	align-items: center;
	justify-content: center;
	min-width: 2.4em;
	padding-inline: 4px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-background-hover);
	/* the slide is clipped to the line the number already occupied, so a
	   rolling counter never pushes the action bar around */
	overflow: hidden;
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
	vertical-align: middle;
	line-height: 1.4;

	&__value {
		display: block;

		/* absolute, so the outgoing digit neither widens the box nor moves
		   the incoming one; centred for counts of different lengths */
		&--leaving {
			position: absolute;
			top: 0;
			inset-inline: 0;
		}
	}
}

.rolling-count--up {
	.rolling-count__value--current {
		animation: rolling-count-enter-up var(--social-motion-base) var(--social-ease);
	}

	.rolling-count__value--leaving {
		animation: rolling-count-leave-up var(--social-motion-base) var(--social-ease) forwards;
	}
}

.rolling-count--down {
	.rolling-count__value--current {
		animation: rolling-count-enter-down var(--social-motion-base) var(--social-ease);
	}

	.rolling-count__value--leaving {
		animation: rolling-count-leave-down var(--social-motion-base) var(--social-ease) forwards;
	}
}

@media (prefers-reduced-motion: reduce) {
	.rolling-count__value,
	.rolling-count__value--current,
	.rolling-count__value--leaving {
		animation: none;
	}
}
</style>
