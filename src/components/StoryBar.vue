<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="viewer && offered" class="story-bar" :aria-label="t('social', 'Stories')">
		<ul class="story-bar__list">
			<!-- the reader's own place is always there, with or without a
			     story in it: it is where a story is added from -->
			<li class="story-bar__item story-bar__item--own">
				<button
					type="button"
					class="story-bar__tile"
					:class="{ 'story-bar__tile--unseen': ownGroup && !ownGroup.seen, 'story-bar__tile--empty': !ownGroup }"
					:aria-label="ownGroup ? t('social', 'Your story') : t('social', 'Add to your story')"
					@click="ownGroup ? open(0) : (composing = true)">
					<span class="story-bar__ring">
						<ActorAvatar
							:actor="viewer"
							:size="52"
							:link="false"
							:hoverCard="false" />
					</span>
					<span class="story-bar__name">{{ t('social', 'Your story') }}</span>
				</button>
				<NcButton
					class="story-bar__add"
					variant="primary"
					:ariaLabel="t('social', 'Add to your story')"
					@click="composing = true">
					<template #icon>
						<IconPlus :size="16" />
					</template>
				</NcButton>
			</li>

			<li v-for="(other, index) in others" :key="other.account.id" class="story-bar__item">
				<button
					type="button"
					class="story-bar__tile"
					:class="{ 'story-bar__tile--unseen': !other.seen }"
					:style="accountStyle(other.account)"
					:aria-label="tileLabel(other)"
					@click="open(ownGroup ? index + 1 : index)">
					<span class="story-bar__ring">
						<ActorAvatar
							:actor="other.account"
							:size="52"
							:link="false"
							:hoverCard="false" />
					</span>
					<span class="story-bar__name">{{ firstName(other.account) }}</span>
				</button>
			</li>
		</ul>

		<StoryViewer
			v-if="viewing !== null"
			:groups="groups"
			:start="viewing"
			@close="viewing = null"
			@seen="markSeen"
			@deleted="forget" />

		<!-- a story starts where a short does: record or upload a video,
		     trim it, pick its cover. A picture or a text story goes on to
		     the story editor, which has the stickers and the cards -->
		<ShortComposerDialog
			v-if="composing"
			v-model:open="composing"
			mode="story"
			@posted="add"
			@other="editPicture" />

		<StoryComposerDialog
			v-if="editing"
			v-model:open="editing"
			:initialFile="editingFile"
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
import IconPlus from 'vue-material-design-icons/Plus.vue'
import ActorAvatar from './ActorAvatar.vue'
import logger from '../services/logger.js'
import { ownAvatarUrl } from '../services/avatar.js'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { accountStyle } from '../services/accountColour.js'

// the viewer and the composer are the heavy halves and most visits open
// neither; they are fetched when one is
const StoryViewer = defineAsyncComponent(() => import(/* webpackChunkName: "stories" */'./StoryViewer.vue'))
const StoryComposerDialog = defineAsyncComponent(() => import(/* webpackChunkName: "stories" */'./StoryComposerDialog.vue'))
const ShortComposerDialog = defineAsyncComponent(() => import(/* webpackChunkName: "stories" */'./ShortComposerDialog.vue'))

/**
 * The row of faces above the home timeline: whose stories are up.
 *
 * Stories have been in the API for a while and had no page in this app; a
 * user of this server could post one from a Pixelfed client and never see
 * it here. The bar is the API's carousel grouped by account — the reader's
 * own first, then the accounts they follow — with a ring around the faces
 * that still hold something unseen, the way every app that has stories
 * draws them. Tapping a face plays that account's stories; the reader's own
 * place is always there, because it is where a story is added from.
 */
