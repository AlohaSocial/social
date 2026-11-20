<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<svg
		class="blooming-flower"
		:class="{ 'blooming-flower--blooming': blooming }"
		:width="size"
		:height="size"
		viewBox="-32 -32 64 64"
		aria-hidden="true"
		focusable="false">
		<path
			v-for="petal in PETALS"
			:key="petal"
			class="blooming-flower__petal"
			:style="{ '--petal-turn': (petal * 72) + 'deg', '--petal-delay': (petal * 55) + 'ms' }"
			d="M0 -4C9 -10 11 -24 0 -30C-11 -24 -9 -10 0 -4Z" />
		<circle class="blooming-flower__heart" r="5.5" />
	</svg>
</template>

<script>
/** The flower of the app's logo has five petals. */
const PETALS = [0, 1, 2, 3, 4]

/** The moments that have bloomed already on this page, by their key. */
const bloomed = new Set()

/**
 * The flower from the app's logo, opening once for a small moment such as
 * being caught up or a first post. With `onceKey` it blooms only the first
 * time per page; a reader who asked for less motion gets it open. Decorative:
 * hidden from assistive technology.
 */
export default {
	name: 'BloomingFlower',

	props: {
		/** Width and height, in pixels. */
		size: {
			type: Number,
			default: 24,
		},

		/**
		 * Names the moment, so it blooms once per page load however often
		 * it is drawn. Without it, every new flower blooms.
		 */
		onceKey: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			PETALS,
			blooming: false,
		}
	},

	created() {
		const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
		if (reduced || (this.onceKey !== '' && bloomed.has(this.onceKey))) {
			return
		}

		if (this.onceKey !== '') {
			bloomed.add(this.onceKey)
		}
		this.blooming = true
	},
}
</script>

<style scoped lang="scss">
.blooming-flower {
	flex: 0 0 auto;
	overflow: visible;
	color: var(--color-primary-element);
	vertical-align: middle;
}

.blooming-flower__petal {
	fill: currentColor;
	fill-opacity: .85;
	transform: rotate(var(--petal-turn));
	transform-box: view-box;
	transform-origin: 50% 50%;
}

.blooming-flower__heart {
	fill: var(--color-main-background);
	transform-box: view-box;
	transform-origin: 50% 50%;
}

.blooming-flower--blooming {
	.blooming-flower__petal {
		animation: blooming-flower-open var(--social-motion-slow) var(--social-ease-spring) var(--petal-delay) both;
	}

	.blooming-flower__heart {
		animation: blooming-flower-heart var(--social-motion-slow) var(--social-ease-spring) 250ms both;
	}
}

@keyframes blooming-flower-open {
	from {
		opacity: 0;
		transform: rotate(calc(var(--petal-turn) - 40deg)) scale(.15);
	}
}

@keyframes blooming-flower-heart {
	from {
		transform: scale(0);
	}
}

@media (forced-colors: active) {
	.blooming-flower {
		color: CanvasText;
	}

	.blooming-flower__heart {
		fill: Canvas;
	}
}

@media (prefers-reduced-motion: reduce) {
	.blooming-flower--blooming {
		.blooming-flower__petal,
		.blooming-flower__heart {
			animation: none;
		}
	}
}
</style>
