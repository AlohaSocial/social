/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The routes the administration page reads and writes.
 *
 * They are the same routes the page used before it was a Vue application, with
 * the same bodies: everything below is where a URL is spelled, so that a
 * section cannot drift from the endpoint it is about.
 */

/**
 * A moderation route.
 *
 * @param {string} [path] appended below /apps/social/moderation
 * @return {string} the whole URL
 */
export function moderationUrl(path = '') {
	return generateUrl('/apps/social/moderation' + path)
}

/**
 * An administration route of the announcements.
 *
 * @param {string} [path] appended below /apps/social/admin/announcements
 * @return {string} the whole URL
 */
export function announcementsUrl(path = '') {
	return generateUrl('/apps/social/admin/announcements' + path)
}

/**
 * The Server card's one endpoint.
 *
 * @return {string} the whole URL
 */
export function serverUrl() {
	return generateUrl('/apps/social/admin/server')
}

/**
 * The About this server card's one endpoint.
 *
 * @return {string} the whole URL
 */
export function aboutUrl() {
	return generateUrl('/apps/social/admin/about')
}

/**
 * Nextcloud's Theming settings, which hold the name and the two legal links.
 *
 * @return {string} the whole URL
 */
export function themingUrl() {
	return generateUrl('/settings/admin/theming')
}

/**
 * The Sections card's one endpoint.
 *
 * @return {string} the whole URL
 */
export function sectionsUrl() {
	return generateUrl('/apps/social/admin/sections')
}

/**
 * The For you card's one endpoint.
 *
 * @return {string} the whole URL
 */
export function interestsUrl() {
	return generateUrl('/apps/social/admin/interests')
}

/** @return {string} the instance-wide For You recommendation kill-switch URL */
export function recommendationsUrl() {
	return generateUrl('/apps/social/admin/recommendations')
}

/** @return {string} the whole URL */
export function recommendationDiscoveryUrl() {
	return generateUrl('/apps/social/admin/recommendations/discovery')
}

/**
 * The relays this instance subscribes to.
 *
 * @param {string} [path] appended below /apps/social/admin/relays
 * @return {string} the whole URL
 */
export function relaysUrl(path = '') {
	return generateUrl('/apps/social/admin/relays' + path)
}

/**
 * The Bluesky card: its settings, its checks, and the relays it tells.
 *
 * @param {string} [path] appended below /apps/social/admin/bluesky
 * @return {string} the whole URL
 */
export function blueskyUrl(path = '') {
	return generateUrl('/apps/social/admin/bluesky' + path)
}

/**
 * The administrator's choice of the account this instance verifies others in
 * the name of.
 *
 * @return {string} the whole URL
 */
export function verifierUrl() {
	return generateUrl('/apps/social/admin/verifications/verifier')
}
