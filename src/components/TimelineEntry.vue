<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<component
		:is="element"
		class="timeline-entry"
		:class="{
			notification: isNotification,
			'with-header': isNotification,
			'timeline-entry--reply': depth > 0,
			'timeline-entry--continues': depth > 0 && continues,
			'timeline-entry--unread': unread,
			'timeline-entry--settled': !staggered,
			'timeline-entry--direct': type === 'direct',
			'timeline-entry--grouped': grouped && !isNotification,
		}"
		:style="entryStyle"
		:data-status-id="entryContent?.id"
		tabindex="-1">
		<!-- the actor's face with the type as a disc on its corner; a grouped
		     card shows the disc alone and the faces beside the words -->
		<span v-if="isNotification" class="notification__lead" :class="{ 'notification__lead--group': groupedAccounts.length > 1 }">
			<ActorAvatar v-if="groupedAccounts.length <= 1" :actor="notification.account" :size="avatarSize" />
			<span class="notification__type" :class="'notification__type--' + typeTone">
				<Heart v-if="notification.type === 'favourite'" :size="typeIconSize" />
				<Repeat v-if="notification.type === 'reblog'" :size="typeIconSize" />
				<AccountPlusOutline v-if="notification.type === 'follow'" :size="typeIconSize" />
				<AccountQuestion v-if="notification.type === 'follow_request'" :size="typeIconSize" />
				<Lock v-if="isDirectMessage" :size="typeIconSize" />
				<At v-else-if="notification.type === 'mention'" :size="typeIconSize" />
				<MessageOutline v-if="notification.type === 'status'" :size="typeIconSize" />
				<MessagePlusOutline v-if="notification.type === 'update'" :size="typeIconSize" />
				<Poll v-if="notification.type === 'poll'" :size="typeIconSize" />
				<Heart v-if="notification.type === 'bluesky:repost_liked'" :size="typeIconSize" />
				<Repeat v-if="notification.type === 'bluesky:repost_reposted'" :size="typeIconSize" />
				<CheckDecagram v-if="notification.type === 'bluesky:verified'" :size="typeIconSize" />
				<CheckDecagramOutline v-if="notification.type === 'bluesky:unverified'" :size="typeIconSize" />
				<EmoticonHappyOutline v-if="notification.type === 'story:react'" :size="typeIconSize" />
				<CommentTextOutline v-if="notification.type === 'story:comment'" :size="typeIconSize" />
				<AccountTagOutline v-if="notification.type === 'tagged'" :size="typeIconSize" />
				<ShieldAlertOutline v-if="notification.type === 'moderation_warning'" :size="typeIconSize" />
				<AccountMultipleRemoveOutline v-if="notification.type === 'severed_relationships'" :size="typeIconSize" />
				<AccountPlusOutline v-if="notification.type === 'bluesky:starterpack_joined'" :size="typeIconSize" />
			</span>
		</span>
		<div v-if="isNotification" class="notification__header">
			<span class="notification__summary">
				<!-- the first few faces of a grouped card, overlapping -->
				<span v-if="groupedAccounts.length > 1" class="notification__faces">
					<ActorAvatar
						v-for="account in groupedAccounts"
						:key="account.id || account.acct"
						:actor="account"
						:size="GROUP_FACE"
						:link="false" />
				</span>
				<BlueskyBadge v-if="groupedAccounts.length <= 1 && isBlueskyAccount(notification.account)" />
				<VerifiedBadge v-if="groupedAccounts.length <= 1" :account="notification.account" />
				<span class="notification__text">{{ actionSummary }}</span>
			</span>
			<span class="notification__details">
				<!-- said in words, as the unread tint alone is easy to miss -->
				<span v-if="unread" class="notification__new">
					{{ t('social', 'New') }}
				</span>
				<router-link
					v-if="!notificationIsAboutAnAccount && notification.status"
					:to="{ name: 'single-post', params: {
						account: item.account.acct,
						id: notification.status.id,
						type: 'single-post',
					} }"
					:data-timestamp="notification.created_at"
					class="post-timestamp"
					:title="notificationFormattedDate">
					{{ notificationRelativeTimestamp }}
				</router-link>
				<span
					v-else
					class="post-timestamp"
					:data-timestamp="notification.created_at"
					:title="notificationFormattedDate">
					{{ notificationRelativeTimestamp }}
				</span>
				<!-- the post has no menu on this page, so muting a mentioning
				     thread is offered here -->
				<NcActions v-if="canMuteConversation" class="notification__menu" :forceMenu="true">
					<NcActionButton :closeAfterClick="true" @click="toggleConversationMute">
						<template #icon>
							<BellOutline v-if="entryContent.muted" :size="20" />
							<BellOffOutline v-else :size="20" />
						</template>
						{{ entryContent.muted ? t('social', 'Unmute conversation') : t('social', 'Mute conversation') }}
					</NcActionButton>
				</NcActions>
			</span>
		</div>
		<template v-else-if="isBoost">
			<div class="boost">
				<Repeat :size="16" />
				<router-link :to="{ name: 'profile', params: { account: item.account.acct } }">
					<ActorAvatar :actor="item.account" :size="16" :link="false" />
					<span :title="item.account.acct" class="post-author">
						{{ item.account.display_name }}
						<BlueskyBadge v-if="isBlueskyAccount(item.account)" />
						<VerifiedBadge :account="item.account" />
					</span>
				</router-link>
				{{ t('social', 'boosted') }}
			</div>
		</template>
		<!-- why For you shows the post, linking to the tag; above the post
		     because it is not part of what the author wrote -->
		<router-link
			v-if="interest !== null && interest.tag !== null"
			class="interest-reason"
			:to="{ name: 'tags', params: { tag: interest.tag } }"
			:title="interest.label"
			:aria-label="interest.label">
			<Pound :size="16" />
			<span class="interest-reason__text">{{ interest.text }}</span>
		</router-link>
		<!-- A discovery post has no tag route to open. -->
		<span
			v-else-if="interest !== null"
			class="interest-reason"
			:class="interest.reason === 'exploration' ? 'interest-reason--exploration' : 'interest-reason--popular'"
			role="note"
			:title="interest.label"
			:aria-label="interest.label">
			<Pound v-if="interest.reason === 'exploration'" :size="16" />
			<TrendingUp v-else :size="16" />
			<span class="interest-reason__text">{{ interest.text }}</span>
		</span>
		<UserEntry v-if="isNotification && notificationIsAboutAnAccount" :displayFollowButton="false" :item="item.account" />
		<!-- a private message shows only its first words and a link to the conversation -->
		<div v-else-if="isDirectMessage" class="notification__direct">
			<p class="notification__direct-preview">
				{{ directPreview }}
			</p>
			<router-link class="notification__direct-open" :to="{ name: 'timeline', params: { type: 'direct' } }">
				{{ t('social', 'Open the conversation') }}
			</router-link>
		</div>
		<template v-else>
			<div v-if="entryContent" class="wrapper">
				<!-- a run of posts by one author shows the face once; the rest
				     keep its room for the line that joins them -->
				<span
					v-if="!isNotification && !hideAvatar && grouped"
					class="entry__thread"
					:style="{ inlineSize: avatarSize + 'px' }"
					aria-hidden="true" />
				<TimelineAvatar
					v-else-if="!isNotification && !hideAvatar"
					class="entry__avatar"
					:item="entryContent"
					:size="avatarSize" />
				<TimelinePost
					class="entry__content"
					:class="{ notification__own: isOwnPost }"
					:item="entryContent"
					:type="type"
					:postHref="postHref"
					:hideAuthor="hideAuthor || isOwnPost"
					:compactAuthor="grouped && !isNotification"
					:embeddedActions="embeddedActions">
					<template #profileActions>
						<slot name="profileActions" />
					</template>
				</TimelinePost>
			</div>
		</template>
	</component>
