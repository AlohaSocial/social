<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="social-profile">
		<Teleport v-if="nativeProfileHeader && bannerUrl" to=".profile__header">
			<div class="social-profile__header-banner" aria-hidden="true">
				<img :src="bannerUrl" alt="">
			</div>
		</Teleport>
		<img
			v-else-if="bannerUrl"
			class="social-profile__banner"
			:src="bannerUrl"
			:alt="t('social', 'Aloha Social profile banner')">
		<h2 class="social-profile__title">
			{{ t('social', 'Aloha Social') }}
		</h2>
		<TimelineSwitcher
			class="social-profile__feeds"
			:options="feedOptions"
			:value="activeFeed"
			:label="t('social', 'Which Aloha Social feed to show')"
			@update:value="selectFeed" />
		<p v-if="activeFeed === 'home'" class="social-profile__feed-description">
			{{ t('social', 'Posts from accounts you follow, including private posts you are allowed to see.') }}
		</p>
		<p v-else-if="activeFeed === 'timeline'" class="social-profile__feed-description">
			{{ t('social', 'Public posts from this Nextcloud server.') }}
		</p>
		<p v-else-if="activeFeed === 'federated'" class="social-profile__feed-description">
			{{ t('social', 'Public posts from across the Fediverse.') }}
		</p>
		<p v-else-if="activeFeed === 'bluesky'" class="social-profile__feed-description">
			{{ t('social', 'Posts published to Bluesky by this account, shown in the same profile.') }}
		</p>

		<p v-if="feedLoading && feedTimeline.length === 0" role="status" class="social-profile__feed-state">
			{{ t('social', 'Loading posts…') }}
		</p>
		<div
			v-else-if="feedError && feedTimeline.length === 0"
			class="social-profile__feed-state"
			role="alert">
			<p>{{ t('social', 'Could not load this feed') }}</p>
			<NcButton variant="secondary" :disabled="feedLoading" @click="loadFeed()">
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>
		<p v-else-if="feedTimeline.length === 0 && !feedLoading" class="social-profile__feed-state">
			{{ emptyFeedMessage }}
		</p>
		<transition-group
			v-else
			name="list"
			tag="ul"
			class="social-profile__timeline">
			<ProfileStatusCard
				v-for="entry in feedTimeline"
				:key="`${activeFeed}-${entry.id}`"
				:status="entry" />
		</transition-group>
		<p v-if="feedError && feedTimeline.length" class="social-profile__feed-state" role="alert">
			{{ t('social', 'Could not load this feed') }}
		</p>
		<NcButton
			v-if="feedTimeline.length && feedHasMore"
			variant="secondary"
			class="social-profile__load-more"
			:disabled="feedLoading"
			@click="loadFeed(feedTimeline[feedTimeline.length - 1]?.id)">
			{{ feedLoading ? t('social', 'Loading…') : t('social', 'Load more') }}
		</NcButton>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconAccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import IconAccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import IconEarth from 'vue-material-design-icons/Earth.vue'
import IconHome from 'vue-material-design-icons/Home.vue'
import IconBird from 'vue-material-design-icons/Bird.vue'
import ProfileStatusCard from './../components/ProfileStatusCard.vue'
import TimelineSwitcher from './../components/TimelineSwitcher.vue'
import logger from './../services/logger.js'
import { bannerOf } from '../utils/banner.js'

const PAGE_SIZE = 20

