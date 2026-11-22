<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="profile-drafts">
		<TimelineSwitcher
			:options="kinds"
			value="drafts"
			:label="t('social', 'Which of their posts to show')" />
		<section
			v-for="section in sections"
			:id="section.id"
			:key="section.id"
			class="profile-drafts__section">
			<h2 class="profile-drafts__title">
				{{ section.title }}
			</h2>
			<p class="profile-drafts__lede">
				{{ section.lede }}
			</p>
			<component :is="section.component" v-bind="section.props" />
		</section>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { defineAsyncComponent } from 'vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import { isOwnProfile, profileKinds } from '../composables/useProfileKinds.js'
import { useTimelineStore } from '../store/timeline.js'

/**
 * The owner's posts that are not out (yet): drafts, scheduled posts, posts a
 * moderator still has to look at, and archived posts.
 */
export default {
	name: 'ProfileDrafts',

	components: {
		ArchivedPosts: defineAsyncComponent(() => import('../components/ArchivedPosts.vue')),
		DraftsButton: defineAsyncComponent(() => import('../components/DraftsButton.vue')),
		HeldPosts: defineAsyncComponent(() => import('../components/HeldPosts.vue')),
		ScheduledPosts: defineAsyncComponent(() => import('../components/ScheduledPosts.vue')),
		TimelineSwitcher,
	},

	computed: {
		account() {
			return String(this.$route.params.account ?? '')
		},

		kinds() {
			return profileKinds(this.account)
		},

		sections() {
			return [
				{
					id: 'drafts',
					component: 'DraftsButton',
					props: { inline: true, disabled: useTimelineStore().composerDisplayStatus },
					title: t('social', 'Drafts'),
					lede: t('social', 'Posts, replies and quotes you started and have not sent yet. Continue one in the composer or delete it.'),
				},
				{
					id: 'scheduled',
					component: 'ScheduledPosts',
					title: t('social', 'Scheduled posts'),
					lede: t('social', 'What you have written to be published later. Move one to another time or cancel it here.'),
				},
				{
					id: 'review',
					component: 'HeldPosts',
					title: t('social', 'Waiting for review'),
					lede: t('social', 'Posts a moderator looks at before they go out, such as the first post of a new account. You can take one back.'),
				},
				{
					id: 'archive',
					component: 'ArchivedPosts',
					title: t('social', 'Archived posts'),
					lede: t('social', 'Posts you put away. They are off your profile and your timelines here, but not deleted on other servers. Put one back any time.'),
				},
			]
		},
	},

	beforeMount() {
		if (!isOwnProfile(this.account)) {
			this.$router.replace({ name: 'profile', params: { account: this.account } })
		}
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.profile-drafts__section {
	max-width: var(--social-column);
	margin: 0 auto 24px;
	padding: 0 10px;
}

.profile-drafts__title {
	margin: 16px 0 4px;
	font-size: 1.2em;
}

.profile-drafts__lede {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}
</style>
