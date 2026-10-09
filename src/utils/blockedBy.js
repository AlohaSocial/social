/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The reason the server gives for a reply, quote or follow it refused
 * because the account it reaches has blocked the person
 * (`BlockedByService::REFUSAL`).
 */
export const BLOCKED_BY = 'This account has blocked you'

/**
 * @param {unknown} error what a failed request threw
 * @return {boolean} whether it was refused because the account it reaches
 * has blocked the person
 */
export function isBlockedBy(error) {
	const data = /** @type {{response?: {data?: {blocked_by?: unknown, error?: unknown}}}} */ (error)?.response?.data

	return data?.blocked_by === true || data?.error === BLOCKED_BY
}
