/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'
import { getLoggerBuilder } from '@nextcloud/logger'

/**
 * A logger that names the app, and the user when there is one.
 *
 * @param {?import('@nextcloud/auth').NextcloudUser} user who is signed in, null on a public page
 * @return {import('@nextcloud/logger').ILogger}
 */
function getLogger(user) {
	if (user === null) {
		return getLoggerBuilder()
			.setApp('social')
			.build()
	}
	return getLoggerBuilder()
		.setApp('social')
		.setUid(user.uid)
		.build()
}

export default getLogger(getCurrentUser())
