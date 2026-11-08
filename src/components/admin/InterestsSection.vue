<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<div class="interests-admin">
			<section class="interests-admin__v4">
				<h4>{{ t('social', 'For You recommendations') }}</h4>
				<NcCheckboxRadioSwitch
					v-model="form.recommendationsEnabled"
					type="switch"
					:disabled="v4Saving"
					@update:modelValue="saveRecommendations">
					{{ t('social', 'Enable For You recommendations') }}
				</NcCheckboxRadioSwitch>
				<p class="interests-admin__hint">
					{{ t('social', 'This enables automatic ranking in the existing For You feed; it does not add a second feed. The ranker combines learned hashtag interests, post content, freshness, follows and discovery. Readers do not choose topics. Activity learning stays off until each reader separately enables it. Discovery uses bounded background jobs and eligible public posts; the chronological Following feed is unchanged.') }}
				</p>
				<div class="interests-admin__operations">
					<div v-for="control in operationalControls" :key="control.key">
						<NcCheckboxRadioSwitch
							:modelValue="form[control.key]"
							type="switch"
							:disabled="v4Saving"
							@update:modelValue="saveOperationalFlag(control.key, $event)">
							{{ control.label() }}
						</NcCheckboxRadioSwitch>
						<p class="interests-admin__hint">
							{{ control.hint() }}
						</p>
					</div>
				</div>
				<DiscoveryHealth />
			</section>
			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('social', 'Enable hashtag starting point') }}
			</NcCheckboxRadioSwitch>
			<p class="interests-admin__hint">
				{{ t('social', 'Keeps automatically learned hashtag interests available in For You and as its compatibility fallback. Readers do not choose topics; this is the same feed route, not a separate timeline.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.learningDefault" type="switch" :disabled="!form.enabled">
				{{ t('social', 'Learn hashtag interests for users who have not chosen') }}
			</NcCheckboxRadioSwitch>
			<p class="interests-admin__hint">
				{{ t('social', 'Off by default: hashtag learning is opt-in, and each user turns it on in their own For You settings. Turned on here, it also learns for every user who has not made a choice. Users can change their own choice at any time, and turning learning off keeps what was already learned.') }}
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
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { defineAsyncComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { interestsUrl, recommendationsUrl } from '../../services/adminApi.js'
import { errorOf } from '../../utils/serverError.js'
import { showError, showSuccess } from '../../services/toast.js'

const DiscoveryHealth = defineAsyncComponent(() => import('./DiscoveryHealth.vue'))

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

/** @type {Array<{key: 'remoteDiscoveryPaused'|'learningPaused'|'forceLegacyFeed', label: () => string, hint: () => string}>} */
const OPERATIONAL_CONTROLS = [
	{
		key: 'remoteDiscoveryPaused',
		label: () => t('social', 'Pause remote discovery'),
		hint: () => t('social', 'Pauses Fediverse and Bluesky discovery; cached posts remain available.'),
	},
	{
		key: 'learningPaused',
		label: () => t('social', 'Pause behavioral learning'),
		hint: () => t('social', 'Stops instance-wide behavioral learning, including for opted-in users.'),
	},
	{
		key: 'forceLegacyFeed',
		label: () => t('social', 'Use the hashtag compatibility feed'),
		hint: () => t('social', 'Temporarily serves the familiar hashtag feed on the existing For You route after readers reload it.'),
	},
]

/**
 * @param {object} settings `adminSettings.interests`, or what a save answered
 * @return {object} the form's values; the numbers as the strings a field holds
 */
function formOf(settings) {
	return {
		enabled: settings?.enabled !== false,
		recommendationsEnabled: settings?.recommendationsEnabled === true,
		remoteDiscoveryPaused: settings?.remoteDiscoveryPaused === true,
		learningPaused: settings?.learningPaused === true,
		forceLegacyFeed: settings?.forceLegacyFeed === true,
		learningDefault: settings?.learningDefault === true,
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
		DiscoveryHealth,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
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
			operationalControls: OPERATIONAL_CONTROLS,
			form: formOf(this.settings),
			saving: false,
			v4Saving: false,
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
				showError(errorOf(
					error,
					t('social', 'Could not save the For you settings'),
					Object.fromEntries(FIELDS.map((field) => [field.key, field.label()])),
				))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Persist the independent recommendations kill switch immediately.
		 *
		 * @param {boolean} enabled Whether recommendations are enabled for this instance.
		 */
		async saveRecommendations(enabled) {
			this.v4Saving = true
			try {
				const { data } = await axios.put(recommendationsUrl(), { enabled })
				this.form.recommendationsEnabled = data?.enabled === true
				showSuccess(t('social', 'Recommendation settings saved'))
			} catch (error) {
				this.form.recommendationsEnabled = !enabled
				showError(errorOf(error, t('social', 'Could not save recommendation settings')))
			} finally {
				this.v4Saving = false
			}
		},

		/**
		 * @param {'remoteDiscoveryPaused'|'learningPaused'|'forceLegacyFeed'} key Which operational switch to save.
		 * @param {boolean} value The new switch state.
		 */
		async saveOperationalFlag(key, value) {
			const previous = this.form[key]
			this.form[key] = value
			this.v4Saving = true
			try {
				const { data } = await axios.put(recommendationsUrl(), { [key]: value })
				this.form[key] = data?.[key] === true
				showSuccess(t('social', 'Recommendation settings saved'))
			} catch (error) {
				this.form[key] = previous
				showError(errorOf(error, t('social', 'Could not save recommendation settings')))
			} finally {
				this.v4Saving = false
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

	&__operations {
		display: grid;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__number {
		min-width: 0;
	}
}
</style>
