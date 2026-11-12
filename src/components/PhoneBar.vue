<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- The four places a reader goes all day, and the one thing they come to
	     do, where a thumb reaches them. Phone only: wider screens have the
	     sidebar open beside the page. Everything else stays in the drawer. -->
	<nav
		v-if="shown"
		class="phone-bar"
		:class="{ 'phone-bar--away': away }"
		:aria-label="t('social', 'Main sections')"
		@focusin="onFocusIn"
		@focusout="onFocusOut">
		<ul class="phone-bar__list">
			<li v-for="item in items" :key="item.key" class="phone-bar__slot">
				<button
					v-if="item.key === 'compose'"
					type="button"
					class="phone-bar__compose"
					:aria-label="t('social', 'New post')"
					:title="t('social', 'New post')"
					@click="compose">
					<IconPlus :size="28" />
				</button>
				<router-link
					v-else
					v-slot="{ href, navigate }"
					:to="item.to"
					custom>
					<a
						:href="href"
						class="phone-bar__link"
						:class="{ 'phone-bar__link--active': isActive(item) }"
						:aria-current="isActive(item) ? 'page' : undefined"
						@click="navigate">
						<span class="phone-bar__icon">
							<component :is="item.icon" :size="24" />
							<span v-if="item.counter > 0" class="phone-bar__badge" aria-hidden="true">
								{{ badgeText(item.counter) }}
							</span>
						</span>
						<span class="phone-bar__label">{{ item.title }}</span>
						<span v-if="item.counter > 0" class="hidden-visually">
							{{ n('social', '%n unread', '%n unread', item.counter) }}
						</span>
					</a>
				</router-link>
			</li>
		</ul>
	</nav>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconCommentAccount from 'vue-material-design-icons/CommentAccount.vue'
import IconCompass from 'vue-material-design-icons/Compass.vue'
import IconHome from 'vue-material-design-icons/Home.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import eventBus from '../services/eventBus.js'
import { isPhone, onPhoneChange } from '../services/phone.js'
import { useAccountStore } from '../store/account.js'
import { useNotificationsStore } from '../store/notifications.js'
import { useSettingsStore } from '../store/settings.js'
import { isEntryActive, MY_FEED_COVERS } from '../utils/navigationActive.js'
import { scroller } from '../utils/scroller.js'

/** The class on the root that reserves the bar's room at the foot of the page. */
export const ROOT_CLASS = 'social-phone-bar'

/**
 * How far the column has to move one way before the bar follows: a finger
 * resting on the screen jitters by a few pixels, and the bar should not.
 */
export const SCROLL_SLACK = 24

/** Above this offset the bar always shows: the page has barely moved. */
export const NEAR_TOP = 56

