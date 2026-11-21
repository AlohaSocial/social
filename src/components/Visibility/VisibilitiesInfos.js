/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @typedef {object} Visibility
 * @property {string} id - One of 'public', 'followers', 'direct', 'unlisted'
 * @property {string} text - Short label of the visibility
 * @property {string} description - Description of the visibility
 * @property {boolean} bluesky - Whether a post with it goes to Bluesky, where
 *   the account has an identity there: only a public one does, since Bluesky
 *   has no audience narrower than everybody
 */

/** @type {Visibility[]} */
const visibilities = [
	{
		id: 'public',
		text: t('social', 'Public'),
		description: t('social', 'Visible for all'),
		bluesky: true,
	},
	{
		id: 'unlisted',
		text: t('social', 'Unlisted'),
		description: t('social', 'Visible for all, but opted-out of discovery features'),
		bluesky: false,
	},
	{
		id: 'followers',
		text: t('social', 'Followers'),
		description: t('social', 'Visible to followers only'),
		bluesky: false,
	},
	{
		id: 'direct',
		text: t('social', 'Direct message'),
		description: t('social', 'Visible to mentioned users only'),
		bluesky: false,
	},
]

export default visibilities

/**
 * Whether anything in the app knows what to do with this id: a visibility
 * read back from localStorage may have been written by another version.
 *
 * @param {string} id the id to check
 * @return {boolean}
 */
export function isKnownVisibility(id) {
	return visibilities.some((visibility) => visibility.id === id)
}
