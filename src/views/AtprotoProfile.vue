<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="atproto-profile">
		<Composer
			v-if="viewerCanFollow && (viewerCanEdit || replyTo !== null)"
			:inReplyTo="replyTo"
			:startExpanded="replyTo !== null"
			@posted="replyTo = null" />
		<header class="atproto-profile__header">
			<h2>@{{ profile.handle || handle }}</h2>
			<p v-if="profile.displayName">
				{{ profile.displayName }}
			</p>
			<form v-if="viewerCanEdit" class="atproto-profile__editor" @submit.prevent="saveProfile">
				<NcTextField
					v-model="editProfile.displayName"
					:label="t('social', 'Bluesky display name')"
					:disabled="savingProfile" />
				<NcTextArea
					v-model="editProfile.description"
					:label="t('social', 'Bluesky profile description')"
					:disabled="savingProfile" />
				<NcButton type="submit" variant="secondary" :disabled="savingProfile">
					{{ savingProfile ? t('social', 'Saving…') : t('social', 'Edit Bluesky profile') }}
				</NcButton>
				<p v-if="profileError" class="atproto-profile__error" role="alert">
					{{ profileError }}
				</p>
			</form>
			<div class="atproto-profile__actions">
				<AtprotoFollowButton
					v-if="profile.did && viewerCanFollow"
					:handle="profile.handle || handle"
					:initialFollowing="dataFollowing" />
				<a v-if="!viewerCanFollow" :href="settingsUrl">{{ t('social', 'Connect your Bluesky account to like, reply or repost') }}</a>
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
			<ProfileStatusCard
				v-for="status in statuses"
				:key="status.id"
				:status="status"
				:canDelete="viewerCanEdit"
				:nativeDelete="viewerCanEdit"
				:canEdit="viewerCanEdit"
				:nativeEdit="viewerCanEdit"
				@deleted="statuses = statuses.filter((entry) => entry.id !== status.id)"
				@updated="statuses = statuses.map((entry) => entry.id === $event.id ? $event : entry)"
				@reply="replyTo = $event" />
		</ul>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ProfileStatusCard from '../components/ProfileStatusCard.vue'
import AtprotoFollowButton from '../components/AtprotoFollowButton.vue'
import Composer from '../components/Composer/Composer.vue'

export default {
	name: 'AtprotoProfile',
	components: { AtprotoFollowButton, Composer, NcButton, NcTextArea, NcTextField, ProfileStatusCard },
	props: { handle: { type: String, required: true } },
	data: () => ({ account: {}, profile: {}, statuses: [], following: false, viewerCanFollow: false, viewerCanEdit: false, replyTo: null, loading: true, error: '', profileError: '', savingProfile: false, editProfile: { displayName: '', description: '' } }),
	computed: {
		dataFollowing() {
			return this.following
		},

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
			this.following = data.following === true
			this.viewerCanFollow = data.viewerCanFollow === true
			this.viewerCanEdit = data.viewerCanEdit === true
			this.editProfile = {
				displayName: this.profile.displayName ?? '',
				description: this.profile.description ?? '',
			}
		} catch (error) {
			this.error = error?.response?.data?.message ?? t('social', 'Could not load this Bluesky profile')
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		async saveProfile() {
			if (this.savingProfile) {
				return
			}
			this.savingProfile = true
			this.profileError = ''
			try {
				const { data } = await axios.put(generateUrl('apps/social/api/v1/atproto/profile'), this.editProfile)
				this.profile = { ...this.profile, ...(data.profile ?? this.editProfile) }
				this.editProfile = {
					displayName: this.profile.displayName ?? '',
					description: this.profile.description ?? '',
				}
			} catch (error) {
				this.profileError = error?.response?.data?.message ?? t('social', 'Could not save your Bluesky profile')
			} finally {
				this.savingProfile = false
			}
		},
	},
}
</script>

<style scoped>
.atproto-profile__editor {
	display: grid;
	gap: 8px;
	margin: 12px 0;
}

.atproto-profile__error {
	color: var(--color-error);
}
</style>
