<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		A timeline with nobody in it yet: one drop falling on still water,
		the rings it makes fading as they spread. Markup in `currentColor`,
		like NoReplies.
	-->
	<svg
		class="quiet-timeline"
		viewBox="0 0 72 56"
		width="72"
		height="56"
		fill="none"
		aria-hidden="true"
		focusable="false">
		<g stroke="currentColor" stroke-width="2" stroke-linecap="round">
			<ellipse
				class="quiet-timeline__far"
				cx="36"
				cy="38"
				rx="28"
				ry="8" />
			<ellipse
				class="quiet-timeline__mid"
				cx="36"
				cy="38"
				rx="18"
				ry="5" />
			<ellipse
				cx="36"
				cy="38"
				rx="7"
				ry="2" />
			<path class="quiet-timeline__drop" d="M36 10c-3 5-5 8-5 11a5 5 0 0 0 10 0c0-3-2-6-5-11z" />
		</g>
	</svg>
</template>

<script>
export default {
	name: 'QuietTimeline',
}
</script>

<style scoped>
.quiet-timeline {
	color: var(--color-text-maxcontrast);
}

/* the rings fade as they spread */
.quiet-timeline__far {
	opacity: .3;
}

.quiet-timeline__mid {
	opacity: .6;
}

.quiet-timeline__drop {
	fill: var(--color-main-background);
}
</style>
