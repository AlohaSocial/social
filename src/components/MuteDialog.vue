<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:open="open"
		:name="t('social', 'Mute {account}?', { account: handle })"
		:buttons="buttons"
		class="mute-dialog"
		@update:open="$emit('update:open', $event)">
		<p class="mute-dialog__hint">
			{{ t('social', 'Their posts and boosts leave your timelines. They are not told, and they can still see and follow you.') }}
		</p>

		<NcCheckboxRadioSwitch v-model="notifications" type="switch" class="mute-dialog__notifications">
			{{ t('social', 'Hide their notifications too') }}
		</NcCheckboxRadioSwitch>

		<fieldset class="mute-dialog__duration">
			<legend>{{ t('social', 'For how long') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="option in durations"
				:key="option.seconds"
				v-model="duration"
				type="radio"
				name="mute-duration"
				:value="String(option.seconds)">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>
	</NcDialog>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { translate as t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { isTracking, sendSignals } from '../services/interests.js'
import { contextFor } from '../services/interestTracker.js'
import logger from '../services/logger.js'
import { focusedElement, returnFocusTo } from '../utils/focus.js'

/**
 * How long each choice lasts, in seconds; 0 is Mastodon's "until I say
 * otherwise", and what the server reads an absent duration as.
 */
export const MUTE_DURATIONS = [
	{ seconds: 0, label: t('social', 'Until I unmute them') },
	{ seconds: 3600, label: t('social', 'For one hour') },
	{ seconds: 86400, label: t('social', 'For one day') },
	{ seconds: 604800, label: t('social', 'For seven days') },
	{ seconds: 2592000, label: t('social', 'For thirty days') },
]

/**
 * The two questions a mute has — their notifications too, and for how long —
 * asked once, in one place, for the profile menu and the post menu alike.
 *
 * The dialog does the muting itself rather than handing the answers back,
 * because both callers would do exactly the same with them: the store action
 * takes the id and the two options, and the relationship it answers with is
 * what every menu reads.
 */
export default {
	name: 'MuteDialog',

	components: {
		NcCheckboxRadioSwitch,
		NcDialog,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		account: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Account>} */ (Object),
			required: true,
		},

		/** The post the mute was asked for from, when there was one. */
		status: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status|null>} */ (Object),
			default: null,
		},

		/** Timeline where the post was shown. */
		timelineType: {
			type: String,
			default: '',
		},
	},

	emits: ['update:open', 'muted'],

	data() {
		return {
			durations: MUTE_DURATIONS,
			/** the default on the server, and the choice most people mean */
			notifications: true,
			/** a string, because that is what a radio's value is */
			duration: '0',
			muting: false,
			/** what had the focus when the dialog opened, to give it back to */
			opener: null,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {string} the account as it is written, with its @ */
		handle() {
			return '@' + (this.account.acct ?? this.account.username ?? '')
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		buttons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => this.$emit('update:open', false),
				},
				{
					label: t('social', 'Mute'),
					variant: 'error',
					disabled: this.muting,
					callback: () => this.mute(),
				},
			]
		},
	},

	watch: {
		// a dialog opened again asks afresh: the last answer was about
		// somebody else. Closed, it gives the focus back to the menu button
		// it was opened from: its own trap would return it to the menu item,
		// which the menu has taken away by then
		open(open) {
			if (open) {
				this.notifications = true
				this.duration = '0'
				this.opener = focusedElement()
			} else {
				this.$nextTick(() => returnFocusTo(this.opener))
			}
		},
	},

	created() {
		if (this.open) {
			this.opener = focusedElement()
		}
	},

	methods: {
		t,

		/** Reports a successful post-menu mute to the legacy hashtag profile. */
		reportToInterests() {
			if (this.status === null || !isTracking(useSettingsStore().getServerData.interests)) {
				return
			}

			sendSignals([{
				status_id: this.status.id,
				kind: 'mute',
				context: contextFor(this.timelineType) ?? 'home',
			}]).catch((error) => logger.debug('Could not report a mute signal', { error }))
		},

		async mute() {
			if (this.muting) {
				return
			}
			this.muting = true
			try {
				const relationship = await this.accountStore.muteAccount({
					id: this.account.id,
					notifications: this.notifications,
					duration: Number(this.duration),
				})
				// the store said what went wrong; the dialog stays for another try
				if (relationship?.id) {
					this.reportToInterests()
					this.$emit('muted', relationship)
					this.$emit('update:open', false)
				}
			} finally {
				this.muting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.mute-dialog {
	&__hint {
		margin-bottom: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__notifications {
		margin-bottom: 8px;
	}

	&__duration {
		border: 0;
		padding: 0;
		margin: 0;

		legend {
			font-weight: bold;
			margin-bottom: 4px;
		}
	}
}
</style>
