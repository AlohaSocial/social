<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="atproto-profile">
		<header class="atproto-profile__card">
			<img
				v-if="profile.banner"
				class="atproto-profile__banner"
				:src="profile.banner"
				:alt="t('social', 'Bluesky profile banner')"
				loading="lazy">
			<div v-else class="atproto-profile__banner-placeholder" aria-hidden="true" />
			<div class="atproto-profile__content">
				<div class="atproto-profile__avatar-frame">
					<img
						v-if="profile.avatar"
						class="atproto-profile__avatar"
						:src="profile.avatar"
						:alt="profile.displayName || shownHandle"
						loading="lazy">
					<span v-else class="atproto-profile__avatar-fallback" aria-hidden="true">{{ initials }}</span>
				</div>
				<h2 class="atproto-profile__name">
					{{ profile.displayName || '@' + shownHandle }}
				</h2>
				<p v-if="profile.displayName" class="atproto-profile__handle">
					@{{ shownHandle }}
				</p>
				<p v-if="profile.description" class="atproto-profile__description">
					{{ profile.description }}
				</p>
				<ul v-if="hasCounts" class="atproto-profile__stats">
					<li>
						<strong>{{ profile.postsCount || 0 }}</strong>
						{{ t('social', 'Posts') }}
					</li>
					<li>
						<strong>{{ profile.followsCount || 0 }}</strong>
						{{ t('social', 'Following') }}
					</li>
					<li>
						<strong>{{ profile.followersCount || 0 }}</strong>
						{{ t('social', 'Followers') }}
					</li>
				</ul>
				<div class="atproto-profile__actions">
					<AtprotoFollowButton
						v-if="profile.did && viewerCanFollow"
						:handle="shownHandle"
						:initialFollowing="dataFollowing" />
					<NcButton
						v-if="viewerCanEdit"
						type="button"
						variant="tertiary"
						:aria-expanded="editing ? 'true' : 'false'"
						@click="editing = !editing">
						{{ editing ? t('social', 'Close') : t('social', 'Edit Bluesky profile') }}
					</NcButton>
					<a
						v-if="shownHandle"
						:href="blueskyProfileUrl"
						target="_blank"
						rel="noreferrer">
						{{ t('social', 'Open on Bluesky') }}
					</a>
					<a v-if="viewerCanEdit && fediverseProfileUrl" :href="fediverseProfileUrl">
						{{ t('social', 'Open Fediverse profile') }}
					</a>
					<a v-if="!viewerCanFollow" class="atproto-profile__connect" :href="settingsUrl">
						{{ t('social', 'Connect your Bluesky account to like, reply or repost') }}
					</a>
				</div>
				<form v-if="viewerCanEdit && editing" class="atproto-profile__editor" @submit.prevent="saveProfile">
					<NcTextField
						v-model="editProfile.displayName"
						:label="t('social', 'Bluesky display name')"
						:disabled="savingProfile" />
					<NcTextArea
						v-model="editProfile.description"
						:label="t('social', 'Bluesky profile description')"
						:disabled="savingProfile" />
					<label class="atproto-profile__upload">
						{{ t('social', 'Bluesky avatar') }}
						<input
							type="file"
							accept="image/jpeg,image/png,image/gif,image/webp"
							:disabled="savingProfile"
							@change="selectProfileImage($event, 'avatar')">
						<NcButton
							type="button"
							variant="tertiary"
							:disabled="savingProfile"
							@click="profileImages.removeAvatar = true">
							{{ t('social', 'Remove Bluesky avatar') }}
						</NcButton>
					</label>
					<label class="atproto-profile__upload">
						{{ t('social', 'Bluesky banner') }}
						<input
							type="file"
							accept="image/jpeg,image/png,image/gif,image/webp"
							:disabled="savingProfile"
							@change="selectProfileImage($event, 'banner')">
						<NcButton
							type="button"
							variant="tertiary"
							:disabled="savingProfile"
							@click="profileImages.removeBanner = true">
							{{ t('social', 'Remove Bluesky banner') }}
						</NcButton>
					</label>
					<div class="atproto-profile__editor-actions">
						<NcButton
							type="button"
							variant="tertiary"
							:disabled="savingProfile"
							@click="editing = false">
							{{ t('social', 'Cancel') }}
						</NcButton>
						<NcButton type="submit" variant="primary" :disabled="savingProfile">
							{{ savingProfile ? t('social', 'Saving…') : t('social', 'Save Bluesky profile') }}
						</NcButton>
					</div>
					<p v-if="profileError" class="atproto-profile__error" role="alert">
						{{ profileError }}
					</p>
				</form>
			</div>
		</header>

		<Composer
			v-if="viewerCanFollow && (replyTo !== null || (viewerCanEdit && !embedded))"
			:inReplyTo="replyTo"
			:startExpanded="replyTo !== null"
			@posted="replyTo = null" />

		<div class="atproto-profile__posts">
			<p v-if="loading" role="status" class="atproto-profile__state">
				{{ t('social', 'Loading Bluesky profile…') }}
			</p>
			<p v-else-if="error" role="alert" class="atproto-profile__state atproto-profile__error">
				{{ error }}
				<NcButton variant="secondary" :disabled="loading" @click="loadProfile">
					{{ t('social', 'Try again') }}
				</NcButton>
			</p>
			<p v-else-if="statuses.length === 0" class="atproto-profile__empty">
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
		</div>
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
	props: {
		handle: { type: String, required: true },
		/** whether a surrounding profile already offers its own composer */
		embedded: { type: Boolean, default: false },
	},

	data: () => ({ account: {}, profile: {}, statuses: [], nextCursor: '', following: false, viewerCanFollow: false, viewerCanEdit: false, replyTo: null, loading: true, loadingMore: false, error: '', loadMoreError: '', profileError: '', savingProfile: false, editing: false, editProfile: { displayName: '', description: '' }, profileImages: { avatar: null, banner: null, removeAvatar: false, removeBanner: false } }),
	computed: {
		fediverseProfileUrl() {
			const uid = getCurrentUser()?.uid ?? window.OC?.getCurrentUser?.()?.uid ?? ''
			return uid ? generateUrl('/@' + encodeURIComponent(uid)) : ''
		},

		dataFollowing() {
			return this.following
		},

		shownHandle() {
			return this.profile.handle || this.handle
		},

		initials() {
			const source = (this.profile.displayName || this.shownHandle || '').replace(/^@/, '')
			return source.charAt(0).toUpperCase() || '?'
		},

		hasCounts() {
			return this.profile.followersCount !== undefined
				|| this.profile.followsCount !== undefined
				|| this.profile.postsCount !== undefined
		},

		settingsUrl() {
			return generateUrl('apps/social/settings') + '#bluesky'
		},

		blueskyProfileUrl() {
			return `https://bsky.app/profile/${encodeURIComponent(this.shownHandle)}`
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
				const body = new FormData()
				body.append('displayName', this.editProfile.displayName)
				body.append('description', this.editProfile.description)
				if (this.profileImages.avatar) {
					body.append('avatar', this.profileImages.avatar)
				}
				if (this.profileImages.banner) {
					body.append('banner', this.profileImages.banner)
				}
				if (this.profileImages.removeAvatar) {
					body.append('removeAvatar', '1')
				}
				if (this.profileImages.removeBanner) {
					body.append('removeBanner', '1')
				}
				// PHP parses multipart fields reliably on POST; the controller
				// exposes POST alongside JSON PUT specifically for this editor.
				const { data } = await axios.post(generateUrl('apps/social/api/v1/atproto/profile'), body)
				this.profile = { ...this.profile, ...(data.profile ?? this.editProfile) }
				this.profileImages = { avatar: null, banner: null, removeAvatar: false, removeBanner: false }
				this.editProfile = {
					displayName: this.profile.displayName ?? '',
					description: this.profile.description ?? '',
				}
				// The PDS assigns a new blob reference (and therefore a new public
				// URL) for uploads. Re-read the profile so replacement/removal is
				// visible immediately rather than after a manual page reload.
				await this.loadProfile()
			} catch (error) {
				this.profileError = error?.response?.data?.message ?? t('social', 'Could not save your Bluesky profile')
			} finally {
				this.savingProfile = false
			}
		},

		selectProfileImage(event, field) {
			const file = event?.target?.files?.[0]
			if (!file) {
				return
			}
			if (file.size > 1 * 1024 * 1024) {
				this.profileError = t('social', 'Profile images must be 1 MiB or smaller')
				if (event.target) {
					event.target.value = ''
				}

				return
			}
			this.profileError = ''
			this.profileImages[field] = file
		},
	},
}
</script>

