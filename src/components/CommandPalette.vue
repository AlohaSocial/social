<!--
 - SPDX-FileCopyrightText: 2026 Aloha Social contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		class="command-palette"
		size="normal"
		closeOnClickOutside
		:labelId="LABEL_ID"
		@close="close">
		<div class="command-palette__body">
			<label :id="LABEL_ID" :for="INPUT_ID" class="hidden-visually">{{ label }}</label>
			<div class="command-palette__field">
				<IconMagnify :size="20" class="command-palette__glass" />
				<input
					:id="INPUT_ID"
					ref="input"
					v-model="query"
					class="command-palette__input"
					type="text"
					role="combobox"
					autocomplete="off"
					autocapitalize="off"
					spellcheck="false"
					aria-autocomplete="list"
					:aria-expanded="results.length > 0 ? 'true' : 'false'"
					:aria-controls="LIST_ID"
					:aria-activedescendant="results.length > 0 ? optionId(active) : undefined"
					:placeholder="label"
					@keydown="onKeydown">
			</div>
			<ul
				:id="LIST_ID"
				class="command-palette__list"
				role="listbox"
				:aria-labelledby="LABEL_ID">
				<!-- the mouse picks with a click; the focus stays in the field,
				     which is what a combobox's options are announced from -->
				<li
					v-for="(command, index) in results"
					:id="optionId(index)"
					:key="command.key"
					role="option"
					class="command-palette__option"
					:class="{ 'command-palette__option--active': index === active }"
					:aria-selected="index === active ? 'true' : 'false'"
					@pointermove="active = index"
					@mousedown.prevent
					@click="run(command)">
					<img
						v-if="command.avatar"
						class="command-palette__avatar"
						:src="command.avatar"
						alt="">
					<component
						:is="command.icon"
						v-else
						class="command-palette__icon"
						:size="20" />
					<span class="command-palette__title" dir="auto">{{ command.title }}</span>
					<span v-if="command.hint" class="command-palette__hint" dir="auto">{{ command.hint }}</span>
				</li>
			</ul>
			<p class="command-palette__empty" role="status">
				{{ results.length === 0 ? t('social', 'No results found') : '' }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcModal from '@nextcloud/vue/components/NcModal'
import { mapStores } from 'pinia'
import { nextTick } from 'vue'
import IconAccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import IconAccountClock from 'vue-material-design-icons/AccountClock.vue'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconBookmark from 'vue-material-design-icons/Bookmark.vue'
import IconCancel from 'vue-material-design-icons/Cancel.vue'
import IconChartBox from 'vue-material-design-icons/ChartBox.vue'
import IconCog from 'vue-material-design-icons/Cog.vue'
import IconCommentAccount from 'vue-material-design-icons/CommentAccount.vue'
import IconCompass from 'vue-material-design-icons/Compass.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconHome from 'vue-material-design-icons/Home.vue'
import IconImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import IconInformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import IconMagnify from 'vue-material-design-icons/Magnify.vue'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import IconPlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconSwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import eventBus from '../services/eventBus.js'
import { isPaletteKey, SHORTCUTS, PALETTE_EVENT } from '../services/shortcuts.js'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { accountAvatarUrl } from '../utils/accountAvatar.js'
import { rankCommands } from '../utils/commandPalette.js'
import { focusedElement } from '../utils/focus.js'
import { latestLoad } from '../utils/latestLoad.js'
import { isCurrentLocation } from '../utils/navigationActive.js'
import { scrollToTop } from '../utils/scroller.js'

const LABEL_ID = 'social-command-palette-label'
const INPUT_ID = 'social-command-palette-input'
const LIST_ID = 'social-command-palette-list'

/** how long the typing settles before the server is asked about accounts */
export const SEARCH_DEBOUNCE_MS = 250

/** how many accounts are listed after the commands */
export const ACCOUNT_LIMIT = 5

/**
 * ⌘K / Ctrl+K: every place the sidebar and the account menu lead to, the
 * settings sections people look for most, New post, and accounts by name,
 * under one field that narrows them as the reader types.
 *
 * A combobox in a modal: the focus stays in the field and the arrow keys move
 * the highlighted option (`aria-activedescendant`), Enter takes it, Escape or
 * the chord again closes. Its own chunk, mounted only while it is open.
 */
export default {
	name: 'CommandPalette',

	components: {
		NcModal,
		IconMagnify,
	},

	emits: ['close'],

	setup() {
		return { LABEL_ID, INPUT_ID, LIST_ID }
	},

	data() {
		return {
			query: '',
			/** the index of the highlighted option */
			active: 0,
			/** accounts found for the query, as commands */
			accounts: [],
			searchTimer: null,
			loads: latestLoad(),
			/** what had the focus before, for the composer to give it back to */
			opener: focusedElement(),
		}
	},

	computed: {
		...mapStores(useAccountStore, useSettingsStore),

		/** @return {string} the field's name, the palette's entry in the shortcut list */
		label() {
			return SHORTCUTS.find(({ event }) => event === PALETTE_EVENT).label
		},

		/** @return {object[]} every command, in the order they are offered */
		commands() {
			const sections = this.settingsStore.getServerData?.sections ?? {}
			const offers = (section) => sections['section_' + section] !== false
			const account = this.accountStore.currentAccount
			const settings = t('social', 'Settings')
			const inSettings = (id, title) => ({ key: 'settings-' + id, icon: IconCog, title, hint: settings, to: { name: 'settings', hash: '#' + id } })

			return [
				...(account?.moved ? [] : [{ key: 'compose', icon: IconPlus, title: t('social', 'New post'), action: 'compose' }]),
				{ key: 'feed', icon: IconHome, title: t('social', 'My Feed'), to: { name: 'timeline' } },
				...(offers('photos') ? [{ key: 'photos', icon: IconImageMultiple, title: t('social', 'Photos'), to: { name: 'timeline', params: { type: 'photos' } } }] : []),
				...(offers('videos')
					? [
							{ key: 'videos', icon: IconPlayBoxMultiple, title: t('social', 'Videos'), to: { name: 'timeline', params: { type: 'videos' } } },
							{ key: 'shorts', icon: IconPlayCircleOutline, title: t('social', 'Shorts'), to: { name: 'shorts' } },
						]
					: []),
				{ key: 'discover', icon: IconCompass, title: t('social', 'Discover'), to: { name: 'discover' } },
				{ key: 'activities', icon: IconBell, title: t('social', 'Activities'), to: { name: 'timeline', params: { type: 'notifications' } } },
				{ key: 'direct', icon: IconCommentAccount, title: t('social', 'Direct messages'), to: { name: 'timeline', params: { type: 'direct' } } },
				...(account?.acct ? [{ key: 'profile', icon: IconAccountCircle, title: t('social', 'My profile'), to: { name: 'profile', params: { account: account.acct } } }] : []),
				{ key: 'follow-requests', icon: IconAccountClock, title: t('social', 'Follow requests'), to: { name: 'follow-requests' } },
				{ key: 'liked', icon: IconHeart, title: t('social', 'Favorites'), to: { name: 'timeline', params: { type: 'favourites' } } },
				{ key: 'bookmarks', icon: IconBookmark, title: t('social', 'Bookmarks'), to: { name: 'timeline', params: { type: 'bookmarks' } } },
				{ key: 'statistics', icon: IconChartBox, title: t('social', 'Statistics'), to: { name: 'statistics' } },
				{ key: 'blocking', icon: IconCancel, title: t('social', 'Blocking'), to: { name: 'blocked-accounts' } },
				{ key: 'migration', icon: IconSwapHorizontal, title: t('social', 'Migration'), to: { name: 'migration' } },
				{ key: 'about', icon: IconInformationOutline, title: t('social', 'About this server'), to: { name: 'about' } },
				{ key: 'settings', icon: IconCog, title: settings, to: { name: 'settings' } },
				inSettings('account', t('social', 'Who can find and follow you')),
				inSettings('lists', t('social', 'Lists')),
				inSettings('notifications', t('social', 'When to tell you')),
				inSettings('drafts', t('social', 'Drafts')),
				inSettings('scheduled', t('social', 'Scheduled posts')),
				inSettings('shortcuts', t('social', 'Keyboard shortcuts')),
			]
		},

		/** @return {object[]} what is listed: the matching commands, then the accounts */
		results() {
			return [...rankCommands(this.query, this.commands), ...this.accounts]
		},
	},

	watch: {
		query(query) {
			this.active = 0
			this.searchAccounts(query)
		},

		results(results) {
			if (this.active >= results.length) {
				this.active = 0
			}
		},
	},

	mounted() {
		// after the modal's own focus trap has put the focus on its first button
		nextTick(() => window.requestAnimationFrame(() => /** @type {HTMLInputElement|undefined} */ (this.$refs.input)?.focus()))
	},

	beforeUnmount() {
		window.clearTimeout(this.searchTimer)
		this.loads.begin()
	},

	methods: {
		t,

		/**
		 * @param {number} index an option's place in the list
		 * @return {string} its element id
		 */
		optionId(index) {
			return `social-command-palette-option-${index}`
		},

		close() {
			this.$emit('close')
		},

		/** @param {KeyboardEvent} event a key pressed in the field */
		onKeydown(event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				this.move(event.key === 'ArrowDown' ? 1 : -1)
			} else if (event.key === 'Enter') {
				const command = this.results[this.active]
				if (command === undefined || event.isComposing) {
					return
				}
				this.run(command)
			} else if (event.key === 'Escape' || isPaletteKey(event)) {
				// the modal's own Escape would close it a second time
				event.stopPropagation()
				this.close()
			} else {
				return
			}
			event.preventDefault()
		},

		/** @param {number} step 1 for the next option, -1 for the one before; it wraps */
		move(step) {
			const count = this.results.length
			if (count === 0) {
				return
			}
			this.active = (this.active + step + count) % count
			nextTick(() => {
				document.getElementById(this.optionId(this.active))?.scrollIntoView?.({ block: 'nearest' })
			})
		},

		/**
		 * Closes the palette and does what the command says. The composer
		 * waits for the palette to be gone, so the focus it hands back on
		 * closing is not taken again by the palette's own.
		 *
		 * @param {object} command one of the results
		 */
		run(command) {
			const opener = this.opener
			this.close()

			if (command.action === 'compose') {
				nextTick(() => eventBus.emit('composer-open', { opener }))
			} else if (isCurrentLocation(this.$router, this.$route, command.to)) {
				scrollToTop()
			} else {
				this.$router.push(command.to)
			}
		},

		/**
		 * Asks the server for accounts by what was typed, once the typing has
		 * settled; only the newest answer is listed.
		 *
		 * @param {string} query what is in the field
		 */
		searchAccounts(query) {
			window.clearTimeout(this.searchTimer)
			const isNewest = this.loads.begin()
			const term = query.trim().replace(/^@/, '')
			if (term.length < 2) {
				this.accounts = []
				return
			}

			this.searchTimer = window.setTimeout(async () => {
				try {
					const response = await axios.get(generateUrl('apps/social/api/v1/global/accounts/search'), { params: { search: term } })
					if (!isNewest()) {
						return
					}
					this.accounts = (response.data?.result?.accounts ?? []).slice(0, ACCOUNT_LIMIT).map((account) => ({
						key: 'account:' + account.acct,
						avatar: accountAvatarUrl(account, 24),
						title: account.display_name || account.username,
						hint: '@' + account.acct,
						to: { name: 'profile', params: { account: account.acct } },
					}))
				} catch {
					if (isNewest()) {
						this.accounts = []
					}
				}
			}, SEARCH_DEBOUNCE_MS)
		},
	},
}
</script>

