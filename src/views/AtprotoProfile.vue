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
			<img
				v-if="profile.avatar"
				class="atproto-profile__avatar"
				:src="profile.avatar"
				:alt="profile.displayName || profile.handle || handle"
				loading="lazy">
			<h2>@{{ profile.handle || handle }}</h2>
			<p v-if="profile.displayName">
				{{ profile.displayName }}
			</p>
			<p v-if="profile.description" class="atproto-profile__description">
				{{ profile.description }}
			</p>
			<dl v-if="profile.followersCount !== undefined || profile.followsCount !== undefined || profile.postsCount !== undefined" class="atproto-profile__stats">
				<div>
					<dt>{{ t('social', 'Posts') }}</dt>
					<dd>{{ profile.postsCount || 0 }}</dd>
				</div>
				<div>
					<dt>{{ t('social', 'Followers') }}</dt>
					<dd>{{ profile.followersCount || 0 }}</dd>
				</div>
				<div>
					<dt>{{ t('social', 'Following') }}</dt>
					<dd>{{ profile.followsCount || 0 }}</dd>
				</div>
			</dl>
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
				<a v-if="viewerCanEdit && fediverseProfileUrl" :href="fediverseProfileUrl">
					{{ t('social', 'Open Fediverse profile') }}
				</a>
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
			<NcButton variant="secondary" :disabled="loading" @click="loadProfile">
				{{ t('social', 'Try again') }}
			</NcButton>
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
		<p v-if="loadMoreError" class="atproto-profile__error" role="alert">
			{{ loadMoreError }}
		</p>
		<NcButton
			v-if="nextCursor"
			class="atproto-profile__load-more"
			variant="secondary"
			:disabled="loadingMore"
			@click="loadMore">
			{{ loadingMore ? t('social', 'Loading…') : t('social', 'Load more Bluesky posts') }}
		</NcButton>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
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
	data: () => ({ account: {}, profile: {}, statuses: [], nextCursor: '', following: false, viewerCanFollow: false, viewerCanEdit: false, replyTo: null, loading: true, loadingMore: false, error: '', loadMoreError: '', profileError: '', savingProfile: false, editProfile: { displayName: '', description: '' } }),
	computed: {
		fediverseProfileUrl() {
			const uid = getCurrentUser()?.uid ?? window.OC?.getCurrentUser?.()?.uid ?? ''
			return uid ? generateUrl('/@' + encodeURIComponent(uid)) : ''
		},

		dataFollowing() {
			return this.following
		},

		settingsUrl() {
			return generateUrl('apps/social/settings') + '#bluesky'
		},
	},

	async mounted() {
		await this.loadProfile()
	},

	methods: {
		t,
		async loadProfile() {
			this.loading = true
			this.error = ''
			this.loadMoreError = ''
			this.nextCursor = ''
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/atproto/profiles/${encodeURIComponent(this.handle)}`))
				this.profile = data.profile ?? {}
				this.account = data.account ?? {}
				this.statuses = data.statuses ?? []
				this.nextCursor = data.nextCursor ?? ''
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

		async loadMore() {
			if (this.loadingMore || !this.nextCursor) {
				return
			}
			this.loadingMore = true
			this.loadMoreError = ''
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/atproto/profiles/${encodeURIComponent(this.handle)}`), { params: { cursor: this.nextCursor } })
				const known = new Set(this.statuses.map((status) => status.id))
				this.statuses = this.statuses.concat((data.statuses ?? []).filter((status) => !known.has(status.id)))
				this.nextCursor = data.nextCursor ?? ''
			} catch (error) {
				this.loadMoreError = error?.response?.data?.message ?? t('social', 'Could not load more Bluesky posts')
			} finally {
				this.loadingMore = false
			}
		},

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

.atproto-profile__avatar {
	width: 80px;
	height: 80px;
	border-radius: 50%;
	object-fit: cover;
}

.atproto-profile__description {
	white-space: pre-wrap;
}

.atproto-profile__stats {
	display: flex;
	gap: 1.25rem;
	margin: 0.75rem 0;
}

.atproto-profile__stats div {
	display: flex;
	gap: 0.35rem;
}

.atproto-profile__stats dt {
	font-weight: 600;
}

.atproto-profile__stats dd {
	margin: 0;
}

.atproto-profile__error {
	color: var(--color-error);
}
</style>
