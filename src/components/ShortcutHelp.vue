<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal v-if="open" :name="t('social', 'Keyboard shortcuts')" @close="$emit('close')">
		<div class="shortcuts">
			<h2>{{ t('social', 'Keyboard shortcuts') }}</h2>
			<ShortcutsSettings />
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import { translate as t } from '@nextcloud/l10n'
import ShortcutsSettings from './ShortcutsSettings.vue'

/**
 * The `?` dialog over whatever the reader was looking at: the switch that
 * turns the shortcuts on or off, and the list of them.
 */
export default {
	name: 'ShortcutHelp',
	components: {
		NcModal,
		ShortcutsSettings,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.shortcuts {
	padding: 24px 32px 32px;

	h2 {
		margin-bottom: 16px;
		font-size: 20px;
		font-weight: bold;
	}
}
</style>
