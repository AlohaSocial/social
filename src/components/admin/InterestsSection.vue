<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'For you')"
		:description="t('social', 'A feed of posts carrying the hashtags each person reads most, learned from how they read here. What is learned stays on this server and is only ever shown to the person it is about — not to other users, and not to administrators.')">
		<div class="interests-admin">
			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('social', 'Enable For you') }}
			</NcCheckboxRadioSwitch>
			<p class="interests-admin__hint">
				{{ t('social', 'Off hides the feed and the settings, and nothing is learned. What was learned is kept, so turning it back on brings everybody\'s interests back.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.learningDefault" type="switch" :disabled="!form.enabled">
				{{ t('social', 'Learn from browsing unless a user turns it off') }}
			</NcCheckboxRadioSwitch>
			<p class="interests-admin__hint">
				{{ t('social', 'The GDPR-relevant choice: off makes learning opt-in, and each person is invited to turn it on. A user\'s own choice always wins.') }}
			</p>

			<div class="interests-admin__numbers">
				<NcTextField
					v-for="field in FIELDS"
					:key="field.key"
					v-model="form[field.key]"
					class="interests-admin__number"
					type="number"
					:min="String(field.min)"
					:max="String(field.max)"
					:step="field.integer ? '1' : '0.1'"
					:disabled="!form.enabled"
					:label="field.label()"
					:error="!valid(field)"
					:helperText="valid(field) ? field.hint() : rangeText(field)" />
			</div>

			<NcButton variant="primary" :disabled="saving || !allValid" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, interestsUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * The four tuning numbers, with the ranges the server holds them to. Checked
 * here as well so a value the server would refuse is said to be wrong next to
 * the field, before anything is sent.
 */
const FIELDS = [
	{
		key: 'halfLife',
		min: 7,
		max: 180,
		integer: true,
		fallback: 30,
		label: () => t('social', 'Half-life (days)'),
		hint: () => t('social', 'How fast an interest fades when it is no longer read. After this many days a score is worth half.'),
	},
	{
		key: 'threshold',
		min: 0.5,
		max: 20,
		integer: false,
		fallback: 3,
		label: () => t('social', 'Listing threshold'),
		hint: () => t('social', 'The score a learned hashtag needs before it counts as an interest. Lower learns faster and guesses more.'),
	},
	{
		key: 'cap',
		min: 5,
		max: 100,
		integer: true,
		fallback: 30,
		label: () => t('social', 'Interests per person'),
		hint: () => t('social', 'The most hashtags one person\'s list holds.'),
	},
	{
		key: 'window',
		min: 1,
		max: 30,
		integer: true,
		fallback: 7,
		label: () => t('social', 'Feed window (days)'),
		hint: () => t('social', 'How far back the feed looks for posts. Longer finds more for rare interests and costs more to rank.'),
	},
]

/**
 * @param {object} settings `adminSettings.interests`, or what a save answered
 * @return {object} the form's values; the numbers as the strings a field holds
 */
function formOf(settings) {
	return {
		enabled: settings?.enabled !== false,
		learningDefault: settings?.learningDefault !== false,
		...Object.fromEntries(FIELDS.map((field) => [field.key, String(settings?.[field.key] ?? field.fallback)])),
	}
}

/**
 * Administration → Social → For you.
 *
 * One save for the card, like the Sections card: the endpoint takes all six
 * values or refuses them all, and six fields that each saved on their own
 * would leave the card half applied when one of them is out of range.
 */
export default {
	name: 'InterestsSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSettingsSection,
		NcTextField,
	},

	props: {
		/** `adminSettings.interests` */
		settings: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			FIELDS,
			form: formOf(this.settings),
			saving: false,
		}
	},

	computed: {
		/** @return {boolean} every number is one the server would take */
		allValid() {
			return FIELDS.every((field) => this.valid(field))
		},
	},

	methods: {
		t,

		/**
		 * @param {object} field one of FIELDS
		 * @return {number} what its box holds, as a number (NaN when it holds none)
		 */
		numberOf(field) {
			const raw = String(this.form[field.key] ?? '').trim().replace(',', '.')

			return raw === '' ? NaN : Number(raw)
		},

		/**
		 * @param {object} field one of FIELDS
		 * @return {boolean} whether its box holds a value in range
		 */
		valid(field) {
			const value = this.numberOf(field)
			if (!Number.isFinite(value) || value < field.min || value > field.max) {
				return false
			}

			return !field.integer || Number.isInteger(value)
		},

		/**
		 * @param {object} field one of FIELDS
		 * @return {string} what the field takes, said when it holds something else
		 */
		rangeText(field) {
			return field.integer
				? t('social', 'A whole number from {min} to {max}', { min: field.min, max: field.max })
				: t('social', 'A number from {min} to {max}', { min: field.min, max: field.max })
		},

		/**
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.allValid) {
				return
			}

			this.saving = true
			try {
				const { data } = await axios.post(interestsUrl(), {
					enabled: this.form.enabled,
					learningDefault: this.form.learningDefault,
					...Object.fromEntries(FIELDS.map((field) => [field.key, this.numberOf(field)])),
				})
				if (data && typeof data === 'object') {
					this.form = formOf(data)
				}
				showSuccess(t('social', 'Saved'))
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not save the For you settings')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.interests-admin {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-start;

	&__hint {
		color: var(--color-text-maxcontrast);
		max-width: 62ch;
		margin-block: 0 calc(var(--default-grid-baseline) * 2);
	}

	&__numbers {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
		gap: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
		inline-size: 100%;
		margin-block-end: calc(var(--default-grid-baseline) * 2);
	}

	&__number {
		min-width: 0;
	}
}
</style>
