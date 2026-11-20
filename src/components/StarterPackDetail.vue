<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="starter-pack-detail">
		<component
			:is="'h' + headingLevel"
			ref="heading"
			class="starter-pack-detail__title"
			tabindex="-1">
			{{ pack.name }}
		</component>
		<p v-if="pack.curator || pack.url || pack.joined > 0" class="starter-pack-detail__origin">
			<template v-if="pack.curator">
				{{ t('social', 'Curated by {curator}', { curator: pack.curator }) }}
			</template>
			<template v-if="pack.topic">
				· #{{ pack.topic }}
			</template>
			<template v-if="pack.joined > 0">
				· {{ n('social', '%n person joined with it', '%n people joined with it', pack.joined) }}
			</template>
			<template v-if="pack.url">
				·
				<a :href="pack.url" target="_blank" rel="noopener noreferrer">{{ t('social', 'View on its server') }} ↗</a>
			</template>
		</p>
		<p v-if="pack.description" class="starter-pack-detail__description">
			{{ pack.description }}
		</p>

		<div v-if="canFollow" class="starter-pack-detail__actions">
			<NcButton variant="primary" :disabled="following || loading || toFollow.length === 0" @click="askFollowAll">
				<template #icon>
					<NcLoadingIcon v-if="following" :size="20" />
					<IconAccountMultiplePlus v-else :size="20" />
				</template>
				{{ followAllLabel }}
			</NcButton>
			<NcCheckboxRadioSwitch v-if="feeds.length > 0" v-model="keepFeeds" :disabled="following">
				{{ n('social', 'Also keep its feed', 'Also keep its %n feeds', feeds.length) }}
			</NcCheckboxRadioSwitch>
		</div>

		<section v-if="feeds.length > 0" class="starter-pack-detail__section">
			<component :is="'h' + (headingLevel + 1)" class="starter-pack-detail__caption">
				{{ t('social', 'Feeds') }}
			</component>
			<ul class="starter-pack-detail__list">
				<li v-for="feed in feeds" :key="feed.uri" class="starter-pack-detail__row">
					<IconRss :size="20" class="starter-pack-detail__row-icon" />
					<div class="starter-pack-detail__text">
						<router-link v-if="feed.route" class="starter-pack-detail__name" :to="feed.route">
							{{ feed.name }}
						</router-link>
						<span v-else class="starter-pack-detail__name">{{ feed.name }}</span>
						<span v-if="feed.description" class="starter-pack-detail__detail">{{ feed.description }}</span>
					</div>
				</li>
			</ul>
		</section>

		<section class="starter-pack-detail__section">
			<component :is="'h' + (headingLevel + 1)" class="starter-pack-detail__caption">
				{{ n('social', '%n person', '%n people', pack.members.length) }}
			</component>
			<NcLoadingIcon v-if="loading" class="starter-pack-detail__loading" :size="32" />
			<ul v-else class="starter-pack-detail__list">
				<li v-for="member in pack.members" :key="member.key" class="starter-pack-detail__row">
					<ActorAvatar :actor="member.actor" :size="40" :hoverCard="false" />
					<div class="starter-pack-detail__text">
						<router-link class="starter-pack-detail__name" :to="{ name: 'profile', params: { account: member.acct } }">
							{{ member.name || member.acct }}
						</router-link>
						<span class="starter-pack-detail__detail">@{{ member.acct }}</span>
					</div>
					<template v-if="canFollow && !member.self">
						<span v-if="isFollowing(member)" class="starter-pack-detail__state">
							{{ t('social', 'Following') }}
						</span>
						<NcButton v-else :disabled="following || pending === member.key" @click="followOne(member)">
							<template v-if="pending === member.key" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('social', 'Follow') }}
						</NcButton>
					</template>
				</li>
			</ul>
		</section>

		<slot />

		<NcDialog
			v-model:open="confirming"
			:name="n('social', 'Follow %n person?', 'Follow %n people?', toFollow.length)"
			:buttons="confirmButtons">
			<p>{{ t('social', 'Their posts will appear in your home timeline. You can unfollow anybody later on their profile.') }}</p>
		</NcDialog>
	</div>
</template>

<script>
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import IconRss from 'vue-material-design-icons/Rss.vue'
import ActorAvatar from './ActorAvatar.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { errorOf } from '../utils/serverError.js'

/** above how many people a follow of everybody is confirmed first */
export const CONFIRM_ABOVE = 10

/**
 * One starter pack, open: who curated it, its members with a Follow each, and
 * following everybody not followed yet. The opening page supplies the follow
 * functions and keys this by the pack, since what was followed here is kept.
 */
