<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcPopover
		class="account-hover"
		:class="`account-hover--${variant}`"
		:shown="shown"
		:triggers="[]"
		:popoverTriggers="[]"
		:noFocusTrap="true"
		:placement="placement"
		popoverBaseClass="account-hover__popover"
		popupRole="dialog"
		@update:shown="onPopoverShown">
		<template #trigger="{ attrs }">
			<span
				class="account-hover__trigger"
				v-bind="attrs"
				@pointerenter="onPointerEnter"
				@pointerleave="onPointerLeave"
				@pointerdown="onPointerDown"
				@touchstart="onTouchStart"
				@focusin="onFocusIn"
				@focusout="onFocusOut">
				<slot />
			</span>
		</template>
		<div
			v-if="shown"
			class="account-hover-card"
			role="dialog"
			:aria-label="t('social', 'Account preview')"
			@pointerenter="onCardEnter"
			@pointerleave="onCardLeave"
			@focusin="onCardEnter"
			@focusout="onFocusOut">
			<template v-if="account">
				<div class="account-hover-card__head">
					<!-- `disableMenu`, or the card sprouts a second card of
					     Nextcloud's own when the pointer reaches this face -->
					<NcAvatar
						v-if="isLocal"
						:size="48"
						:user="account.username"
						:displayName="account.display_name || account.username"
						:hideStatus="true"
						:disableMenu="true"
						:disableTooltip="true" />
					<NcAvatar
						v-else
						:size="48"
						:url="account.avatar"
						:hideStatus="true"
						:disableMenu="true"
						:disableTooltip="true" />
					<span class="account-hover-card__names">
						<span class="account-hover-card__name">
							<AccountDisplayName :text="account.display_name || account.username || handle" :emojis="account.emojis" />
							<BlueskyBadge v-if="isBluesky" />
						</span>
						<span class="account-hover-card__handle">@{{ account.acct || handle }}</span>
						<!-- Where this account lives. It used to be a chip on every
						     remote post, which put a hostname beside every byline
						     in a timeline whose whole point is that it spans
						     servers. It belongs here: the ring around the avatar
						     says there is another server involved, and this says
						     which one, to the reader who asked. -->
						<span
							v-if="origin.instance"
							class="account-hover-card__instance"
							:style="{ '--instance-colour': origin.colour }">
							{{ origin.instance }}
						</span>
						<span v-if="joined" class="account-hover-card__joined">{{ joined }}</span>
					</span>
				</div>
				<ul v-if="badges.length" class="account-hover-card__badges">
					<li v-for="badge in badges" :key="badge" class="account-hover-card__badge">
						{{ badge }}
					</li>
				</ul>
				<!-- Sanitized: the bio is remote HTML, see sanitizeHtml.js -->
				<!-- eslint-disable-next-line vue/no-v-html -->
				<p v-if="note" class="account-hover-card__bio" v-html="note" />
				<dl v-if="fields.length" class="account-hover-card__fields">
					<div v-for="(field, index) in fields" :key="index" class="account-hover-card__field">
						<dt :title="field.name">
							{{ field.name }}
						</dt>
						<dd :title="field.text">
							<a
								v-if="field.href"
								:href="field.href"
								target="_blank"
								rel="nofollow noopener noreferrer">{{ field.text }}</a>
							<template v-else>
								{{ field.text }}
							</template>
							<VerifiedCheck v-if="field.verified" :verifiedAt="field.verifiedAt" />
						</dd>
					</div>
				</dl>
				<ul v-if="hasCounts" class="account-hover-card__counts">
					<li v-if="statusesCount !== null">
						<strong>{{ statusesCount }}</strong>
						{{ n('social', 'post', 'posts', statusesCount) }}
					</li>
					<li v-if="serverData.hideCounts !== true">
						<strong>{{ followersCount }}</strong>
						{{ n('social', 'follower', 'followers', followersCount) }}
					</li>
					<li>
						<strong>{{ followingCount }}</strong>
						{{ t('social', 'following') }}
					</li>
				</ul>
			</template>
			<div v-else class="account-hover-card__loading">
				<span class="hidden-visually">{{ t('social', 'Loading account…') }}</span>
				<span class="account-hover-card__skeleton account-hover-card__skeleton--avatar" />
				<span class="account-hover-card__skeleton account-hover-card__skeleton--line" />
				<span class="account-hover-card__skeleton account-hover-card__skeleton--line account-hover-card__skeleton--short" />
			</div>
		</div>
	</NcPopover>
