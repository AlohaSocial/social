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
		<!-- the instance in words, for readers who cannot see the ring's colour -->
		<span v-if="!origin.local" class="hidden-visually">
			{{ t('social', 'Account on {instance}', { instance: origin.instance }) }}
		</span>
		<AccountHoverCard
			:handle="item.account.acct"
			:fallback="item.account"
			variant="block"
			placement="bottom-start">
			<!-- `component :is` because the profile section on a Nextcloud user
			     page has no router, where a `router-link` would render nothing.
			     `disableMenu` keeps NcAvatar's own profile card from opening over
			     this one (see ActorAvatar). -->
			<component :is="linkTag" v-bind="linkProps">
				<NcAvatar
					v-if="pattern !== ''"
					:url="pattern"
					:size="size ?? undefined"
					:disableMenu="true"
					:disableTooltip="true" />
				<NcAvatar
					v-else-if="nextcloudUserOf(item.account) !== ''"
					class="messages__avatar__icon"
					:hideStatus="true"
					:user="nextcloudUserOf(item.account)"
					:displayName="item.account.display_name"
					:size="size ?? undefined"
					:disableMenu="true"
					:disableTooltip="true" />
				<NcAvatar
					v-else
					:url="accountAvatarUrl(item.account, size) || undefined"
					:size="size ?? undefined"
					:disableMenu="true"
					:disableTooltip="true" />
			</component>
		</AccountHoverCard>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { translate as t } from '@nextcloud/l10n'
import AccountHoverCard from './AccountHoverCard.vue'
import { originOf } from '../utils/instanceIdentity.js'
import { localProfileUrl } from '../utils/accountProfileLink.js'
import { isLocalAccount, nextcloudUserOf } from '../utils/accountLocality.js'
import { accountAvatarUrl } from '../utils/accountAvatar.js'
import { patternAvatarUrl } from '../utils/patternAvatar.js'

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

		/** @return {string} the placeholder flower, or '' for an account with a picture */
		pattern() {
			return patternAvatarUrl(this.item.account)
		},

		/** @return {{instance: string, colour: string, local: boolean}} where the author lives */
		origin() {
			return originOf(this.item.account)
		},
	},

	methods: {
		accountAvatarUrl,
		t,
		nextcloudUserOf,
	},
}
</script>

<style scoped lang='scss'>
.post-avatar {
	padding: 5px 10px 10px 5px;
	height: 52px;
	width: 52px;

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
