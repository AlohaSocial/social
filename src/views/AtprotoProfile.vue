<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="atproto-profile" :class="{ 'atproto-profile--page': !embedded }">
		<!-- the same card the Fediverse profile draws: one page, one design,
		     and behind it either half of the reader's identity -->
		<div v-if="cardVisible" class="user-profile">
			<div
				class="user-profile__banner"
				aria-hidden="true"
				:class="{ 'user-profile__banner--visible': !!profile.banner }"
				:style="bannerStyle" />
			<div class="user-profile__content">
				<NcAvatar
					v-if="profile.avatar"
					:url="profile.avatar"
					:disableMenu="true"
					:disableTooltip="true"
					:size="128" />
				<div v-else class="atproto-profile__avatar-fallback" aria-hidden="true">
					{{ initials }}
				</div>
				<h2>
					{{ displayName }}<span v-if="profile.displayName && profile.handle" class="user-profile__pronouns"> @{{ profile.handle }}</span>
				</h2>
				<!-- the same three counters the Fediverse profile carries, in the
				     same words: a Bluesky profile has no lists of the people
				     behind them here, so they are counts rather than links -->
				<ul class="user-profile__info user-profile__sections">
					<li>
						<span class="user-profile__count">{{ postsLabel }}</span>
					</li>
					<li>
						<span class="user-profile__count">{{ followingLabel }}</span>
					</li>
					<li>
						<span class="user-profile__count">{{ followersLabel }}</span>
					</li>
				</ul>
				<div class="user-profile__actions">
					<AtprotoFollowButton
						v-if="canFollow"
						:handle="shownHandle"
						:initialFollowing="dataFollowing" />
					<NcButton
						v-if="viewerCanEdit"
						variant="tertiary"
						:disabled="savingProfile"
						@click="openProfileModal">
						<template #icon>
							<TableEdit :size="20" />
						</template>
						{{ t('social', 'Edit profile') }}
					</NcButton>
					<a
						v-if="shownHandle"
						class="user-profile__protocol-link"
						:href="blueskyProfileUrl"
						target="_blank"
						rel="noreferrer">
						{{ t('social', 'Open on Bluesky') }}
					</a>
					<a
						v-if="viewerCanEdit && fediverseProfileUrl"
						class="user-profile__protocol-link"
						:href="fediverseProfileUrl">
						{{ t('social', 'Open Fediverse profile') }}
					</a>
					<a
						v-if="!viewerCanFollow"
						class="user-profile__protocol-link"
						:href="settingsUrl">
						{{ t('social', 'Connect your Bluesky account to like, reply or repost') }}
					</a>
				</div>
				<!-- a bio rather than markup: a Bluesky description is plain
				     text, and it is written here, not federated -->
				<p v-if="profile.description" class="user-profile__note">
					{{ profile.description }}
				</p>

				<NcModal
					v-if="showProfileModal"
					:name="t('social', 'Edit profile')"
					@close="showProfileModal = false">
					<div class="user-profile__fields-modal">
						<h3>{{ t('social', 'Edit profile') }}</h3>
						<div class="user-profile__bio">
							<label class="user-profile__bio-label" for="social-atproto-display-name">
								{{ t('social', 'Display name') }}
							</label>
							<input
								id="social-atproto-display-name"
								v-model="editProfile.displayName"
								class="user-profile__bio-input"
								type="text"
								:disabled="savingProfile">
						</div>
						<div class="user-profile__bio">
							<label class="user-profile__bio-label" for="social-atproto-bio">
								{{ t('social', 'Bio') }}
							</label>
							<textarea
								id="social-atproto-bio"
								v-model="editProfile.description"
								class="user-profile__bio-input"
								rows="5"
								aria-describedby="social-atproto-bio-count"
								:aria-invalid="bioTooLong ? 'true' : 'false'"
								:disabled="savingProfile" />
							<span
								id="social-atproto-bio-count"
								class="user-profile__bio-count"
								:class="{ 'user-profile__bio-count--over': bioTooLong }"
								role="status">
								{{ bioCharactersLeftLabel }}
							</span>
						</div>
						<div class="user-profile__banner-edit">
							<span class="user-profile__banner-edit-label">{{ t('social', 'Avatar') }}</span>
							<div class="user-profile__banner-edit-url">
								<input
									type="file"
									accept="image/jpeg,image/png,image/gif,image/webp"
									:disabled="savingProfile"
									@change="selectProfileImage($event, 'avatar')">
								<NcButton
									variant="tertiary"
									:disabled="!profile.avatar || savingProfile"
									@click="profileImages.removeAvatar = true">
									{{ t('social', 'Remove') }}
								</NcButton>
							</div>
						</div>
						<div class="user-profile__banner-edit">
							<span class="user-profile__banner-edit-label">{{ t('social', 'Banner') }}</span>
							<div class="user-profile__banner-edit-url">
								<input
									type="file"
									accept="image/jpeg,image/png,image/gif,image/webp"
									:disabled="savingProfile"
									@change="selectProfileImage($event, 'banner')">
								<NcButton
									variant="tertiary"
									:disabled="!profile.banner || savingProfile"
									@click="profileImages.removeBanner = true">
									{{ t('social', 'Remove') }}
								</NcButton>
							</div>
						</div>
						<p v-if="profileError" class="atproto-profile__error" role="alert">
							{{ profileError }}
						</p>
						<div class="user-profile__fields-modal-actions">
							<NcButton variant="tertiary" :disabled="savingProfile" @click="showProfileModal = false">
								{{ t('social', 'Cancel') }}
							</NcButton>
							<NcButton variant="primary" :disabled="savingProfile || bioTooLong" @click="saveProfile">
								{{ savingProfile ? t('social', 'Saving…') : t('social', 'Save') }}
							</NcButton>
						</div>
					</div>
				</NcModal>
			</div>
		</div>

		<Composer
			v-if="viewerCanFollow && (replyTo !== null || viewerCanEdit)"
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
import { translate, translatePlural } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import TableEdit from 'vue-material-design-icons/TableEdit.vue'
import ProfileStatusCard from '../components/ProfileStatusCard.vue'
import AtprotoFollowButton from '../components/AtprotoFollowButton.vue'
import Composer from '../components/Composer/Composer.vue'
import { formatCount } from '../utils/number.js'