export default {
	name: 'PhoneBar',

	components: {
		IconPlus,
	},

	data() {
		return {
			phone: isPhone(),
			/** slid out of the way while the reader scrolls down */
			away: false,
			/** where the column was at the last scroll event */
			lastTop: 0,
			/** how far it has moved in one direction since it last turned */
			travel: 0,
			/** a keyboard user inside the bar keeps it on screen */
			focused: false,
			stopPhone: null,
		}
	},

	computed: {
		...mapStores(useAccountStore, useNotificationsStore, useSettingsStore),

		/** @return {boolean} whether the bar is drawn at all */
		shown() {
			return this.phone && this.settingsStore.getServerData?.public !== true
		},

		/** @return {boolean} whether this account can post: a moved one posts to nobody */
		canPost() {
			return !this.accountStore.currentAccount?.moved
		},

		/** @return {Array<object>} the entries, with the compose button in the middle */
		items() {
			const feed = {
				key: 'feed',
				icon: IconHome,
				title: t('social', 'My Feed'),
				to: { name: 'timeline' },
				covers: MY_FEED_COVERS,
			}
			const discover = {
				key: 'discover',
				icon: IconCompass,
				title: t('social', 'Discover'),
				to: { name: 'discover' },
			}
			const activities = {
				key: 'activities',
				icon: IconBell,
				title: t('social', 'Activities'),
				to: { name: 'timeline', params: { type: 'notifications' } },
				counter: this.notificationsStore.activitiesCount,
			}
			const messages = {
				key: 'messages',
				icon: IconCommentAccount,
				title: t('social', 'Messages'),
				to: { name: 'timeline', params: { type: 'direct' } },
				counter: this.notificationsStore.unreadDirectMessages,
			}

			return this.canPost
				? [feed, discover, { key: 'compose' }, activities, messages]
				: [feed, discover, activities, messages]
		},
	},

	watch: {
		shown: {
			immediate: true,
			handler(shown) {
				document.documentElement.classList.toggle(ROOT_CLASS, shown)
				if (!shown) {
					this.away = false
				}
			},
		},

		/** another page starts with the bar on screen */
		$route() {
			this.away = false
			this.travel = 0
			this.lastTop = scroller()?.scrollTop ?? 0
		},
	},

	mounted() {
		this.stopPhone = onPhoneChange((phone) => {
			this.phone = phone
		})
		// scroll does not bubble; caught on the way down, it is heard from the
		// column however often the app content is drawn again
		document.addEventListener('scroll', this.onScroll, { capture: true, passive: true })
	},

	beforeUnmount() {
		this.stopPhone?.()
		document.removeEventListener('scroll', this.onScroll, { capture: true })
		document.documentElement.classList.remove(ROOT_CLASS)
	},

	methods: {
		t,
		n,

		/**
		 * @param {object} item one of the entries
		 * @return {boolean} whether it is the page on screen
		 */
		isActive(item) {
			return isEntryActive(this.$route, item)
		},

		/**
		 * @param {number} count unread items
		 * @return {string} what fits in the badge
		 */
		badgeText(count) {
			return count > 99 ? '99+' : String(count)
		},

		/**
		 * Opens the common composer, which the sidebar owns; focus comes back
		 * here when it closes.
		 *
		 * @param {MouseEvent} event the press
		 */
		compose(event) {
			eventBus.emit('composer-open', { opener: event.currentTarget })
		},

		/**
		 * Down hides the bar, up brings it back. Only the app's column counts:
		 * a strip of chips or the Shorts stack scrolling sideways or inside
		 * itself says nothing about where the reader is going.
		 *
		 * @param {Event} event a scroll anywhere in the page
		 */
		onScroll(event) {
			const column = scroller()
			const target = event.target === document ? document.scrollingElement : event.target
			if (!this.shown || target === null || (target !== column && event.target !== document)) {
				return
			}

			const top = /** @type {Element} */ (target).scrollTop
			const delta = top - this.lastTop
			this.lastTop = top
			if (top <= NEAR_TOP) {
				this.away = false
				this.travel = 0
				return
			}

			// a turn starts the count again
			this.travel = Math.sign(delta) === Math.sign(this.travel) ? this.travel + delta : delta
			if (this.travel > SCROLL_SLACK && !this.focused) {
				this.away = true
			} else if (this.travel < -SCROLL_SLACK) {
				this.away = false
			}
		},

		onFocusIn() {
			this.focused = true
			this.away = false
		},

		/** @param {FocusEvent} event focus moving on */
		onFocusOut(event) {
			const next = /** @type {Node|null} */ (event.relatedTarget)
			if (next === null || !this.$el?.contains?.(next)) {
				this.focused = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.phone-bar {
	--phone-bar-height: 56px;
	position: fixed;
	inset-inline: 0;
	inset-block-end: 0;
	/* over the page, under the drawer (1400 on a phone) and every dialog */
	z-index: 1300;
	padding-block-end: env(safe-area-inset-bottom, 0px);
	border-block-start: 1px solid var(--color-border);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-resting);
	transition: transform .2s ease;

	&--away {
		/* the shadow and the safe area go with it */
		transform: translateY(calc(100% + 8px));
	}
}

.phone-bar__list {
	display: flex;
	align-items: stretch;
	block-size: var(--phone-bar-height);
	margin: 0;
	padding: 0 4px;
	list-style: none;
}

.phone-bar__slot {
	display: flex;
	flex: 1 1 0;
	align-items: center;
	justify-content: center;
	min-inline-size: 0;
}

.phone-bar__link {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 2px;
	inline-size: 100%;
	block-size: 100%;
	min-inline-size: 44px;
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
	text-decoration: none;

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: -4px;
	}

	&--active {
		color: var(--color-primary-element);
		font-weight: 600;

		.phone-bar__icon {
			background: var(--color-primary-element-light);
		}
	}
}

.phone-bar__icon {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	inline-size: 48px;
	block-size: 28px;
	border-radius: var(--border-radius-pill);
}

.phone-bar__label {
	max-inline-size: 100%;
	overflow: hidden;
	font-size: 11px;
	line-height: 1.2;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.phone-bar__badge {
	position: absolute;
	inset-block-start: -4px;
	inset-inline-end: 2px;
	min-inline-size: 18px;
	block-size: 18px;
	padding: 0 5px;
	box-sizing: border-box;
	border: 2px solid var(--color-main-background);
	border-radius: 9px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 10px;
	font-weight: 700;
	line-height: 14px;
	text-align: center;
}

.phone-bar__compose {
	display: flex;
	align-items: center;
	justify-content: center;
	inline-size: 48px;
	block-size: 40px;
	min-block-size: 40px;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	cursor: pointer;

	&:hover,
	&:focus,
	&:active {
		background: var(--color-primary-element-hover);
		color: var(--color-primary-element-text);
	}

	&:focus-visible {
		outline: 2px solid var(--color-main-text);
		outline-offset: 2px;
	}
}

@media (prefers-reduced-motion: reduce) {
	/* it still gets out of the way, without the slide */
	.phone-bar {
		transition: none;
	}
}

@media (forced-colors: active) {
	.phone-bar {
		border-block-start-color: CanvasText;
	}

	.phone-bar__link--active {
		color: Highlight;
	}

	.phone-bar__compose {
		border: 1px solid ButtonText;
		background: ButtonFace;
		color: ButtonText;
	}
}
</style>

<style lang="scss">
/* The room the bar takes, for whatever has to stay clear of it: the page's
   own foot (App.vue), the toasts, the small player, the conversation's
   message field and the Shorts stack. Zero wherever the bar is not drawn. */
:root.social-phone-bar {
	--social-phone-bar-space: calc(56px + env(safe-area-inset-bottom, 0px));
	--toast-offset-block-end: var(--social-phone-bar-space);
}

/* a focused field scrolled into view stops above the bar, not under it */
:root.social-phone-bar #app-content-vue {
	scroll-padding-block-end: var(--social-phone-bar-space);
}
</style>
