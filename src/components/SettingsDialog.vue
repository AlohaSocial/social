<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		size="normal"
		closeOnClickOutside
		:labelId="headingId"
		@close="$emit('close')">
		<div class="settings-dialog">
			<h2 :id="headingId" class="settings-dialog__title">
				{{ title }}
			</h2>
			<p v-if="description" class="settings-dialog__description">
				{{ description }}
			</p>
			<slot />
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'

/**
 * A setting edited where it is used rather than in Settings: the lists from
 * the sidebar, featured hashtags from the profile, invitations from Discover.
 */
export default {
	name: 'SettingsDialog',

	components: {
		NcModal,
	},

	props: {
		title: { type: String, required: true },
		description: { type: String, default: '' },
	},

	emits: ['close'],

	computed: {
		headingId() {
			return `settings-dialog-${this.$.uid}`
		},
	},
}
</script>

<style scoped lang="scss">
.settings-dialog {
	padding: 20px 24px 24px;

	&__title {
		margin: 0 0 4px;
		font-size: 1.3em;
	}

	&__description {
		margin: 0 0 16px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
