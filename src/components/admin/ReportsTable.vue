<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social-admin__scroll">
		<table class="social-admin__table reports-table">
			<thead>
				<tr>
					<th>{{ t('social', 'Reported account') }}</th>
					<th>{{ t('social', 'Reporter') }}</th>
					<th>{{ t('social', 'Category') }}</th>
					<th>{{ t('social', 'Comment') }}</th>
					<th>{{ t('social', 'Statuses') }}</th>
					<th>{{ t('social', 'Date') }}</th>
					<th>{{ t('social', 'Status') }}</th>
					<th>{{ t('social', 'Account') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<!-- every cell below is interpolated, never `v-html`: a handle,
				     an instance name and the comment on a report are whatever a
				     remote server sent, and this is the page whose buttons
				     delete accounts -->
				<tr v-for="report in reports" :key="report.id" class="reports-table__row">
					<td>
						<a
							v-if="report.account && isLink(report.account_id)"
							class="reports-table__actor"
							:href="report.account_id"
							:title="report.account_id"
							target="_blank"
							rel="noreferrer noopener">{{ report.account }}</a>
						<span v-else class="reports-table__actor" :title="report.account_id">
							{{ report.account || report.account_id }}
						</span>
					</td>
					<td>
						<span class="reports-table__actor" :title="report.reporter">{{ report.reporter }}</span>
						<em v-if="!report.local">({{ t('social', 'remote') }})</em>
					</td>
					<td>
						{{ report.category }}
						<ul v-if="report.rules?.length" class="reports-table__rules">
							<li v-for="(rule, index) in report.rules" :key="index">
								{{ rule }}
							</li>
						</ul>
					</td>
					<td>
						<span class="reports-table__comment">{{ report.comment }}</span>
					</td>
					<td class="reports-table__statuses">
						<span
							v-for="statusId in report.status_ids"
							:key="statusId"
							class="reports-table__status">
							<template v-if="takenDown.includes(statusId)">
								{{ t('social', 'Taken down') }}
							</template>
							<template v-else>
								<a
									v-if="isLink(statusId)"
									:href="statusId"
									:aria-label="t('social', 'Open the reported post')"
									target="_blank"
									rel="noreferrer noopener">↗</a>
								<template v-else>{{ statusId }}</template>
								<NcButton
									variant="tertiary"
									size="small"
									:title="t('social', 'Take this post down')"
									@click="$emit('takedown', { report, statusId })">
									{{ t('social', 'Take down') }}
								</NcButton>
							</template>
						</span>
					</td>
					<td>{{ moment(report.creation) }}</td>
					<td class="reports-table__state">
						{{ report.resolved ? t('social', 'Resolved') : t('social', 'Open') }}
					</td>
					<td>
						<div class="social-admin__actions">
							<span class="reports-table__decision">{{ decisionOf(report.level) }}</span>
							<NcButton
								size="small"
								:disabled="report.level === 'silence'"
								@click="$emit('moderate', { report, level: 'silence' })">
								{{ t('social', 'Silence') }}
							</NcButton>
							<NcButton
								size="small"
								variant="error"
								:disabled="report.level === 'suspend'"
								@click="$emit('moderate', { report, level: 'suspend' })">
								{{ t('social', 'Suspend') }}
							</NcButton>
							<NcButton size="small" @click="$emit('moderate', { report, level: '' })">
								{{ t('social', 'Lift') }}
							</NcButton>
						</div>
					</td>
					<td>
						<NcButton size="small" @click="$emit('resolve', report)">
							{{ report.resolved ? t('social', 'Reopen') : t('social', 'Resolve') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

/**
 * One table of reports, open or resolved.
 *
 * The two tables carry the same nine columns and the same four decisions, so
 * they are one component: the version of this page that drew them twice, once
 * in PHP and once in Javascript, had the two copies to keep in step.
 */
export default {
	name: 'ReportsTable',

	components: {
		NcButton,
	},

	props: {
		/** the rows, as /moderation/reports sends them */
		reports: {
			type: /** @type {import('vue').PropType<import('../../types/Moderation.js').ModerationReport[]>} */ (Array),
			required: true,
		},

		/** the posts taken down since the page loaded */
		takenDown: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['resolve', 'moderate', 'takedown'],

	methods: {
		t,

		/**
		 * What stands against the reported account, in the moderator's words.
		 *
		 * @param {string} level 'silence', 'suspend', or '' for nothing
		 * @return {string} what the cell says
		 */
		decisionOf(level) {
			if (level === 'suspend') {
				return t('social', 'Suspended')
			}

			return level === 'silence' ? t('social', 'Silenced') : ''
		},

		/**
		 * The date the rows have always carried: UTC, to the minute.
		 *
		 * @param {number} creation seconds since the epoch, or 0
		 * @return {string} the date, or ''
		 */
		moment(creation) {
			return creation > 0
				? new Date(creation * 1000).toISOString().slice(0, 16).replace('T', ' ')
				: ''
		},

		/**
		 * Whether an id is an address this page will put in an `href`.
		 *
		 * Every id in this table — the reported account's, each reported
		 * post's — is a string a remote server chose, and a `javascript:` one
		 * in an `href` is a click away from running on the page whose buttons
		 * delete accounts. Anything that is not plainly `https:` is shown as
		 * the text it is instead.
		 *
		 * @param {string} id the id of a reported account or post
		 * @return {boolean} whether it is an address a moderator can open
		 */
		isLink(id) {
			return typeof id === 'string' && id.startsWith('https://')
		},
	},
}
</script>

<style lang="scss" scoped>
.reports-table {
	// a reporter is an actor id, which is a URL as long as its instance's
	// name plus a username; left to itself one of them takes the width the
	// decision buttons need and pushes Resolve off the end of the table
	// An actor id is a URL with no space in it, and a table laying its columns
	// out automatically will not wrap one however it is asked to: the id is
	// painted straight across the two columns to its right. Ending it in an
	// ellipsis keeps the columns where they are, and the whole id is one hover
	// away — the readable handle is in the column to the left anyway.
	&__actor {
		display: inline-block;
		max-width: 24ch;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		vertical-align: bottom;
	}

	// as is whatever the person filing the report chose to write
	&__comment {
		display: inline-block;
		max-width: 28ch;
		overflow-wrap: anywhere;
	}

	&__rules {
		max-width: 28ch;
		margin: 0;
		padding-inline-start: calc(var(--default-grid-baseline) * 4);
		list-style: disc;
		overflow-wrap: anywhere;
	}

	&__statuses {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__status {
		display: flex;
		gap: var(--default-grid-baseline);
		align-items: center;
	}

	&__decision {
		color: var(--color-text-maxcontrast);
	}
}
</style>