</template>

<script>
import { h } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import { translate, translatePlural } from '@nextcloud/l10n'
import { emojifyPlain } from './MessageContent.js'
import BlueskyBadge from './BlueskyBadge.vue'
import VerifiedCheck from './VerifiedCheck.vue'
import { isBlueskyAccount, isLocalAccount } from '../utils/accountLocality.js'
import { originOf } from '../utils/instanceIdentity.js'
import { profileFields } from '../utils/profileFields.js'
import { sanitizeHtml } from '../utils/sanitizeHtml.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useServerData } from '../composables/useServerData.js'

/**
 * A display name with its custom emoji as inline images — what DisplayName.js
 * does, defined here instead of imported.
 *
 * MessageContent.js renders the mentions this card hangs off, so it imports
 * this file; importing DisplayName.js back would close the cycle
 * MessageContent → AccountHoverCard → DisplayName → MessageContent through a
 * module that reads its import while its own body runs (`components: {}`),
 * which is exactly the shape that breaks. `emojifyPlain` is a hoisted
 * function declaration and is only called from a render, so the remaining
 * cycle has nothing to observe half-built.
 */
const AccountDisplayName = {
	name: 'AccountDisplayName',
	props: {
		text: {
			type: String,
			default: '',
		},
		emojis: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').CustomEmoji[]>} */ (Array),
			default: () => [],
		},
	},
	render() {
		return h('span', { class: 'display-name' }, emojifyPlain(h, this.text, this.emojis))
	},
}

/**
 * Long enough that a pointer crossing a mention on its way somewhere else
 * never opens anything, short enough that a deliberate hover feels answered.
 */
export const OPEN_DELAY = 350

/**
 * Short grace period on the way out, so the pointer can travel the gap
 * between the mention and the card without the card disappearing under it.
 */
export const CLOSE_DELAY = 200

/**
 * How many profile metadata rows the card shows. Mastodon's own ceiling, and
 * the point past which a preview stops being one.
 */
const MAX_FIELDS = 4

/**
 * Fetches in flight or already answered, by handle.
 *
 * The store is the cache — its `getAccount` is consulted before anything is
 * dispatched. This map only covers the window between dispatching and the
 * answer arriving (two cards for the same person opened in the same second),
 * and the case the store cannot answer at all: `getAccount` resolves handles
 * through `accountIdMap`, which keys local accounts as `user@host`, so a bare
 * local handle would otherwise be re-fetched on every hover.
 *
 * @type {Map<string, Promise<object|undefined>>}
 */
const pending = new Map()

/**
 * Forget everything fetched so far. Only for tests — each one gets a fresh
 * store, and a module-level cache would otherwise outlive it.
 */
export function resetAccountCache() {
	pending.clear()
}

