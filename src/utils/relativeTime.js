/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * The two date formats this app shows, from the platform rather than from a
 * date library.
 *
 * `moment` was pulled into an entrypoint to provide `fromNow()` and one
 * `LLL` format — around 600 kB of module graph for five calls. `Intl` does
 * both, is localised by the browser, and costs nothing to ship.
 */

/** the steps a relative time is rounded to, longest first */
/**
 * The units `Intl.RelativeTimeFormat` is given, largest first, with how many
 * seconds each one is. Typed as tuples so the unit stays a unit: inferred, both
 * halves would be `string | number` and the arithmetic below would be untyped.
 *
 * @type {Array<[Intl.RelativeTimeFormatUnit, number]>}
 */
const STEPS = [
	['year', 365 * 24 * 3600],
	['month', 30 * 24 * 3600],
	['week', 7 * 24 * 3600],
	['day', 24 * 3600],
	['hour', 3600],
	['minute', 60],
	['second', 1],
]

/** @return {string} the locale to format in, falling back to the browser's */
function locale() {
	try {
		return getCanonicalLocale()
	} catch {
		return undefined
	}
}

/**
 * How long ago something happened, in words: "5 minutes ago", "last week".
 *
 * @param {string|number|Date} date when it happened
 * @param {Date} [now] the moment to measure from, for tests
 * @return {string} a localised relative time, or '' for a date that cannot be read
 */
export function fromNow(date, now = new Date()) {
	const then = new Date(date)
	if (Number.isNaN(then.getTime())) {
		return ''
	}

	const seconds = Math.round((then.getTime() - now.getTime()) / 1000)
	const magnitude = Math.abs(seconds)

	const [unit, size] = STEPS.find(([, step]) => magnitude >= step) ?? ['second', 1]
	const formatter = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' })

	return formatter.format(Math.round(seconds / size), unit)
}

/**
 * Up to these, a post's age is a number and a unit; older, it is its date.
 * Smallest first: the first unit the age counts fewer than `below` of is it.
 *
 * @type {Array<{unit: string, size: number, below: number}>}
 */
const SHORT_STEPS = [
	{ unit: 'minute', size: 60, below: 60 },
	{ unit: 'hour', size: 3600, below: 24 },
	{ unit: 'day', size: 24 * 3600, below: 7 },
	{ unit: 'week', size: 7 * 24 * 3600, below: 5 },
]

/**
 * How old a post is, as briefly as it can be said: "now", "5m", "3h", "2d",
 * "2w", then the date ("Sep 3", with the year once it is not this one).
 *
 * The units are the locale's own narrow forms (`Intl.NumberFormat` with
 * `unitDisplay: 'narrow'`), so a German reader sees "3 Std." where an
 * English one sees "3h", and no "ago": beside a name it is understood.
 *
 * @param {string|number|Date} date when the post was written
 * @param {Date} now the moment it is read
 * @return {string} the age, or '' for a date that cannot be read
 */
export function shortAgo(date, now = new Date()) {
	const then = new Date(date)
	if (Number.isNaN(then.getTime())) {
		return ''
	}

	const seconds = Math.max(0, Math.round((now.getTime() - then.getTime()) / 1000))
	if (seconds < 60) {
		return new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' }).format(0, 'second')
	}

	for (const { unit, size, below } of SHORT_STEPS) {
		const count = Math.floor(seconds / size)
		if (count < below) {
			return new Intl.NumberFormat(locale(), { style: 'unit', unit, unitDisplay: 'narrow' }).format(count)
		}
	}

	return new Intl.DateTimeFormat(locale(), {
		month: 'short',
		day: 'numeric',
		...(then.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }),
	}).format(then)
}

/**
 * The full date and time, as `moment`'s `LLL` showed it: a readable date with
 * the time, in the viewer's locale.
 *
 * @param {string|number|Date} date the date to write out
 * @return {string} a localised date and time, or '' for a date that cannot be read
 */
export function fullDateTime(date) {
	const value = new Date(date)
	if (Number.isNaN(value.getTime())) {
		return ''
	}

	return new Intl.DateTimeFormat(locale(), {
		dateStyle: 'long',
		timeStyle: 'short',
	}).format(value)
}

/**
 * The date alone, without a time of day.
 *
 * For a bound that is a whole day rather than a moment: an announcement whose
 * window was given as whole days ends at midnight, and writing that out as
 * "22 September 2026 at 00:00" tells a reader about an implementation detail
 * of the window rather than about the day it runs to.
 *
 * @param {string|number|Date} date the date to write out
 * @return {string} a localised date, or '' for a date that cannot be read
 */
export function fullDate(date) {
	const value = new Date(date)
	if (Number.isNaN(value.getTime())) {
		return ''
	}

	return new Intl.DateTimeFormat(locale(), { dateStyle: 'long' }).format(value)
}
