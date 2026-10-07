<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="viewer && offered" class="shorts-bar" aria-labelledby="shorts-bar-heading">
		<h2 id="shorts-bar-heading" class="shorts-bar__heading">
			{{ t('social', 'Shorts') }}
		</h2>
		<ul class="shorts-bar__list">
			<!-- the reader's own place is always there, with or without a
			     24-hour short in it: it is where one is made from -->
			<li class="shorts-bar__item shorts-bar__item--own">
				<router-link
					v-if="ownGroup"
					class="shorts-bar__tile"
					:class="{ 'shorts-bar__tile--unseen': !ownGroup.seen }"
					:to="feedAt(ownGroup.account)"
					:aria-label="t('social', 'Your shorts')">
					<span class="shorts-bar__ring">
						<ActorAvatar
							:actor="viewer"
							:size="52"
							:link="false"
							:hoverCard="false" />
					</span>
					<span class="shorts-bar__name">{{ t('social', 'Your shorts') }}</span>
				</router-link>
				<button
					v-else
					type="button"
					class="shorts-bar__tile shorts-bar__tile--empty"
					:aria-label="t('social', 'New short')"
					@click="composing = true">
					<span class="shorts-bar__ring">
						<ActorAvatar
							:actor="viewer"
							:size="52"
							:link="false"
							:hoverCard="false" />
					</span>
					<span class="shorts-bar__name">{{ t('social', 'Your shorts') }}</span>
				</button>
				<NcButton
					class="shorts-bar__add"
					variant="primary"
					:ariaLabel="t('social', 'New short')"
					@click="composing = true">
					<template #icon>
						<IconPlus :size="16" />
					</template>
				</NcButton>
			</li>

			<li v-for="other in others" :key="other.account.id" class="shorts-bar__item">
				<router-link
					class="shorts-bar__tile"
					:class="{ 'shorts-bar__tile--unseen': !other.seen }"
					:style="accountStyle(other.account)"
					:to="feedAt(other.account)"
					:aria-label="tileLabel(other)">
					<span class="shorts-bar__ring">
						<ActorAvatar
							:actor="other.account"
							:size="52"
							:link="false"
							:hoverCard="false" />
					</span>
					<span class="shorts-bar__name">{{ firstName(other.account) }}</span>
				</router-link>
			</li>

			<!-- the way on to every short, kept ones included -->
			<li class="shorts-bar__item">
				<router-link class="shorts-bar__tile shorts-bar__tile--all" :to="{ name: 'shorts' }">
					<span class="shorts-bar__ring">
						<span class="shorts-bar__all-icon">
							<IconPlayBoxMultiple :size="28" />
						</span>
					</span>
					<span class="shorts-bar__name">{{ t('social', 'All shorts') }}</span>
				</router-link>
			</li>
		</ul>

		<!-- New short, opened on 24 hours: a picture or a card goes on to
		     the picture editor from inside the dialog -->
		<ShortComposerDialog
			v-if="composing"
			v-model:open="composing"
			lifetime="day"
			@posted="add" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { n, t } from '@nextcloud/l10n'
import { defineAsyncComponent } from 'vue'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultipleOutline.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import ActorAvatar from './ActorAvatar.vue'
import logger from '../services/logger.js'
import { ownAvatarUrl } from '../services/avatar.js'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { accountStyle } from '../services/accountColour.js'

// the dialog is the heavy half and most visits never open it
const ShortComposerDialog = defineAsyncComponent(() => import(/* webpackChunkName: "short-composer" */'./ShortComposerDialog.vue'))

/**
 * The Shorts bar above the home timeline: whose 24-hour shorts are up.
 *
 * The stories carousel grouped by account: the reader's own place first, then
 * the people they follow, those with something unseen first, with a ring
 * around the faces that still hold something unseen; last, a tile on to the
 * whole Shorts feed. A face opens the Shorts feed at that person
 * (`/shorts?account=`), which is where every short is watched; the reader's
 * own place opens New short on its 24-hour lifetime when there is nothing in
 * it yet, and the + does the same at any time.
 */