<style scoped lang="scss">
.command-palette__body {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
}

.command-palette__field {
	position: relative;
	/* the modal's close button sits over the end of this row */
	margin-inline-end: calc(var(--default-clickable-area, 44px) + var(--default-grid-baseline, 4px));
}

.command-palette__glass {
	position: absolute;
	inset-block: 0;
	inset-inline-start: calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}

/* Nextcloud's own text field, a little larger, with room for the glass */
.command-palette__field .command-palette__input {
	inline-size: 100%;
	min-height: var(--default-clickable-area, 44px);
	margin: 0;
	padding-inline: calc(var(--default-grid-baseline, 4px) * 11) calc(var(--default-grid-baseline, 4px) * 3);
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 16px;

	&:focus,
	&:focus-visible {
		border-color: var(--color-primary-element);
	}
}

.command-palette__list {
	max-height: min(60vh, 420px);
	margin: 0;
	padding: 0;
	overflow-y: auto;
	list-style: none;
}

.command-palette__option {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	min-height: var(--default-clickable-area, 44px);
	padding-inline: calc(var(--default-grid-baseline, 4px) * 3);
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
	cursor: pointer;

	&--active {
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}
}

.command-palette__icon,
.command-palette__avatar {
	flex: 0 0 auto;
}

.command-palette__avatar {
	inline-size: 24px;
	block-size: 24px;
	border-radius: 50%;
	object-fit: cover;
}

.command-palette__title {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.command-palette__hint {
	flex: 0 1 auto;
	min-width: 0;
	margin-inline-start: auto;
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: .9em;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.command-palette__option--active .command-palette__hint {
	color: inherit;
}

.command-palette__empty {
	margin: 0;
	padding-inline: calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast);
}

@media (forced-colors: active) {
	.command-palette__field .command-palette__input:focus {
		border-color: Highlight;
	}

	.command-palette__option--active {
		outline: 2px solid Highlight;
		outline-offset: -2px;
	}
}
</style>
