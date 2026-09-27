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
import { isTracking } from '../services/interests.js'
import { contextFor, signalNow } from '../services/interestTracker.js'

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

		/**
		 * The post the mute was asked for from, when it was: muting somebody
		 * over a post says something about its hashtags, which My interests
		 * is told. Null from a profile, which is about the person.
		 *
		 */
		status: {
			/** @type {import('vue').PropType<import('../types/Mastodon.js').Status|null>} */
			type: Object,
			default: null,
		},

		/** the timeline that post was shown in, for the signal's context */
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
		// somebody else
		open(open) {
			if (open) {
				this.notifications = true
				this.duration = '0'
			}
		},
	},

	methods: {
		t,

		/** Tells My interests, when the mute came from a post and learning is on. */
		reportToInterests() {
			if (this.status === null || !isTracking(useSettingsStore().getServerData.interests)) {
				return
			}

			// a profile or a list is not one of the places signals come
			// from; the post was still read somewhere, and home is where
			// most reading happens
			signalNow(this.status, 'mute', contextFor(this.timelineType) ?? 'home')
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
