<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- Show button only if user is authenticated and she is not the same as the account viewed -->
	<div v-if="!serverData.public && relationship !== undefined" class="follow-button-wrapper">
		<!-- the ring that expands out of the button once, the same one the
		     like in a post throws; only ever present for a follow that the
		     server took -->
		<span v-if="celebrating" class="follow-button__burst" aria-hidden="true" />
		<!--
		  One real button, not two swapped by a :hover rule. The pair used to be
		  a "Following" label with no handler plus an "Unfollow" button that
		  `display: none` kept out of the tab order until a pointer hovered the
		  container — so a keyboard user and every touch device had no way to
		  unfollow anyone at all. The label changes on hover and focus instead.
		-->
		<NcButton
			v-if="relationship.following"
			:disabled="loading"
			class="follow-button follow-button--following"
			:class="{ 'follow-button--confirmed': celebrating, 'follow-button--refused': refused }"
			:variant="unfollowIntent ? 'error' : 'success'"
			:aria-label="t('social', 'Unfollow {account}', { account: uid })"
			@mouseenter="unfollowIntent = true"
			@mouseleave="unfollowIntent = false"
			@focus="unfollowIntent = true"
			@blur="unfollowIntent = false"
			@click="askToUnfollow">
			<template #icon>
				<!-- keyed by the state they stand for: the icon is replaced,
				     not restyled, so each one fades in on its own arrival -->
				<CloseOctagon
					v-if="unfollowIntent"
					key="unfollow"
					:size="20"
					class="follow-button__icon" />
				<Check
					v-else
					key="following"
					:size="20"
					class="follow-button__icon follow-button__check" />
			</template>
			<span :key="unfollowIntent ? 'unfollow' : 'following'" class="follow-button__label">
				{{ unfollowIntent ? words.unfollow : words.following }}
			</span>
		</NcButton>
		<NcButton
			v-else-if="relationship.requested"
			:disabled="true"
			variant="secondary"
			class="follow-button">
			<span key="requested" class="follow-button__label">{{ t('social', 'Requested') }}</span>
		</NcButton>
		<NcButton
			v-else
			:disabled="loading"
			variant="primary"
			class="follow-button"
			:class="{ 'follow-button--pending': pending, 'follow-button--refused': refused }"
			@click="follow">
			<!--
			  While the request is in flight the button already says what it is
			  about to become — but dimmed and breathing, so it reads as being
			  applied rather than done. A refusal takes that back: the label
			  returns to "Follow" and the button shakes, the way a post says a
			  like it had already shown was rolled back.
			-->
			<span :key="pending ? 'pending' : 'follow'" class="follow-button__label">
				{{ pending ? words.following : words.follow }}
			</span>
		</NcButton>

		<!-- unfollowing is quiet and easy to do by accident, and on a locked
		     account following again means asking again -->
		<NcDialog
			v-model:open="confirmUnfollow"
			:name="t('social', 'Unfollow {account}?', { account: uid })"
			:buttons="unfollowButtons">
			<p class="unfollow-hint">
				{{ t('social', 'Their posts stop appearing in your home timeline. If their account is locked you will have to ask again to follow them.') }}
			</p>
		</NcDialog>
	</div>
</template>

<script>
import Check from 'vue-material-design-icons/Check.vue'
import CloseOctagon from 'vue-material-design-icons/CloseOctagon.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { translate } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import { feel } from '../services/senses.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useAccount } from '../composables/useAccount.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'

/** how long the confirmation plays, the same window a liked post celebrates for */
const CELEBRATION_MS = 600
/** how long the shake lasts, matched to the refusal in a post's action bar */
const REFUSAL_MS = 400

