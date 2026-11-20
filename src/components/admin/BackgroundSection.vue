<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcNoteCard v-if="unregistered" type="warning">
			{{ t('social', 'Some jobs are not registered at all. That is what an upgrade whose migrations have not run looks like; occ upgrade registers them.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!background?.late" type="success">
			{{ t('social', 'Every job has run recently.') }}
		</NcNoteCard>
		<NcNoteCard v-else type="error">
			<strong>
				{{ n('social', '%n job has not run when it should have.', '%n jobs have not run when they should have.', background.late) }}
			</strong>
			{{ t('social', 'The longest has been waiting {duration}. If that is all of them, cron is not running: check the system timer or the web cron that calls cron.php.', { duration: duration(background.worst || 0) }) }}
		</NcNoteCard>

		<div class="social-admin__scroll">
			<table class="social-admin__table">
				<thead>
					<tr>
						<th>{{ t('social', 'Job') }}</th>
						<th>{{ t('social', 'Runs every') }}</th>
						<th>{{ t('social', 'Last run') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="job in jobs" :key="job.class" :class="{ 'background__row--late': job.late }">
						<td>{{ job.label }}</td>
						<td>{{ duration(job.interval) }}</td>
						<td>{{ when(job) }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { duration } from '../../utils/duration.js'

/**
 * Whether the background jobs are running. Read-only, from initial state: the
 * summary is one read of Nextcloud's job list, and running a job from here
 * would need it to be safe to run twice at once.
 */
export default {
	name: 'BackgroundSection',

	components: {
		NcNoteCard,
	},

	props: {
		/**
		 * `BackgroundHealthService::summary()`, as initial state. Not required,
		 * so the page still draws when the server sent nothing.
		 */
		background: {
			type: Object,
			default: () => ({ jobs: [], late: 0, worst: 0 }),
		},
	},

	computed: {
		/** @return {Array<object>} the rows, which may not have arrived at all */
		jobs() {
			return Array.isArray(this.background?.jobs) ? this.background.jobs : []
		},

		/**
		 * @return {boolean} whether any job is missing from the job list,
		 * which is what an upgrade with unrun migrations looks like
		 */
		unregistered() {
			return this.jobs.some((job) => !job.registered)
		},
	},

	methods: {
		t,
		n,

		/**
		 * @param {object} job one row of the summary
		 * @return {string} when it last ran, in words a reader can act on
		 */
		when(job) {
			if (!job.registered) {
				return t('social', 'not registered')
			}
			if (!job.last) {
				return t('social', 'never')
			}

			const since = Math.max(0, Math.floor(Date.now() / 1000) - job.last)

			return t('social', '{duration} ago', { duration: this.duration(since) })
		},

		duration,
	},
}
</script>

<style scoped lang="scss">
.background__row--late td {
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}
</style>