/** Mirrors the length `app.bsky.actor.profile.description` is capped at. */
const BIO_MAX_LENGTH = 300

export default {
	name: 'AtprotoProfile',
	components: { AtprotoFollowButton, Composer, NcAvatar, NcButton, NcModal, ProfileStatusCard, TableEdit },
	props: {
		handle: { type: String, required: true },
		/** whether a surrounding profile already offers its own composer */
		embedded: { type: Boolean, default: false },
	},

	data: () => ({
		account: {},
		profile: {},
		statuses: [],
		nextCursor: '',
		following: false,
		viewerCanFollow: false,
		viewerCanEdit: false,
		replyTo: null,
		loading: true,
		loadingMore: false,
		error: '',
		loadMoreError: '',
		profileError: '',
		savingProfile: false,
		showProfileModal: false,
		editProfile: { displayName: '', description: '' },
		profileImages: { avatar: null, banner: null, removeAvatar: false, removeBanner: false },
	}),

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

		displayName() {
			return this.profile.displayName || this.profile.handle || this.handle
		},

		initials() {
			const source = (this.displayName || '').replace(/^@/, '')
			return source.charAt(0).toUpperCase() || '?'
		},

		/**
		 * The card appears once there is a profile to draw, the way the
		 * Fediverse one waits for its account.
		 *
		 * @return {boolean}
		 */
		cardVisible() {
			return !this.loading && !this.error && !!this.shownHandle
		},

		/**
		 * Following oneself is not offered on the Fediverse profile, and is
		 * not offered here either: the same account owns both.
		 *
		 * @return {boolean}
		 */
		canFollow() {
			return this.viewerCanFollow && !this.viewerCanEdit && !!this.profile.did
		},

		bannerStyle() {
			return this.profile.banner
				? { backgroundImage: `url(${JSON.stringify(String(this.profile.banner))})` }
				: {}
		},

		/** @return {string} */
		postsLabel() {
			const count = Number(this.profile.postsCount) || 0

			return translatePlural('social', '{count} post', '{count} posts', count, { count: formatCount(count) })
		},

		/** @return {string} */
		followingLabel() {
			const count = Number(this.profile.followsCount) || 0

			return translatePlural('social', '{count} following', '{count} following', count, { count: formatCount(count) })
		},

		/** @return {string} */
		followersLabel() {
			const count = Number(this.profile.followersCount) || 0

			return translatePlural('social', '{count} follower', '{count} followers', count, { count: formatCount(count) })
		},

		/** @return {number} how many characters the bio has left */
		bioCharsLeft() {
			return BIO_MAX_LENGTH - [...(this.editProfile.description ?? '')].length
		},

		/** @return {boolean} */
		bioTooLong() {
			return this.bioCharsLeft < 0
		},

		/** @return {string} */
		bioCharactersLeftLabel() {
			return this.bioTooLong
				? this.n('social', '%n character too many', '%n characters too many', -this.bioCharsLeft)
				: this.n('social', '%n character left', '%n characters left', this.bioCharsLeft)
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
		t: translate,
		n: translatePlural,

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
				this.error = error?.response?.data?.message ?? translate('social', 'Could not load this Bluesky profile')
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
				this.loadMoreError = error?.response?.data?.message ?? translate('social', 'Could not load more Bluesky posts')
			} finally {
				this.loadingMore = false
			}
		},

		/**
		 * Opens the editor on the profile already on screen: there is nothing
		 * to fetch, which is why the Fediverse dialog is the only part of this
		 * page that ever waits.
		 */
		openProfileModal() {
			this.editProfile = {
				displayName: this.profile.displayName ?? '',
				description: this.profile.description ?? '',
			}
			this.profileImages = { avatar: null, banner: null, removeAvatar: false, removeBanner: false }
			this.profileError = ''
			this.showProfileModal = true
		},

		async saveProfile() {
			if (this.savingProfile || this.bioTooLong) {
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
				this.showProfileModal = false
				// The PDS assigns a new blob reference (and therefore a new public
				// URL) for uploads. Re-read the profile so replacement/removal is
				// visible immediately rather than after a manual page reload.
				await this.loadProfile()
			} catch (error) {
				this.profileError = error?.response?.data?.message ?? translate('social', 'Could not save your Bluesky profile')
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
				this.profileError = translate('social', 'Profile images must be 1 MiB or smaller')
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

<style scoped lang="scss">
@use '../components/ProfileInfo.scss';

.atproto-profile {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 100%;

	/* the standalone page draws inside the column the Fediverse profile page
	   draws inside; an embedded one is already inside it */
	&--page {
		max-width: var(--social-column);
		margin: 0 auto;
		padding: calc(var(--default-grid-baseline) * 4);
	}
}

/* where the account has no avatar at all, a letter in the same place the
   Fediverse avatar hangs from the banner */
.atproto-profile__avatar-fallback {
	position: absolute;
	top: -48px;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 128px;
	height: 128px;
	border-radius: 50%;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 40px;
	font-weight: 700;
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
