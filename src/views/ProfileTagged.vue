<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="tagged">
		<TimelineSwitcher
			:options="kinds"
			value="tagged"
			:label="t('social', 'Which of their posts to show')" />

		<NcLoadingIcon v-if="loading" class="tagged__loading" :size="32" />

		<div v-else-if="error" class="tagged__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="posts.length">
			<ul class="tagged__list">
				<TimelineEntry
					v-for="post in posts"
					:key="post.id"
					:item="post"
					type="account" />
			</ul>
			<NcLoadingIcon v-if="loadingMore" class="tagged__loading" :size="32" />
			<div v-else-if="cursor" class="tagged__more">
				<NcButton @click="loadMore">
					{{ t('social', 'Show more') }}
				</NcButton>
			</div>
		</template>

		<NcEmptyContent
			v-else
			:name="t('social', 'No photos of them')"
			:description="emptyDescription">
			<template #icon>
				<IconAccountBoxMultiple :size="20" />
			</template>
		</NcEmptyContent>

		<div ref="sentinel" class="tagged__sentinel" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import logger from '../services/logger.js'
import { profileKinds } from '../composables/useProfileKinds.js'
import { latestLoad } from '../utils/latestLoad.js'
import { nextCursor } from '../utils/linkHeader.js'

/** How many posts one request asks for; the route's own default. */
const PAGE_SIZE = 20

/**
 * The photographs somebody else took that this account is named in.
 *
 * Not a filter of the account's own posts: every post here belongs to
 * somebody else, which is why it is a page rather than a fourth kind beside
 * Photos and Videos. What the reader may see is the server's decision — a
 * post they could not otherwise read is simply not in the answer — so this
 * page renders what it is given and asks no questions of its own.
 *
 * It pages on the `Link` header and not on the last post drawn: a page the
 * reader may not see all of comes back short without being the last, so only
 * the header's absence says there is nothing more.
 */
export default {
	name: 'ProfileTagged',

	components: {
		IconAccountBoxMultiple,
		IconRefresh,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		TimelineEntry,
		TimelineSwitcher,
	},

	props: { profileLayout: { type: Object, default: undefined } },

	data() {
		return {
			posts: [],
			loading: true,
			loadingMore: false,
			error: '',
			loads: latestLoad(),
			/** where the next page starts, '' once the server said there is none */
			cursor: '',
			observer: null,
		}
	},

	computed: {
		/** @return {string} the handle in the route */
		account() {
			return String(this.$route.params.account ?? '')
		},

		/** @return {Array} the five places a profile can be read */
		kinds() {
			return profileKinds(this.account, this.profileLayout, 'tagged')
		},

		/** @return {string} */
		emptyDescription() {
			return t('social', 'When somebody names them in a photo, it shows up here.')
		},
	},

	watch: {
		'$route.params.account': 'load',
	},

	mounted() {
		this.load()
		this.observer = new IntersectionObserver((entries) => {
			if (entries[0]?.isIntersecting) {
				this.loadMore()
			}
		}, { rootMargin: '300px' })
		this.observer.observe(this.$refs.sentinel)
	},

	unmounted() {
		this.observer?.disconnect()
	},

	methods: {
		t,

		/**
		 * @param {string} account the account whose photos to ask for
		 * @param {string} maxId where the page starts, '' for the first
		 * @return {Promise<{posts: Array, cursor: string}>}
		 */
		async fetchPage(account, maxId) {
			const url = generateUrl('apps/social/api/v1.1/accounts/{account}/tagged', { account })
			const params = { limit: PAGE_SIZE }
			if (maxId) {
				params.max_id = maxId
			}
			const { data, headers } = await axios.get(url, { params })

			return { posts: Array.isArray(data) ? data : [], cursor: nextCursor(headers) }
		},

		/** @return {Promise<void>} */
		async load() {
			const isNewest = this.loads.begin()
			const account = this.account
			this.loading = true
			this.loadingMore = false
			this.error = ''
			this.cursor = ''
			try {
				const page = await this.fetchPage(account, '')
				if (!isNewest()) {
					return
				}
				this.posts = page.posts
				this.cursor = page.cursor
			} catch (error) {
				logger.error('could not load the photos somebody is tagged in', { error })
				if (isNewest()) {
					this.error = t('social', 'Could not load these photos')
				}
			} finally {
				if (isNewest()) {
					this.loading = false
				}
			}
		},

		/** @return {Promise<void>} */
		async loadMore() {
			if (this.loading || this.loadingMore || !this.cursor) {
				return
			}
			const account = this.account
			// tied to the load that drew the first page: a page for a profile
			// the reader has since left is theirs no longer
			const isNewest = this.loads.current()
			this.loadingMore = true
			try {
				const page = await this.fetchPage(account, this.cursor)
				// a page that arrives after the reader has moved to another
				// profile belongs to the one they left
				if (!isNewest() || account !== this.account) {
					return
				}
				const seen = new Set(this.posts.map((post) => post.id))
				this.posts = [...this.posts, ...page.posts.filter((post) => !seen.has(post.id))]
				this.cursor = page.cursor
			} catch (error) {
				logger.error('could not load more of the photos somebody is tagged in', { error })
			} finally {
				if (isNewest()) {
					this.loadingMore = false
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.tagged__loading {
	margin-block: 32px;
}

.tagged__error {
	text-align: center;
	margin-block: 24px;
}

.tagged__more {
	display: flex;
	justify-content: center;
	margin-block: 16px;
}

.tagged__sentinel {
	height: 1px;
}

.tagged__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
