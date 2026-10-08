<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<blockquote v-if="quote" class="quoted-post" :cite="citeUrl">
		<template v-if="quotedStatus">
			<router-link
				class="quoted-post__author-link"
				:to="{ name: 'profile', params: { account: quotedAccount.acct } }">
				<ActorAvatar
					:actor="quotedAccount"
					:size="20"
					:hoverCard="false"
					:link="false" />
				<span class="quoted-post__author">
					<DisplayName :text="quotedAccount.display_name" :emojis="quotedAccount.emojis" />
				</span>
				<BlueskyBadge v-if="isBlueskyAccount(quotedAccount)" />
				<span class="quoted-post__handle">@{{ quotedAccount.acct }}</span>
			</router-link>
			<div class="quoted-post__message">
				<MessageContent :item="quotedStatus" />
			</div>
			<!-- a quote of a quote is rendered as this line and never as another
			     card: the chain can be arbitrarily long, and two posts quoting
			     each other would otherwise recurse until the stack gives out -->
			<p v-if="quotedStatus.quote" class="quoted-post__deeper">
				{{ t('social', 'The quoted post quotes another post.') }}
			</p>
			<router-link class="quoted-post__open" :to="quotedRoute">
				{{ t('social', 'Open the quoted post') }}
			</router-link>
		</template>
		<p v-else class="quoted-post__notice">
			{{ notice }}
		</p>
	</blockquote>
</template>

<script>
import ActorAvatar from './ActorAvatar.vue'
import BlueskyBadge from './BlueskyBadge.vue'
import DisplayName from './DisplayName.js'
import MessageContent from './MessageContent.js'
import { isBlueskyAccount } from '../utils/accountLocality.js'

export default {
	name: 'QuotedPost',
	components: {
		ActorAvatar,
		BlueskyBadge,
		DisplayName,
		MessageContent,
	},

	props: {
		/**
		 * The `quote` of a status: `{ state, quoted_status }`, or null when the
		 * post quotes nothing. `quoted_status` is only ever filled in when the
		 * quote was accepted and the reader is allowed to read the quoted post,
		 * so every other combination has to read as an explanation.
		 */
		quote: {
			type: /** @type {import('vue').PropType<{state: string, quoted_status: ?object}>} */ (Object),
			default: null,
		},
	},

	computed: {
		/** @return {?object} the quoted post, when there is one to show */
		quotedStatus() {
			const quoted = this.quote?.quoted_status ?? null

			// an accepted quote whose post did not come with it is one the
			// reader may not read; it is not an error and must not render half
			// a card, so it falls through to the notice
			return this.quote?.state === 'accepted' && quoted?.account ? quoted : null
		},

		/** @return {object} */
		quotedAccount() {
			return this.quotedStatus.account
		},

		/** @return {object} the conversation of the quoted post */
		quotedRoute() {
			return {
				name: 'single-post',
				params: {
					account: this.quotedAccount.acct,
					id: this.quotedStatus.id,
					type: 'single-post',
				},
			}
		},

		/** @return {string|undefined} what the quotation is of, for the markup */
		citeUrl() {
			return this.quotedStatus?.url || undefined
		},

		/**
		 * @return {string} why there is no quoted post here. A state this
		 * version does not know reads as unavailable rather than as an error:
		 * the set is Mastodon's and it can grow.
		 */
		notice() {
			switch (this.quote.state) {
				case 'pending':
					return t('social', 'This quote is waiting for the quoted author to approve it.')
				case 'rejected':
					return t('social', 'The author of the quoted post did not allow this quote.')
				case 'revoked':
					return t('social', 'The author of the quoted post withdrew their permission for this quote.')
				default:
					return t('social', 'The quoted post is not available.')
			}
		},
	},

	methods: {
		isBlueskyAccount,
	},
}
</script>

<style scoped lang="scss">
/**
 * Somebody else's post inside this one: set in, ruled off and a shade darker,
 * so it cannot be read as something the author of the outer post wrote.
 */
.quoted-post {
	margin: 8px 0 10px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-inline-start: 3px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	font-size: 14px;

	&__author-link {
		display: flex;
		align-items: center;
		gap: 6px;
		min-width: 0;
		color: var(--color-main-text);

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 1px;
		}
	}

	&__author {
		font-weight: 650;
		letter-spacing: -.01em;
	}

	&__handle {
		color: var(--color-text-lighter);
		font-size: 13px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__message {
		margin-top: 6px;
		overflow-wrap: break-word;

		:deep(p) {
			margin: 0 0 6px;

			&:last-child {
				margin-bottom: 0;
			}
		}

		:deep(a) {
			overflow-wrap: anywhere;
		}

		:deep(img) {
			max-width: 100%;
			height: auto;
		}

		:deep(img.emoji) {
			display: inline-block;
			width: 1em;
			height: 1em;
			max-width: none;
			margin: 0 .08em;
			vertical-align: -.15em;
		}

		:deep(img.custom-emoji) {
			display: inline-block;
			width: auto;
			height: 1.25em;
			max-width: none;
			margin: 0 .08em;
			vertical-align: -.2em;
		}
	}

	&__deeper,
	&__notice {
		color: var(--color-text-lighter);
		font-size: 13px;
	}

	&__deeper {
		margin-top: 6px;
	}

	&__open {
		display: inline-block;
		margin-top: 6px;
		font-size: 13px;
		color: var(--color-primary-element);

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 1px;
		}
	}
}
</style>
