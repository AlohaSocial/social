<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="interests-settings">
		<p v-if="loading" class="interests-settings__hint">
			{{ t('social', 'Loading …') }}
		</p>

		<template v-else>
			<div class="interests-settings__switches">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="settings.learning"
					:disabled="saving"
					@update:modelValue="saveSetting('learning', $event)">
					{{ t('social', 'Learn from my browsing') }}
				</NcCheckboxRadioSwitch>
				<p class="interests-settings__hint">
					{{ t('social', 'Off until you turn it on. For You then learns from the hashtags of posts you open, like, boost, reply to or save, and of videos you watch most of. You cannot choose its topics.') }}
				</p>

				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="settings.paused"
					:disabled="saving || !settings.learning"
					@update:modelValue="saveSetting('paused', $event)">
					{{ t('social', 'Pause learning') }}
				</NcCheckboxRadioSwitch>
				<p class="interests-settings__hint">
					{{ t('social', 'For a day of reading something unusual. For You keeps working with what it knows while learning is paused.') }}
				</p>
			</div>

			<NcNoteCard v-if="!settings.learning" type="info" class="interests-settings__note">
				{{ t('social', 'Learning from your browsing is off, so nothing new is learned. For You still uses the hashtags you follow, the people you follow, local discovery and anything learned earlier, until you reset it.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="thin" type="info" class="interests-settings__note">
				{{ t('social', 'Still learning what you like. Until it knows more, For You also shows posts from people you follow and local discovery.') }}
			</NcNoteCard>

			<div class="interests-settings__languages">
				<NcSelect
					:modelValue="chosenLanguages"
					class="interests-settings__language-select"
					:inputLabel="t('social', 'Languages in For You')"
					:options="languageOptions"
					:multiple="true"
					:keepOpen="true"
					:disabled="saving"
					:placeholder="t('social', 'All languages')"
					label="name"
					@update:modelValue="saveLanguages" />
				<p class="interests-settings__hint">
					{{ t('social', 'Only posts in these languages. None chosen means every language.') }}
				</p>
			</div>

			<div class="interests-settings__reset">
				<NcButton variant="error" :disabled="resetting" @click="confirmingReset = true">
					<template #icon>
						<IconRestore :size="20" />
					</template>
					{{ t('social', 'Reset automatic interests') }}
				</NcButton>
				<p class="interests-settings__hint">
					{{ t('social', 'Forgets the automatically learned profile and starts learning again. Followed hashtags stay followed.') }}
				</p>
			</div>

			<NcDialog
				:open="confirmingReset"
				:name="t('social', 'Reset automatic interests?')"
				:buttons="resetButtons"
				@update:open="confirmingReset = $event">
				<p class="interests-settings__hint">
					{{ t('social', 'Everything Aloha Social learned about what you like goes. It starts learning again from nothing. This cannot be undone.') }}
				</p>
			</NcDialog>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import IconRestore from 'vue-material-design-icons/Restore.vue'
import logger from '../services/logger.js'
import { fetchInterests, resetInterests, saveInterestSettings } from '../services/interests.js'
import { showError, showSuccess } from '../services/toast.js'
import { POST_LANGUAGES, languageName } from '../utils/postLanguage.js'

export default {
	name: 'InterestsSettings',

	components: {
		IconRestore,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			state: null,
			saving: false,
			resetting: false,
			confirmingReset: false,
		}
	},

	computed: {
		settings() {
			return { learning: true, paused: false, languages: [], ...(this.state?.settings ?? {}) }
		},

		thin() {
			return this.state?.thin === true
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

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		resetButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.confirmingReset = false } },
				{ label: t('social', 'Reset automatic interests'), variant: 'error', disabled: this.resetting, callback: () => this.reset() },
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
				this.state = await fetchInterests()
			} catch (error) {
				logger.error('Could not load automatic interests', { error })
				showError(t('social', 'Could not load your For You settings'))
			} finally {
				this.loading = false
			}
		},

		async saveSetting(key, value) {
			const previous = this.state
			this.state = { ...(this.state ?? {}), settings: { ...this.settings, [key]: value } }
			this.saving = true
			try {
				this.state = await saveInterestSettings({ [key]: value })
			} catch (error) {
				logger.error('Could not save the For You learning setting', { error })
				showError(t('social', 'Could not save that setting'))
				this.state = previous
			} finally {
				this.saving = false
			}
		},

		async saveLanguages(options) {
			const languages = (options ?? []).map((option) => option.code)
			const previous = this.state
			this.state = { ...(this.state ?? {}), settings: { ...this.settings, languages } }
			this.saving = true
			try {
				this.state = await saveInterestSettings({ languages })
			} catch (error) {
				logger.error('Could not save For You languages', { error })
				showError(t('social', 'Could not save the languages'))
				this.state = previous
			} finally {
				this.saving = false
			}
		},

		async reset() {
			this.resetting = true
			try {
				this.state = await resetInterests()
				this.confirmingReset = false
				showSuccess(t('social', 'Your automatic interests were reset'))
			} catch (error) {
				logger.error('Could not reset automatic interests', { error })
				showError(t('social', 'Could not reset automatic interests'))
			} finally {
				this.resetting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.interests-settings {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__hint {
		max-width: 62ch;
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__switches {
		display: flex;
		flex-direction: column;
		gap: 2px;

		.interests-settings__hint {
			margin-block-end: calc(var(--default-grid-baseline) * 2);
			padding-inline-start: calc(var(--default-grid-baseline) * 2);
		}
	}

	&__note {
		margin: 0;
	}

	&__languages {
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-width: 480px;
	}

	&__language-select {
		inline-size: 100%;
	}

	&__reset {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
		padding-block-start: calc(var(--default-grid-baseline) * 3);
		border-block-start: 1px solid var(--color-border);
	}
}
</style>