export default {
	name: 'StarterPackDetail',

	components: {
		ActorAvatar,
		IconAccountMultiplePlus,
		IconRss,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
	},

	props: {
		/**
		 * `{ name, description, curator, topic, url, joined, feeds, members }`,
		 * each member `{ key, acct, name, actor, following, self }`, where
		 * `key` is what the follow functions name the member by and `actor`
		 * is what its avatar is drawn from. `feeds` is optional, each
		 * `{ uri, name, description, route }`.
		 */
		pack: {
			type: Object,
			required: true,
		},

		/** the level of the pack's name; its sections are one below */
		headingLevel: {
			type: Number,
			default: 1,
		},

		/** whether the members are still being read, so not yet followable */
		loading: {
			type: Boolean,
			default: false,
		},

		/** whether the reader can follow anybody: a visitor cannot */
		canFollow: {
			type: Boolean,
			default: true,
		},

		/**
		 * Follows the members given, and the feeds when asked to.
		 * `(members, feeds, progress) => Promise<{followed: string[], failed: string[]}>`,
		 * both lists of member keys; `progress(done)` says how far it got.
		 */
		followMembers: {
			type: Function,
			required: true,
		},

		/**
		 * Follows one member and says whether it worked, having said why
		 * not itself. `(member) => Promise<boolean>`
		 */
		followMember: {
			type: Function,
			required: true,
		},
	},

	data() {
		return {
			/** the keys followed during this visit */
			followedNow: [],
			/** whether a follow of everybody is under way */
			following: false,
			/** how many of them have been asked for so far */
			done: 0,
			/** the key of the one member being followed */
			pending: '',
			/** whether following everybody keeps the feeds too */
			keepFeeds: true,
			/** whether "Follow N people?" is open */
			confirming: false,
		}
	},

	computed: {
		/** @return {object[]} the pack's feeds, none where it has none */
		feeds() {
			return this.pack.feeds ?? []
		},

		/** @return {object[]} the members not followed yet, leaving out the reader */
		toFollow() {
			return this.pack.members.filter((member) => !member.self && !this.isFollowing(member))
		},

		/** @return {string} what the follow-everybody button says now */
		followAllLabel() {
			if (this.following) {
				return t('social', 'Following {done} of {total} …', { done: this.done, total: this.toFollow.length })
			}
			if (this.loading) {
				return t('social', 'Follow everyone')
			}
			if (this.toFollow.length === 0) {
				return t('social', 'You follow everybody in it')
			}

			return n('social', 'Follow %n person', 'Follow all %n people', this.toFollow.length)
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		confirmButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.confirming = false
					},
				},
				{
					label: t('social', 'Follow'),
					variant: 'primary',
					callback: () => this.followAll(),
				},
			]
		},
	},

	methods: {
		t,
		n,

		/**
		 * @param {object} member one member of the pack
		 * @return {boolean} whether the reader follows them, or asked to
		 */
		isFollowing(member) {
			return member.following === true || this.followedNow.includes(member.key)
		},

		/** Puts the keyboard focus on the pack's name. */
		focusHeading() {
			/** @type {HTMLElement|undefined} */ (this.$refs.heading)?.focus()
		},

		askFollowAll() {
			if (this.toFollow.length > CONFIRM_ABOVE) {
				this.confirming = true

				return
			}
			this.followAll()
		},

		async followAll() {
			this.confirming = false
			const members = this.toFollow
			const feeds = this.keepFeeds && this.feeds.length > 0
			this.following = true
			this.done = 0
			try {
				const { followed, failed } = await this.followMembers(members, feeds, (done) => {
					this.done = done
				})
				this.markFollowed(followed)
				if (followed.length === 0) {
					// "followed 0" is nothing having happened, whatever the
					// request answered
					showError(t('social', 'Nobody in this pack could be followed. Their servers may be busy; try again later.'))
				} else if (failed.length > 0) {
					showError(n('social', '%n account could not be followed', '%n accounts could not be followed', failed.length))
				} else {
					showSuccess(n('social', 'You now follow %n person from this pack', 'You now follow %n people from this pack', followed.length))
				}
			} catch (error) {
				logger.error('Following from the starter pack failed', { error })
				showError(errorOf(error, t('social', 'Could not follow from the starter pack')))
			} finally {
				this.following = false
			}
		},

		/**
		 * @param {object} member one member of the pack
		 */
		async followOne(member) {
			this.pending = member.key
			try {
				if (await this.followMember(member)) {
					this.markFollowed([member.key])
				}
			} finally {
				this.pending = ''
			}
		},

		/**
		 * @param {string[]} keys the members just followed
		 */
		markFollowed(keys) {
			this.followedNow = [...this.followedNow, ...keys.filter((key) => !this.followedNow.includes(key))]
		},
	},
}
</script>

<style scoped lang="scss">
.starter-pack-detail {
	width: 100%;

	&__title {
		margin: 0;
		font-size: 1.5em;
	}

	&__origin,
	&__detail,
	&__row-icon,
	&__state {
		color: var(--color-text-maxcontrast);
	}

	&__origin {
		margin: 4px 0 0;
		overflow-wrap: anywhere;
	}

	&__description {
		margin: 12px 0;
		max-width: 70ch;
		line-height: 1.5;
		white-space: pre-line;
	}

	&__actions {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 12px;
		margin: 16px 0;
	}

	&__section {
		margin-top: 16px;
	}

	&__caption {
		font-size: 1em;
		font-weight: bold;
		margin: 0 0 4px;
	}

	&__loading {
		margin: 24px auto;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 52px;
		padding: 6px 0;
		border-top: 1px solid var(--color-border);
	}

	&__row-icon {
		flex-shrink: 0;
	}

	&__text {
		display: flex;
		flex-direction: column;
		flex: 1 1 auto;
		min-width: 0;
	}

	&__name {
		font-weight: bold;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__detail {
		font-size: 13px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
}
</style>