export default {
	name: 'FollowButton',
	components: {
		Check,
		CloseOctagon,
		NcButton,
		NcDialog,
	},

	props: {
		uid: {
			type: String,
			default: '',
		},
	},

	setup(props) {
		const { serverData } = useServerData()
		const { cloudId } = useCurrentUser()
		const { profileAccount, relationship } = useAccount(() => props.uid)

		return { serverData, cloudId, profileAccount, relationship }
	},

	data() {
		return {
			loading: false,
			/** whether the pointer or the keyboard is on the button */
			unfollowIntent: false,
			confirmUnfollow: false,
			/** whether the optimistic "Following" label is showing */
			pending: false,
			/** whether the follow the server took is playing its confirmation */
			celebrating: false,
			/** whether the server refused, so the button can say so */
			refused: false,
			/** when the confirmation and the refusal stop showing */
			celebrationTimer: null,
			refusalTimer: null,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/**
		 * What this button is called, which depends on what is behind it.
		 *
		 * A channel — a PeerTube one, or one of ours — is a `Group`, and you
		 * subscribe to a channel rather than follow it. The act and the API are
		 * identical; only the word people use for it is not, and a button that
		 * says "Follow" under a channel reads as a mistake to anybody who has
		 * used a video site.
		 *
		 * @return {{follow: string, following: string, unfollow: string}}
		 */
		words() {
			if (this.profileAccount?.group) {
				return {
					follow: translate('social', 'Subscribe'),
					following: translate('social', 'Subscribed'),
					unfollow: translate('social', 'Unsubscribe'),
				}
			}

			return {
				follow: translate('social', 'Follow'),
				following: translate('social', 'Following'),
				unfollow: translate('social', 'Unfollow'),
			}
		},

		/** @return {boolean} */
		isCurrentUserFollowing() {
			return this.accountStore.isFollowingUser(this.profileAccount)
		},

		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.accountStore.currentAccount
		},

		unfollowButtons() {
			return [
				{
					label: translate('social', 'Cancel'),
					callback: () => {
						this.confirmUnfollow = false
					},
				},
				{
					label: this.words.unfollow,
					variant: 'error',
					callback: () => this.unfollow(),
				},
			]
		},
	},

	beforeUnmount() {
		window.clearTimeout(this.celebrationTimer)
		window.clearTimeout(this.refusalTimer)
	},

	methods: {
		t: translate,
		askToUnfollow() {
			this.confirmUnfollow = true
		},

		async follow() {
			logger.debug('Following an account', { account: this.profileAccount })
			try {
				this.loading = true
				// the label goes ahead of the server, and comes back if it has to
				this.pending = true
				await this.accountStore.followAccount({ currentAccount: this.cloudId, accountToFollow: this.profileAccount })
				// the store commits the follow only when the server took it —
				// on a refusal it reports the error itself and commits nothing,
				// which is the only signal this component gets
				if (this.relationship?.following || this.relationship?.requested) {
					this.celebrate()
					feel('follow')
				} else {
					this.refuse()
				}
			} catch (error) {
				// the store says what went wrong; without this the rejection
				// had nowhere to go but the console, as an unhandled one
				logger.error('Failed to follow an account', { error })
				this.refuse()
			} finally {
				this.loading = false
				this.pending = false
			}
		},

		async unfollow() {
			this.confirmUnfollow = false
			logger.debug('Unfollowing an account', { account: this.profileAccount })
			try {
				this.loading = true
				await this.accountStore.unfollowAccount({ currentAccount: this.cloudId, accountToUnfollow: this.profileAccount })
				if (this.relationship?.following) {
					this.refuse()
				}
			} catch (error) {
				logger.error('Failed to unfollow an account', { error })
				this.refuse()
			} finally {
				this.loading = false
				this.unfollowIntent = false
			}
		},

		/** The follow landed: the button that replaces this one arrives celebrating. */
		celebrate() {
			if (this.prefersReducedMotion()) {
				return
			}

			this.celebrating = true
			// a touch device can feel the confirmation as well as see it
			window.navigator.vibrate?.(8)
			window.clearTimeout(this.celebrationTimer)
			this.celebrationTimer = window.setTimeout(() => {
				this.celebrating = false
			}, CELEBRATION_MS)
		},

		/** The server would not have it: take the optimistic state back visibly. */
		refuse() {
			this.celebrating = false
			this.refused = true
			window.clearTimeout(this.refusalTimer)
			this.refusalTimer = window.setTimeout(() => {
				this.refused = false
			}, REFUSAL_MS)
		},

		/** @return {boolean} */
		prefersReducedMotion() {
			return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
		},
	},
}
</script>

