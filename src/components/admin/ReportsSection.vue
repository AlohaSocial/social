<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'Reports')"
		:description="t('social', 'Reports filed by the people on this instance and reports received from other instances. The open ones are here; the ones somebody has already dealt with are below them, folded away.')">
		<NcEmptyContent v-if="open.length === 0" :name="t('social', 'No open reports.')">
			<template #icon>
				<IconCheckCircle :size="20" />
			</template>
		</NcEmptyContent>

		<ReportsTable
			v-else
			:reports="open"
			:takenDown="takenDown"
			@resolve="toggleResolved"
			@moderate="askModerate"
			@takedown="askTakedown" />

		<!-- the count belongs to the table; the empty state above already says
		     there is nothing, and the old page said both at once -->
		<p v-if="open.length > 0" class="social-admin__actions">
			<span class="social-admin__hint">
				{{ n('social', '%n open report.', '%n open reports.', openTotal) }}
			</span>
			<NcButton v-if="openHasMore" :disabled="loadingOpen" @click="loadReports(false)">
				<template v-if="loadingOpen" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Show more') }}
			</NcButton>
		</p>

		<!-- not loaded with the page: on an instance that has been moderated
		     for a year the resolved reports are most of the table and none of
		     what anybody came here for -->
		<details v-if="resolvedTotal > 0" class="reports__fold" @toggle="onFold">
			<summary class="reports__summary">
				<IconChevronRight class="reports__chevron" :size="20" />
				<span>{{ n('social', '%n resolved report', '%n resolved reports', resolvedTotal) }}</span>
			</summary>

			<ReportsTable
				:reports="resolved"
				:takenDown="takenDown"
				@resolve="toggleResolved"
				@moderate="askModerate"
				@takedown="askTakedown" />

			<p class="social-admin__actions">
				<NcLoadingIcon v-if="loadingResolved" :size="20" />
				<NcButton v-if="resolvedHasMore" :disabled="loadingResolved" @click="loadReports(true)">
					{{ t('social', 'Show more') }}
				</NcButton>
			</p>
		</details>

		<ConfirmDialog
			v-if="pending !== null"
			:open="true"
			:name="pending.name"
			:message="pending.message"
			:confirmLabel="pending.confirmLabel"
			@update:open="pending = null"
			@confirm="pending.go()" />
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import IconCheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import IconChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import ConfirmDialog from './ConfirmDialog.vue'
import ReportsTable from './ReportsTable.vue'
import { moderationUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'
import { suspensionWarning } from './moderation.js'

/**
 * What people here and peers elsewhere have complained about.
 *
 * The first page of the open reports arrives with the page as initial state;
 * everything after it, and the resolved ones, is read from /moderation/reports
 * a page at a time.
 */
export default {
	name: 'ReportsSection',

	components: {
		ConfirmDialog,
		IconCheckCircle,
		IconChevronRight,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSettingsSection,
		ReportsTable,
	},

	props: {
		/** the first page of the open reports */
		reports: {
			type: /** @type {import('vue').PropType<import('../../types/Moderation.js').ModerationReport[]>} */ (Array),
			required: true,
		},

		/** how many open reports there are in all */
		openTotal: {
			type: Number,
			default: 0,
		},

		/** how many resolved ones there are, for the fold */
		resolvedTotal: {
			type: Number,
			default: 0,
		},

		/** how many rows the server sends per page */
		perPage: {
			type: Number,
			default: 50,
		},
	},

	data() {
		return {
			open: [...this.reports],
			resolved: [],
			// which page of each of the two lists has been fetched so far
			openPage: 1,
			resolvedPage: 0,
			openHasMore: this.openTotal > this.perPage,
			resolvedHasMore: false,
			loadingOpen: false,
			loadingResolved: false,
			takenDown: [],
			pending: null,
		}
	},

	methods: {
		t,
		n,

		/**
		 * Appends the next page of the open reports, or of the resolved ones.
		 *
		 * @param {boolean} resolved the resolved ones instead of the open ones
		 * @return {Promise<void>} once the rows are in the table
		 */
		async loadReports(resolved) {
			// the page is claimed before the request rather than after it: two
			// clicks on "Show more" while the first is in flight would
			// otherwise both ask for the same page and the table would hold
			// each row twice
			const next = (resolved ? this.resolvedPage : this.openPage) + 1
			if (resolved) {
				this.resolvedPage = next
				this.loadingResolved = true
			} else {
				this.openPage = next
				this.loadingOpen = true
			}

			try {
				const { data } = await axios.get(moderationUrl('/reports'), {
					params: { resolved: resolved ? '1' : '0', page: next },
				})
				const more = next * data.perPage < data.total
				if (resolved) {
					this.resolved = this.resolved.concat(data.reports)
					this.resolvedHasMore = more
				} else {
					this.open = this.open.concat(data.reports)
					this.openHasMore = more
				}
			} catch {
				if (resolved) {
					this.resolvedPage = next - 1
				} else {
					this.openPage = next - 1
				}
				showError(t('social', 'Could not load the reports'))
			} finally {
				if (resolved) {
					this.loadingResolved = false
				} else {
					this.loadingOpen = false
				}
			}
		},

		/**
		 * Fills the resolved table the first time the fold is opened.
		 *
		 * @param {Event} event the toggle of the fold
		 */
		onFold(event) {
			if (/** @type {HTMLDetailsElement} */ (event.target).open && this.resolvedPage === 0) {
				this.loadReports(true)
			}
		},

		/**
		 * Resolves a report, or reopens one.
		 *
		 * The row stays in the table it is in. Moving it to the other one would
		 * renumber the pages under the cursor of whoever is working through
		 * them; the next load puts it where it belongs.
		 *
		 * @param {object} report the row that was acted on
		 * @return {Promise<void>}
		 */
		async toggleResolved(report) {
			const resolved = !report.resolved
			try {
				await axios.post(moderationUrl('/reports/' + report.id + '/resolve'), { resolved })
				report.resolved = resolved
			} catch {
				showError(t('social', 'Could not update the report'))
			}
		},

		/**
		 * Silences, suspends or lifts. Suspending deletes, so it asks first and
		 * says what it will cost.
		 *
		 * @param {object} decision the report acted on and the level
		 * @param {object} decision.report the row
		 * @param {string} decision.level 'silence', 'suspend' or ''
		 */
		askModerate({ report, level }) {
			if (level !== 'suspend') {
				this.moderate(report, level)

				return
			}

			this.pending = {
				name: t('social', 'Suspend this account?'),
				message: suspensionWarning(),
				confirmLabel: t('social', 'Suspend'),
				go: () => this.moderate(report, level),
			}
		},

		/**
		 * @param {object} report the row acted on
		 * @param {string} level what was decided
		 * @return {Promise<void>}
		 */
		async moderate(report, level) {
			this.pending = null
			try {
				await axios.post(moderationUrl('/accounts'), {
					actorId: report.account_id,
					level,
					comment: '',
				})
				report.level = level
				showSuccess(level === ''
					? t('social', 'The decision was lifted')
					: t('social', 'The decision was applied'))
			} catch {
				showError(t('social', 'Could not apply the decision'))
			}
		},

		/**
		 * Takes one reported post down: the lightest thing a moderator can do
		 * about a report, and the one the panel could not do at all.
		 *
		 * @param {object} target the post to remove
		 * @param {string} target.statusId the post
		 */
		askTakedown({ statusId }) {
			this.pending = {
				name: t('social', 'Take this post down?'),
				message: t('social', 'This deletes the post for everybody here.'),
				confirmLabel: t('social', 'Take down'),
				go: () => this.takeDown(statusId),
			}
		},

		/**
		 * @param {string} statusId the post to remove
		 * @return {Promise<void>}
		 */
		async takeDown(statusId) {
			this.pending = null
			try {
				await axios.post(moderationUrl('/statuses/remove'), { streamId: statusId })
				this.takenDown = this.takenDown.concat([statusId])
				showSuccess(t('social', 'The post was taken down'))
			} catch {
				showError(t('social', 'Could not take the post down'))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.reports__fold {
	margin-block-start: calc(var(--default-grid-baseline) * 3);

	// the browser's own disclosure triangle is the one thing on this page that
	// belongs to no design system; the row below it is a Nextcloud control
	.reports__summary {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
		width: fit-content;
		cursor: pointer;
		padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius-element, var(--border-radius-large));
		list-style: none;
		font-weight: bold;

		&::-webkit-details-marker {
			display: none;
		}

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-hover);
		}
	}

	.reports__chevron {
		color: var(--color-text-maxcontrast);
		transition: transform var(--animation-quick, .1s) ease;
	}

	&[open] .reports__chevron {
		transform: rotate(90deg);
	}
}

@media (prefers-reduced-motion: reduce) {
	.reports__chevron {
		transition: none;
	}
}
</style>
