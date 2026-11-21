<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="scheduled-posts">
		<p v-if="loading && posts.length === 0" class="scheduled-posts__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<ul v-else-if="posts.length > 0" class="scheduled-posts__list">
			<li v-for="post in posts" :key="post.id" class="scheduled-posts__item">
				<div class="scheduled-posts__when">
					<ClockOutline :size="16" decorative title="" />
					<time :datetime="post.scheduled_at">{{ fullDateTime(post.scheduled_at) }}</time>
					<VisibilityIcon :visibility="visibilityOf(post)" :size="16" />
				</div>
				<p class="scheduled-posts__text">
					{{ textOf(post) }}
				</p>
				<p v-if="attachmentsOf(post) > 0" class="scheduled-posts__meta">
					{{ n('social', '%n attachment', '%n attachments', attachmentsOf(post)) }}
				</p>
				<div class="scheduled-posts__actions">
					<NcButton
						variant="tertiary"
						class="scheduled-posts__reschedule"
						:aria-label="t('social', 'Change the time of this scheduled post')"
						:title="t('social', 'Change time')"
						:aria-expanded="editing === post.id ? 'true' : 'false'"
						:disabled="rescheduling || cancelling.includes(post.id)"
						@click="toggleEditor(post)">
						<template #icon>
							<ClockEditOutline :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						class="scheduled-posts__cancel"
						:aria-label="t('social', 'Cancel this scheduled post')"
						:title="t('social', 'Cancel this scheduled post')"
						:disabled="cancelling.includes(post.id)"
						@click="cancel(post)">
						<template #icon>
							<Close :size="20" />
						</template>
					</NcButton>
				</div>
				<SchedulePicker
					v-if="editing === post.id"
					v-model="newTime"
					class="scheduled-posts__editor">
					<NcButton
						variant="primary"
						class="scheduled-posts__save"
						:disabled="!canReschedule(post)"
						@click="reschedule(post)">
						{{ rescheduling ? t('social', 'Saving …') : t('social', 'Save') }}
					</NcButton>
				</SchedulePicker>
			</li>
		</ul>
		<p v-else class="scheduled-posts__hint">
			{{ t('social', 'Nothing is waiting to be posted. The clock in the composer schedules a post for later.') }}
		</p>

		<p v-if="hasMore" class="scheduled-posts__more">
			<NcButton :disabled="loading" @click="load(true)">
				{{ loading ? t('social', 'Loading …') : t('social', 'Show more') }}
			</NcButton>
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import ClockEditOutline from 'vue-material-design-icons/ClockEditOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import SchedulePicker from './Composer/SchedulePicker.vue'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { errorOf } from '../utils/serverError.js'
import { fullDateTime } from '../utils/relativeTime.js'
import { isTooSoon } from '../utils/schedule.js'
import { isNewerId } from '../utils/snowflake.js'

/** How many to ask for at a time; an account may hold up to 300 waiting posts. */
const PAGE_SIZE = 50

/**
 * The posts waiting to go out, in a section of Settings: each can be moved to
 * another time (`PUT /api/v1/scheduled_statuses/{id}`, with the composer's own
 * picker) or cancelled. What a post says is not changed here.
 */