</template>

<script>
import { fullDateTime, shortAgo } from '../utils/relativeTime.js'
import Bell from 'vue-material-design-icons/Bell.vue'
import BellOffOutline from 'vue-material-design-icons/BellOffOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import At from 'vue-material-design-icons/At.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Poll from 'vue-material-design-icons/Poll.vue'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'
import EmoticonHappyOutline from 'vue-material-design-icons/EmoticonHappyOutline.vue'
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import AccountTagOutline from 'vue-material-design-icons/AccountTagOutline.vue'
import ShieldAlertOutline from 'vue-material-design-icons/ShieldAlertOutline.vue'
import AccountMultipleRemoveOutline from 'vue-material-design-icons/AccountMultipleRemoveOutline.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import Pound from 'vue-material-design-icons/Pound.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import { translate as t } from '@nextcloud/l10n'
import TimelinePost from './TimelinePost.vue'
import ActorAvatar from './ActorAvatar.vue'
import BlueskyBadge from './BlueskyBadge.vue'
import { isBlueskyAccount } from '../utils/accountLocality.js'
import TimelineAvatar from './TimelineAvatar.vue'
import UserEntry from './UserEntry.vue'
import VerifiedBadge from './VerifiedBadge.vue'
import { GROUP_FACES, isDirectMessage, notificationSummary } from '../services/notifications.js'
import { htmlToPlainText } from '../utils/plainText.js'

/** how much of a private message an Activities card shows: about one line */
const DIRECT_PREVIEW_LENGTH = 120
import { interestReason } from '../utils/interestReason.js'
import { onTick } from '../services/clock.js'
import { isPhone, onPhoneChange } from '../services/phone.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { isPersonalizedFeed, useTimelineStore } from '../store/timeline.js'

/** the face's size inside the card, on a phone */
const PHONE_AVATAR = 36
/** the face beside a post in a list, sized to line up with the name */
const LIST_AVATAR = 40
/** each face of a card that stands for several people */
const GROUP_FACE = 28

