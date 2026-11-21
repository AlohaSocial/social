<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<!-- the same switcher the timelines carry: one account seen three ways -->
		<TimelineSwitcher
			:options="kinds"
			:value="kind"
			:label="t('social', 'Which of their posts to show')" />

		<ProfileMosaic
			v-if="kind === '' && photosTab"
			:account="routeAccount"
			:to="photosTab.to" />

		<!-- pinned posts only on Posts: on Photos they would be whatever the
		     account pinned, photos or not -->
		<ul v-if="pinned.length && kind === ''" class="profile-pinned">
			<TimelineEntry
				v-for="entry in pinned"
				:key="`pinned-${entry.id}`"
				:item="entry"
				type="account" />
		</ul>

		<TimelineList :display="display" :account="routeAccount" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import ProfileMosaic from './../components/ProfileMosaic.vue'
import TimelineEntry from './../components/TimelineEntry.vue'
import TimelineList from './../components/TimelineList.vue'
import TimelineSwitcher from './../components/TimelineSwitcher.vue'
import { t } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import { mapStores } from 'pinia'
import { useTimelineStore } from '../store/timeline.js'
import { profileKinds } from '../composables/useProfileKinds.js'

export default {
	name: 'ProfileTimeline',
	components: {
		ProfileMosaic,
		TimelineEntry,
		TimelineList,
		TimelineSwitcher,
	},

	props: { profileLayout: { type: Object, default: undefined } },

	data() {
		return {
			pinnedIds: [],
			/**
			 * Which profile's pinned posts are awaited, so an answer for a profile
			 * the reader has left is dropped (as `TimelineList` does).
			 */
			generation: 0,
		}
	},

	computed: {
		/** @return {string} the account the page is about, as the address names it */
		routeAccount() {
			return String(this.$route.params.account ?? '')
		},

		...mapStores(useTimelineStore),
		/**
		 * Which of an account's posts are read, from the `media` query (the API's
		 * `media_type`), so a profile stays one route. Anything else means all.
		 *
		 * @return {string} '', 'image' or 'video'
		 */
		kind() {
			const media = String(this.$route.query?.media ?? '')

			return ['image', 'video'].includes(media) ? media : ''
		},

		/**
		 * How the tab is drawn: Posts as a list, Photos and Videos as a grid.
		 *
		 * @return {string} 'grid' or 'timeline'
		 */
		display() {
			return (this.kind === '') ? 'timeline' : 'grid'
		},

		/**
		 * @return {object[]} the three tabs, for the switcher
		 */
		kinds() {
			return profileKinds(this.routeAccount, this.profileLayout, this.kind)
		},

		/**
		 * @return {object|undefined} the Photos tab, where the account shows one
		 */
		photosTab() {
			return this.kinds.find((tab) => tab.value === 'image')
		},

		/**
		 * The pinned posts, read from the store so that likes, boosts and unpins
		 * reach them.
		 *
		 * @return {object[]}
		 */
		pinned() {
			return this.pinnedIds
				.map((id) => this.timelineStore.getStatus(id))
				.filter(Boolean)
		},
	},

	watch: {
		'$route.params.account': 'load',
		// a tab is a different query to the server, not a filter of what is on screen
		kind: 'loadTimeline',
	},

	beforeMount() {
		this.load()
	},

	methods: {
		t,

		load() {
			this.loadTimeline()
			this.loadPinned()
		},

		loadTimeline() {
			if (this.routeAccount !== '') {
				this.timelineStore.changeTimelineTypeAccount(this.routeAccount, this.kind)
			}
		},

		/**
		 * The pinned posts sit above the timeline, the way every Fediverse
		 * profile shows them. A failure here leaves the timeline alone.
		 */
		async loadPinned() {
			this.pinnedIds = []
			const account = this.$route.params.account
			if (!account) {
				return
			}

			this.generation += 1
			const generation = this.generation
			try {
				const { data } = await axios.get(
					generateUrl(`apps/social/api/v1/accounts/${account}/statuses`),
					{ params: { pinned: true } },
				)
				const statuses = Array.isArray(data) ? data : []
				// the posts themselves are worth keeping whoever is on screen
				// now — they are indexed by their own id and nothing reads
				// them until something asks for one
				for (const status of statuses) {
					this.timelineStore.addToStatuses(status)
				}

				// the *list* is about one profile, and this is the answer for
				// a profile the reader may have left
				if (generation !== this.generation) {
					return
				}

				this.pinnedIds = statuses.map((status) => status.id)
			} catch (error) {
				logger.error('Failed to load the pinned posts', { error })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.profile-pinned {
	list-style: none;
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}
</style>
