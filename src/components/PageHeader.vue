<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<header class="page-header" :class="{ 'page-header--clear-navigation': clearNavigation, 'page-header--hide-title': hideTitle }">
		<div class="page-header__copy">
			<h1 class="page-header__title" :class="{ 'hidden-visually': hideTitle }" dir="auto">
				{{ title }}
			</h1>
			<p v-if="description" class="page-header__description">
				{{ description }}
			</p>
		</div>
		<slot name="actions" />
	</header>
</template>

<script setup>
defineProps({
	title: { type: String, required: true },
	description: { type: String, default: '' },
	clearNavigation: { type: Boolean, default: false },
	hideTitle: { type: Boolean, default: false },
})
</script>

<style scoped lang="scss">
@use '../styles/layout.scss';

.page-header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 4);
	color: var(--color-main-text);

	&__copy {
		flex: 1 1 auto;
		min-width: 0;
		max-width: 100%;
	}

	&__title {
		margin: 0;
		color: var(--social-page-title-color, var(--color-main-text));
		font-size: 1.5rem;
		font-weight: 700;
		line-height: 1.25;
		overflow-wrap: anywhere;
	}

	&__description {
		max-width: 70ch;
		margin: var(--default-grid-baseline, 4px) 0 0;
		color: var(--color-text-maxcontrast);
		overflow-wrap: anywhere;
	}
}

.page-header--hide-title .page-header__copy {
	flex: 0 0 auto;
}

@include layout.below(layout.$folded) {
	.page-header--clear-navigation {
		padding-block-start: calc(var(--default-clickable-area, 44px) + var(--default-grid-baseline, 4px) * 2);
	}
}
</style>
