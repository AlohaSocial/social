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
		}"
		:style="entryStyle"
		:data-status-id="entryContent?.id"
		tabindex="-1">
		<div v-if="isNotification" class="notification__header">
			<span class="notification__summary">
				<!-- one face, or the first few of the several this card stands
				     for, overlapping so a row of nine cannot push the words off
				     the card -->
				<span v-if="groupedAccounts.length > 1" class="notification__faces">
					<ActorAvatar
						v-for="account in groupedAccounts"
						:key="account.id || account.acct"
						:actor="account"
						:size="24"
						:link="false" />
				</span>
				<ActorAvatar v-else :actor="notification.account" :size="24" />
				<Heart v-if="notification.type === 'favourite'" :size="16" />
				<Repeat v-if="notification.type === 'reblog'" :size="16" />
				<AccountPlusOutline v-if="notification.type === 'follow'" :size="16" />
				<AccountQuestion v-if="notification.type === 'follow_request'" :size="16" />
				<At v-if="notification.type === 'mention'" :size="16" />
				<MessageOutline v-if="notification.type === 'status'" :size="16" />
				<MessagePlusOutline v-if="notification.type === 'update'" :size="16" />
				<Poll v-if="notification.type === 'poll'" :size="16" />
				{{ actionSummary }}
			</span>
			<span class="notification__details">
				<!-- what the badge was counting, said on the card itself: the
				     tint behind an unread notification is a pale frame around
				     an opaque post, which is easy to miss and impossible to
				     see at all for a reader who cannot tell the two apart -->
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
			</span>
		</div>
		<template v-else-if="isBoost">
			<div class="boost">
				<Repeat :size="16" />
				<router-link :to="{ name: 'profile', params: { account: item.account.acct } }">
					<ActorAvatar :actor="item.account" :size="16" :link="false" />
					<span :title="item.account.acct" class="post-author">
						{{ item.account.display_name }}&ensp;
					</span>
				</router-link>
				{{ t('social', 'boosted') }}
			</div>
		</template>
		<!-- why For you put the post here, and the way to the tag that
		     did it. Above the post rather than inside it: it is the feed's
		     remark about the post, not part of what the author wrote -->
		<router-link
			v-if="interest !== null && interest.tag !== null"
			class="interest-reason"
			:to="{ name: 'tags', params: { tag: interest.tag } }"
			:aria-label="interest.label">
			<Pound :size="16" />
			<span class="interest-reason__text">{{ interest.text }}</span>
		</router-link>
		<!-- a post no hashtag brought: there is no tag to go to -->
		<span
			v-else-if="interest !== null"
			class="interest-reason interest-reason--popular"
			role="note"
			:aria-label="interest.label">
			<TrendingUp :size="16" />
			<span class="interest-reason__text">{{ interest.text }}</span>
		</span>
		<UserEntry v-if="isNotification && notificationIsAboutAnAccount" :displayFollowButton="false" :item="item.account" />
		<template v-else>
			<div v-if="entryContent" class="wrapper">
				<TimelineAvatar
					v-if="!isNotification && !hideAvatar"
					class="entry__avatar"
					:item="entryContent"
					:size="avatarSize" />
				<TimelinePost
					class="entry__content"
					:item="entryContent"
					:type="type"
					:postHref="postHref"
					:hideAuthor="hideAuthor"
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
import { fromNow, fullDateTime } from '../utils/relativeTime.js'
import Bell from 'vue-material-design-icons/Bell.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import At from 'vue-material-design-icons/At.vue'
import Poll from 'vue-material-design-icons/Poll.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import Pound from 'vue-material-design-icons/Pound.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import { translate } from '@nextcloud/l10n'
import TimelinePost from './TimelinePost.vue'
import ActorAvatar from './ActorAvatar.vue'
import TimelineAvatar from './TimelineAvatar.vue'
import UserEntry from './UserEntry.vue'
import { GROUP_FACES, notificationSummary } from '../services/notifications.js'
import { interestReason } from '../utils/interestReason.js'
import { onTick } from '../services/clock.js'
import { isPhone, onPhoneChange } from '../services/phone.js'
import { mapStores } from 'pinia'
import { useTimelineStore } from '../store/timeline.js'

/** the face's size inside the card, on a phone */
const PHONE_AVATAR = 36

/** how many levels of a conversation are indented before the column runs out */
const MAX_INDENT = 4

/**
 * How many entries are staggered on the way in.
 *
 * Only what a reader can plausibly see at first paint: past that the delay
 * would be time spent looking at nothing, and a page appended by the infinite
 * scroll is already on screen by the time it arrives, so it fades with no
 * delay at all rather than counting up from wherever it landed.
 */
const STAGGER_DEPTH = 8

