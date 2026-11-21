<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<div class="review__switches">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="reviewFirstPostOn"
				:disabled="savingSettings"
				@update:modelValue="saveSettings($event, autospamOn, reviewVideosOn)">
				{{ t('social', 'Hold the first post of a new account') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="autospamOn"
				:disabled="savingSettings"
				@update:modelValue="saveSettings(reviewFirstPostOn, $event, reviewVideosOn)">
				{{ t('social', 'Hold posts that read like spam') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="reviewVideosOn"
				:disabled="savingSettings"
				@update:modelValue="saveSettings(reviewFirstPostOn, autospamOn, $event)">
				{{ t('social', 'Hold every post with a video on it') }}
			</NcCheckboxRadioSwitch>
		</div>

		<NcEmptyContent v-if="held.length === 0" :name="t('social', 'Nothing is waiting.')">
			<template #icon>
				<IconCheckCircle :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="review__scroll">
			<table class="review__table">
				<!-- fixed layout takes its widths from the first row, so they live here, not on the cells -->
				<colgroup>
					<col class="review__col-account">
					<col class="review__col-text">
					<col class="review__col-reason">
					<col class="review__col-since">
					<col class="review__col-actions">
				</colgroup>
				<thead>
					<tr>
						<th>{{ t('social', 'Account') }}</th>
						<th>{{ t('social', 'Post') }}</th>
						<th>{{ t('social', 'Held because') }}</th>
						<th>{{ t('social', 'Waiting since') }}</th>
						<th class="review__actions-head">
							{{ t('social', 'Actions') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="post in held" :key="post.id">
						<td class="review__account" :title="post.account_id">
							{{ post.username }}
						</td>
						<td class="review__text">
							<p v-if="post.spoiler_text" class="review__warning">
								{{ post.spoiler_text }}
							</p>
							<p>{{ post.text }}</p>
							<p v-if="post.media_count > 0" class="review__hint">
								{{ n('social', '%n attachment', '%n attachments', post.media_count) }}
							</p>
						</td>
						<td class="review__reason">
							{{ reasonText(post.reason) }}
						</td>
						<td class="review__since">
							{{ since(post.created_at) }}
						</td>
						<td class="review__actions">
							<NcButton :disabled="busy === post.id" @click="approve(post)">
								{{ t('social', 'Publish') }}
							</NcButton>
							<NcButton :disabled="busy === post.id" @click="askReject(post)">
								{{ t('social', 'Refuse') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<p v-if="hasMore" class="social-admin__actions">
			<NcButton :disabled="loading" @click="loadMore">
				<template v-if="loading" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Show more') }}
			</NcButton>
		</p>

		<ConfirmDialog
			v-if="pending !== null"
			:open="true"
			:name="t('social', 'Refuse this post?')"
			:message="rejectWarning"
			:confirmLabel="t('social', 'Refuse')"
			@update:open="pending = null"
			@confirm="reject()" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import IconCheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ConfirmDialog from './ConfirmDialog.vue'
import { moderationUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * The review queue. Each row shows the held post's text, since the post is
 * nowhere else to be seen. Refusing asks first because it cannot be undone;
 * publishing does not, since a published post can still be taken down.
 */
export default {
	name: 'ReviewSection',

	components: {
		ConfirmDialog,
		IconCheckCircle,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		/** the first page of the queue */
		queue: {
			type: /** @type {import('vue').PropType<import('../../types/Moderation.js').HeldPost[]>} */ (Array),
			required: true,
		},

		/** how many are waiting in all */
		total: {
			type: Number,
			default: 0,
		},

		/** whether a new account's first post is held */
		reviewFirstPost: {
			type: Boolean,
			default: true,
		},

		/** whether the spam rules are applied */
		reviewVideos: {
			type: Boolean,
			default: false,
		},

		autospam: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			held: [...this.queue],
			page: 1,
			perPage: 50,
			hasMore: this.total > this.queue.length,
			loading: false,
			busy: null,
			pending: null,
			reviewFirstPostOn: this.reviewFirstPost,
			autospamOn: this.autospam,
			reviewVideosOn: this.reviewVideos,
			savingSettings: false,
		}
	},

	computed: {
		rejectWarning() {
			return t(
				'social',
				'The post is deleted and its author is told. This cannot be undone: '
				+ 'nothing of it is kept here afterwards.',
			)
		},
	},

	methods: {
		t,
		n,

		/**
		 * Why it is waiting, in the moderator's language. The set of reasons is
		 * closed, so the strings live here to be translated.
		 *
		 * @param {string} reason the rule that held it
		 * @return {string} what the table shows
		 */
		reasonText(reason) {
			switch (reason) {
				case 'first_post':
					return t('social', 'The first post of a new account')
				case 'links':
					return t('social', 'More links than a post usually carries')
				case 'mentions':
					return t('social', 'Mentions of accounts with no connection to this one')
				case 'repeat':
					return t('social', 'The same text as a post already waiting')
				case 'video':
					return t('social', 'It has a video on it')
				default:
					return reason
			}
		},

		/**
		 * @param {string} isoDate when it was held
		 * @return {string} the date, in the reader's own format
		 */
		since(isoDate) {
			const when = new Date(isoDate)

			return isNaN(when.getTime()) ? '' : when.toLocaleString()
		},

		/** @return {Promise<void>} once the next page is in the table */
		async loadMore() {
			const next = this.page + 1
			this.page = next
			this.loading = true

			try {
				const { data } = await axios.get(moderationUrl('/review'), { params: { page: next } })
				this.held = this.held.concat(data.held)
				this.perPage = data.perPage
				this.hasMore = next * data.perPage < data.total
			} catch {
				this.page = next - 1
				showError(t('social', 'Could not load the queue'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} post the held post
		 * @return {Promise<void>} once it is published
		 */
		async approve(post) {
			this.busy = post.id
			try {
				await axios.post(moderationUrl(`/review/${post.id}/approve`))
				this.held = this.held.filter((one) => one.id !== post.id)
				showSuccess(t('social', 'The post was published'))
			} catch {
				showError(t('social', 'Could not publish the post'))
			} finally {
				this.busy = null
			}
		},

		/** @param {object} post the held post */
		askReject(post) {
			this.pending = post
		},

		/** @return {Promise<void>} once it is refused */
		async reject() {
			const post = this.pending
			this.pending = null
			if (post === null) {
				return
			}

			this.busy = post.id
			try {
				await axios.post(moderationUrl(`/review/${post.id}/reject`))
				this.held = this.held.filter((one) => one.id !== post.id)
				showSuccess(t('social', 'The post was refused and its author told'))
			} catch {
				showError(t('social', 'Could not refuse the post'))
			} finally {
				this.busy = null
			}
		},

		/**
		 * @param {boolean} first whether a new account's first post is held
		 * @param {boolean} spam whether the spam rules are applied
		 * @param {boolean} videos whether every post with a video on it is held
		 * @return {Promise<void>} once all three are saved
		 */
		async saveSettings(first, spam, videos) {
			this.savingSettings = true
			try {
				// always all three: the endpoint turns off any switch it is not sent
				const { data } = await axios.post(moderationUrl('/review/settings'), {
					reviewFirstPost: first,
					autospam: spam,
					reviewVideos: videos,
				})
				this.reviewFirstPostOn = data.reviewFirstPost
				this.autospamOn = data.autospam
				this.reviewVideosOn = data.reviewVideos
			} catch {
				showError(t('social', 'Could not save the setting'))
			} finally {
				this.savingSettings = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.review__switches {
	margin-block-end: 12px;
}

.review__scroll {
	// the table may be wider than the page: its columns do not stack
	overflow-x: auto;
}

.review__table {
	width: 100%;
	// auto layout would size a column to its longest word, such as an actor id
	table-layout: fixed;
	min-width: 720px;
	border-collapse: collapse;

	th,
	td {
		padding: 8px 12px 8px 0;
		text-align: start;
		vertical-align: top;
		// the settings page sets `nowrap` on table cells, and a cell that
		// cannot wrap ignores the width the colgroup gives it
		white-space: normal;
		overflow-wrap: break-word;
		border-block-end: 1px solid var(--color-border);
	}
}

.review__col-account {
	width: 16%;
}

.review__col-text {
	width: 28%;
}

.review__col-reason {
	width: 18%;
}

.review__col-since {
	width: 14%;
}

.review__col-actions {
	// two buttons side by side; they wrap on a narrow screen
	width: 24%;
}

.review__account {
	overflow-wrap: anywhere;
}

.review__text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.review__warning {
	font-weight: bold;
}

.review__hint {
	color: var(--color-text-maxcontrast);
}

.review__actions-head {
	text-align: end;
}

.review__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;

	// the labels are short; never truncate them
	:deep(.button-vue__text) {
		white-space: nowrap;
	}
}
</style>
