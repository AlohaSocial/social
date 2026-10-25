<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section
		class="post-event"
		:aria-label="t('social', 'Event details')">
		<strong v-if="event.name" class="post-event__title">{{ event.name }}</strong>
		<div class="post-event__facts">
			<span v-if="eventTimeLabel" class="post-event__fact">
				<CalendarMonthOutline :size="16" />
				{{ eventTimeLabel }}
			</span>
			<span v-if="event.location" class="post-event__fact">
				<MapMarkerOutline :size="16" />
				{{ event.location }}
			</span>
		</div>
		<NcButton
			v-if="event.start_time && !isPublic"
			class="post-event__calendar"
			:disabled="importingEvent"
			@click="importEventToCalendar">
			<template #icon>
				<CalendarMonthOutline :size="16" />
			</template>
			{{ importingEvent ? t('social', 'Adding…') : t('social', 'Add to Nextcloud Calendar') }}
		</NcButton>
		<a
			v-if="event.start_time && !isPublic && calendarImportFailed"
			class="post-event__calendar post-event__calendar--download"
			:href="eventCalendarUrl">
			<CalendarMonthOutline :size="16" />
			{{ t('social', 'Download calendar file') }}
		</a>
		<a
			v-if="event.start_time && isPublic"
			class="post-event__calendar"
			:href="eventCalendarUrl">
			<CalendarMonthOutline :size="16" />
			{{ t('social', 'Download calendar file') }}
		</a>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { showError, showSuccess } from '../services/toast.js'
import logger from '../services/logger.js'

export default {
	name: 'TimelineEventCard',
	components: { CalendarMonthOutline, MapMarkerOutline, NcButton },
	props: {
		event: { type: Object, required: true },
		statusId: { type: [String, Number], required: true },
		isPublic: { type: Boolean, default: false },
	},

	setup() {
		return { t }
	},

	data() {
		return { importingEvent: false, calendarImportFailed: false }
	},

	computed: {
		eventTimeLabel() {
			const format = (value) => {
				if (value === null || value === undefined || value === '') {
					return ''
				}
				const date = new Date(value)
				return Number.isNaN(date.getTime())
					? ''
					: new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
			}
			const start = format(this.event.start_time)
			if (!start) {
				return ''
			}
			const end = format(this.event.end_time)

			return end ? `${start} – ${end}` : start
		},

		eventCalendarUrl() {
			return generateUrl(`apps/social/api/v1/statuses/${encodeURIComponent(this.statusId)}/calendar`)
		},
	},

	methods: {
		async importEventToCalendar() {
			if (this.importingEvent) {
				return
			}
			this.importingEvent = true
			this.calendarImportFailed = false
			try {
				const { data } = await axios.post(generateUrl(`apps/social/api/v1/statuses/${encodeURIComponent(this.statusId)}/calendar/import`))
				showSuccess(data.already_imported
					? t('social', 'This event is already in your calendar')
					: t('social', 'Event added to {calendar}', { calendar: data.calendar || t('social', 'your Nextcloud Calendar') }))
			} catch (error) {
				logger.error('Could not import the event into Nextcloud Calendar', { error })
				this.calendarImportFailed = true
				const reason = error?.response?.data?.error
				const status = error?.response?.status
				const message = status === 401
					? t('social', 'Your Nextcloud session is no longer valid. Reload the page and sign in again, then retry the calendar import.')
					: status === 403
						? t('social', 'Nextcloud refused this request. Reload the page and try again; if it continues, check your calendar permissions.')
						: reason === 'No writable calendar is available'
							? t('social', 'No writable calendar is available. Download the calendar file and add it manually.')
							: reason === 'Calendar import is unavailable'
								? t('social', 'Calendar import is unavailable. Download the calendar file instead.')
								: reason === 'Event not found'
									? t('social', 'This event could not be found. It may have been removed.')
									: t('social', 'Could not add this event to your Nextcloud Calendar. You can download the calendar file instead.')
				showError(message)
			} finally {
				this.importingEvent = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.post-event {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 8px 16px;
	margin-block: 0 10px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.post-event__facts {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 6px 14px;
	min-width: 0;
}

.post-event__title {
	flex-basis: 100%;
	font-size: 14px;
}

.post-event__fact,
.post-event__calendar {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
}

.post-event__fact {
	color: var(--color-text-maxcontrast);
}

.post-event__calendar {
	color: var(--color-primary-element);
	font-weight: 600;
	white-space: nowrap;

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}
</style>