/** the type disc's colour family, by notification type; the rest are 'primary' */
const TYPE_TONES = {
	favourite: 'like',
	'bluesky:repost_liked': 'like',
	'story:react': 'like',
	reblog: 'boost',
	'bluesky:repost_reposted': 'boost',
	moderation_warning: 'warning',
	severed_relationships: 'warning',
	'bluesky:unverified': 'warning',
}

/** how many levels of a conversation are indented before the column runs out */
const MAX_INDENT = 4

/**
 * How many entries are staggered on the way in: about the first screenful.
 * Later entries, and pages appended by infinite scroll, fade in without delay.
 */
const STAGGER_DEPTH = 8

/** how far apart the staggered ones start, in milliseconds */
const STAGGER_STEP = 45

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		ActorAvatar,
		BlueskyBadge,
		TimelineAvatar,
		UserEntry,
		Bell,
		BellOffOutline,
		BellOutline,
		NcActionButton,
		NcActions,
		Repeat,
		Heart,
		AccountPlusOutline,
		AccountQuestion,
		At,
		Lock,
		Poll,
		CheckDecagram,
		CheckDecagramOutline,
		EmoticonHappyOutline,
		CommentTextOutline,
		AccountTagOutline,
		ShieldAlertOutline,
		AccountMultipleRemoveOutline,
		MessageOutline,
		MessagePlusOutline,
		Pound,
		TrendingUp,
		VerifiedBadge,
	},

	props: {
		hideAvatar: {
			type: Boolean,
			default: false,
		},

		hideAuthor: {
			type: Boolean,
			default: false,
		},

		embeddedActions: {
			type: Boolean,
			default: false,
		},

		postHref: {
			type: String,
			default: '',
		},

		item: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status|import('../types/Mastodon.js').Notification>} */ (Object),
			default: () => {},
		},

		type: {
			type: String,
			required: true,
		},

		/**
		 * Whether to appear in place without rising in, for a list that
		 * replaces one already on screen. Read once at mount.
		 */
		immediate: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether replies below this entry hang off it, so its thread line
		 * carries on down instead of stopping at its elbow.
		 */
		continues: {
			type: Boolean,
			default: false,
		},

		/**
		 * How deep this entry sits in a conversation, 0 for none; indented
		 * up to MAX_INDENT levels.
		 */
		depth: {
			type: Number,
			default: 0,
		},

		/**
		 * Whether this post continues a run by the same author, drawn
		 * without the face and the name the post above already shows.
		 */
		grouped: {
			type: Boolean,
			default: false,
		},

		element: {
			type: String,
			default: 'li',
		},

		/** whether this arrived after the reader last read their notifications */
		unread: {
			type: Boolean,
			default: false,
		},

		/** the entry's position in the list, for the stagger (see STAGGER_DEPTH) */
		index: {
			type: Number,
			default: 0,
		},
	},

	data() {
		return {
			/** whether this entry rises in, staggered */
			staggered: !this.immediate,
			/** on a phone the face moves inside the card and shrinks */
			isPhone: isPhone(),
			MAX_INDENT,
			GROUP_FACE,
			/** updated from the shared clock, for the relative timestamp */
			now: Date.now(),
			/** unsubscribers for the phone-width watch and the shared clock */
			stopPhoneWatch: null,
			stopTicking: null,
		}
	},

	computed: {
		/** the face's size: smaller on a phone, where it sits inside the row */
		avatarSize() {
			return this.isPhone ? PHONE_AVATAR : LIST_AVATAR
		},

		/**
		 * The thread indent and the stagger delay, as custom properties.
		 *
		 * @return {object|undefined} the style, or undefined when there is neither
		 */
		entryStyle() {
			const style = {}

			if (this.depth > 0) {
				style['--thread-depth'] = Math.min(this.depth, MAX_INDENT)
			}

			if (this.staggered && this.index < STAGGER_DEPTH) {
				style['--stagger-delay'] = `${this.index * STAGGER_STEP}ms`
			}

			return Object.keys(style).length > 0 ? style : undefined
		},

		...mapStores(useAccountStore, useSettingsStore, useTimelineStore),

		/**
		 * Whether the card offers to mute its thread: a mention (replies
		 * included) about a post, for a signed-in reader.
		 *
		 * @return {boolean}
		 */
		canMuteConversation() {
			return this.isNotification
				&& this.notification.type === 'mention'
				&& Boolean(this.entryContent?.id)
				&& this.settingsStore.getServerData?.public !== true
		},

		/**
		 * @return {import('../types/Mastodon.js').Status}
		 */
		entryContent() {
			if (this.isNotification) {
				// the store's copy, so actions on the card are reflected
				return this.timelineStore.getStatus(this.notification.status?.id) ?? this.notification.status
			} else if (this.isBoost) {
				// the store's copy, so actions on it are reflected
				return this.timelineStore.getStatus(this.status.reblog.id)
			} else {
				return this.status
			}
		},

		/**
		 * Why For you shows this post; null on every other timeline.
		 *
		 * @return {{tag: string|null, reason?: string, text: string, label: string}|null}
		 */
		interest() {
			if (this.isNotification || !isPersonalizedFeed({ type: this.type, params: this.timelineStore.params })) {
				return null
			}

			const snapshot = this.timelineStore.getRecommendationReason(this.status.id)
			return interestReason(snapshot !== undefined ? snapshot : (this.status.interest ?? this.entryContent?.interest))
		},

		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},

		/** @return {string} */
		notificationFormattedDate() {
			return fullDateTime(this.notification.created_at)
		},

		/** @return {string} */
		notificationRelativeTimestamp() {
			return shortAgo(this.notification.created_at, new Date(this.now))
		},

		/** @return {boolean} */
		isBoost() {
			return this.status.reblog !== null
		},

		/** @return {import('../types/Mastodon.js').Notification} */
		notification() {
			return /** @type {import('../types/Mastodon.js').Notification} */ (this.item)
		},

		/** @return {import('../types/Mastodon.js').Status} */
		status() {
			return /** @type {import('../types/Mastodon.js').Status} */ (this.item)
		},

		/** @return {boolean} whether this card is a direct message, which reaches the bell as a mention */
		isDirectMessage() {
			return this.isNotification && isDirectMessage(this.notification)
		},

		/**
		 * The first words of a private message: its content warning, else its text.
		 *
		 * @return {string}
		 */
		directPreview() {
			const status = this.entryContent
			const text = (status?.spoiler_text || htmlToPlainText(status?.content ?? '')).replace(/\s+/g, ' ').trim()

			return text.length > DIRECT_PREVIEW_LENGTH ? text.slice(0, DIRECT_PREVIEW_LENGTH).trimEnd() + '…' : text
		},

		/** @return {boolean} */
		notificationIsAboutAnAccount() {
			return ['follow', 'follow_request', 'admin.sign_up', 'admin.report', 'bluesky:verified', 'bluesky:unverified', 'bluesky:starterpack_joined'].includes(this.notification.type)
		},

		/**
		 * The faces a grouped card shows, capped at GROUP_FACES; empty for a
		 * card about one account, which draws the single avatar instead.
		 *
		 * @return {import('../types/Mastodon.js').Account[]}
		 */
		groupedAccounts() {
			if (!Array.isArray(this.notification.accounts)) {
				return []
			}

			return this.notification.accounts.slice(0, GROUP_FACES)
		},

		/**
		 * @return {string}
		 */
		actionSummary() {
			return notificationSummary(this.notification)
		},

		/**
		 * The colour family of the type disc.
		 *
		 * @return {string}
		 */
		typeTone() {
			return TYPE_TONES[this.notification.type] ?? 'primary'
		},

		/** @return {number} the type icon: small on a face's corner, larger standing alone */
		typeIconSize() {
			return this.groupedAccounts.length > 1 ? 16 : 12
		},

		/**
		 * Whether the post the card is about is the reader's own, shown as
		 * an excerpt without the author line.
		 *
		 * @return {boolean}
		 */
		isOwnPost() {
			const author = this.isNotification ? this.entryContent?.account : undefined
			const me = this.accountStore.currentAccount
			if (!author || !me) {
				return false
			}

			return (author.id !== undefined && author.id === me.id) || author.acct === me.acct
		},
	},

	mounted() {
		this.stopPhoneWatch = onPhoneChange((phone) => {
			this.isPhone = phone
		})
		this.stopTicking = onTick((now) => {
			this.now = now
		})
	},

	unmounted() {
		this.stopPhoneWatch?.()
		this.stopTicking?.()
	},

	methods: {
		t,
		isBlueskyAccount,

		toggleConversationMute() {
			this.timelineStore.postMuteConversation({ status: this.entryContent, muted: !this.entryContent.muted })
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

.wrapper {
	display: flex;
	gap: 12px;
	padding: 0;

	&:focus {
		background-color: var(--color-background-hover);
	}

	.entry__avatar {
		flex-shrink: 0;
		margin-top: 6px;
	}

	.entry__content {
		flex-grow: 1;
		min-width: 0;
	}
}

.timeline-entry {
	margin-bottom: 14px;
	padding: 0;
	border-radius: 8px;
	/* a card off screen is skipped by layout, holding a placeholder height */
	content-visibility: auto;
	contain-intrinsic-size: auto 320px;

	/* cards rise in; --stagger-delay is set for the first screenful only */
	animation: timeline-rise .28s ease-out both;
	animation-delay: var(--stagger-delay, 0ms);

	/* one list swapped for another: no animation */
	&.timeline-entry--settled {
		animation: none;
	}

	/**
	 * A reply is indented by --thread-depth and joined to the post above by
	 * an elbow line.
	 */
	&--reply {
		margin-inline-start: calc(var(--thread-depth, 1) * 24px);
		padding-inline-start: 18px;
		position: relative;

		&::before {
			content: '';
			position: absolute;
			inset-block-start: -10px;
			inset-inline-start: 0;
			// from the gap above down to the middle of this entry, where the elbow turns in
			block-size: 34px;
			inline-size: 12px;
			border-inline-start: 2px solid var(--color-border);
			border-block-end: 2px solid var(--color-border);
			border-end-start-radius: 10px;
			pointer-events: none;
		}

		// a reply with replies of its own carries the line on down to them
		&.timeline-entry--continues::after {
			content: '';
			position: absolute;
			inset-block-start: 24px;
			inset-block-end: -10px;
			inset-inline-start: 0;
			border-inline-start: 2px solid var(--color-border);
			pointer-events: none;
		}
	}

	&:last-child {
		margin-bottom: 0;
	}

	// The actions panel reaches over the gap into the next entry. Each entry's
	// rise animation makes it a stacking context, so the entry itself has to
	// be raised above its siblings.
	&:hover,
	&:focus-within {
		position: relative;
		z-index: 3;
	}

	// also while the overflow menu holds the panel open; a separate rule so a
	// browser without `:has()` keeps the hover case
	&:has(.post-actions-reveal--held) {
		position: relative;
		z-index: 3;
	}

	// the panel would cover the next entry's boost byline all but its first
	// letters, so the byline is hidden instead
	&:hover + .timeline-entry .boost {
		opacity: 0;
	}

	&:has(.post-actions-reveal--held) + .timeline-entry .boost {
		opacity: 0;
	}

	/* unread: a bar down the row's start edge, out of the flow. A
	   notification is never a reply, so `::before` is free here. */
	&--unread.notification::before {
		content: '';
		position: absolute;
		inset-block: 8px;
		inset-inline-start: 0;
		inline-size: 3px;
		border-radius: 3px;
		background: var(--color-primary-element);
		pointer-events: none;
	}

	// Nextcloud's notifications app styles this class globally with a bottom border
	&.notification {
		border: none;
	}
}

/*
 * A notification is two columns: the face, spanning both rows, and the
 * summary line over the excerpt.
 */
.timeline-entry.notification {
	--notification-face: 40px;
	// the row's background, so a ring reads as a gap between two faces
	--notification-ring: var(--color-main-background);
	display: grid;
	grid-template-columns: var(--notification-face) minmax(0, 1fr);
	grid-template-rows: auto 1fr;
	column-gap: 12px;

	&:hover {
		--notification-ring: var(--color-background-hover);
	}

	> * {
		grid-column: 2;
	}

	> .notification__lead {
		grid-column: 1;
		grid-row: 1 / span 2;
	}

	@include layout.below(layout.$phone) {
		--notification-face: 36px;
	}
}

.notification {
	&__lead {
		position: relative;
		display: flex;
		justify-content: center;
		align-self: start;
		inline-size: var(--notification-face);
		block-size: var(--notification-face);
	}

	// a grouped card: the disc stands alone, level with the faces
	&__lead--group {
		block-size: auto;
	}

	&__type {
		display: flex;
		align-items: center;
		justify-content: center;
		box-sizing: border-box;
		inline-size: 20px;
		block-size: 20px;
		border-radius: 50%;
		background: var(--notification-tone);
		color: var(--notification-tone-text);
		box-shadow: 0 0 0 2px var(--notification-ring);
		--notification-tone: var(--color-primary-element);
		--notification-tone-text: var(--color-primary-element-text);

		// the element colours, as --color-error and friends are pale
		// backgrounds from Nextcloud 32 on
		&--like {
			--notification-tone: var(--color-element-error, var(--color-error));
			--notification-tone-text: var(--color-main-background);
		}

		&--boost {
			--notification-tone: var(--color-element-success, var(--color-success));
			--notification-tone-text: var(--color-main-background);
		}

		&--warning {
			--notification-tone: var(--color-element-warning, var(--color-warning));
			--notification-tone-text: var(--color-main-background);
		}

		.material-design-icon {
			display: flex;
		}
	}

	// on the face's bottom end corner
	&__lead:not(.notification__lead--group) &__type {
		position: absolute;
		inset-block-end: -3px;
		inset-inline-end: -5px;
	}

	&__lead--group &__type {
		inline-size: 28px;
		block-size: 28px;
	}

	// the age and the menu stay on the first line when the words wrap
	&__header {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		min-height: 20px;
		margin-bottom: 2px;
	}

	// the account a follow is about is a line in the row, not a card
	:deep(.user-entry) {
		width: auto;
		margin-bottom: 0;
		padding: 0;
		border: none;
		background: transparent;
	}

	&__summary {
		flex-grow: 1;
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 8px;
		min-width: 0;
		color: var(--color-main-text);
		font-size: 14px;
		line-height: 20px;
	}

	&__text {
		min-width: 0;
		overflow-wrap: anywhere;
	}

	/* a grouped card's faces, on their own line, overlapping with a ring between */
	&__faces {
		display: flex;
		flex-basis: 100%;
		align-items: center;

		> * {
			border-radius: 50%;
			box-shadow: 0 0 0 2px var(--notification-ring);
		}

		> * + * {
			margin-inline-start: -4px;
		}
	}

	// the reader's own post: a quiet excerpt without the author line
	:deep(.notification__own) {
		.post-header {
			display: none;
		}

		.post-message {
			display: -webkit-box;
			overflow: hidden;
			-webkit-box-orient: vertical;
			-webkit-line-clamp: 3;
			line-clamp: 3;
			color: var(--color-text-maxcontrast);
		}
	}

	&__details {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 20px;
		font-size: 12px;

		// the menu button is taller than the line; centred without pushing the excerpt down
		.notification__menu {
			margin-block: calc((20px - var(--default-clickable-area, 34px)) / 2);
		}

		.notification__new {
			padding: 1px 6px;
			border-radius: 8px;
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
			font-size: 10px;
			font-weight: 700;
			letter-spacing: .04em;
			text-transform: uppercase;
			white-space: nowrap;
		}

		.post-timestamp {
			color: var(--color-text-lighter);
		}

		a:hover {
			text-decoration: underline;
		}
	}

	&__direct {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 4px 12px;
		color: var(--color-text-maxcontrast);
	}

	&__direct-preview {
		margin: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		max-width: 100%;
	}

	&__direct-open {
		flex-shrink: 0;
		font-size: 12px;
		font-weight: 600;
		color: var(--color-primary-element);

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	:deep(.post-header) {
		.post-visibility,
		.post-timestamp {
			display: none;
		}
	}

	:deep(.user-entry) {
		.user-avatar {
			display: none;
		}
	}
}

.interest-reason {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	max-width: 100%;
	margin-bottom: 6px;
	padding: 2px 10px 2px 6px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 13px;

	&:hover,
	&:focus-visible {
		color: var(--color-main-text);
	}

	// not a link
	&--popular:hover,
	&--topic:hover {
		color: var(--color-text-maxcontrast);
	}

	&__text {
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
	}
}

.boost {
	color: var(--color-text-lighter);
	font-size: 13px;
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 6px;
	padding-inline-start: 4px;
	// fades when the entry above opens its actions over it; see `.timeline-entry`
	transition: opacity .16s ease;

	a {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-weight: 600;
		color: var(--color-main-text);

		&:hover {
			color: var(--color-primary-element);
		}
	}

	.post-author {
		font-size: inherit;
	}
}

// in a list the boost icon is centred over the avatar column
.timeline-entry:not(.notification, .timeline-entry--direct) .boost {
	gap: 10px;
	padding-inline-start: 0;

	> .material-design-icon {
		display: flex;
		justify-content: center;
		inline-size: 40px;

		@include layout.below(layout.$phone) {
			inline-size: 36px;
		}
	}
}

@media (prefers-reduced-motion: reduce) {
	.boost {
		transition: none;
	}
}

/*
 * On a phone the face moves inside the card, smaller (`PHONE_AVATAR`), so
 * posts get the full width. Same breakpoint as `PHONE_WIDTH` in services/phone.js.
 */
@include layout.below(layout.$phone) {
	// a smaller indent, so nested replies keep room for content and actions
	.timeline-entry--reply {
		margin-inline-start: calc(var(--thread-depth, 1) * 12px);
		padding-inline-start: 10px;
	}

	// not for a notification (its faces are in the summary) or a direct message
	.timeline-entry:not(.notification, .timeline-entry--direct) .wrapper {
		position: relative;
		gap: 0;

		.entry__avatar {
			position: absolute;
			top: 12px;
			inset-inline-start: 12px;
			z-index: 2;
			margin-top: 0;
		}

		:deep(.post-header) {
			// the face is 36 wide and sits 12 in; the card's own padding is 16
			padding-inline-start: 36px;
			min-height: 36px;
			align-items: center;
		}
	}
}

@keyframes timeline-rise {
	from {
		opacity: 0;
		transform: translateY(6px);
	}

	to {
		opacity: 1;
		transform: none;
	}
}

/* no rise and no stagger, which would otherwise hold a card invisible for its delay */
@media (prefers-reduced-motion: reduce) {
	.timeline-entry {
		animation: none;
	}
}

/*
 * In a list, a post or notification is a row rather than a card, with a
 * hairline between rows. A direct message keeps its bubble (DirectMessages.vue),
 * and a post outside a list keeps its card look.
 */
.timeline-entry:not(.timeline-entry--direct) {
	position: relative;
	margin-bottom: 13px;
	padding-block: 14px 13px;
	padding-inline: 8px;
	border-radius: var(--border-radius-large, 8px);
	transition: background-color .15s ease;

	// the hairline in the middle of the gap; none before a reply, whose
	// thread line joins the two
	&:not(:last-child):not(:has(+ .timeline-entry--reply))::after {
		content: '';
		position: absolute;
		inset-inline: 8px;
		inset-block-end: -7px;
		block-size: 1px;
		background: var(--color-border);
		pointer-events: none;
	}

	&:last-child {
		margin-bottom: 0;
	}

	// a gap would cut a reply's elbow line
	&:has(+ .timeline-entry--reply) {
		margin-bottom: 0;
	}

	// the whole row opens the thread
	&:hover {
		background-color: var(--color-background-hover);
	}

	.wrapper {
		align-items: flex-start;
		gap: 10px;
	}

	// aligns the face's top with the name's letters
	.wrapper .entry__avatar {
		margin-top: 2px;
	}
}

// on a phone a post's face sits in the row's own corner
.timeline-entry:not(.notification, .timeline-entry--direct) {
	@include layout.below(layout.$phone) {
		padding-block-start: 16px;

		.wrapper {
			gap: 0;
		}

		.wrapper .entry__avatar {
			top: 0;
			inset-inline-start: 0;
			margin-top: 0;
		}

		.wrapper :deep(.post-header) {
			padding-inline-start: 46px;
		}
	}
}

.timeline-entry:not(.timeline-entry--direct) {
	.wrapper :deep(.post-content),
	.wrapper :deep(.post-content:hover),
	.wrapper :deep(.post-content:focus-within) {
		padding: 0;
		border: none;
		border-radius: 0;
		background: transparent;
		box-shadow: none;
		transform: none;
	}

	.wrapper :deep(.post-content) {
		line-height: 1.55;
	}

	.wrapper :deep(.post-content .post-header) {
		margin-bottom: 2px;
	}

	.wrapper :deep(.post-content .post-message) {
		margin-bottom: 0;
	}

	// the last icon ends where the timestamp ends, despite the button's padding
	.wrapper :deep(.post-footer .post-actions-reveal) {
		margin-inline-end: -9px;
	}

	// likewise, the button's padding does not push the hairline down
	.wrapper :deep(.post-footer:not(:has(.reaction-bar))) {
		margin-bottom: -6px;
	}
}

/*
 * With a pointer, the controls take no room of their own: they float over the
 * top end of the row on hover or focus. A post with reactions or counts in
 * that row keeps it.
 */
@media (hover: hover) {
	.timeline-entry:not(.notification, .timeline-entry--direct) .wrapper :deep(.post-footer:not(:has(.reaction-bar, .post-action-count))) {
		height: 0;
		margin: 0;

		.post-actions-reveal {
			position: absolute;
			inset-block-start: -8px;
			inset-inline-end: 0;
			margin-inline-end: 0;
		}
	}

	// a like or boost already given shows as a mark by the timestamp, and
	// its button only on hover
	.timeline-entry:not(.notification, .timeline-entry--direct) .wrapper :deep(.post-content:not(:has(.reaction-bar, .post-action-count))) {
		.post-given {
			display: inline-flex;
		}

		&:not(:hover):not(:focus-within):not(:has(.post-actions-reveal--held)) .button-vue[aria-pressed="true"] .button-vue__icon {
			opacity: 0;
		}
	}
}

/*
 * Pointed at or focused, the row lifts 3px onto a soft shadow, and its
 * controls come to full strength, those without a count sliding in from the
 * end. The lift is `translate`, as `transform` is taken by the rise animation.
 */
@media (hover: hover) {
	.timeline-entry:not(.timeline-entry--direct) {
		// a softer corner than a card's, for an edge that comes and goes
		border-radius: 12px;
		// a transparent shadow at rest, so it grows rather than appears
		box-shadow: 0 1px 2px transparent, 0 2px 6px -6px transparent;
		transition: box-shadow .1s linear, translate .1s linear, background-color .1s linear;

		&:hover,
		&:focus-within {
			background-color: var(--color-main-background);
			box-shadow: 0 1px 3px color-mix(in srgb, var(--color-box-shadow) 35%, transparent), 0 12px 24px -10px color-mix(in srgb, var(--color-box-shadow) 70%, transparent);
			translate: 0 -3px;
			transition: box-shadow .25s ease, translate .25s cubic-bezier(.2, .8, .2, 1), background-color .2s ease;
		}

		// a separate rule so a browser without `:has()` keeps the hover case
		&:has(.post-actions-reveal--held) {
			background-color: var(--color-main-background);
			box-shadow: 0 1px 3px color-mix(in srgb, var(--color-box-shadow) 35%, transparent), 0 12px 24px -10px color-mix(in srgb, var(--color-box-shadow) 70%, transparent);
			translate: 0 -3px;
		}

		.wrapper :deep(.post-actions-reveal::before) {
			display: none;
		}

		&:is(:hover, :focus-within) .wrapper :deep(.post-actions .button-vue__icon),
		&:is(:hover, :focus-within) .wrapper :deep(.post-actions .action-item),
		&:is(:hover, :focus-within) .wrapper :deep(.post-actions .actions) {
			opacity: 1;
		}

		// the whole row is pointed at, the face's column too, so the controls
		// that take no room at rest (TimelinePost.scss) come back with it
		&:is(:hover, :focus-within) .wrapper :deep(.post-actions > :first-child),
		&:is(:hover, :focus-within) .wrapper :deep(.post-action-group) {
			@include layout.visually-shown;
		}

		// the controls without a count arrive from the end of the row
		.wrapper :deep(.post-actions > :first-child),
		.wrapper :deep(.post-action-group:not(:has(.post-action-count))) {
			transform: translateX(8px);
			transition: transform .1s linear;
		}

		&:is(:hover, :focus-within) .wrapper :deep(.post-actions > :first-child),
		&:is(:hover, :focus-within) .wrapper :deep(.post-action-group:not(:has(.post-action-count))),
		&:has(.post-actions-reveal--held) .wrapper :deep(.post-actions > :first-child),
		&:has(.post-actions-reveal--held) .wrapper :deep(.post-action-group:not(:has(.post-action-count))) {
			transform: none;
			transition: transform .3s cubic-bezier(.2, .8, .2, 1) .06s;
		}

		&:dir(rtl) .wrapper :deep(.post-actions > :first-child),
		&:dir(rtl) .wrapper :deep(.post-action-group:not(:has(.post-action-count))) {
			transform: translateX(-8px);
		}

		&:dir(rtl):is(:hover, :focus-within) .wrapper :deep(.post-actions > :first-child),
		&:dir(rtl):is(:hover, :focus-within) .wrapper :deep(.post-action-group:not(:has(.post-action-count))) {
			transform: none;
		}
	}

	// with no counts, the floating controls replace the timestamp
	.timeline-entry:not(.notification, .timeline-entry--direct):not(:has(.reaction-bar, .post-action-count)) {
		.wrapper :deep(.post-timestamp),
		.wrapper :deep(.post-given) {
			transition: opacity .1s linear;
		}

		&:is(:hover, :focus-within) .wrapper :deep(.post-timestamp),
		&:is(:hover, :focus-within) .wrapper :deep(.post-given),
		&:has(.post-actions-reveal--held) .wrapper :deep(.post-timestamp),
		&:has(.post-actions-reveal--held) .wrapper :deep(.post-given) {
			opacity: 0;
		}
	}
}

@media (hover: hover) and (prefers-reduced-motion: reduce) {
	.timeline-entry:not(.timeline-entry--direct),
	.timeline-entry:not(.timeline-entry--direct):is(:hover, :focus-within) {
		translate: none;
		transition: none;

		.wrapper :deep(.post-actions > :first-child),
		.wrapper :deep(.post-action-group) {
			transform: none;
			transition: none;
		}
	}
}

@media (forced-colors: active) {
	.timeline-entry:not(.timeline-entry--direct):is(:hover, :focus-within) {
		outline: 1px solid CanvasText;
		outline-offset: -1px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.timeline-entry:not(.timeline-entry--direct) {
		transition: none;
	}
}

/*
 * A run of posts by one author sits closer, without hairlines, joined by a
 * line down the face's column that starts under the face (14px padding + 2px
 * step + 40px face) and is centred on it.
 */
.entry__thread {
	flex: 0 0 auto;
}

.timeline-entry:not(.notification, .timeline-entry--direct) {
	&:has(+ .timeline-entry--grouped) {
		margin-bottom: 0;

		&::after {
			content: none;
		}
	}

	&.timeline-entry--grouped {
		padding-block-start: 6px;
	}

	&:has(+ .timeline-entry--grouped)::before,
	&.timeline-entry--grouped::before {
		content: '';
		position: absolute;
		inset-inline-start: 27px;
		inline-size: 2px;
		border-radius: 1px;
		background: var(--color-border);
		pointer-events: none;
	}

	&:has(+ .timeline-entry--grouped)::before {
		inset-block: 60px 0;
	}

	&.timeline-entry--grouped::before {
		inset-block-start: 0;
		block-size: 16px;
	}

	&.timeline-entry--grouped:has(+ .timeline-entry--grouped)::before {
		block-size: auto;
		inset-block-end: 0;
	}

	// a phone has no face column, so there is no line
	@include layout.below(layout.$phone) {
		&:has(+ .timeline-entry--grouped)::before,
		&.timeline-entry--grouped::before {
			content: none;
		}

		.entry__thread {
			display: none;
		}

		&.timeline-entry--grouped .wrapper :deep(.post-header) {
			padding-inline-start: 0;
			min-height: 0;
		}
	}

	// a forced palette would paint the line in the page's own colour
	@media (forced-colors: active) {
		&:has(+ .timeline-entry--grouped)::before,
		&.timeline-entry--grouped::before {
			forced-color-adjust: none;
			background: CanvasText;
		}
	}
}
</style>