export default {
	name: 'AccountHoverCard',
	components: {
		AccountDisplayName,
		BlueskyBadge,
		NcAvatar,
		NcPopover,
		VerifiedCheck,
	},

	props: {
		/** The account handle to preview, as it appears after the `@` */
		handle: {
			type: String,
			required: true,
		},

		/**
		 * What is already known about this account (a status carries its
		 * author in full), shown while the fetch is on its way.
		 */
		fallback: {
			type: /** @type {import('vue').PropType<Partial<import('../types/Mastodon.js').Account>|null>} */ (Object),
			default: null,
		},

		/**
		 * `inline` sits in a run of text (a mention), `block` wraps something
		 * with a box of its own (an avatar).
		 */
		variant: {
			type: String,
			default: 'inline',
			validator: (value) => ['inline', 'block'].includes(String(value)),
		},

		placement: {
			type: String,
			default: 'bottom-start',
		},
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			shown: false,
			/** the account as it came back from the fetch, when the store cannot key it */
			fetched: null,
			/** whether the focus the trigger is about to take came from a press */
			focusFromPointer: false,
			/** the pending open and close, while the pointer rests or leaves */
			openTimer: null,
			closeTimer: null,
			/** clears focusFromPointer once the focus it was about has happened */
			focusResetTimer: null,
			/** the document keydown handler that closes an open card on Escape */
			escapeListener: null,
		}
	},

	computed: {
		...mapStores(useAccountStore),
		/** @return {import('../types/Mastodon.js').Account|null} what to show, if anything */
		account() {
			return this.storedAccount ?? this.fetched ?? this.fallback
		},

		/**
		 * Whether this account can be looked up at all.
		 *
		 * A public page has nobody logged in and no lookup endpoint to ask, so
		 * it is better served by what the page already carried than by a card
		 * that stays a skeleton forever.
		 *
		 * @return {boolean}
		 */
		canFetch() {
			return Boolean(this.handle) && !this.serverData.public
		},

		/** @return {import('../types/Mastodon.js').Account|undefined} the store's copy */
		storedAccount() {
			return this.accountStore.getAccount(this.handle)
		},

		/** @return {boolean} */
		isLocal() {
			return isLocalAccount(this.account ?? { acct: this.handle })
		},

		/** @return {boolean} */
		isBluesky() {
			return isBlueskyAccount(this.account)
		},

		/**
		 * Which server this account is on, and the colour that server is drawn
		 * in everywhere else — the ring around its avatars, most visibly.
		 *
		 * `instance` is '' for a local account, and the row is then absent:
		 * everything here is on this server, and naming it would be noise on
		 * every card a reader opens.
		 *
		 * @return {{instance: string, colour: string, local: boolean}}
		 */
		origin() {
			return originOf(this.account ?? this.handle ?? '')
		},

		/** @return {string} the bio, reduced to markup that is safe to inject */
		note() {
			return sanitizeHtml(this.account?.note ?? '')
		},

		/**
		 * The profile's own metadata rows — a website, a pronoun, whatever the
		 * account chose to put there. Four of them, which is Mastodon's own
		 * ceiling, so a profile that carries more cannot turn the card into a
		 * page.
		 *
		 * @return {Array<{name: string, text: string, href: string, verified: boolean, verifiedAt: string}>}
		 */
		fields() {
			return profileFields(this.account?.fields, MAX_FIELDS)
		},

		/**
		 * What is true of the account rather than about it: that it follows
		 * the reader, that it is automated, that following it is a request
		 * rather than an act. Each is a thing somebody deciding whether to
		 * follow would want to know before they open the profile.
		 *
		 * @return {string[]}
		 */
		badges() {
			const badges = []
			if (this.followsYou) {
				badges.push(translate('social', 'Follows you'))
			}
			if (this.account?.locked === true) {
				badges.push(translate('social', 'Approves followers'))
			}
			if (this.account?.bot === true) {
				badges.push(translate('social', 'Automated'))
			}
			if (this.isBluesky) {
				badges.push(translate('social', 'On Bluesky'))
			}

			return badges
		},

		/**
		 * When the account joined, to the month: the day is noise at this size
		 * and the year alone reads as an estimate.
		 *
		 * @return {string} empty when the entity carried no date
		 */
		joined() {
			const created = this.account?.created_at
			if (typeof created !== 'string' || created === '') {
				return ''
			}

			const date = new Date(created)
			if (Number.isNaN(date.getTime())) {
				return ''
			}

			return translate('social', 'Joined {date}', {
				date: date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' }),
			})
		},

		/**
		 * @return {number|null} how much the account has posted, or null when
		 * the entity did not say — a card built from what a status carried has
		 * the counts, one built from a bare mention may not
		 */
		statusesCount() {
			return this.account?.statuses_count ?? null
		},

		/**
		 * @return {boolean} whether the numbers are known at all — a card built
		 * from what a status carried may have none, and "0 followers" would be
		 * a lie rather than a gap
		 */
		hasCounts() {
			return this.account?.followers_count !== undefined || this.account?.following_count !== undefined
		},

		/** @return {number} */
		followersCount() {
			return this.account?.followers_count ?? 0
		},

		/** @return {number} */
		followingCount() {
			return this.account?.following_count ?? 0
		},

		/**
		 * Whether this account follows the reader — shown only when the answer
		 * is already in the store. Worth a badge, never worth a request.
		 *
		 * @return {boolean}
		 */
		followsYou() {
			return this.accountStore.getRelationshipWith(this.account?.id)?.followed_by === true
		},
	},

	beforeUnmount() {
		this.clearTimers()
		if (this.focusResetTimer) {
			window.clearTimeout(this.focusResetTimer)
		}
		this.stopListeningForEscape()
	},

	methods: {
		t: translate,
		n: translatePlural,

		/**
		 * A pointer arrived. Touch never opens the card: a tap on a mention
		 * is a request to follow the link, and a card in the way of it is a
		 * card the reader cannot get rid of.
		 *
		 * @param {PointerEvent} event - the pointerenter
		 */
		onPointerEnter(event) {
			if (event?.pointerType === 'touch' || event?.pointerType === 'pen') {
				return
			}
			this.scheduleOpen()
		},

		/** @param {PointerEvent} event - the pointerleave */
		onPointerLeave(event) {
			if (event?.pointerType === 'touch' || event?.pointerType === 'pen') {
				return
			}
			this.scheduleClose()
		},

		/**
		 * A press is on its way, so the focus that follows it is not a reader
		 * arriving by keyboard — it is a tap or a click on the link.
		 */
		onPointerDown() {
			this.suppressFocusOpen(0)
		},

		/**
		 * Browsers follow a tap with a synthetic mouse enter; cancelling here
		 * covers the ones that do not report a pointer type. The synthetic
		 * focus can trail the tap by a few hundred milliseconds.
		 */
		onTouchStart() {
			this.clearTimers()
			this.close()
			this.suppressFocusOpen(700)
		},

		/**
		 * Ignore the focus a press is about to hand the link.
		 *
		 * The focus event follows the press synchronously, so the flag is
		 * dropped again on the next turn of the loop: Safari does not focus a
		 * link on click at all, and a flag left standing there would make the
		 * card unreachable by keyboard for the rest of the page's life.
		 *
		 * @param {number} ms - how long the press may still claim the focus
		 */
		suppressFocusOpen(ms) {
			this.focusFromPointer = true
			if (this.focusResetTimer) {
				window.clearTimeout(this.focusResetTimer)
			}
			this.focusResetTimer = window.setTimeout(() => {
				this.focusResetTimer = null
				this.focusFromPointer = false
			}, ms)
		},

		/**
		 * Keyboard focus is deliberate: no delay, and no request until now.
		 * Focus taken by a press is not — that reader is following the link.
		 */
		onFocusIn() {
			if (this.focusFromPointer) {
				return
			}
			this.cancelClose()
			this.open()
		},

		onFocusOut() {
			this.focusFromPointer = false
			this.scheduleClose()
		},

		onCardEnter() {
			this.cancelClose()
		},

		/** @param {PointerEvent} event - the pointerleave */
		onCardLeave(event) {
			this.onPointerLeave(event)
		},

		/**
		 * floating-vue closes on click outside by itself; the component state
		 * has to follow, or the card could never be opened again.
		 *
		 * @param {boolean} value - what the popover decided
		 */
		onPopoverShown(value) {
			if (!value && this.shown) {
				this.close()
			}
		},

		scheduleOpen() {
			this.cancelClose()
			if (this.shown || this.openTimer) {
				return
			}
			this.openTimer = window.setTimeout(() => {
				this.openTimer = null
				this.open()
			}, OPEN_DELAY)
		},

		scheduleClose() {
			this.cancelOpen()
			if (!this.shown || this.closeTimer) {
				return
			}
			this.closeTimer = window.setTimeout(() => {
				this.closeTimer = null
				this.close()
			}, CLOSE_DELAY)
		},

		cancelOpen() {
			if (this.openTimer) {
				window.clearTimeout(this.openTimer)
				this.openTimer = null
			}
		},

		cancelClose() {
			if (this.closeTimer) {
				window.clearTimeout(this.closeTimer)
				this.closeTimer = null
			}
		},

		clearTimers() {
			this.cancelOpen()
			this.cancelClose()
		},

		open() {
			// nothing to show, and no way to get it: better no card at all
			if (this.shown || (!this.account && !this.canFetch)) {
				return
			}
			this.shown = true
			this.listenForEscape()
			this.load()
		},

		close() {
			this.clearTimers()
			this.shown = false
			this.stopListeningForEscape()
		},

		/**
		 * Escape dismisses the card wherever the focus happens to be — on the
		 * mention, inside the card, or nowhere at all because the reader is
		 * using a pointer.
		 */
		listenForEscape() {
			if (this.escapeListener) {
				return
			}
			this.escapeListener = (event) => {
				if (event.key === 'Escape') {
					this.close()
				}
			}
			document.addEventListener('keydown', this.escapeListener)
		},

		stopListeningForEscape() {
			if (this.escapeListener) {
				document.removeEventListener('keydown', this.escapeListener)
				this.escapeListener = null
			}
		},

		/**
		 * Ask for the account, once. Called from open() and nowhere else: a
		 * pointer that never rests on a mention costs nothing.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (this.storedAccount || !this.canFetch) {
				return
			}
			let request = pending.get(this.handle)
			if (request === undefined) {
				request = Promise.resolve(this.accountStore.fetchAccountInfo(this.handle))
				pending.set(this.handle, request)
			}
			const data = await request
			if (data) {
				this.fetched = data
			} else {
				// the account could not be loaded; let the next hover try again
				pending.delete(this.handle)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.account-hover {
	/* the anchor must keep a box of its own — floating-vue measures it — but
	   it must not disturb the run of text a mention sits in */
	display: inline;

	&--block {
		display: inline-block;
	}
}

