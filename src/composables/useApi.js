/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Instance-relative API client for the native AT Protocol settings. */
export function useApi() {
	return {
		get: (path, config) => axios.get(generateUrl(`/apps/social${path}`), config),
		post: (path, data) => axios.post(generateUrl(`/apps/social${path}`), data),
		put: (path, data) => axios.put(generateUrl(`/apps/social${path}`), data),
		delete: (path) => axios.delete(generateUrl(`/apps/social${path}`)),
	}
}