<style scoped lang="scss">
	/* the confirmation a follow deserves: the same short overshoot a like
	   gives the heart, and the same ring thrown out behind it */
	@keyframes follow-pop {
		0% { transform: scale(1); }
		40% { transform: scale(1.35); }
		70% { transform: scale(.92); }
		100% { transform: scale(1); }
	}

	@keyframes follow-burst {
		0% { transform: scale(.4); opacity: .4; }
		100% { transform: scale(1.35); opacity: 0; }
	}

	/* the server refused: the optimistic label is being taken back */
	@keyframes follow-refused {
		0%, 100% { transform: translateX(0); }
		25% { transform: translateX(-4px); }
		75% { transform: translateX(4px); }
	}

	/* the label and the icon are replaced whenever the state changes, so each
	   one arrives instead of appearing */
	@keyframes follow-label-in {
		0% { opacity: 0; transform: translateY(3px); }
		100% { opacity: 1; transform: translateY(0); }
	}

	@keyframes follow-icon-in {
		0% { opacity: 0; transform: scale(.7); }
		100% { opacity: 1; transform: scale(1); }
	}

	/* waiting on the server, with the label already ahead of it */
	@keyframes follow-pending {
		0%, 100% { opacity: 1; }
		50% { opacity: .62; }
	}

	.follow-button-wrapper {
		position: relative;
	}

	.follow-button {
		/* the ring behind it is absolutely positioned; the button has to be
		   painted on top of it rather than under it */
		position: relative;
		z-index: 1;
		width: 150px !important;
		border-radius: 8px !important;
		font-weight: 600 !important;
		/* the colours cross from primary to success to error as the state
		   changes under the pointer, instead of switching in one frame */
		transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
	}

	.follow-button__label {
		display: inline-block;
		animation: follow-label-in .18s ease;
	}

	.follow-button__icon {
		animation: follow-icon-in .18s ease;
	}

	.follow-button--pending {
		animation: follow-pending 1.1s ease-in-out infinite;
	}

	.follow-button--refused {
		animation: follow-refused .4s ease;
	}

	.follow-button--confirmed .follow-button__check {
		animation: follow-pop .45s cubic-bezier(.34, 1.56, .64, 1);
	}

	/* the ring, sized to the button it comes out of and behind it */
	.follow-button__burst {
		position: absolute;
		top: 0;
		inset-inline-start: 0;
		width: 150px;
		height: 100%;
		border-radius: 8px;
		background: var(--color-success, var(--color-primary-element));
		pointer-events: none;
		animation: follow-burst .5s ease-out forwards;
	}

	@media (prefers-reduced-motion: reduce) {
		.follow-button,
		.follow-button__label,
		.follow-button__icon,
		.follow-button--pending,
		.follow-button--refused,
		.follow-button--confirmed .follow-button__check,
		.follow-button__burst {
			transition: none;
			animation: none;
		}

		.follow-button__burst {
			opacity: 0;
		}
	}

	.unfollow-hint {
		padding: 0 12px 12px;
		color: var(--color-text-lighter);
		line-height: 1.5;
	}

	.user-entry {
		padding: 20px;
		margin-bottom: 10px;
	}

	.user-avatar {
		margin: 5px;
		margin-inline-end: 10px;
		border-radius: 50%;
		flex-shrink: 0;
	}

	.post-author {
		font-weight: bold;
	}

	.entry-content {
		display: flex;
		align-items: flex-start;
	}

	.user-details {
		flex-grow: 1;
	}

	.user-description {
		color: var(--color-text-lighter);
	}

	button {
		min-width: 110px;
	}

	button * {
		cursor: pointer;
	}
</style>
