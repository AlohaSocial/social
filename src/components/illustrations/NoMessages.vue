<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		Nothing private yet: a message in a bottle on the water. Markup in
		`currentColor` with the page's own background inside the bottle, like
		NoReplies; the full-size bottle is a scene in AlohaScene.
	-->
	<svg
		class="no-messages"
		viewBox="0 0 72 56"
		width="72"
		height="56"
		fill="none"
		aria-hidden="true"
		focusable="false">
		<g
			stroke="currentColor"
			stroke-width="2"
			stroke-linecap="round"
			stroke-linejoin="round">
			<g transform="rotate(-22 36 28)">
				<rect
					class="no-messages__bottle"
					x="12"
					y="20"
					width="34"
					height="18"
					rx="7" />
				<path class="no-messages__bottle" d="M46 25h8a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-8" />
				<path d="M56 26h4v6h-4" />
				<path class="no-messages__note" d="M19 27h18M19 31h12" />
			</g>
			<path class="no-messages__water" d="M6 48q5-3 10 0t10 0t10 0t10 0t10 0t10 0" />
		</g>
	</svg>
</template>

<script>
export default {
	name: 'NoMessages',
}
</script>

<style scoped>
.no-messages {
	color: var(--color-text-maxcontrast);
}

.no-messages__bottle {
	fill: var(--color-main-background);
}

.no-messages__note {
	opacity: .55;
}

.no-messages__water {
	opacity: .4;
}
</style>
