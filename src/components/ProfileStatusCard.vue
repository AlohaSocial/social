<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<TimelineEntry
		class="profile-status-card"
		:item="displayStatus"
		type="account"
		:postHref="postHref"
		:embeddedActions="true">
		<template #profileActions>
			<div class="profile-status-card__details">
				<div class="profile-status-card__toolbar" :aria-label="t('social', 'Post actions')">
					<NcButton variant="tertiary" :aria-expanded="likesOpen" @click="likesOpen = !likesOpen">
						{{ t('social', 'Likes ({count})', { count: displayStatus.favourites_count || 0 }) }}
					</NcButton>
					<NcButton variant="tertiary" :aria-expanded="commentsOpen" @click="toggleComments">
						{{ t('social', 'Comments ({count})', { count: commentCount }) }}
					</NcButton>
					<NcButton variant="tertiary" @click="$emit('reply', displayStatus)">
						{{ t('social', 'Reply') }}
					</NcButton>
					<NcButton
						v-if="canEdit"
						variant="tertiary"
						:disabled="editing || savingEdit"
						@click="beginEdit">
						{{ t('social', 'Edit') }}
					</NcButton>
					<NcButton
						v-if="canDelete"
						variant="tertiary"
						:disabled="deleting"
						@click="deletePost">
						{{ deleting ? t('social', 'Deleting…') : t('social', 'Delete') }}
					</NcButton>
					<a v-if="postHref" class="profile-status-card__open" :href="postHref">{{ t('social', 'Open post') }}</a>
				</div>
				<form v-if="editing" class="profile-status-card__editor" @submit.prevent="saveEdit">
					<textarea v-model="editText" :disabled="savingEdit" :aria-label="t('social', 'Edit post')" />
					<NcButton type="submit" variant="primary" :disabled="savingEdit">
						{{ savingEdit ? t('social', 'Saving…') : t('social', 'Save') }}
					</NcButton>
					<NcButton
						type="button"
						variant="tertiary"
						:disabled="savingEdit"
						@click="editing = false">
						{{ t('social', 'Cancel') }}
					</NcButton>
				</form>
				<PostReactedBy v-if="likesOpen" :status="status" />
				<section v-if="commentsOpen" class="profile-status-card__comments" :aria-label="t('social', 'Comments')">
					<p v-if="commentsLoading" role="status">
						{{ t('social', 'Loading comments…') }}
					</p>
					<p v-else-if="commentsError" role="alert">
						{{ t('social', 'Could not load comments') }}
					</p>
					<p v-else-if="comments.length === 0">
						{{ t('social', 'No comments yet') }}
					</p>
					<template v-else>
						<article v-for="comment in comments" :key="comment.id" class="profile-status-card__comment">
							<strong>{{ comment.account?.display_name || comment.account?.acct || t('social', 'Unknown account') }}</strong>
							<MessageContent :item="comment" />
						</article>
					</template>
				</section>
			</div>
		</template>
	</TimelineEntry>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import axios from '@nextcloud/axios'
import { defineAsyncComponent } from 'vue'
import PostReactedBy from './PostReactedBy.vue'
import MessageContent from './MessageContent.js'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { getActivePinia, mapStores } from 'pinia'
import { useTimelineStore } from '../store/timeline.js'

// The whole post renderer: the menu, the gallery, the hover card, polls and
// quotes. The profile page's entry is loaded on Nextcloud's own profile page
// for every user, most of whom have not posted anything, so the renderer
// arrives only once there is a post to draw -- and as the same chunk the app
// already has, rather than a second copy inside this entry.
const TimelineEntry = defineAsyncComponent(() => import('./TimelineEntry.vue'))

