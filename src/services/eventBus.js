/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import mitt from 'mitt'
export default mitt()

/**
 * The reader's lists have changed: one was made, renamed or deleted. The
 * sidebar fetches them itself, so the settings page announces it here.
 */
export const LISTS_CHANGED = 'social:lists-changed'

/** The Bluesky feeds and lists the reader keeps have changed, for the same reason. */
export const BLUESKY_FEEDS_CHANGED = 'social:bluesky-feeds-changed'

/**
 * Somebody pressed "add a reaction" on a post; the payload is `{ react }`,
 * which takes the chosen emoji. The page mounts one picker, and the card
 * cannot own one: `@nextcloud/vue` must not be imported under `TimelinePost`,
 * which both the app and the dashboard bundle (see `tests/js/bundles.test.js`).
 */
export const REACTION_PICK = 'social:reaction-pick'

/**
 * Asks for New short on its 24-hour lifetime: the camera in the post composer
 * sends it, and the Shorts bar, which owns the dialog and adds the new short to
 * the reader's own place, opens it.
 */
export const SHORT_COMPOSE = 'social:short-compose'

/**
 * The reader pressed "Mark all as read" on the Activities page; the payload
 * is the id the marker was moved to. The list below the button owns the line
 * between new and old, so it has to be told.
 */
export const NOTIFICATIONS_READ = 'social:notifications-read'

/**
 * The server said a timeline has something new; the payload is the kinds
 * that arrived (`PUSH_KINDS`). notify_push is subscribed to once for the page
 * (`timelinePush.js`), and every timeline list on screen hears it here.
 */
export const TIMELINE_PUSHED = 'social:timeline-pushed'
