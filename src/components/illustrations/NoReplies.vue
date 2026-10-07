<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		No replies yet: a single shell on the sand. Markup in `currentColor`
		with the page's own background inside the shell, so it follows the
		theme without a filter. The full-size scenes are in AlohaScene.
	-->
	<svg
		class="no-replies"
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
			<path class="no-replies__shell" d="M14 30C14 12 58 12 58 30C58 38 46 46 36 48C26 46 14 38 14 30Z" />
			<path class="no-replies__ribs" d="M36 48L19 24M36 48L27 17M36 48V14M36 48L45 17M36 48L53 24" />
			<path class="no-replies__shell" d="M30 48h12l-2 5h-8z" />
		</g>
	</svg>
</template>

<script>
export default {
	name: 'NoReplies',
}
</script>

<style scoped>
.no-replies {
	color: var(--color-text-maxcontrast);
}

.no-replies__shell {
	fill: var(--color-main-background);
}

.no-replies__ribs {
	opacity: .55;
}
</style>