export default {
	name: 'ScheduledPosts',
	components: {
		ClockEditOutline,
		ClockOutline,
		Close,
		NcButton,
		SchedulePicker,
		VisibilityIcon,
	},

	data() {
		return {
			loading: false,
			/** @type {object[]} ScheduledStatus entities, soonest first */
			posts: [],
			/** @type {string[]} the ids whose cancellation is in flight */
			cancelling: [],
			/** whether the last page came back full, so there may be more behind it */
			hasMore: false,
			/** the post-scheduled handler, kept so only this one is removed */
			onScheduled: null,
			/** @type {?string} the id whose time is being changed */
			editing: null,
			/** @type {?Date} the time picked for it */
			newTime: null,
			/** whether a new time is on its way to the server */
			rescheduling: false,
		}
	},

	mounted() {
		this.load()
		// the New post dialog opens over this page too, and a post scheduled
		// from it belongs in the list without a reload
		this.onScheduled = () => this.load()
		eventBus.on('post-scheduled', this.onScheduled)
	},

	unmounted() {
		eventBus.off('post-scheduled', this.onScheduled)
	},

	methods: {
		t,
		n,
		fullDateTime,

		/** @param {object} post a ScheduledStatus */
		textOf(post) {
			return post.params?.text || ''
		},

		/**
		 * The audience, in the words the icon knows. Mastodon's `private` is
		 * this app's `followers`; anything else the icon marks as unknown.
		 *
		 * @param {object} post a ScheduledStatus
		 * @return {string}
		 */
		visibilityOf(post) {
			const visibility = post.params?.visibility || 'public'

			return visibility === 'private' ? 'followers' : visibility
		},

		/** @param {object} post a ScheduledStatus */
		attachmentsOf(post) {
			return Array.isArray(post.media_attachments) ? post.media_attachments.length : 0
		},

		/**
		 * A page of the waiting posts, soonest first. The cursor is `min_id`, as
		 * the next page is the one scheduled later; the server compares ids
		 * against the row they name (`ScheduledStatusesRequest::beyond()`), since
		 * ids are creation order and the list is publication order.
		 *
		 * @param {boolean} more whether this is the reader asking for the page
		 *                       after the one they have
		 */
		async load(more = false) {
			if (this.loading) {
				return
			}

			const params = { limit: PAGE_SIZE }
			if (more) {
				const last = this.posts.at(-1)
				if (!last) {
					return
				}

				params.min_id = String(last.id)
			}

			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('apps/social/api/v1/scheduled_statuses'),
					{ params },
				)
				const page = Array.isArray(data) ? data : []
				// a full page may have more behind it; a short one is the end
				this.hasMore = page.length >= PAGE_SIZE
				this.posts = more ? this.merge(this.posts, page) : page
			} catch (error) {
				logger.error('Failed to load the scheduled posts', { error, more })
				showError(t('social', 'Could not load your scheduled posts'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * One entry per id, in the order they arrived: a post moved between two
		 * requests can sit on both sides of the cursor.
		 *
		 * @param {object[]} held what is already on screen
		 * @param {object[]} page what just arrived
		 * @return {object[]} the two, without repeats
		 */
		merge(held, page) {
			const seen = new Set(held.map((post) => String(post.id)))

			return [...held, ...page.filter((post) => !seen.has(String(post.id)))]
		},

		/**
		 * Opens the picker under an entry, starting from the time it has, or
		 * closes it again.
		 *
		 * @param {object} post a ScheduledStatus
		 */
		toggleEditor(post) {
			if (this.editing === post.id) {
				this.editing = null
				this.newTime = null

				return
			}

			this.editing = post.id
			this.newTime = new Date(post.scheduled_at)
		},

		/**
		 * Whether Save would send anything worth sending: a time the server
		 * accepts, and not the one the post already has.
		 *
		 * @param {object} post a ScheduledStatus
		 * @return {boolean}
		 */
		canReschedule(post) {
			return !this.rescheduling
				&& !isTooSoon(this.newTime)
				&& this.newTime.getTime() !== new Date(post.scheduled_at).getTime()
		},

		/**
		 * Moves a post to the time picked, and to its place in the list. The
		 * server has the last word (the daily cap is only known there); a refusal
		 * leaves the entry as it was and the picker open.
		 *
		 * @param {object} post a ScheduledStatus
		 */
		async reschedule(post) {
			if (!this.canReschedule(post)) {
				return
			}

			this.rescheduling = true
			try {
				const { data } = await axios.put(
					generateUrl('apps/social/api/v1/scheduled_statuses/' + post.id),
					{ scheduled_at: this.newTime.toISOString() },
				)
				const moved = data && typeof data === 'object' && data.id !== undefined
					? data
					: { ...post, scheduled_at: this.newTime.toISOString() }
				this.posts = this.place(this.posts.map((one) => (one.id === post.id ? moved : one)), moved)
				this.editing = null
				this.newTime = null
				showSuccess(t('social', 'Moved to {date}', { date: fullDateTime(moved.scheduled_at) }))
			} catch (error) {
				logger.error('Failed to reschedule a post', { error })
				showError(errorOf(error, t('social', 'Could not change the time of the scheduled post')))
			} finally {
				this.rescheduling = false
			}
		},

		/**
		 * The list in publication order again after one entry moved, ties by id
		 * as the server orders them. An entry moved past the end of a list with
		 * more behind it is dropped, or it would become the cursor and the next
		 * page would skip what lies between its old and new time.
		 *
		 * @param {object[]} posts the list, with the moved entry in it
		 * @param {object} moved the entry that moved
		 * @return {object[]}
		 */
		place(posts, moved) {
			const sorted = [...posts].sort((a, b) => {
				const diff = new Date(a.scheduled_at).getTime() - new Date(b.scheduled_at).getTime()
				if (diff !== 0) {
					return diff
				}

				return isNewerId(a.id, b.id) ? 1 : -1
			})

			if (this.hasMore && sorted.length > 1 && sorted.at(-1).id === moved.id) {
				return sorted.slice(0, -1)
			}

			return sorted
		},

		/** @param {object} post the ScheduledStatus to take back */
		async cancel(post) {
			this.cancelling = [...this.cancelling, post.id]
			try {
				await axios.delete(generateUrl('apps/social/api/v1/scheduled_statuses/' + post.id))
				this.posts = this.posts.filter((one) => one.id !== post.id)
			} catch (error) {
				logger.error('Failed to cancel a scheduled post', { error })
				showError(t('social', 'Could not cancel the scheduled post'))
			} finally {
				this.cancelling = this.cancelling.filter((id) => id !== post.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.scheduled-posts__hint {
	color: var(--color-text-maxcontrast);
}

.scheduled-posts__more {
	display: flex;
	justify-content: center;
	margin-block-start: 0.75rem;
}

.scheduled-posts__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.scheduled-posts__item {
	position: relative;
	padding: 10px 96px 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.scheduled-posts__when {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.scheduled-posts__text {
	margin: 4px 0 0;
	// three lines is enough to know which post it is
	display: -webkit-box;
	-webkit-line-clamp: 3;
	line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
	overflow-wrap: anywhere;
}

.scheduled-posts__meta {
	margin: 4px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.scheduled-posts__actions {
	position: absolute;
	inset-inline-end: 8px;
	inset-block-start: 8px;
	display: flex;
	gap: 4px;
}

.scheduled-posts__editor {
	// the actions column is beside the text, not beside the picker
	margin-inline-end: -84px;
}

.scheduled-posts__save {
	margin-inline-start: auto;
}
</style>
