<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		Nobody here yet: an outrigger canoe with room for people in it.
		Markup in `currentColor`, like NoReplies.
	-->
	<svg
		class="nobody-yet"
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
			<path class="nobody-yet__hull" d="M6 32Q36 44 66 32Q61 40 36 41Q11 40 6 32Z" />
			<path class="nobody-yet__float" d="M14 16Q36 12 58 16Q36 20 14 16Z" />
			<path class="nobody-yet__float" d="M24 18V35M48 18V35" />
			<path class="nobody-yet__water" d="M8 50q5-3 10 0t10 0t10 0t10 0t10 0" />
		</g>
	</svg>
</template>

<script>
export default {
	name: 'NobodyYet',
}
</script>

<style scoped>
.nobody-yet {
	color: var(--color-text-maxcontrast);
}

.nobody-yet__hull {
	fill: var(--color-main-background);
}

.nobody-yet__float {
	opacity: .7;
}

.nobody-yet__water {
	opacity: .4;
}
</style>