export default {
	name: 'ProfilePageIntegration',
	components: {
		NcButton,
		ProfileStatusCard,
		TimelineSwitcher,
	},

	props: {
		userId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			accountInfo: null,
			nativeProfileHeader: Boolean(document.querySelector('.profile__header')),
			activeFeed: 'profile',
			feedTimeline: [],
			feedLoading: false,
			feedError: false,
			feedHasMore: false,
			feedRequest: 0,
			anchorRequest: false,
		}
	},

	computed: {
		isOwnProfile() {
			return Boolean(window.OC?.getCurrentUser?.()?.uid)
				&& window.OC.getCurrentUser().uid === this.userId
		},

		feedOptions() {
			const options = [
				{ value: 'profile', label: t('social', 'Posts'), icon: IconAccountCircle },
			]
			if (this.isOwnProfile) {
				options.push({ value: 'home', label: t('social', 'My Feed'), icon: IconHome })
				options.push({ value: 'bluesky', label: t('social', 'Bluesky'), icon: IconBird })
			}
			options.push(
				{ value: 'timeline', label: t('social', 'Local'), icon: IconAccountMultiple },
				{ value: 'federated', label: t('social', 'Global'), icon: IconEarth },
			)
			return options
		},

		bannerUrl() {
			return bannerOf(this.accountInfo)
		},

		emptyFeedMessage() {
			return this.activeFeed === 'profile'
				? t('social', 'No public posts on this profile yet.')
				: this.activeFeed === 'home'
					? t('social', 'No posts in your feed yet')
					: t('social', 'No posts in this feed yet')
		},
	},

	beforeMount() {
		if (!this.userId) {
			return
		}

		this.loadAccount()
		this.loadFeed()
	},

	methods: {
		t,

		async loadAccount() {
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(this.userId)}`))
				this.accountInfo = data
			} catch (error) {
				// The feed still works if profile metadata is unavailable. In
				// particular, an unset banner should not hide public posts.
				logger.error('Failed to load Social profile details', { error, uid: this.userId })
			}
		},

		selectFeed(feed) {
			if (feed === this.activeFeed || (feed === 'home' && !this.isOwnProfile)) {
				return
			}
			this.activeFeed = feed
			this.loadFeed()
		},

		async loadFeed(maxId = '') {
			if (!this.userId || (maxId && this.feedLoading) || (this.activeFeed === 'home' && !this.isOwnProfile)) {
				return
			}

			const request = maxId ? this.feedRequest : ++this.feedRequest
			const feed = this.activeFeed
			this.feedLoading = true
			this.feedError = false
			if (!maxId) {
				this.feedTimeline = []
				this.feedHasMore = false
			}

			try {
				const params = { limit: PAGE_SIZE }
				if (maxId) {
					params.max_id = maxId
				}
				let url
				if (feed === 'profile') {
					url = generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(this.userId)}/statuses`)
				} else if (feed === 'bluesky') {
					url = generateUrl('apps/social/api/v1/atproto/profile')
				} else if (feed === 'home') {
					url = generateUrl('apps/social/api/v1/timelines/home')
				} else {
					url = generateUrl('apps/social/api/v1/timelines/public')
					params.local = feed === 'timeline'
				}
				const { data } = await axios.get(url, { params })
				if (request !== this.feedRequest || feed !== this.activeFeed) {
					return
				}
				const page = Array.isArray(data) ? data : []
				const seen = new Set(this.feedTimeline.map((status) => String(status.id)))
				this.feedTimeline = maxId
					? [...this.feedTimeline, ...page.filter((status) => !seen.has(String(status.id)))]
					: page
				this.feedHasMore = page.length === PAGE_SIZE
				if (feed === 'profile' && !maxId) {
					await this.restorePostAnchor(request)
				}
			} catch (error) {
				if (request === this.feedRequest) {
					this.feedError = true
					logger.error('Failed to load the Social profile feed', { error, uid: this.userId, feed })
				}
			} finally {
				if (request === this.feedRequest) {
					this.feedLoading = false
				}
			}
		},

		async restorePostAnchor(request) {
			const match = window.location.hash.match(/^#social-profile-status-(\d+)$/)
			if (!match || this.anchorRequest) {
				return
			}
			const statusId = match[1]
			let target = document.getElementById(`social-profile-status-${statusId}`)
			if (!target && /^\d+$/.test(statusId)) {
				this.anchorRequest = true
				try {
					const params = { limit: PAGE_SIZE, max_id: (BigInt(statusId) + 1n).toString() }
					const url = generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(this.userId)}/statuses`)
					const { data } = await axios.get(url, { params })
					const page = Array.isArray(data) ? data : []
					if (request === this.feedRequest && this.activeFeed === 'profile'
						&& page.some((status) => String(status.id) === statusId)) {
						const posts = new Map(this.feedTimeline.map((status) => [String(status.id), status]))
						for (const status of page) {
							posts.set(String(status.id), status)
						}
						this.feedTimeline = [...posts.values()].sort((left, right) => {
							const leftId = BigInt(left.id)
							const rightId = BigInt(right.id)
							return leftId === rightId ? 0 : leftId > rightId ? -1 : 1
						})
						this.feedHasMore = page.length === PAGE_SIZE
					}
				} catch (error) {
					logger.error('Failed to load the linked Social post on the Nextcloud profile', { error, statusId })
				} finally {
					this.anchorRequest = false
				}
			}

			if (request !== this.feedRequest || this.activeFeed !== 'profile') {
				return
			}
			await this.$nextTick()
			target = document.getElementById(`social-profile-status-${statusId}`)
			target?.scrollIntoView?.({ block: 'center' })
		},
	},
}
</script>

<style>
.profile__header:has(> .social-profile__header-banner) {
	position: relative;
	top: 0;
	bottom: auto;
	isolation: isolate;
	overflow: hidden;
}

.profile__header:has(> .social-profile__header-banner) > .profile__header__container {
	position: relative;
	z-index: 1;
}

.profile__header:has(> .social-profile__header-banner) .profile__header__container__displayname {
	color: #fff;
	text-shadow: 0 1px 4px rgb(0 0 0 / 70%);
}

.social-profile__header-banner {
	position: absolute;
	z-index: 0;
	inset: 0;
	pointer-events: none;
}

.social-profile__header-banner img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.social-profile__header-banner::after {
	position: absolute;
	inset: 0;
	background: linear-gradient(90deg, rgb(0 0 0 / 62%), rgb(0 0 0 / 18%));
	content: '';
}
</style>

<style scoped>
.social-profile {
	min-width: 0;
}

.social-profile__banner {
	display: block;
	width: 100%;
	max-height: 15rem;
	margin-block-end: 1rem;
	border-radius: var(--border-radius-large);
	object-fit: cover;
}

.social-profile__title {
	margin-block: 0 0.75rem;
}

.social-profile__feeds {
	margin-block-end: 1rem;
}

.social-profile__feed-description {
	margin: 0 0 0.75rem;
	color: var(--color-text-maxcontrast);
}

.social-profile__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
}

.social-profile__feed-state {
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
}

.social-profile__load-more {
	margin-block: 1rem 2rem;
}
</style>
