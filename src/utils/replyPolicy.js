/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/**
 * Who may reply to one of one's own posts, as the server's `reply_policy`
 * says it: on Bluesky the post's threadgate, elsewhere `canReply`.
 *
 * @return {Array<{value: string, label: string}>} the choices, everybody first
 */
export function replyPolicies() {
	return [
		{ value: 'everyone', label: t('social', 'Anybody') },
		{ value: 'followers', label: t('social', 'People who follow me') },
		{ value: 'following', label: t('social', 'People I follow') },
		{ value: 'mentioned', label: t('social', 'Only people I mention') },
		{ value: 'nobody', label: t('social', 'Nobody') },
	]
}
