/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { userLocale } from './locale.js'

/**
 * Date formats built on `Intl`, localised by the browser, so no date library
 * has to ship.
 */

/**
 * The units `Intl.RelativeTimeFormat` is given, largest first, with their
 * length in seconds. Typed as tuples so the unit stays a unit.
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

/**
 * The formatters built so far, by locale and kind: building one is the
 * expensive part of `Intl`, and every post's age is re-read on each tick.
 *
 * @type {Map<string, unknown>}
 */
const formatters = new Map()

/**
 * @template T
 * @param {string} kind what the formatter is for, with the options that
 *   shape it, e.g. 'unit:hour'
 * @param {(locale: string|undefined) => T} build makes it for a locale
 * @return {T} the one formatter of that kind for the reader's locale
 */
function cached(kind, build) {
	const locale = userLocale()
	const key = `${locale ?? ''}|${kind}`
	if (!formatters.has(key)) {
		formatters.set(key, build(locale))
	}

	return /** @type {T} */ (formatters.get(key))
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

	return relativeFormat().format(Math.round(seconds / size), unit)
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
		return relativeFormat().format(0, 'second')
	}

	for (const { unit, size, below } of SHORT_STEPS) {
		const count = Math.floor(seconds / size)
		if (count < below) {
			return cached(`unit:${unit}`, (locale) => new Intl.NumberFormat(locale, { style: 'unit', unit, unitDisplay: 'narrow' })).format(count)
		}
	}

	const withYear = then.getFullYear() !== now.getFullYear()

	return cached(withYear ? 'day:year' : 'day', (locale) => new Intl.DateTimeFormat(locale, {
		month: 'short',
		day: 'numeric',
		...(withYear ? { year: 'numeric' } : {}),
	})).format(then)
}

/** @return {Intl.RelativeTimeFormat} the one that says "yesterday" and "now" */
function relativeFormat() {
	return cached('relative', (locale) => new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }))
}

/**
 * The full date and time in the viewer's locale.
 *
 * @param {string|number|Date} date the date to write out
 * @return {string} a localised date and time, or '' for a date that cannot be read
 */
export function fullDateTime(date) {
	const value = new Date(date)
	if (Number.isNaN(value.getTime())) {
		return ''
	}

	return cached('datetime', (locale) => new Intl.DateTimeFormat(locale, {
		dateStyle: 'long',
		timeStyle: 'short',
	})).format(value)
}

/**
 * The time of day alone: "9:05 AM", "09:05".
 *
 * @param {string|number|Date} date the moment to write out
 * @return {string} a localised time, or '' for a date that cannot be read
 */
export function timeOfDay(date) {
	const value = new Date(date)
	if (Number.isNaN(value.getTime())) {
		return ''
	}

	return cached('time', (locale) => new Intl.DateTimeFormat(locale, { timeStyle: 'short' })).format(value)
}

/**
 * The date alone, for a bound that is a whole day rather than a moment (an
 * announcement window given in days ends at midnight).
 *
 * @param {string|number|Date} date the date to write out
 * @param {string} [timeZone] the zone whose calendar day to name; the reader's own when omitted
 * @return {string} a localised date, or '' for a date that cannot be read
 */
export function fullDate(date, timeZone = undefined) {
	const value = new Date(date)
	if (Number.isNaN(value.getTime())) {
		return ''
	}

	return cached(`date:${timeZone ?? ''}`, (locale) => new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeZone })).format(value)
}