export default {
	name: 'StoryBar',

	components: {
		ActorAvatar,
		IconPlus,
		NcButton,
		ShortComposerDialog,
		StoryComposerDialog,
		StoryViewer,
	},

	data() {
		return {
			/** @type {Array<{account: object, stories: Array<object>, seen: boolean, own: boolean}>} */
			groups: [],
			/** which group the viewer is playing, or null while it is closed */
			viewing: null,
			composing: false,
			/** the story editor is open, for a picture or a text story */
			editing: false,
			/** @type {File|null} the picture it was opened with, if any */
			editingFile: null,
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
		 * Whether this instance offers stories at all.
		 *
		 * Asked here rather than where the bar is drawn, so that every place that
		 * draws one is covered. Default on, and on for a server that said nothing
		 * about it.
		 *
		 * @return {boolean} whether to draw the bar
		 */
		offered() {
			return this.settingsStore.getServerData?.sections?.stories !== false
		},

		/** @return {string} the reader's handle, for telling their own stories apart */
		viewerAcct() {
			return this.accountStore.currentAccount?.acct ?? getCurrentUser()?.uid ?? ''
		},

		/** @return {object|undefined} the reader's own stories, grouped */
		ownGroup() {
			return this.groups.find((group) => group.own)
		},

		/** @return {Array<object>} everybody else's */
		others() {
			return this.groups.filter((group) => !group.own)
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
		 * what a full name comes to in the space a story tile has, and a first
		 * name is both shorter and warmer than a truncated surname.
		 *
		 * @param {object} account whose story it is
		 * @return {string} one word, or the handle when there is no name
		 */
		firstName(account) {
			const name = String(account?.display_name ?? '').trim()

			return name === '' ? (account?.username ?? '') : name.split(/\s+/)[0]
		},

		t,
		n,

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/stories/carousel'))
				this.groups = this.group(Array.isArray(data) ? data : [])
			} catch (error) {
				// a bar that could not be loaded is no bar: the timeline below
				// is the page, and a toast over it for this would be noise
				logger.error('could not load the stories', { error })
				this.groups = []
			}
		},

		/**
		 * One group per account, in the order the server gave them — the
		 * viewer's own first, then the followed — each seen only when every
		 * story in it was.
		 *
		 * @param {Array<object>} stories the carousel
		 * @return {Array<object>}
		 */
		group(stories) {
			const groups = new Map()
			for (const story of stories) {
				const account = story.account
				if (!account) {
					continue
				}
				if (!groups.has(account.id)) {
					groups.set(account.id, {
						account,
						stories: [],
						seen: true,
						own: this.viewerAcct !== '' && account.acct === this.viewerAcct,
					})
				}
				const group = groups.get(account.id)
				group.stories.push(story)
				if (!story.seen) {
					group.seen = false
				}
			}

			const list = [...groups.values()]
			// own first whatever the server did; the API already does this,
			// and the bar should not depend on it
			list.sort((a, b) => Number(b.own) - Number(a.own))

			return list
		},

		/**
		 * @param {number} index which group to play
		 */
		open(index) {
			this.viewing = index
		},

		/**
		 * @param {object} group one account's stories
		 * @return {string} what the tile is, for a reader who cannot see the ring
		 */
		tileLabel(group) {
			const name = group.account.display_name || group.account.username
			const count = n('social', '%n story', '%n stories', group.stories.length)

			return group.seen
				? t('social', '{name}: {count}, seen', { name, count })
				: t('social', '{name}: {count}, new', { name, count })
		},

		/**
		 * The viewer marked one seen; the ring follows.
		 *
		 * @param {string} id the story
		 */
		markSeen(id) {
			for (const group of this.groups) {
				for (const story of group.stories) {
					if (story.id === id) {
						story.seen = true
					}
				}
				group.seen = group.stories.every((story) => story.seen)
			}
		},

		/**
		 * The viewer deleted one of the reader's own.
		 *
		 * @param {object} deleted the story
		 */
		forget(deleted) {
			this.groups = this.groups
				.map((group) => ({ ...group, stories: group.stories.filter((story) => story.id !== deleted.id) }))
				.filter((group) => group.stories.length > 0)
			if (this.viewing !== null && this.viewing >= this.groups.length) {
				this.viewing = null
			}
		},

		/**
		 * A story the reader just posted goes to the front of their own place.
		 *
		 * @param {object} story the new story
		 */
		/**
		 * From the video dialog to the story editor.
		 *
		 * @param {File|null} file a picture chosen there, or null to start empty
		 */
		editPicture(file) {
			this.editingFile = file
			this.composing = false
			this.editing = true
		},

		add(story) {
			const own = this.ownGroup
			if (own) {
				own.stories.push({ ...story, seen: true })
			} else if (this.viewer) {
				this.groups = [{ account: story.account ?? this.viewer, stories: [{ ...story, seen: true }], seen: true, own: true }, ...this.groups]
			}
		},
	},
}
</script>

<style scoped lang="scss">
/* a ring that swells and fades: drawn as a pseudo-element rather than a
   box-shadow, because a shadow on a card is this app's elevation and means
   something else */
@keyframes story-breathe {
	0% { transform: scale(1); opacity: .45; }
	70%, 100% { transform: scale(1.22); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
	.story-bar__tile--unseen .story-bar__ring::after {
		animation: none;
		opacity: 0;
	}
}

.story-bar {
	max-width: var(--social-column);
	margin: 8px auto 12px;
	padding: 0 10px;
}

.story-bar__list {
	display: flex;
	gap: 14px;
	margin: 0;
	padding: 4px 2px;
	list-style: none;
	overflow-x: auto;
	scrollbar-width: thin;
}

.story-bar__item {
	position: relative;
	flex: 0 0 auto;
}

.story-bar__tile {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: center;
	width: 72px;
	padding: 0;
	border: 0;
	background: none;
	color: var(--color-main-text);
	cursor: pointer;

	&:focus-visible .story-bar__ring {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

.story-bar__ring {
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
	.story-bar__tile--unseen & {
		border-color: hsl(var(--account-hue, 210) 65% 50%);
		position: relative;

		&::after {
			content: '';
			position: absolute;
			inset: -3px;
			border-radius: 50%;
			border: 2px solid hsl(var(--account-hue, 210) 65% 50%);
			animation: story-breathe 2.4s ease-out infinite;
			pointer-events: none;
		}
	}

	.story-bar__tile--empty & {
		border-style: dashed;
	}
}

.story-bar__name {
	max-width: 72px;
	overflow: hidden;
	font-size: 12px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.story-bar__add {
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
