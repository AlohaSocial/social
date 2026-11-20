/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The rules a publication time is held to, shared by the composer's clock and
 * the Scheduled posts list so the two agree with the server.
 */

/**
 * How far ahead a scheduled post has to be, in milliseconds: the server's
 * `ScheduledStatusService::MIN_LEAD_TIME`. Checked here too so the refusal
 * comes while the time can still be moved.
 */
export const MIN_SCHEDULE_LEAD = 5 * 60 * 1000

/** the step the picker offers, and what a proposed time is rounded to */
export const SCHEDULE_STEP = 5 * 60 * 1000

/** how far ahead the clock proposes when it is first pressed */
export const SCHEDULE_PROPOSAL = 60 * 60 * 1000

/**
 * The soonest the picker offers, which is also what the server accepts. A
 * Date rather than a number, because that is what the picker's `min` takes.
 *
 * @param {number} [now] the current time, in milliseconds
 * @return {Date}
 */
export function earliestSchedule(now = Date.now()) {
	return new Date(now + MIN_SCHEDULE_LEAD)
}

/**
 * Whether a picked time is one the server would refuse: less than five
 * minutes out, or not a time at all.
 *
 * @param {?Date} when what the picker holds
 * @param {number} [now] the current time, in milliseconds
 * @return {boolean}
 */
export function isTooSoon(when, now = Date.now()) {
	return !(when instanceof Date)
		|| Number.isNaN(when.getTime())
		|| when.getTime() < now + MIN_SCHEDULE_LEAD
}

/**
 * The time the clock proposes: an hour out, rounded up to the picker's step.
 *
 * @param {number} [now] the current time, in milliseconds
 * @return {Date}
 */
export function proposedSchedule(now = Date.now()) {
	return new Date(Math.ceil((now + SCHEDULE_PROPOSAL) / SCHEDULE_STEP) * SCHEDULE_STEP)
}

let datePicker = null

/**
 * The date picker's module, fetched at most once: it brings a date library
 * and its locales with it, and most posts go out now.
 *
 * @return {Promise<object>} the module
 */
export const datePickerModule = () => (datePicker ??= import('@nextcloud/vue/components/NcDateTimePicker'))
