/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import mitt from 'mitt'
export default mitt()

/**
 * The reader's lists have changed — one was made, renamed or deleted.
 *
 * The sidebar draws them from a fetch of its own, once per page, so the
 * settings page says so here rather than reaching into it. The name lives with
 * the bus because two unrelated components have to agree on it.
 */
export const LISTS_CHANGED = 'social:lists-changed'

/**
 * Somebody pressed "add a reaction" on a post.
 *
 * The payload is `{ react }`, where `react` takes the chosen emoji. The picker
 * is mounted once for the page rather than once per card, and the card keeps
 * the request: the bus carries the callback so neither has to know the other.
 *
 * It goes through the bus rather than the card owning a picker because
 * `@nextcloud/vue` must not be imported anywhere under `TimelinePost` — that
 * component is in both the app's entry and the dashboard's, and a framework
 * import beneath it moves the shared l10n chunk out of `social-framework` and
 * copies it into each entry. See `tests/js/bundles.test.js`, which pins the
 * sizes this would have broken.
 */
export const REACTION_PICK = 'social:reaction-pick'

/**
 * The reader pressed "Mark all as read" on the Activities page.
 *
 * The payload is the id the marker was moved to. The page owns that button
 * and the list below it owns the line between what is new and what was
 * already there -- frozen for the length of the visit, on purpose -- so the
 * one has to tell the other. Without this the "New" heading and the pills
 * would stay up over activities the badge has stopped counting.
 */
export const NOTIFICATIONS_READ = 'social:notifications-read'

/**
 * The server said a timeline has something new.
 *
 * notify_push is subscribed to once for the page — see `timelinePush.js`,
 * and `listen()` cannot be undone — and every timeline list on screen hears
 * about it here, so a list that is gone stops listening with its component.
 */
export const TIMELINE_PUSHED = 'social:timeline-pushed'