.account-hover__trigger {
	/* an inline box of its own: pointerenter never fires on a box-less
	   element, and floating-vue has nothing to measure without one */
	display: inline;
}

.account-hover--block .account-hover__trigger {
	display: inline-block;
}

.account-hover-card {
	width: 300px;
	max-width: 100%;
	padding: 14px 16px 12px;
	font-size: 13px;
	line-height: 1.5;
	color: var(--color-main-text);

	/* rises into place rather than appearing, in the vocabulary of the
	   timeline entries */
	animation: account-hover-card-rise .18s cubic-bezier(.4, 0, .2, 1);

	&__head {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	&__names {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__name {
		font-weight: 600;
		font-size: 15px;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__handle {
		color: var(--color-text-lighter);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	/* the hostname, in that host's own colour: the same one the ring around
	   the avatar is drawn in, so the card answers the question the ring asks */
	&__instance {
		align-self: flex-start;
		max-width: 100%;
		margin-top: 2px;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 10px);
		background: var(--instance-colour);
		color: var(--color-primary-element-text);
		font-size: 11px;
		font-weight: 600;
		letter-spacing: .01em;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__joined {
		color: var(--color-text-maxcontrast, var(--color-text-lighter));
		font-size: 12px;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__badges {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
		margin-top: 8px;
	}

	&__badge {
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 100px);
		background: var(--color-background-dark);
		color: var(--color-text-lighter);
		font-size: 12px;
	}

	&__fields {
		margin: 8px 0 0;
		/* the server pads every `dl`, `dt` and `dd` for the settings pages, and
		   at this size that padding is three times the text it surrounds */
		padding: 8px 0 0;
		border-top: 1px solid var(--color-border);
	}

	&__field {
		display: flex;
		gap: 8px;
		font-size: 12px;
		line-height: 1.45;

		/* one line each: a field is a label, and a profile that wrote an essay
		   into one must not push the counts off the card */
		dt,
		dd {
			margin: 0;
			padding: 1px 0;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		dt {
			flex: 0 0 34%;
			font-weight: 600;
			color: var(--color-text-lighter);
		}

		dd {
			flex: 1;
			min-width: 0;

			a {
				color: var(--color-primary-element);

				&:hover {
					text-decoration: underline;
				}
			}
		}
	}

	&__bio {
		margin: 8px 0 0;
		/* three lines of bio: enough to recognise somebody, never enough to
		   cover what the reader was reading */
		display: -webkit-box;
		-webkit-line-clamp: 3;
		-webkit-box-orient: vertical;
		overflow: hidden;

		:deep(a) {
			color: var(--color-primary-element);
		}

		:deep(p) {
			margin: 0;
		}
	}

	&__counts {
		display: flex;
		gap: 16px;
		margin-top: 10px;
		color: var(--color-text-lighter);

		strong {
			color: var(--color-main-text);
		}
	}

	&__loading {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 10px;
	}

	&__skeleton {
		border-radius: var(--border-radius, 3px);
		background: var(--color-background-dark);
		animation: account-hover-card-pulse 1.4s ease-in-out infinite;

		&--avatar {
			width: 48px;
			height: 48px;
			border-radius: 50%;
		}

		&--line {
			width: calc(100% - 58px);
			height: 14px;
		}

		&--short {
			width: 60%;
		}
	}
}

@keyframes account-hover-card-rise {
	from {
		opacity: 0;
		transform: translateY(6px);
	}

	to {
		opacity: 1;
		transform: none;
	}
}

@keyframes account-hover-card-pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: .55; }
}

@media (prefers-reduced-motion: reduce) {
	.account-hover-card,
	.account-hover-card__skeleton {
		animation: none;
	}
}
</style>
