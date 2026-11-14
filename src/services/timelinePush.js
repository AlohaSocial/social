/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { listen } from '@nextcloud/notify_push'
import eventBus, { TIMELINE_PUSHED } from './eventBus.js'

/**
 * `listen()` has no counterpart: what it registers stays registered for the
 * life of the page. A component that called it per mount therefore left a
 * closure holding itself behind on every unmount — and every open thread
 * mounts a timeline list of its own — so after a few navigations one pushed
 * event ran the same timeline request several times over.
 *
 * So it is called once here, for the page, and the event is handed on over the
 * bus, which components can leave.
 *
 * The server pushes once per post that reaches the account, and each
 * subscriber answers a push with requests of its own.
 * Someone who follows a few hundred active accounts was pushed every few
 * seconds, in every open tab, hidden ones too. So the pushes are gathered
 * here before anybody hears of them: a run of them is handed on once it has
 * been quiet for `PUSH_SETTLE_MS` (and no later than `PUSH_MAX_WAIT_MS` after
 * the first, so a steady stream still gets through); a tab nobody is looking
 * at hands on nothing and catches up once when it is looked at again; and a
 * subscriber whose last answer is still on its way is not asked again until
 * it has arrived, and then once for everything pushed meanwhile.
 *
 * Each push says what arrived (`PushService`): a post for the home timeline,
 * a direct message, a notification. A subscriber names the kinds that can
 * change what it shows and hears of nothing else — the direct messages are
 * not read again because a followed account posted. A push that names no kind,
 * from a server or a notify_push older than that, is every kind.
 */

/** A post for the home timeline. */
export const PUSH_HOME = 'home'

/** A direct message, sent or received. */
export const PUSH_DIRECT = 'direct'

/** A notification, whether it is listed or held for a decision. */
export const PUSH_NOTIFICATION = 'notification'

/** Every kind there is: what a subscriber hears of unless it says otherwise. */
export const PUSH_KINDS = [PUSH_HOME, PUSH_DIRECT, PUSH_NOTIFICATION]

/** How long the pushes have to be quiet before they are handed on, in milliseconds. */
export const PUSH_SETTLE_MS = 3000

/** The longest a run of pushes is held back, from the first of them, in milliseconds. */
export const PUSH_MAX_WAIT_MS = 10000

/** Whether the server has notify_push, or null before it has been asked. */
let available = null

/** The timer that hands the gathered pushes on, while one is waiting. */
let timer = null

/** When the first push of the run being gathered came, 0 for none. */
let firstAt = 0

/** The kinds pushed in the run being gathered. */
const gathered = new Set()

/** The kinds pushed while the tab was hidden. */
const missed = new Set()

/** @type {Map<(kinds: string[]) => unknown, (kinds?: string[]) => void>} each subscriber => what the bus runs for it */
const subscribers = new Map()

/**
 * @param {unknown} body what the server sent with the push
 * @return {string[]} the kinds it names; every kind when it names none
 */
function kindsOf(body) {
	const kinds = /** @type {{kinds?: unknown}|null} */ (body)?.kinds

	return Array.isArray(kinds) ? kinds.map(String) : PUSH_KINDS
}

/**
 * A push from the server.
 *
 * @param {string} _event the event's name
 * @param {unknown} body what came with it
 */
function pushed(_event, body) {
	gather(kindsOf(body))
}

/** @param {Iterable<string>} kinds what arrived */
function gather(kinds) {
	const into = document.visibilityState === 'hidden' ? missed : gathered
	for (const kind of kinds) {
		into.add(kind)
	}
	if (into === missed) {
		return
	}

	const now = Date.now()
	firstAt = firstAt || now
	clearTimeout(timer)
	timer = setTimeout(handOn, Math.min(PUSH_SETTLE_MS, firstAt + PUSH_MAX_WAIT_MS - now))
}

/** Tells the subscribers, once for the run that was gathered. */
function handOn() {
	timer = null
	firstAt = 0
	const kinds = [...gathered]
	gathered.clear()
	if (document.visibilityState === 'hidden') {
		kinds.forEach((kind) => missed.add(kind))
		return
	}

	eventBus.emit(TIMELINE_PUSHED, kinds)
}

/** Catches up once, on the way back to a tab that was pushed to while hidden. */
function catchUp() {
	if (document.visibilityState === 'visible' && missed.size > 0) {
		const kinds = [...missed]
		missed.clear()
		gather(kinds)
	}
}

/**
 * The subscriber as the bus runs it: never twice at once, and only for the
 * kinds it named. A push that comes while its answer is on the way runs it
 * once more when that answer is in, for every kind pushed meanwhile.
 *
 * @param {(kinds: string[]) => (Promise<unknown>|unknown)} handler the subscriber
 * @param {string[]} wanted the kinds it hears of
 * @return {(kinds?: string[]) => void} what the bus runs
 */
function oneAtATime(handler, wanted) {
	let running = false
	const again = new Set()

	const run = (kinds = PUSH_KINDS) => {
		const relevant = kinds.filter((kind) => wanted.includes(kind))
		if (relevant.length === 0) {
			return
		}
		if (running) {
			relevant.forEach((kind) => again.add(kind))
			return
		}

		running = true
		const done = () => {
			running = false
			if (again.size > 0) {
				const next = [...again]
				again.clear()
				run(next)
			}
		}
		try {
			Promise.resolve(handler(relevant)).then(done, done)
		} catch {
			done()
		}
	}

	return run
}

/**
 * Subscribes to the server's word that something has arrived.
 *
 * @param {(kinds: string[]) => (Promise<unknown>|unknown)} handler what to
 *        run, told which of its kinds arrived — a promise it returns is waited
 *        for before it is run again; the same function unsubscribes
 * @param {string[]} kinds what it is to hear of: `PUSH_HOME`, `PUSH_DIRECT`,
 *        `PUSH_NOTIFICATION`; every kind when left out
 * @return {boolean} whether push is available — if not, the caller has to poll
 */
export function onTimelinePush(handler, kinds = PUSH_KINDS) {
	if (available === null) {
		available = listen('social_timeline', pushed)
		document.addEventListener('visibilitychange', catchUp)
	}

	if (!subscribers.has(handler)) {
		const run = oneAtATime(handler, kinds)
		subscribers.set(handler, run)
		eventBus.on(TIMELINE_PUSHED, run)
	}

	return available
}

/**
 * @param {(kinds: string[]) => (Promise<unknown>|unknown)} handler the function that was subscribed
 */
export function offTimelinePush(handler) {
	const run = subscribers.get(handler)
	if (run !== undefined) {
		eventBus.off(TIMELINE_PUSHED, run)
		subscribers.delete(handler)
	}
}
