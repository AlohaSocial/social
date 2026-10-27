<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section
		class="post-event"
		:aria-label="t('social', 'Event details')">
		<strong v-if="event.name" class="post-event__title">{{ event.name }}</strong>
		<div class="post-event__state">
			<span v-if="eventStateLabel">{{ eventStateLabel }}</span>
			<span v-if="updatedAt">{{ t('social', 'Event updated') }}</span>
		</div>
		<div class="post-event__facts">
			<span v-if="eventTimeLabel" class="post-event__fact">
				<CalendarMonthOutline :size="16" />
				{{ eventTimeLabel }} ({{ eventTimeZone }})
			</span>
			<span v-if="event.location" class="post-event__fact">
				<MapMarkerOutline :size="16" />
				{{ event.location }}
			</span>
		</div>
		<div class="post-event__actions">
			<NcButton
				v-if="hasStart && !isPublic"
				class="post-event__calendar"
				:disabled="importingEvent || eventImported"
				@click="importEventToCalendar">
				<template #icon>
					<CalendarMonthOutline :size="16" />
				</template>
				{{ importingEvent ? t('social', 'Adding…') : t('social', 'Add to Nextcloud Calendar') }}
			</NcButton>
			<a v-if="hasStart" class="post-event__calendar post-event__calendar--download" :href="eventCalendarUrl">
				<CalendarMonthOutline :size="16" />
				{{ t('social', 'Download calendar file') }}
			</a>
			<a
				v-if="safeParticipationUrl"
				class="post-event__calendar"
				:href="safeParticipationUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('social', 'Respond on the event website') }}
			</a>
			<a
				v-if="safeOriginalUrl"
				class="post-event__calendar"
				:href="safeOriginalUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('social', 'Open original') }}
			</a>
		</div>
		<p v-if="!isPublic && hasStart" class="post-event__hint">
			{{ t('social', 'Adding a calendar copy does not confirm attendance or automatically synchronize changes.') }}
		</p>
		<p v-if="calendarFeedback" class="post-event__feedback" role="status">
			{{ calendarFeedback }}
		</p>
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
import { useServerData } from '../composables/useServerData.js'
import { userLocale } from '../utils/locale.js'
import { onTick } from '../services/clock.js'

export default {
	name: 'TimelineEventCard',
	components: { CalendarMonthOutline, MapMarkerOutline, NcButton },
	props: {
		event: { type: Object, required: true },
		statusId: { type: [String, Number], required: true },
		isPublic: { type: Boolean, default: false },
		originalUrl: { type: String, default: '' },
		updatedAt: { type: String, default: null },
	},

	setup() {
		return { t, ...useServerData() }
	},

	data() {
		return {
			now: Date.now(),
			stopTicking: null,
			calendarFeedback: '',
			importingEvent: false,
			eventImported: false,
		}
	},

	computed: {
		eventTimeZone() {
			try {
				return new Intl.DateTimeFormat(userLocale(), { timeZone: this.serverData.timeZone || undefined }).resolvedOptions().timeZone
			} catch {
				return new Intl.DateTimeFormat().resolvedOptions().timeZone
			}
		},

		hasStart() {
			return Boolean(this.event.start_time) && Number.isFinite(Date.parse(this.event.start_time))
		},

		eventStateLabel() {
			if (this.event.status === 'cancelled') {
				return t('social', 'Cancelled')
			}
			if (this.event.status === 'tentative') {
				return t('social', 'Tentative')
			}
			if (!this.hasStart) {
				return ''
			}
			const start = Date.parse(this.event.start_time)
			const end = this.event.end_time ? Date.parse(this.event.end_time) : NaN
			if (start > this.now) {
				return t('social', 'Upcoming')
			}
			if (!Number.isFinite(end) || end <= start) {
				return t('social', 'Started')
			}
			return end > this.now ? t('social', 'Ongoing') : t('social', 'Past')
		},

		safeOriginalUrl() {
			try {
				const url = new URL(this.originalUrl)
				return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : ''
			} catch {
				return ''
			}
		},

		safeParticipationUrl() {
			try {
				const url = new URL(this.event.participation_url)
				return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : ''
			} catch {
				return ''
			}
		},

		eventTimeLabel() {
			const format = (value) => {
				if (value === null || value === undefined || value === '') {
					return ''
				}
				const date = new Date(value)
				return Number.isNaN(date.getTime())
					? ''
					: new Intl.DateTimeFormat(userLocale(), { dateStyle: 'medium', timeStyle: 'short', timeZone: this.eventTimeZone }).format(date)
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

	mounted() {
		this.stopTicking = onTick((now) => {
			this.now = now
		})
	},

	beforeUnmount() {
		this.stopTicking?.()
	},

	methods: {
		async importEventToCalendar() {
			if (!this.hasStart || this.isPublic || this.importingEvent || this.eventImported) {
				return
			}
			this.importingEvent = true
			this.calendarFeedback = ''
			try {
				const { data } = await axios.post(generateUrl(`apps/social/api/v1/statuses/${encodeURIComponent(this.statusId)}/calendar/import`))
				if (data?.imported !== true) {
					throw new Error('Calendar import was not confirmed')
				}
				this.eventImported = true
				this.calendarFeedback = data.already_imported
					? t('social', 'This event is already in your calendar')
					: t('social', 'Event added to {calendar}', { calendar: data.calendar || t('social', 'your Nextcloud Calendar') })
				showSuccess(this.calendarFeedback)
			} catch (error) {
				logger.error('Could not import the event into Nextcloud Calendar', { error })
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
				this.calendarFeedback = message
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

.post-event__state,
.post-event__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
}

.post-event__state,
.post-event__hint,
.post-event__feedback {
	flex-basis: 100%;
	margin: 0;
	font-size: 13px;
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
	color: var(--color-main-text);
	font-weight: 600;
	overflow-wrap: anywhere;
	text-decoration: underline;

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}
</style>