/** how far apart the staggered ones start, in milliseconds */
const STAGGER_STEP = 45

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		ActorAvatar,
		TimelineAvatar,
		UserEntry,
		Bell,
		Repeat,
		Heart,
		AccountPlusOutline,
		AccountQuestion,
		At,
		Poll,
		MessageOutline,
		MessagePlusOutline,
		Pound,
		TrendingUp,
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
		 * How deep this entry sits in a conversation: 0 for a reply to the post
		 * being read, 1 for a reply to that, and so on. Indented accordingly,
		 * up to MAX_INDENT levels — deeper than that the column would run out.
		 */
		/**
		 * Whether something below this entry hangs off it.
		 *
		 * A reply at the end of a branch stops the line at its own elbow; one
		 * with replies under it carries the line on down, so a reader can see
		 * where a branch continues without counting indents.
		 */
		continues: {
			type: Boolean,
			default: false,
		},

		depth: {
			type: Number,
			default: 0,
		},

		element: {
			type: String,
			default: 'li',
		},

		/**
		 * Whether this arrived after the reader last read their notifications.
		 * Drawn as a tint and a bar down the leading edge, so the new ones can
		 * be picked out of a page that is mostly not new.
		 */
		unread: {
			type: Boolean,
			default: false,
		},

		/**
		 * Where this entry sits in the list, so the first screenful can come
		 * in one after another instead of all at once. Only the first few are
		 * staggered — see STAGGER_DEPTH.
		 */
		index: {
			type: Number,
			default: 0,
		},
	},

	data() {
		return {
			/**
			 * On a phone the avatar column beside the card would take a
			 * quarter of the width, so the face moves inside the card and
			 * shrinks; see the `@media` block below.
			 */
			isPhone: isPhone(),
			MAX_INDENT,
			/** re-read from the shared clock, so the wording stays true */
			now: Date.now(),
			/** unsubscribe from the phone-width watch and the shared clock */
			stopPhoneWatch: null,
			stopTicking: null,
		}
	},

	computed: {
		/** the face's size: smaller on a phone, where it sits inside the card */
		avatarSize() {
			return this.isPhone ? PHONE_AVATAR : null
		},

		/**
		 * The thread indent and the stagger delay, which are both one custom
		 * property on the same element.
		 *
		 * @return {object|undefined} the style, or undefined when there is
		 *                            neither an indent nor a delay to set
		 */
		entryStyle() {
			const style = {}

			if (this.depth > 0) {
				style['--thread-depth'] = Math.min(this.depth, MAX_INDENT)
			}

			if (this.index < STAGGER_DEPTH) {
				style['--stagger-delay'] = `${this.index * STAGGER_STEP}ms`
			}

			return Object.keys(style).length > 0 ? style : undefined
		},

		...mapStores(useTimelineStore),
		/**
		 * @return {import('../types/Mastodon.js').Status}
		 */
		entryContent() {
			if (this.isNotification) {
				return this.notification.status
			} else if (this.isBoost) {
				// We use the object stored in the store so that actions on it are reflected.
				return this.timelineStore.getStatus(this.status.reblog.id)
			} else {
				return this.status
			}
		},

		/**
		 * Why For you shows this post, from the `interest` the feed puts
		 * on each of its posts; every other timeline sends null.
		 *
		 * @return {{tag: string|null, text: string, label: string}|null}
		 */
		interest() {
			if (this.isNotification) {
				return null
			}

			return interestReason(this.status.interest ?? this.entryContent?.interest)
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
			return fromNow(this.notification.created_at, new Date(this.now))
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

		/** @return {boolean} */
		notificationIsAboutAnAccount() {
			return ['follow', 'follow_request', 'admin.sign_up', 'admin.report'].includes(this.notification.type)
		},

		/**
		 * The faces a grouped card shows: everyone in it, capped, so that nine
		 * people liking one post is a row of five and a count rather than nine
		 * avatars. Empty for a card that stands for one thing, which draws the
		 * single avatar instead.
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
		t: translate,
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

	/*
	 * Cards used to appear all at once, in one hard step from the skeleton to
	 * a full page. They now rise in, and the first screenful one after another
	 * so the eye is led down the column instead of having to find the top of a
	 * page that arrived whole. --stagger-delay is set by the component for the
	 * first few only; everything below the fold, and every page appended
	 * afterwards, has no delay and simply fades.
	 */
	animation: timeline-rise .28s ease-out both;
	animation-delay: var(--stagger-delay, 0ms);

	/**
	 * A reply steps in under the one it answers, joined to it by a line.
	 *
	 * The line was a plain border down the whole side, which is a margin
	 * marking rather than a connection: it began above the reply, ended below
	 * it, and pointed at nothing. Drawn as an elbow instead -- up from the
	 * reply's own left edge and curving in towards it -- it reads as coming
	 * *from* the post above, which is what a thread is. The depth is the
	 * custom property the list sets.
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
			// up past the gap into the post above, and down to the middle of
			// this one, where the elbow turns in
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

	// The post inside opens its actions into a panel that reaches over the gap
	// to the next entry, so the entry it belongs to has to be above the entries
	// below it. Its own z-index cannot do that: every entry carries the
	// scroll-driven `timeline-entry-rise` transform, which makes each one a
	// stacking context, and a z-index inside a stacking context cannot lift it
	// past a sibling. Without this the *next* entry's "X boosted" line is drawn
	// straight through the action icons.
	&:hover,
	&:focus-within {
		position: relative;
		z-index: 3;
	}

	// the same while the overflow menu holds a panel open with the pointer
	// somewhere else entirely. Its own rule: a browser without `:has()` drops
	// the selector, and it must not take the hover case with it.
	&:has(.post-actions-reveal--held) {
		position: relative;
		z-index: 3;
	}

	// The panel lands on the gap, and a boosted entry keeps its "X boosted"
	// byline there — which starts further left than the card, so the panel
	// covers all of it but the first few letters and leaves them sticking out
	// like a fault. The line steps out of the way instead: it is about to be
	// covered either way, and half a word is worse than none.
	&:hover + .timeline-entry .boost {
		opacity: 0;
	}

	&:has(.post-actions-reveal--held) + .timeline-entry .boost {
		opacity: 0;
	}

	// A notification is a card of its own: it is a thing that happened, and
	// the post inside it is quoted evidence. A boost is not — it is somebody
	// else's post with a line saying who passed it on, so giving it a card
	// too put a box inside a box and inset the post by the outer padding,
	// leaving boosted posts narrower than every post around them.
	&.with-header {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: 8px;
		padding: 14px;
	}

	&.notification {
		margin-bottom: 10px;
	}

	/* Arrived since the reader last looked. A tint and a bar, not a dot: the
	   page is read by running down it, and an edge is visible in peripheral
	   vision where a dot beside the timestamp is not. The border is already
	   there on a notification card, so this colours it rather than adding a
	   second one and shifting the card by 3px against its read neighbours. */
	&--unread.with-header {
		background: var(--color-primary-element-light);
		border-inline-start: 3px solid var(--color-primary-element);
		padding-inline-start: 12px;
	}
}

/* the faces of a card that stands for several people, overlapping */
.notification__faces {
	display: flex;
	align-items: center;

	> * + * {
		// each face tucks under the one before it, and its own ring keeps the
		// edge readable against the one it covers
		margin-inline-start: -8px;
		border-radius: 50%;
		box-shadow: 0 0 0 2px var(--color-main-background);
	}
}

.notification {
	&__header {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		padding-bottom: 4px;
	}

	&__summary {
		flex-grow: 1;
		display: flex;
		align-items: center;
		// the badge sits over the face's corner and reaches past it, so the
		// words start clear of the badge rather than under it
		gap: 12px;
		color: var(--color-text-lighter);
		font-size: 13px;
		position: relative;

		.material-design-icon {
			position: absolute;
			top: 12px;
			inset-inline-start: 14px;
			padding: 2px;
			background: var(--color-main-background);
			border-radius: 50%;
			border: 1px solid var(--color-background-dark);
		}
	}

	&__details {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 12px;

		/* the word rather than a dot: a dot has to be learnt, and there is
		   room for three letters beside a relative timestamp */
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

// the same quiet line a boost gets, as a link: it goes somewhere
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

	// words, not a link: nothing to answer a pointer with
	&--popular:hover {
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
	// it fades rather than vanishes when the entry above opens its actions
	// over it; see the rule in `.timeline-entry`
	transition: opacity .16s ease;

	a {
		font-weight: 600;
		color: var(--color-main-text);

		&:hover {
			color: var(--color-primary-element);
		}
	}
}

// the byline still steps out of the panel's way; it just stops fading to do it
@media (prefers-reduced-motion: reduce) {
	.boost {
		transition: none;
	}
}

/*
 * A phone. The avatar column beside the card took 64 of a phone's 390 pixels
 * and gave every post a quarter less room than the screen has, so the face
 * moves inside the card, smaller (`PHONE_AVATAR`), over the corner the header
 * leaves for it. Same number as `PHONE_WIDTH` in services/phone.js.
 */
@include layout.below(layout.$phone) {
	.wrapper {
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

/*
 * The stagger is decoration: the page is the same page without it, so it goes
 * away entirely rather than being made faster. `both` on the animation above
 * means a card would otherwise sit at the from-state for its whole delay.
 */
@media (prefers-reduced-motion: reduce) {
	.timeline-entry {
		animation: none;
	}
}
</style>