<style scoped>
.atproto-profile {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 100%;
}

.atproto-profile__card {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 100%;
	max-width: var(--social-column);
	margin-block-end: calc(var(--default-grid-baseline) * 3);
	text-align: center;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
	position: relative;
}

.atproto-profile__banner,
.atproto-profile__banner-placeholder {
	display: block;
	width: 100%;
	min-height: 120px;
	max-height: 200px;
	object-fit: cover;
	background-color: var(--color-background-dark);
}

.atproto-profile__content {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 100%;
	padding: 56px calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4);
	position: relative;
	z-index: 1;
}

.atproto-profile__avatar-frame {
	position: absolute;
	top: -48px;
}

.atproto-profile__avatar,
.atproto-profile__avatar-fallback {
	box-sizing: border-box;
	width: 96px;
	height: 96px;
	border: 4px solid var(--color-main-background);
	border-radius: 50%;
	object-fit: cover;
}

.atproto-profile__avatar-fallback {
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 36px;
	font-weight: 700;
}

.atproto-profile__name {
	margin: 0 0 4px;
	font-size: 26px;
	font-weight: 700;
	letter-spacing: -.02em;
}

.atproto-profile__handle {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}

.atproto-profile__description {
	max-width: 60ch;
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
	white-space: pre-wrap;
}

