<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="for-you-settings">
		<p class="for-you-settings__hint">
			{{ t('social', 'For you shows posts you are likely to enjoy, based on the hashtags you follow and what it learns from your reading. What it learns stays on this server.') }}
		</p>
		<p v-if="loading" class="for-you-settings__hint" role="status">
			{{ t('social', 'Loading …') }}
		</p>
		<template v-else>
			<fieldset v-if="learningOffered" class="for-you-settings__group">
				<legend class="for-you-settings__legend">
					{{ t('social', 'Learn from my reading') }}
				</legend>
				<NcCheckboxRadioSwitch
					v-for="option in modes"
					:key="option.value"
					type="radio"
					name="social-for-you-mode"
					:value="option.value"
					:modelValue="mode"
					:disabled="saving"
					@update:modelValue="setMode">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
				<p class="for-you-settings__hint">
					{{ modeHint }}
				</p>
				<NcCheckboxRadioSwitch
					v-if="activityOffered"
					type="switch"
					:modelValue="activityLearning"
					:disabled="saving || mode === 'off'"
					@update:modelValue="setActivityLearning">
					{{ t('social', 'Also learn from likes, saves and the videos I watch') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcSelect
				:modelValue="chosenLanguages"
				class="for-you-settings__languages"
				:inputLabel="t('social', 'Languages in For you')"
				:options="languageOptions"
				:multiple="true"
				:keepOpen="true"
				:disabled="saving"
				:placeholder="t('social', 'All languages')"
				label="name"
				@update:modelValue="saveLanguages" />
			<NcButton variant="error" :disabled="saving" @click="confirmingReset = true">
				<template #icon>
					<IconRestore :size="20" />
				</template>
				{{ t('social', 'Clear what was learned') }}
			</NcButton>
		</template>
		<NcDialog
			:open="confirmingReset"
			:name="t('social', 'Clear what For you learned?')"
			:buttons="resetButtons"
			@update:open="confirmingReset = $event">
			<p>{{ t('social', 'For you starts again from the hashtags and people you follow. Your follows, mutes and blocks are kept.') }}</p>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import IconRestore from 'vue-material-design-icons/Restore.vue'
import { useServerData } from '../composables/useServerData.js'
import logger from '../services/logger.js'
import { fetchInterests, resetInterests, saveInterestSettings } from '../services/interests.js'
import { showError, showSuccess } from '../services/toast.js'
import { POST_LANGUAGES, languageName } from '../utils/postLanguage.js'

const preferencesUrl = () => generateUrl('apps/social/api/v1/recommendations/preferences')
const learningUrl = () => generateUrl('apps/social/api/v1/recommendations/learning')
const resetUrl = () => generateUrl('apps/social/api/v1/recommendations/reset')

/**
 * Everything For you can be told, in one place: whether it learns from the
 * reader (on, paused or off), whether it also learns from likes, saves and
 * watching, which languages it shows, and starting again.
 *
 * Two stores answer underneath: the hashtag interests (`/social/interests`)
 * and, where the administrator has the newer recommendations on, the
 * activity profile (`/recommendations`). The one choice sets both.
 */
export default {
	name: 'ForYouSettings',

	components: {
		IconRestore,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
	},

	setup() {
		const { serverData } = useServerData()
		return { serverData }
	},

	data() {
		return {
			loading: true,
			saving: false,
			confirmingReset: false,
			interests: null,
			/** the activity profile's revision, which every write must name */
			revision: 0,
			/** 'off', 'on' or 'paused' */
			activityMode: 'off',
		}
	},

	computed: {
		learningOffered() {
			return this.serverData?.interests?.enabled === true
		},

		activityOffered() {
			return this.serverData?.interests?.recommendationsEnabled === true
		},

		settings() {
			return { learning: true, paused: false, languages: [], ...(this.interests?.settings ?? {}) }
		},

		/** @return {string} 'on', 'paused' or 'off' */
		mode() {
			if (!this.settings.learning) {
				return 'off'
			}
			return this.settings.paused ? 'paused' : 'on'
		},

		modes() {
			return [
				{ value: 'on', label: t('social', 'On') },
				{ value: 'paused', label: t('social', 'Paused') },
				{ value: 'off', label: t('social', 'Off') },
			]
		},

		modeHint() {
			return {
				on: t('social', 'It learns from the hashtags of posts you open, like, boost, reply to or save.'),
				paused: t('social', 'For a day of reading something unusual: For you keeps what it knows and learns nothing new.'),
				off: t('social', 'Nothing new is learned. For you still uses the hashtags and people you follow.'),
			}[this.mode]
		},

		activityLearning() {
			return this.activityMode !== 'off'
		},

		languageOptions() {
			const codes = new Set([...POST_LANGUAGES, ...this.settings.languages])
			return [...codes]
				.map((code) => ({ code, name: languageName(code) }))
				.sort((a, b) => a.name.localeCompare(b.name))
		},

		chosenLanguages() {
			return this.settings.languages.map((code) => this.languageOptions.find((option) => option.code === code) ?? { code, name: code })
		},

		resetButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.confirmingReset = false } },
				{ label: t('social', 'Clear'), variant: /** @type {'error'} */ ('error'), disabled: this.saving, callback: () => this.reset() },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		async load() {
			try {
				const [interests, preferences] = await Promise.all([
					fetchInterests(),
					this.activityOffered ? axios.get(preferencesUrl()).then(({ data }) => data) : Promise.resolve(null),
				])
				this.interests = interests
				this.applyPreferences(preferences)
			} catch (error) {
				logger.error('Could not load the For you settings', { error })
				showError(t('social', 'Could not load your For You settings'))
			} finally {
				this.loading = false
			}
		},

		/** @param {object|null} preferences the activity profile as the server holds it */
		applyPreferences(preferences) {
			if (!preferences) {
				return
			}
			this.revision = Number(preferences.revision) || 0
			this.activityMode = ['off', 'on', 'paused'].includes(preferences.learning_mode) ? preferences.learning_mode : 'off'
		},

		/** @param {string} mode 'on', 'paused' or 'off' */
		async setMode(mode) {
			const activity = mode === 'off' ? 'off' : (this.activityLearning ? mode : 'off')
			await this.save({ learning: mode !== 'off', paused: mode === 'paused' }, activity)
		},

		/** @param {boolean} on whether likes, saves and watching count too */
		async setActivityLearning(on) {
			await this.save(null, on ? this.mode : 'off')
		},

		async saveLanguages(options) {
			await this.save({ languages: (options ?? []).map((option) => option.code) }, null)
		},

		/**
		 * Writes the interest settings and the activity mode, each only when it changes.
		 *
		 * @param {object|null} settings interest settings to write
		 * @param {string|null} activity the activity learning mode to write
		 */
		async save(settings, activity) {
			const previous = this.interests
			this.saving = true
			try {
				if (settings) {
					this.interests = { ...(this.interests ?? {}), settings: { ...this.settings, ...settings } }
					this.interests = await saveInterestSettings(settings)
				}
				if (this.activityOffered && activity !== null && activity !== this.activityMode) {
					const { data } = await axios.put(learningUrl(), { if_revision: this.revision, mode: activity })
					this.applyPreferences(data)
				}
			} catch (error) {
				logger.error('Could not save the For you settings', { error })
				showError(t('social', 'Could not save that setting'))
				this.interests = previous
				const current = error?.response?.data?.current
				if (error?.response?.status === 409 && current) {
					this.applyPreferences(current)
				}
			} finally {
				this.saving = false
			}
		},

		async reset() {
			this.confirmingReset = false
			this.saving = true
			try {
				this.interests = await resetInterests()
				if (this.activityOffered) {
					const { data } = await axios.post(resetUrl(), { if_revision: this.revision, scope: 'learning' })
					this.applyPreferences(data)
				}
				showSuccess(t('social', 'For you starts again'))
			} catch (error) {
				logger.error('Could not clear what For you learned', { error })
				showError(t('social', 'Could not clear what For you learned'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.for-you-settings {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__group {
		width: 100%;
	}

	&__legend {
		font-weight: 600;
	}

	&__languages {
		width: min(100%, 420px);
	}
}
</style>
