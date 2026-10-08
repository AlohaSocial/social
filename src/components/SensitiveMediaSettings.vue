<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="sensitive-media-settings">
		<!-- PeerTube's three NSFW policies, which Mastodon's own reading
		     preference already has names for -->
		<NcSelect
			v-model="choice"
			class="sensitive-media-settings__select"
			:inputLabel="t('social', 'Media marked sensitive')"
			:options="options"
			:clearable="false"
			:searchable="false"
			:disabled="saving"
			label="text"
			@update:modelValue="save" />
		<p class="sensitive-media-settings__hint">
			{{ t('social', 'A content warning is a different thing: it is the author saying something about the whole post in their own words, and it always covers its post.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { translate as t } from '@nextcloud/l10n'
import { useServerData } from '../composables/useServerData.js'
import { showError, showSuccess } from '../services/toast.js'

/**
 * How pictures and videos their author marked sensitive are shown. A reading
 * preference, so it lives with the other reading settings rather than with
 * the account's privacy.
 */
export default {
	name: 'SensitiveMediaSettings',

	components: {
		NcSelect,
	},

	data() {
		const serverData = useServerData().serverData.value

		return {
			/**
			 * What this account chose, or '' for "follow the instance".
			 *
			 * Out of the page rather than a request: the effective policy is
			 * already there because the timeline needs it before it draws, and
			 * the choice behind it travels with it.
			 */
			sensitive: serverData?.nsfwChoice ?? '',
			saving: false,
		}
	},

	computed: {
		/**
		 * The three policies, plus the fourth thing that is not one of them:
		 * following the instance, which is different from choosing whatever
		 * the instance happens to do today.
		 *
		 * @return {Array<{id: string, text: string}>}
		 */
		options() {
			return [
				{ id: '', text: t('social', 'Whatever this server does') },
				{ id: 'show_all', text: t('social', 'Show it like anything else') },
				{ id: 'default', text: t('social', 'Cover it, one press away') },
				{ id: 'hide_all', text: t('social', 'Do not show it; I will open the post') },
			]
		},

		/** @return {{id: string, text: string}} */
		choice: {
			get() {
				return this.options.find((option) => option.id === this.sensitive) ?? this.options[0]
			},

			set(option) {
				this.sensitive = option?.id ?? ''
			},
		},
	},

	methods: {
		t,

		/**
		 * Writes the preference.
		 *
		 * It takes effect on the next page rather than at once: the policy is
		 * read out of the page's own initial state, because it decides what
		 * the first screenful looks like and a timeline that uncovered itself
		 * a moment after drawing would be worse than either policy.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				await axios.put(generateUrl('apps/social/api/v1/preferences'), {
					expandMedia: this.sensitive,
				})
				showSuccess(t('social', 'Saved. It applies the next time this page loads.'))
			} catch {
				showError(t('social', 'Could not save that setting'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.sensitive-media-settings__select {
	max-width: 420px;
}

.sensitive-media-settings__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
