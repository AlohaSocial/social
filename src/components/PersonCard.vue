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
			<!--
				A directory result carries an absolute URL to the account's own
				server; an account this instance knows carries one pointing at
				this server's document cache. Both are pictures and both load,
				so both are used directly — asking the avatar component to
				resolve one instead drew `NcAvatar`'s question mark for every
				account whose picture this server has not cached.
			-->
			<img
				v-if="picture && !pictureFailed"
				class="person__avatar"
				:src="picture"
				alt=""
				loading="lazy"
				@error="pictureFailed = true">
			<!-- an account *on* this Nextcloud with no picture of its own:
			     Nextcloud generates one, and the avatar component knows how to
			     ask for it. A remote account with no picture gets a letter
			     instead, because the only thing there was to resolve is an
			     address that answers 404 — which `NcAvatar` draws as a
			     question mark, and a list full of those says nothing -->
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
					<span v-if="account.bot" class="person__bot">{{ t('social', 'bot') }}</span>
				</span>
				<span class="person__handle">@{{ account.acct }}</span>
				<!-- why this person is on this page at all: which directory
				     answered, who of your follows follows them, or that they
				     are on this Nextcloud. A list of strangers with no reason
				     beside each is a list nobody can act on -->
				<span v-if="reason" class="person__reason">{{ reason }}</span>
				<span v-if="summary" class="person__note">{{ summary }}</span>
			</span>
		</component>

		<!-- `FollowButton` waits for a relationship the account store has
		     never fetched for a stranger, so in a list like this it renders
		     nothing; this follows by handle, which is all these rows carry -->
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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

/**
 * One person, in a list of people.
 *
 * Three lists on the Discover page showed a person — the search results, the
 * follow-graph suggestions and the accounts this server knows — and each drew
 * them differently: different avatar sizes, different spacing, the handle in
 * three weights, and a Follow button in two of them. A reader comparing three
 * lists of strangers should not also have to work out that they are the same
 * kind of thing.
 *
 * The row carries a **reason**. Whose directory answered, which of the people
 * you follow follows them, or that they are on this Nextcloud: a list of
 * strangers with no reason beside each is a list nobody can act on, and it is
 * the one thing that differs between the three lists.
 */
export default {
	name: 'PersonCard',

	components: {
		ActorAvatar,
		NcButton,
		NcLoadingIcon,
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
		 * The picture the row was handed, where it was handed one.
		 *
		 * A directory result carries an absolute URL to the account's own
		 * server. An account this instance knows carries one pointing at this
		 * server's document cache, and `ActorAvatar` builds a better one — so
		 * only a picture that is plainly somewhere else is used directly.
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
		 * @return {boolean} whether this account lives on this Nextcloud, which
		 * is the only case where there is a picture to generate rather than to
		 * fetch
		 */
		onThisServer() {
			return !(this.account.acct ?? '').includes('@')
		},

		/** @return {string} the letter a blank avatar shows */
		initial() {
			const name = this.account.display_name || this.account.username || this.account.acct || '?'

			return [...name][0].toUpperCase()
		},

		/**
		 * Where the name goes: this instance's own profile page where it knows
		 * the account, and their own server where it does not — following a
		 * profile link here for somebody nobody has heard of is a 404.
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
		 * The bio, as one line of text.
		 *
		 * `note` is HTML written on somebody else's server. Rendering it would
		 * put a stranger's markup in a list; printing it raw put `<p>` in
		 * front of every bio on the page. One line of its text is what a row
		 * this size can show anyway.
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
	transition: background-color .15s ease;

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
