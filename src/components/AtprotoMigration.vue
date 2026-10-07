<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<section v-if="modelValue" role="dialog" :aria-label="t('social', 'Bluesky account migration')">
		<h2>{{ t('social', 'Bluesky account migration') }}</h2>
		<p>{{ t('social', 'Account migration is not available in this draft. Your existing account and PDS remain unchanged.') }}</p>
		<NcButton @click="emit('update:modelValue', false)">
			{{ t('social', 'Close') }}
		</NcButton>
	</section>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
defineProps({ modelValue: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue'])
</script>
