<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- `avatarProps` sets `disableMenu`: the preview is this app's hover card, never Nextcloud's -->
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
import { isLocalAccount, nextcloudUserOf } from '../utils/accountLocality.js'
import { accountAvatarUrl } from '../utils/accountAvatar.js'
import { patternAvatarUrl } from '../utils/patternAvatar.js'

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

		/** Whether hovering this avatar previews the account. */
		hoverCard: {
			type: Boolean,
			default: true,
		},

		/**
		 * Whether clicking this avatar opens the account's profile. Turn it off
		 * where the avatar already sits inside a link, since nested anchors are
		 * invalid.
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
		 * What wraps the avatar: a router link where there is a router, a plain
		 * link where there is not (the profile section on a Nextcloud user's
		 * page has none), and a span where there is nowhere to go.
		 *
		 * @return {string}
		 */
		linkTag() {
			if (!this.link || !this.actor?.acct) {
				return 'span'
			}
			if (this.isLocal && this.actor.username) {
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
			if (this.linkTag === 'a' && this.isLocal && this.actor.username) {
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
		 * What NcAvatar is given, shared by the hovered and the plain avatar.
		 *
		 * @return {object}
		 */
		avatarProps() {
			return {
				size: this.size,
				hideStatus: true,
				disableTooltip: true,
				// NcAvatar would open Nextcloud's own profile card on a local
				// account's avatar, on top of this app's hover card
				disableMenu: true,
				// the placeholder flower for an account without a picture, else
				// the Nextcloud user's avatar for a local account, else the
				// server's picture
				...(this.pattern !== ''
					? { url: this.pattern }
					: this.nextcloudUser !== ''
						? { user: this.nextcloudUser, displayName: this.actor.acct }
						: { url: this.avatarUrl }),
			}
		},

		/** @return {string} the placeholder flower, or '' for an account with a picture */
		pattern() {
			return patternAvatarUrl(this.actor)
		},

		/** @return {string} */
		avatarUrl() {
			// actors cached without an icon have no avatar URL; the endpoint
			// resolves one from the ActivityPub id
			return accountAvatarUrl(this.actor, this.size)
				|| generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + encodeURIComponent(this.actor.url ?? ''))
		},

		/**
		 * @return {boolean}
		 */
		isLocal() {
			return isLocalAccount(this.actor)
		},

		/** @return {string} the Nextcloud user of a local account, or '' */
		nextcloudUser() {
			return nextcloudUserOf(this.actor)
		},
	},
}
</script>
