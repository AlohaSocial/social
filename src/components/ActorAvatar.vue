<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- `avatarProps` carries `disableMenu`: the account preview in this app is
	     this app's own, everywhere, and never Nextcloud's -->
	<AccountHoverCard
		v-if="showHoverCard"
		:handle="actor.acct"
		:fallback="actor"
		variant="block">
		<component :is="linkTag" v-bind="linkProps">
			<NcAvatar v-bind="avatarProps" />
		</component>
	</AccountHoverCard>
	<component :is="linkTag" v-else v-bind="linkProps">
		<NcAvatar v-bind="avatarProps" />
	</component>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import AccountHoverCard from './AccountHoverCard.vue'
import { localProfileUrl } from '../utils/accountProfileLink.js'

export default {
	name: 'ActorAvatar',
	components: {
		AccountHoverCard,
		NcAvatar,
	},

	props: {
		actor: {
			type: /** @type {import('vue').PropType<Partial<import('../types/Mastodon.js').Account>>} */ (Object),
			default: () => {},
		},

		size: {
			type: Number,
			default: 32,
		},

		/**
		 * Whether hovering this avatar previews the account. Off where the
		 * avatar is decoration rather than a reference to somebody — the
		 * reader's own face in the composer, say.
		 */
		hoverCard: {
			type: Boolean,
			default: true,
		},

		/**
		 * Whether clicking this avatar opens the account's profile.
		 *
		 * On by default, because a face is the most obvious thing on screen to
		 * click and doing nothing is the one thing it should not do. Off where
		 * the avatar already sits inside a link — nesting one anchor in another
		 * is invalid and the browser resolves it by dropping content — and
		 * where following it would interrupt something, as it would from the
		 * composer's reply and quote lines.
		 */
		link: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			followingText: t('social', 'Following'),
		}
	},

	computed: {
		/**
		 * @return {boolean} an actor without a handle cannot be looked up
		 */
		showHoverCard() {
			return this.hoverCard && Boolean(this.actor.acct)
		},

		/**
		 * What wraps the avatar: the router where there is one, a plain link
		 * where there is not, and nothing at all where there is nowhere to go.
		 *
		 * The profile section this app adds to a Nextcloud user's page is a
		 * custom element with an app of its own and no router in it, so a
		 * `router-link` there resolves to nothing and swallows the avatar. The
		 * account's own address is what that surface can offer instead.
		 *
		 * @return {string}
		 */
		linkTag() {
			if (!this.link || !this.actor?.acct) {
				return 'span'
			}
			if (!this.actor.acct.includes('@') && this.actor.username) {
				return 'a'
			}
			if (this.$router !== undefined) {
				return 'router-link'
			}

			return (this.actor.url) ? 'a' : 'span'
		},

		/** @return {object} what that wrapper needs */
		linkProps() {
			if (this.linkTag === 'span') {
				return {}
			}

			const label = t('social', 'Open the profile of {account}', { account: this.actor.acct })
			if (this.linkTag === 'a' && !this.actor.acct.includes('@') && this.actor.username) {
				return { href: localProfileUrl(this.actor), 'aria-label': label }
			}
			if (this.linkTag === 'router-link') {
				return {
					to: { name: 'profile', params: { account: this.actor.acct } },
					'aria-label': label,
				}
			}

			return {
				href: this.actor.url,
				target: '_blank',
				rel: 'nofollow noopener noreferrer',
				'aria-label': label,
			}
		},

		/**
		 * What NcAvatar is given, in one place so the hovered and the plain
		 * avatar cannot drift apart.
		 *
		 * @return {object}
		 */
		avatarProps() {
			return {
				size: this.size,
				hideStatus: true,
				disableTooltip: true,
				// NcAvatar hangs Nextcloud's own profile card off a local
				// account's avatar, on hover, and it opened over the top of
				// this one: two cards about the same person, the larger of
				// them the one that knows nothing about following. Only local
				// avatars were affected, so which card a reader got depended
				// on which instance the account was on
				disableMenu: true,
				...(this.isLocal
					? { user: this.actor.username, displayName: this.actor.acct }
					: { url: this.avatarUrl }),
			}
		},

		/** @return {string} */
		avatarUrl() {
			// Remote actors are delivered with an avatar URL already pointing at this
			// server's document cache. Actors cached without an icon have none, so fall
			// back to the endpoint that resolves one from the ActivityPub id.
			return this.actor.avatar
				|| generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + encodeURIComponent(this.actor.url ?? ''))
		},

		/**
		 * @return {boolean}
		 */
		isLocal() {
			return !this.actor.acct.includes('@')
		},
	},
}
</script>
