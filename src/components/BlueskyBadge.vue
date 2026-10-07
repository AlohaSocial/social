<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<template>
	<span v-if="showBadge(actor)" class="bluesky-badge" :title="title">
		<component :is="BlueskyIcon" class="bluesky-badge-icon" />
		<span class="bluesky-badge-text">{{ handle }}</span>
	</span>
</template>

<script setup>
import { computed } from 'vue'
import { useBlueskyBadge } from '../composables/useBlueskyBadge.js'

const props = defineProps({
	actor: {
		type: Object,
		required: true,
	},
	size: {
		type: String,
		default: 'normal',
		validator: (value) => ['small', 'normal', 'large'].includes(String(value)),
	},
})

const { showBadge, getBadgeProps, blueskyIcon } = useBlueskyBadge()

const badgeProps = computed(() => getBadgeProps(props.actor))
const handle = computed(() => badgeProps.value.handle)
const title = computed(() => badgeProps.value.title)

const BlueskyIcon = {
	template: blueskyIcon,
}
</script>

<style scoped>
.bluesky-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--bluesky-badge-bg, linear-gradient(135deg, #0085ff, #00d4ff));
	color: white;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 500;
	line-height: 1;
	white-space: nowrap;
}

.bluesky-badge-small {
	padding: 1px 6px;
	font-size: 0.7rem;
}

.bluesky-badge-large {
	padding: 4px 12px;
	font-size: 0.85rem;
}

.bluesky-badge-icon {
	flex-shrink: 0;
	width: 14px;
	height: 14px;
}

.bluesky-badge-small .bluesky-badge-icon {
	width: 12px;
	height: 12px;
}

.bluesky-badge-large .bluesky-badge-icon {
	width: 16px;
	height: 16px;
}

.bluesky-badge-text {
	font-family: var(--font-family, system-ui);
}
</style>
