<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="post-header">
		<div v-if="!hideAuthor" class="post-author-wrapper" :title="item.account.acct">
			<component
				:is="isLocalAccount(item.account) ? 'a' : 'router-link'"
				v-if="item.account"
				:href="isLocalAccount(item.account) ? localProfileUrl(item.account) : undefined"
				:to="isLocalAccount(item.account) ? undefined : { name: 'profile', params: { account: item.account.acct } }">
				<span class="post-author">
					<DisplayName :text="item.account.display_name" :emojis="item.account.emojis" />
				</span>
				<BlueskyBadge v-if="isBlueskyAccount(item.account)" />
				<VerifiedBadge :account="item.account" />
				<span v-if="!hasDisplayName" class="post-author-id">
					@{{ item.account.username }}
				</span>
			</component>
		</div>
		<span v-if="isBoosted || isLiked" class="post-given" aria-hidden="true">
			<Repeat v-if="isBoosted" :size="14" fillColor="var(--color-primary-element)" />
			<Heart v-if="isLiked" :size="14" fillColor="var(--color-element-error)" />
		</span>
		<a
			v-if="postHref"
			:href="postHref"
			:data-timestamp="timestamp"
			class="post-timestamp live-relative-timestamp"
			:title="formattedDate"
			:aria-label="t('social', 'Open this post, written {time}', { time: formattedDate })">
			{{ relativeTimestamp }}
		</a>
		<button
			v-else
			:data-timestamp="timestamp"
			type="button"
			class="post-timestamp live-relative-timestamp"
			:title="formattedDate"
			:aria-label="t('social', 'Open this post, written {time}', { time: formattedDate })"
			@click="$emit('open')">
			{{ relativeTimestamp }}
		</button>
		<span v-if="item.pinned" class="post-pinned" :title="t('social', 'Pinned post')">
			<Pin :size="14" />
			{{ t('social', 'Pinned') }}
		</span>
		<span v-if="item.ai_generated === true" class="post-ai">
			<CreationOutline :size="14" />
			{{ t('social', 'Made with AI') }}
		</span>
		<span v-if="item.delivery === 'held'" class="post-held">
			{{ t('social', 'Converting video — reaches other servers when done') }}
		</span>
		<VisibilityIcon
			v-if="visibility"
			:title="visibility.text"
			class="post-visibility"
			:size="14"
			:visibility="visibility.id" />
		<router-link
			v-if="item.place && item.place.id"
			class="post-place"
			:to="{ name: 'place', params: { id: item.place.id } }"
			:title="t('social', 'Posts from {place}', { place: placeLabel })">
			<MapMarkerOutline :size="14" />
			<span class="post-place__name">{{ placeLabel }}</span>
		</router-link>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Pin from 'vue-material-design-icons/Pin.vue'
import CreationOutline from 'vue-material-design-icons/CreationOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import BlueskyBadge from './BlueskyBadge.vue'
import DisplayName from './DisplayName.js'
import VerifiedBadge from './VerifiedBadge.vue'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import { isBlueskyAccount, isLocalAccount as isLocalActor } from '../utils/accountLocality.js'
import { localProfileUrl } from '../utils/accountProfileLink.js'
import { fullDateTime, shortAgo } from '../utils/relativeTime.js'
import { onTick } from '../services/clock.js'

export default {
	name: 'TimelinePostHeader',
	components: {
		Pin,
		CreationOutline,
		MapMarkerOutline,
		Repeat,
		Heart,
		BlueskyBadge,
		DisplayName,
		VerifiedBadge,
		VisibilityIcon,
	},

	props: {
		item: { type: Object, required: true },
		hideAuthor: { type: Boolean, default: false },
		postHref: { type: String, default: '' },
		isBoosted: { type: Boolean, default: false },
		isLiked: { type: Boolean, default: false },
	},

	emits: ['open'],
	setup() {
		return {
			t,
			isBlueskyAccount,
			isLocalAccount: (account) => Boolean(account?.acct && isLocalActor(account) && account.username),
			localProfileUrl,
		}
	},

	data() {
		return { now: Date.now(), stopTicking: null }
	},

	computed: {
		timestamp() {
			return Date.parse(this.item.created_at)
		},

		formattedDate() {
			return fullDateTime(this.item.created_at)
		},

		relativeTimestamp() {
			return shortAgo(this.item.created_at, new Date(this.now))
		},

		hasDisplayName() {
			return (this.item.account?.display_name ?? '').trim() !== ''
		},

		visibility() {
			if (this.item.visibility === 'public') {
				return undefined
			}
			return visibilitiesInfo.find(({ id }) => this.item.visibility === id)
		},

		placeLabel() {
			const place = this.item.place
			if (!place) {
				return ''
			}
			return place.country ? `${place.name}, ${place.country}` : place.name
		},
	},

	mounted() {
		this.stopTicking = onTick((now) => {
			this.now = now
		})
	},

	unmounted() {
		this.stopTicking?.()
	},
}
</script>

<style lang="scss" scoped>
.post-header {
	display: flex;
	gap: 8px;
	align-items: baseline;
	margin-bottom: 10px;

	.post-given {
		display: none;
		align-self: center;
		gap: 4px;
	}

	.post-author-wrapper {
		flex-grow: 1;
		min-width: 0;
		display: flex;
		align-items: baseline;

		.post-author {
			font-weight: 650;
			font-size: 14px;
			color: var(--color-main-text);
			letter-spacing: -.01em;
		}

		.post-author-id {
			font-size: 13px;
			color: var(--color-text-lighter);
			margin-inline-start: 6px;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
	}

	.post-place {
		display: inline-flex;
		gap: 3px;
		align-items: center;
		max-width: 40%;
		color: var(--color-text-maxcontrast);
		font-size: 12px;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	.post-place__name {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	.post-visibility {
		color: var(--color-text-lighter);
		flex-shrink: 0;
		align-self: center;
	}

	.post-pinned,
	.post-ai,
	.post-held {
		display: inline-flex;
		align-items: center;
		align-self: center;
		gap: 4px;
		flex-shrink: 0;
		font-size: 12px;
		font-weight: normal;
		color: var(--color-text-lighter);
	}

	.post-timestamp {
		background: none;
		border: none;
		border-radius: 0;
		padding: 0;
		margin: 0;
		min-height: 0;
		font-family: inherit;
		font-weight: normal;
		font-size: 12px;
		text-align: end;
		color: var(--color-text-lighter);
		white-space: nowrap;
		cursor: pointer;
		flex-shrink: 0;

		&:hover {
			color: var(--color-primary-element);
			background: none;
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
			border-radius: var(--border-radius-small, 4px);
		}
	}
}
</style>
