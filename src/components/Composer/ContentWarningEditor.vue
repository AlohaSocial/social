<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="content-warning-row">
		<div class="content-warning-row__field">
			<input
				:value="value"
				type="text"
				class="content-warning"
				dir="auto"
				maxlength="200"
				:aria-label="t('social', 'Content warning')"
				:placeholder="t('social', 'Content warning, e.g. what the post is about')"
				@input="$emit('update:value', valueOf($event))">
			<NcButton
				variant="tertiary"
				class="content-warning-row__remove"
				:title="t('social', 'Remove the content warning')"
				:aria-label="t('social', 'Remove the content warning')"
				@click.prevent="$emit('remove')">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>
		<ul class="content-warning-presets" :aria-label="t('social', 'Common content warnings')">
			<li v-for="preset in presets" :key="preset">
				<button
					type="button"
					class="content-warning-presets__item"
					:class="{ 'content-warning-presets__item--active': value === preset }"
					:aria-pressed="value === preset ? 'true' : 'false'"
					@click="$emit('choose', preset)">
					{{ preset }}
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { valueOf } from '../../utils/dom.js'

/** Edits the warning text while the composer retains it in drafts. */
export default {
	name: 'ContentWarningEditor',

	components: { Close, NcButton },

	props: {
		value: { type: String, default: '' },
		presets: {
			/** @type {import('vue').PropType<string[]>} */
			type: Array,
			required: true,
		},
	},

	emits: ['update:value', 'remove', 'choose'],

	setup() {
		return { t, valueOf }
	},
}
</script>

<style lang="scss" scoped>
/* the box and the presses that fill it in are one thing on the form */
.content-warning-row {
	margin-bottom: 6px;

	&__field {
		display: flex;
		align-items: center;
		gap: 4px;
	}

	.content-warning {
		flex-grow: 1;
		margin-bottom: 4px;
	}
}

.content-warning-presets {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.content-warning-presets__item {
	margin: 0;
	padding: 2px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 20px;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
		color: var(--color-main-text);
	}

	/* The active preset is visible and can be pressed again to clear it. */
	&--active {
		border-color: var(--color-primary-element);
		background: var(--color-primary-element-light);
		color: var(--color-main-text);
	}
}
</style>
