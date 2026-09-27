<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="filter-picker"
		role="radiogroup"
		:aria-label="t('social', 'Filter')">
		<button
			v-for="filter in filters"
			:key="filter.id"
			type="button"
			role="radio"
			class="filter-picker__option"
			:class="{ 'filter-picker__option--active': filter.id === modelValue }"
			:aria-checked="filter.id === modelValue"
			:title="filter.name"
			@click="$emit('update:modelValue', filter.id)">
			<span class="filter-picker__swatch">
				<img
					v-if="preview"
					class="filter-picker__image"
					:src="preview"
					:style="{ filter: filter.css }"
					alt=""
					aria-hidden="true"
					decoding="async">
				<span
					v-else
					class="filter-picker__placeholder"
					:style="{ filter: filter.css }"
					aria-hidden="true" />
			</span>
			<span class="filter-picker__name">{{ filter.name }}</span>
		</button>
	</div>
</template>

<script>
import { availableFilters } from '../../utils/imageFilters.js'
import { t } from '@nextcloud/l10n'

/**
 * The filters, each drawn over the actual picture being posted.
 *
 * Every swatch is the same `<img>` with a different `filter:` on it, so showing
 * eight of them costs one decode and no canvas work at all — which is what makes
 * it reasonable to render the real photo in every swatch rather than a generic
 * gradient. The filter is baked in once, on send.
 */
export default {
	name: 'FilterPicker',

	props: {
		/** The chosen filter id. */
		modelValue: {
			type: String,
			default: 'none',
		},

		/** A URL for the picture being filtered, for the swatches. */
		preview: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue'],

	computed: {
		filters() {
			return availableFilters()
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.filter-picker {
	display: flex;
	gap: var(--default-grid-baseline);
	overflow-x: auto;
	padding-block: var(--default-grid-baseline);
	// a filter strip is a horizontal scroller by nature; keep it from stealing
	// the vertical scroll of the composer around it
	overscroll-behavior-x: contain;

	&__option {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 2px;
		padding: 2px;
		border: none;
		background: none;
		cursor: pointer;
		flex: 0 0 auto;

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			border-radius: var(--border-radius);
		}
	}

	&__swatch {
		width: 48px;
		height: 48px;
		border-radius: var(--border-radius);
		overflow: hidden;
		border: 2px solid transparent;
		background-color: var(--color-background-dark);

		.filter-picker__option--active & {
			border-color: var(--color-primary-element);
		}
	}

	&__image,
	&__placeholder {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
	}

	&__placeholder {
		background: linear-gradient(135deg, var(--color-primary-element), var(--color-background-darker));
	}

	&__name {
		font-size: var(--font-size-small, 0.85em);
		color: var(--color-text-maxcontrast);

		.filter-picker__option--active & {
			color: var(--color-main-text);
			font-weight: bold;
		}
	}
}
</style>
