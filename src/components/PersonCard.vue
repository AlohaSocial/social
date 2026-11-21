<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<li class="person">
		<component
			:is="link ? 'router-link' : 'a'"
			class="person__link"
			v-bind="target">
			<!-- a directory result's own picture, used directly -->
			<img
				v-if="picture && !pictureFailed"
				class="person__avatar"
				:src="picture"
				alt=""
				loading="lazy"
				@error="pictureFailed = true">
			<!-- an account on this Nextcloud without a picture gets the generated
			     one; anybody else gets a letter, since NcAvatar draws a 404 as a
			     question mark -->
			<ActorAvatar
				v-else-if="!picture && onThisServer"
				class="person__avatar"
				:actor="account"
				:size="44"
				:link="false"
				:preview="false" />
			<span v-else class="person__avatar person__avatar--blank" aria-hidden="true">
				{{ initial }}
			</span>

			<span class="person__text">
				<span class="person__name">
					{{ account.display_name || account.username }}
					<BlueskyBadge v-if="isBluesky" />
					<VerifiedBadge :account="account" />
					<span v-if="account.bot" class="person__bot">{{ t('social', 'bot') }}</span>
				</span>
				<span class="person__handle">@{{ account.acct }}</span>
				<!-- why this person is listed: which directory answered, who of
				     your follows follows them, or that they are on this Nextcloud -->
				<span v-if="reason" class="person__reason">{{ reason }}</span>
				<span v-if="summary" class="person__note">{{ summary }}</span>
			</span>
		</component>

		<!-- not `FollowButton`, which waits for a relationship the store never
		     fetches for a stranger; this follows by handle -->
		<NcButton
			class="person__follow"
			:variant="followed ? 'success' : 'primary'"
			:disabled="pending || followed"
			@click="$emit('follow', account)">
			<template v-if="pending" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ followed ? t('social', 'Following') : t('social', 'Follow') }}
		</NcButton>
	</li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ActorAvatar from './ActorAvatar.vue'
import BlueskyBadge from './BlueskyBadge.vue'
import VerifiedBadge from './VerifiedBadge.vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { isBlueskyAccount, isLocalAccount } from '../utils/accountLocality.js'

/**
 * One person in a list of people on the Discover page, drawn the same way in
 * every list, with the reason they are shown.
 */
export default {
	name: 'PersonCard',

	components: {
		ActorAvatar,
		BlueskyBadge,
		NcButton,
		NcLoadingIcon,
		VerifiedBadge,
	},

	props: {
		/** an Account entity */
		account: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Account>} */ (Object),
			required: true,
		},

		/** Why this person is being shown; empty for no line at all. */
		reason: {
			type: String,
			default: '',
		},

		/** Whether this instance knows them well enough for a local profile. */
		link: {
			type: Boolean,
			default: false,
		},

		followed: {
			type: Boolean,
			default: false,
		},

		pending: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['follow'],

	data() {
		return {
			/** a directory's picture URL that 404s leaves a broken frame */
			pictureFailed: false,
		}
	},

	computed: {
		/**
		 * The picture of an account this instance does not know, as the
		 * directory gave it; '' otherwise, where `ActorAvatar` or a letter is
		 * drawn instead.
		 *
		 * @return {string}
		 */
		picture() {
			const avatar = this.account.avatar ?? ''

			return (typeof avatar === 'string' && /^https?:\/\//.test(avatar) && !this.link)
				? avatar
				: ''
		},

		/**
		 * @return {boolean} whether this account lives on this Nextcloud, where
		 * a missing picture is generated rather than fetched
		 */
		onThisServer() {
			return isLocalAccount(this.account)
		},

		/** @return {boolean} */
		isBluesky() {
			return isBlueskyAccount(this.account)
		},

		/** @return {string} the letter a blank avatar shows */
		initial() {
			const name = this.account.display_name || this.account.username || this.account.acct || '?'

			return [...name][0].toUpperCase()
		},

		/**
		 * Where the name goes: this instance's profile page where it knows the
		 * account, otherwise their own server, as a local profile would be a 404.
		 *
		 * @return {object} the attributes to bind
		 */
		target() {
			if (this.link) {
				return { to: { name: 'profile', params: { account: this.account.acct } } }
			}

			return {
				href: this.account.url || '#',
				target: '_blank',
				rel: 'noopener noreferrer',
			}
		},

		/**
		 * The bio, as one line of plain text: `note` is HTML from another
		 * server and is not rendered here.
		 *
		 * @return {string}
		 */
		summary() {
			const note = this.account.note ?? ''
			if (note === '') {
				return ''
			}

			const text = note
				.replace(/<br\s*\/?>|<\/p>/gi, ' ')
				.replace(/<[^>]*>/g, '')
				.replace(/&nbsp;/g, ' ')
				.replace(/\s+/g, ' ')
				.trim()

			// the entities a bio actually carries, decoded by the browser
			// rather than by a table of our own
			const decoded = new DOMParser().parseFromString(text, 'text/html').documentElement.textContent

			return (decoded ?? text).trim()
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.person {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding-inline-end: calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
	transition: background-color var(--social-motion-fast) var(--social-ease);

	&:hover,
	&:focus-within {
		background-color: var(--color-background-dark);
	}
}

.person__link {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
	flex: 1 1 260px;
	color: inherit;
}

.person__avatar {
	flex: 0 0 auto;
	width: 44px;
	height: 44px;
	border-radius: 50%;
	object-fit: cover;
	background-color: var(--color-background-dark);
}

.person__avatar--blank {
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.person__text {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
	flex: 1 1 auto;
}

.person__name {
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* said quietly, because it is a fact about the account rather than a warning */
.person__bot {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 6px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
	font-size: var(--font-size-small, 0.85em);
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.person__handle,
.person__note,
.person__reason {
	font-size: var(--font-size-small, 0.85em);
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* the one line that says why this row is here, so it reads before the bio */
.person__reason {
	color: var(--color-primary-element);
}

.person__follow {
	flex: 0 0 auto;
}

/* the hover is the only thing that moves here, and somebody who has asked for
   less movement has asked for this one too */
@media (prefers-reduced-motion: reduce) {
	.person {
		transition: none;
	}
}
</style>