export default {
	name: 'ShortsBar',

	components: {
		ActorAvatar,
		IconPlayBoxMultiple,
		IconPlus,
		NcButton,
		ShortComposerDialog,
	},

	data() {
		return {
			/** @type {Array<{account: object, shorts: Array<object>, seen: boolean, own: boolean}>} */
			groups: [],
			composing: false,
		}
	},

	computed: {
		...mapStores(useAccountStore, useSettingsStore),

		/**
		 * The reader, as something to draw a face from.
		 *
		 * The account store's copy where it has arrived, and the Nextcloud
		 * user otherwise — the store is filled by a request that may not have
		 * come back yet, and a bar that waits for it is missing on the first
		 * paint and, where that request fails, for good.
		 *
		 * @return {object|null}
		 */
		viewer() {
			const account = this.accountStore.currentAccount
			if (account) {
				return account
			}

			const user = getCurrentUser()
			if (!user) {
				return null
			}

			return {
				id: user.uid,
				acct: user.uid,
				username: user.uid,
				display_name: user.displayName || user.uid,
				avatar: ownAvatarUrl(64),
			}
		},

		/**
		 * Whether this instance offers 24-hour shorts at all (the admin's
		 * `stories` section). Default on, and on for a server that said
		 * nothing about it.
		 *
		 * @return {boolean} whether to draw the bar
		 */
		offered() {
			return this.settingsStore.getServerData?.sections?.stories !== false
		},

		/** @return {string} the reader's handle, for telling their own shorts apart */
		viewerAcct() {
			return this.accountStore.currentAccount?.acct ?? getCurrentUser()?.uid ?? ''
		},

		/** @return {object|undefined} the reader's own 24-hour shorts, grouped */
		ownGroup() {
			return this.groups.find((group) => group.own)
		},

		/** @return {Array<object>} everybody else's, those with something unseen first */
		others() {
			const others = this.groups.filter((group) => !group.own)

			return [...others.filter((group) => !group.seen), ...others.filter((group) => group.seen)]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		accountStyle,

		/**
		 * What to call somebody under their face.
		 *
		 * The first word of their display name. "Maya Lin…" and "Petra No…" are
		 * what a full name comes to in the space a tile has, and a first name is
		 * both shorter and warmer than a truncated surname.
		 *
		 * @param {object} account whose shorts they are
		 * @return {string} one word, or the handle when there is no name
		 */
		firstName(account) {
			const name = String(account?.display_name ?? '').trim()

			return name === '' ? (account?.username ?? '') : name.split(/\s+/)[0]
		},

		t,
		n,

		/**
		 * @param {object} account whose 24-hour shorts to start at
		 * @return {object} the Shorts feed, opened at them
		 */
		feedAt(account) {
			return { name: 'shorts', query: { account: account.acct } }
		},

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/stories/carousel'))
				this.groups = this.group(Array.isArray(data) ? data : [])
			} catch (error) {
				// a bar that could not be loaded is no bar: the timeline below
				// is the page, and a toast over it for this would be noise
				logger.error('could not load the 24-hour shorts', { error })
				this.groups = []
			}
		},

		/**
		 * One group per account, in the order the server gave them, each seen
		 * only when every short in it was.
		 *
		 * @param {Array<object>} shorts the carousel
		 * @return {Array<object>}
		 */
		group(shorts) {
			const groups = new Map()
			for (const short of shorts) {
				const account = short.account
				if (!account) {
					continue
				}
				if (!groups.has(account.id)) {
					groups.set(account.id, {
						account,
						shorts: [],
						seen: true,
						own: this.viewerAcct !== '' && account.acct === this.viewerAcct,
					})
				}
				const group = groups.get(account.id)
				group.shorts.push(short)
				if (!short.seen) {
					group.seen = false
				}
			}

			return [...groups.values()]
		},

		/**
		 * @param {object} group one account's 24-hour shorts
		 * @return {string} what the tile is, for a reader who cannot see the ring
		 */
		tileLabel(group) {
			const name = group.account.display_name || group.account.username
			const count = n('social', '%n short', '%n shorts', group.shorts.length)

			return group.seen
				? t('social', '{name}: {count}, seen', { name, count })
				: t('social', '{name}: {count}, new', { name, count })
		},

		/**
		 * A short the reader just posted: a 24-hour one goes into their own
		 * place; a kept one is not the bar's.
		 *
		 * @param {object} short the new short
		 * @param {string} lifetime 'kept' or 'day'
		 */
		add(short, lifetime) {
			if (lifetime !== 'day') {
				return
			}

			const own = this.ownGroup
			if (own) {
				own.shorts.push({ ...short, seen: true })
			} else if (this.viewer) {
				this.groups = [{ account: short.account ?? this.viewer, shorts: [{ ...short, seen: true }], seen: true, own: true }, ...this.groups]
			}
		},
	},
}
</script>

