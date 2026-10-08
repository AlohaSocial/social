<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		v-if="item.account"
		class="post-avatar"
		:class="{ 'post-avatar--remote': !origin.local, 'post-avatar--compact': size !== null }"
		:style="{ '--instance-colour': origin.colour, '--avatar-size': size === null ? undefined : size + 'px' }"
		:title="origin.local ? undefined : origin.instance">
		<!-- the ring says which instance in colour, which is nothing at all to
		     a reader who cannot see it or cannot tell two hues apart. The post
		     header shows the instance in words too, but not every avatar sits
		     next to one -->
		<span v-if="!origin.local" class="hidden-visually">
			{{ t('social', 'Account on {instance}', { instance: origin.instance }) }}
		</span>
		<AccountHoverCard
			:handle="item.account.acct"
			:fallback="item.account"
			variant="block"
			placement="bottom-start">
			<!-- the face beside a post is the most obvious thing on screen to
			     click, and it did nothing. `component :is` because the profile
			     section on a Nextcloud user page runs an app with no router in
			     it, where a `router-link` resolves to nothing and would take
			     the avatar with it -->
			<!-- `disableMenu`: NcAvatar hangs Nextcloud's own profile card off a
			     local account's avatar on hover, and it opened over this one.
			     See ActorAvatar, which says the rest. -->
			<component :is="linkTag" v-bind="linkProps">
				<NcAvatar
					v-if="isLocal"
					class="messages__avatar__icon"
					:hideStatus="true"
					:user="item.account.username"
					:displayName="item.account.display_name"
					:size="size ?? undefined"
					:disableMenu="true"
					:disableTooltip="true" />
				<NcAvatar
					v-else
					:url="item.account.avatar"
					:size="size ?? undefined"
					:disableMenu="true"
					:disableTooltip="true" />
			</component>
		</AccountHoverCard>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { translate } from '@nextcloud/l10n'
import AccountHoverCard from './AccountHoverCard.vue'
import { originOf } from '../utils/instanceIdentity.js'
import { localProfileUrl } from '../utils/accountProfileLink.js'
import { isLocalAccount } from '../utils/accountLocality.js'

export default {
	name: 'TimelineAvatar',
	components: {
		AccountHoverCard,
		NcAvatar,
	},

	props: {
		item: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			default: () => {},
		},

		/**
		 * The face's size in pixels, or null for the default. On a phone the
		 * entry asks for a smaller one and puts it inside the card.
		 */
		size: {
			type: Number,
			default: null,
		},
	},

	computed: {
		/**
		 * @return {string}
		 */
		userTest() {
			return this.item.account.display_name
		},

		/** @return {boolean} */
		isLocal() {
			return isLocalAccount(this.item.account)
		},

		/**
		 * @return {string} what wraps the avatar — see the note in ActorAvatar,
		 * which follows the same three cases for the same reason
		 */
		linkTag() {
			if (!this.item.account.acct) {
				return 'span'
			}
			if (this.isLocal && this.item.account.username) {
				return 'a'
			}
			if (this.$router !== undefined) {
				return 'router-link'
			}

			return (this.item.account.url) ? 'a' : 'span'
		},

		/** @return {object} what that wrapper needs */
		linkProps() {
			if (this.linkTag === 'span') {
				return {}
			}

			const label = t('social', 'Open the profile of {account}', { account: this.item.account.acct })
			if (this.linkTag === 'a' && this.isLocal && this.item.account.username) {
				return { href: localProfileUrl(this.item.account), 'aria-label': label }
			}
			if (this.linkTag === 'router-link') {
				return {
					to: { name: 'profile', params: { account: this.item.account.acct } },
					'aria-label': label,
				}
			}

			return {
				href: this.item.account.url,
				target: '_blank',
				rel: 'nofollow noopener noreferrer',
				'aria-label': label,
			}
		},

		/** @return {{instance: string, colour: string, local: boolean}} where the author lives */
		origin() {
			return originOf(this.item.account)
		},
	},

	methods: {
		t: translate,
	},
}
</script>

<style scoped lang='scss'>
.post-avatar {
	padding: 5px 10px 10px 5px;
	height: 52px;
	width: 52px;

	/* a ring in the colour of the server the author is on, so a timeline
	   visibly spans instances instead of hiding it after the @ */
	/* sized by the caller: no padding, the box is the face */
	&--compact {
		padding: 0;
		height: var(--avatar-size);
		width: var(--avatar-size);
	}

	&--remote :deep(.avatardiv) {
		box-shadow: 0 0 0 2px var(--color-main-background), 0 0 0 4px var(--instance-colour);
	}
}
</style>
