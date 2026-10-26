<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="!dismissed" class="info-card" :aria-label="title">
		<header class="info-card__header">
			<h2>{{ title }}</h2>
			<NcButton variant="tertiary" :aria-label="t('social', 'Hide {title}', { title })" @click="dismiss">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</header>
		<div class="info-card__body">
			<slot />
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import Close from 'vue-material-design-icons/Close.vue'
import { dismissInfoCard, infoCardDismissed } from '../services/infoCards.js'

export default {
	name: 'InfoCard',
	components: { NcButton, Close },
	props: {
		dismissKey: { type: String, required: true },
		title: { type: String, required: true },
	},

	emits: ['dismiss'],
	data() {
		return { dismissed: infoCardDismissed(this.dismissKey) }
	},

	watch: {
		dismissKey(key) {
			this.dismissed = infoCardDismissed(key)
		},
	},

	methods: {
		t,
		dismiss() {
			dismissInfoCard(this.dismissKey)
			this.dismissed = true
			this.$emit('dismiss')
		},
	},
}
</script>

<style scoped lang="scss">
.info-card {
	margin-block: 8px 12px;
	padding: 8px 12px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-width: 0;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;

		h2 {
			margin: 0;
			font-size: 14px;
			font-weight: 600;
			overflow-wrap: anywhere;
		}
	}

	&__body {
		font-size: 14px;
		line-height: 1.5;
		overflow-wrap: anywhere;

		:deep(p) { margin-block: 0; }
	}
}
</style>