<style scoped lang="scss">
/* a ring that swells and fades: drawn as a pseudo-element rather than a
   box-shadow, because a shadow on a card is this app's elevation and means
   something else */
@keyframes shorts-breathe {
	0% { transform: scale(1); opacity: .45; }
	70%, 100% { transform: scale(1.22); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
	.shorts-bar__tile--unseen .shorts-bar__ring::after {
		animation: none;
		opacity: 0;
	}
}

.shorts-bar {
	max-width: var(--social-column);
	margin: 8px auto 12px;
	padding: 0 10px;
}

.shorts-bar__heading {
	margin: 0 0 2px;
	padding: 0 2px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.shorts-bar__list {
	display: flex;
	gap: 14px;
	margin: 0;
	padding: 4px 2px;
	list-style: none;
	overflow-x: auto;
	scrollbar-width: thin;
}

.shorts-bar__item {
	position: relative;
	flex: 0 0 auto;
}

.shorts-bar__tile {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: center;
	width: 72px;
	padding: 0;
	border: 0;
	background: none;
	color: var(--color-main-text);
	text-decoration: none;
	cursor: pointer;

	&:focus-visible .shorts-bar__ring {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

.shorts-bar__ring {
	display: flex;
	padding: 3px;
	border-radius: 50%;
	// seen: a quiet grey ring; unseen: the accent, which is what the eye
	// looks for in a row of faces
	border: 2px solid var(--color-border-dark);

	/**
	 * Unseen: the account's own colour, breathing.
	 *
	 * The accent made every unseen ring the same colour as every other, so a
	 * row of faces was a row of identical circles; `--account-hue` is the
	 * colour that account is everywhere else in the app. The breath is two
	 * seconds and barely there -- enough to say "something here", not enough
	 * to be a thing moving on the page while somebody is reading.
	 */
	.shorts-bar__tile--unseen & {
		border-color: hsl(var(--account-hue, 210) 65% 50%);
		position: relative;

		&::after {
			content: '';
			position: absolute;
			inset: -3px;
			border-radius: 50%;
			border: 2px solid hsl(var(--account-hue, 210) 65% 50%);
			animation: shorts-breathe 2.4s ease-out infinite;
			pointer-events: none;
		}
	}

	.shorts-bar__tile--empty & {
		border-style: dashed;
	}
}

// the last tile: no face, the Shorts feed's own sign
.shorts-bar__all-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	inline-size: 52px;
	block-size: 52px;
	border-radius: 50%;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.shorts-bar__name {
	max-width: 72px;
	overflow: hidden;
	font-size: 12px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.shorts-bar__add {
	position: absolute;
	top: 42px;
	inset-inline-end: 4px;
	min-width: 24px !important;
	min-height: 24px !important;
	padding: 0 !important;
	border: 2px solid var(--color-main-background);
	border-radius: 50%;
}
</style>