.atproto-profile__stats {
	display: flex;
	flex-wrap: wrap;
	gap: 20px;
	justify-content: center;
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--color-text-lighter);
	font-size: 13px;
}

.atproto-profile__stats strong {
	color: var(--color-main-text);
	font-weight: 700;
}

.atproto-profile__stats li {
	display: flex;
	align-items: baseline;
	gap: 4px;
}

.atproto-profile__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	align-items: center;
	justify-content: center;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.atproto-profile__actions a {
	color: var(--color-main-text);
	font-size: 13px;
	text-decoration: none;
}

.atproto-profile__actions a:hover {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.atproto-profile__actions .atproto-profile__connect {
	color: var(--color-text-maxcontrast);
}

.atproto-profile__editor {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
	width: 100%;
	max-width: 480px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
	padding-block-start: calc(var(--default-grid-baseline) * 3);
	border-block-start: 1px solid var(--color-border);
	text-align: start;
}

.atproto-profile__upload {
	display: grid;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.atproto-profile__editor-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: flex-end;
}

.atproto-profile__posts {
	display: flex;
	flex-direction: column;
	align-items: stretch;
	width: 100%;
	max-width: var(--social-column);
	gap: calc(var(--default-grid-baseline) * 3);
}

.atproto-profile__state {
	margin: 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.atproto-profile__empty {
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 5) calc(var(--default-grid-baseline) * 4);
	text-align: center;
	color: var(--color-text-maxcontrast);
	border: 1px dashed var(--color-border);
	border-radius: 8px;
}

.atproto-profile__timeline {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	margin: 0;
	padding: 0;
	list-style: none;
}

.atproto-profile__load-more {
	align-self: center;
}

.atproto-profile__error {
	color: var(--color-error);
}
</style>
