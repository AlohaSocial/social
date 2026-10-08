<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcNoteCard v-if="items.length === 0" type="success">
			{{ t('social', 'Nothing is waiting for you. Reports, posts held for review, registrations and deliveries are all in order.') }}
		</NcNoteCard>

		<ul v-else class="attention">
			<li v-for="item in items" :key="item.section">
				<a
					:href="`#${item.section}`"
					class="attention__item"
					:class="`attention__item--${item.type}`"
					@click="go(item.section, $event)">
					<span class="attention__count">{{ item.count }}</span>
					<span class="attention__text">{{ item.text }}</span>
					<IconChevronRight class="attention__go" :size="20" />
				</a>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import IconChevronRight from 'vue-material-design-icons/ChevronRight.vue'

/**
 * What needs an administrator now, each line a link to the section that
 * deals with it.
 */
export default {
	name: 'AttentionSection',

	components: {
		IconChevronRight,
		NcNoteCard,
	},

	props: {
		/** what is waiting, each naming the section that deals with it */
		items: {
			type: /** @type {import('vue').PropType<Array<{section: string, count: number, text: string, type: 'error'|'warning'|'info'}>>} */ (Array),
			default: () => [],
		},
	},

	emits: ['go'],

	methods: {
		t,

		/**
		 * @param {string} section the section to open
		 * @param {MouseEvent} event the click
		 */
		go(section, event) {
			event.preventDefault()
			this.$emit('go', section)
		},
	},
}
</script>

<style lang="scss" scoped>
.attention {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	list-style: none;
	margin: 0;
	padding: 0;

	&__item {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
		padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
		border: 1px solid var(--color-border);
		border-inline-start-width: 4px;
		border-radius: var(--border-radius-large);
		color: var(--color-main-text);
		text-decoration: none;

		&:hover,
		&:focus-visible {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		&--error {
			border-inline-start-color: var(--color-element-error, var(--color-error));
		}

		&--warning {
			border-inline-start-color: var(--color-element-warning, var(--color-warning));
		}

		&--info {
			border-inline-start-color: var(--color-element-info, var(--color-primary-element));
		}
	}

	&__count {
		min-width: 2ch;
		font-size: 20px;
		font-weight: bold;
		font-variant-numeric: tabular-nums;
	}

	&__text {
		flex: 1 1 auto;
	}

	&__go {
		color: var(--color-text-maxcontrast);
	}
}
</style>
