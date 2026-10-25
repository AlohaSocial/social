<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="for-you-settings">
		<NcNoteCard v-if="recommendationsEnabled" type="info">
			{{ t('social', 'For You builds one automatic ranking from the hashtags you read and interact with, post content, freshness, follows, discovery and any activity learning you enable. You do not need to choose topics or configure the feed.') }}
		</NcNoteCard>
		<NcNoteCard v-else type="info">
			{{ t('social', 'For You uses your automatically learned hashtag interests in one personalized ranking. The administrator has not enabled the newer recommendation signals on this instance.') }}
		</NcNoteCard>

		<InterestsSettings v-if="legacyEnabled" />
		<NcNoteCard v-else type="warning">
			{{ t('social', 'Automatic interest learning is disabled on this instance, so there is no learned profile to show yet.') }}
		</NcNoteCard>

		<details v-if="recommendationsEnabled" class="for-you-settings__learning">
			<summary>{{ t('social', 'Behavioral learning and data controls') }}</summary>
			<p>{{ t('social', 'Likes, boosts, saves, opening a post and measured video playback can refine the same feed. Qualified views help limit repeated stories; they are not treated as proof that you like a post. This additional activity profile is off until you turn it on.') }}</p>
			<ActivityLearningSettings />
		</details>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { useServerData } from '../composables/useServerData.js'
import InterestsSettings from './InterestsSettings.vue'
import ActivityLearningSettings from './ActivityLearningSettings.vue'

export default {
	name: 'ForYouSettings',

	components: {
		InterestsSettings,
		NcNoteCard,
		ActivityLearningSettings,
	},

	setup() {
		const { serverData } = useServerData()
		return { serverData }
	},

	computed: {
		recommendationsEnabled() {
			return this.serverData?.interests?.recommendationsEnabled === true
		},

		legacyEnabled() {
			return this.serverData?.interests?.enabled === true
		},
	},

	methods: { t },
}
</script>

<style lang="scss" scoped>
.for-you-settings {
	> :deep(*) {
		margin-block-end: 1rem;
	}

	&__learning {
		padding: 0.75rem 1rem;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);

		summary {
			min-height: 44px;
			display: flex;
			align-items: center;
			font-weight: 600;
			cursor: pointer;
		}
	}
}
</style>