export default {
	name: 'ProfileStatusCard',
	components: { MessageContent, NcButton, PostReactedBy, TimelineEntry },
	props: {
		status: { type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object), required: true },
		canDelete: { type: Boolean, default: false },
		nativeDelete: { type: Boolean, default: false },
		canEdit: { type: Boolean, default: false },
		nativeEdit: { type: Boolean, default: false },
	},

	emits: ['reply', 'deleted', 'updated'],

	data() {
		return { likesOpen: false, commentsOpen: false, comments: [], commentsLoading: false, commentsError: false, deleting: false, editing: false, savingEdit: false, editText: '' }
	},

	computed: {
		...mapStores(useTimelineStore),

		displayStatus() {
			if (!getActivePinia()) {
				return this.status
			}

			return this.timelineStore.getStatus(this.status.id) ?? this.status
		},

		postHref() {
			return this.displayStatus.url || this.displayStatus.uri || ''
		},

		commentCount() {
			return Number(this.displayStatus.replies_count) || 0
		},
	},

	watch: {
		status: {
			immediate: true,
			handler(status) {
				if (getActivePinia() && status?.id !== undefined) {
					this.timelineStore.addToStatuses(status)
				}
			},
		},
	},

	methods: {
		t,
		async toggleComments() {
			this.commentsOpen = !this.commentsOpen
			if (!this.commentsOpen || this.comments.length || this.commentsLoading) {
				return
			}
			this.commentsLoading = true
			this.commentsError = false
			try {
				const id = String(this.displayStatus.id)
				const native = id.includes('/ap/bluesky/')
				const { data } = native
					? await axios.get(generateUrl('apps/social/api/v1/atproto/thread'), { params: { id } })
					: await axios.get(generateUrl(`apps/social/api/v1/statuses/${encodeURIComponent(id)}/context`))
				this.comments = (Array.isArray(data?.descendants) ? data.descendants : [])
					.filter((reply) => native || String(reply.in_reply_to_id) === String(this.displayStatus.id))
			} catch (error) {
				this.commentsError = true
				logger.error('Failed to load profile post comments', { error, statusId: this.displayStatus.id })
			} finally {
				this.commentsLoading = false
			}
		},

		async deletePost() {
			if (this.deleting || !this.canDelete) {
				return
			}
			this.deleting = true
			try {
				const id = String(this.displayStatus.id)
				if (this.nativeDelete) {
					await axios.delete(generateUrl('apps/social/api/v1/atproto/post'), { params: { id } })
				} else {
					await axios.delete(generateUrl(`apps/social/api/v1/statuses/${encodeURIComponent(id)}`))
				}
				this.$emit('deleted', this.displayStatus)
			} catch (error) {
				logger.error('Failed to delete profile post', { error, statusId: this.displayStatus.id })
				await showError(error?.response?.data?.message ?? t('social', 'Could not delete this post'))
			} finally {
				this.deleting = false
			}
		},

		beginEdit() {
			this.editText = String(this.displayStatus.text ?? this.displayStatus.content ?? '').replace(/<[^>]+>/g, '').trim()
			this.editing = true
		},

		async saveEdit() {
			if (this.savingEdit || !this.canEdit || !this.nativeEdit) {
				return
			}
			this.savingEdit = true
			try {
				await axios.put(generateUrl('apps/social/api/v1/atproto/post'), { id: this.displayStatus.id, text: this.editText })
				this.$emit('updated', { ...this.displayStatus, text: this.editText, content: `<p>${this.editText.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}</p>` })
				this.editing = false
			} catch (error) {
				logger.error('Failed to edit native Bluesky post', { error, statusId: this.displayStatus.id })
				await showError(error?.response?.data?.message ?? t('social', 'Could not edit this post'))
			} finally {
				this.savingEdit = false
			}
		},
	},
}
</script>

<style scoped>
.profile-status-card {
	margin-block: 0 1rem;
}

.profile-status-card__toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.25rem 0.4rem;
}

.profile-status-card__open {
	margin-inline-start: auto;
}

.profile-status-card__comments {
	margin-block-start: 0.5rem;
	padding-block-start: 0.4rem;
	border-block-start: 1px solid var(--color-border);
}

.profile-status-card__comment {
	padding-block: 0.75rem;
	border-bottom: 1px solid var(--color-border);
}
</style>
