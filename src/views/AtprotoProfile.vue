<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="atproto-profile">
		<header class="atproto-profile__header">
			<h2>@{{ profile.handle || handle }}</h2>
			<p v-if="profile.displayName">
				{{ profile.displayName }}
			</p>
			<div class="atproto-profile__actions">
				<FollowButton v-if="account.id" :profileAccount="account" />
				<a :href="settingsUrl">{{ t('social', 'Connect your Bluesky account to like, reply or repost') }}</a>
			</div>
		</header>
		<p v-if="loading" role="status">
			{{ t('social', 'Loading Bluesky profile…') }}
		</p>
		<p v-else-if="error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="statuses.length === 0">
			{{ t('social', 'No public Bluesky posts yet.') }}
		</p>
		<ul v-else class="atproto-profile__timeline">
			<ProfileStatusCard v-for="status in statuses" :key="status.id" :status="status" />
		</ul>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import ProfileStatusCard from '../components/ProfileStatusCard.vue'
import FollowButton from '../components/FollowButton.vue'

export default {
	name: 'AtprotoProfile',
	components: { FollowButton, ProfileStatusCard },
	props: { handle: { type: String, required: true } },
	data: () => ({ account: {}, profile: {}, statuses: [], loading: true, error: '' }),
	computed: {
		settingsUrl() {
			return generateUrl('apps/social/settings') + '#bluesky'
		},
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl(`apps/social/api/v1/atproto/profiles/${encodeURIComponent(this.handle)}`))
			this.profile = data.profile ?? {}
			this.account = data.account ?? {}
			this.statuses = data.statuses ?? []
		} catch (error) {
			this.error = error?.response?.data?.message ?? t('social', 'Could not load this Bluesky profile')
		} finally {
			this.loading = false
		}
	},

	methods: { t },
}
</script>
