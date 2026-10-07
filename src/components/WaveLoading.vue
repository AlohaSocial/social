<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		A wave rolling past while more posts are on their way. Decoration
		only: the list says "Loading posts" to screen readers itself.
	-->
	<svg
		class="wave-loading"
		viewBox="0 0 96 24"
		width="96"
		height="24"
		fill="none"
		aria-hidden="true"
		focusable="false">
		<path class="wave-loading__swell" :d="wave(14)" />
		<path class="wave-loading__crest" :d="wave(11)" />
	</svg>
</template>

<script>
/** one wavelength in viewBox units; the animation moves the line by exactly this much */
const WAVELENGTH = 24

export default {
	name: 'WaveLoading',

	methods: {
		/**
		 * A wave line one wavelength wider than the box on the left, so
		 * sliding it right by one wavelength looks like it never moved.
		 *
		 * @param {number} y the line's resting height
		 * @return {string} path data
		 */
		wave(y) {
			const half = WAVELENGTH / 2
			let d = `M${-WAVELENGTH} ${y}q${half / 2}-7 ${half} 0`
			for (let x = -WAVELENGTH + half; x < 96; x += half) {
				d += `t${half} 0`
			}
			return d
		},
	},
}
</script>

<style scoped>
.wave-loading {
	display: block;
	margin: 20px auto;
	color: color-mix(in oklab, #138a86 70%, var(--color-main-text));
	/* the line fades in and out at the ends rather than being cut off */
	mask-image: linear-gradient(to right, transparent, #000 25%, #000 75%, transparent);
}

.wave-loading__crest,
.wave-loading__swell {
	stroke: currentColor;
	stroke-width: 2.5;
	stroke-linecap: round;
	animation: wave-roll 1.1s linear infinite;
}

.wave-loading__swell {
	opacity: .35;
	animation-duration: 1.7s;
}

@keyframes wave-roll {
	from { transform: translateX(0); }
	to { transform: translateX(24px); }
}

@media (prefers-reduced-motion: reduce) {
	.wave-loading__crest,
	.wave-loading__swell {
		animation: none;
	}
}
</style>
