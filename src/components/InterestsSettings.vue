<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
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
					class="interests-settings__learning"
					:modelValue="settings.learning"
					:disabled="saving"
					@update:modelValue="saveSetting('learning', $event)">
					{{ t('social', 'Learn from my browsing') }}
				</NcCheckboxRadioSwitch>
				<p class="interests-settings__hint">
					{{ t('social', 'Which hashtags you linger on, open, like and skip. It stays on this server and nobody but you sees it.') }}
				</p>

				<NcCheckboxRadioSwitch
					type="switch"
					class="interests-settings__paused"
					:modelValue="settings.paused"
					:disabled="saving || !settings.learning"
					@update:modelValue="saveSetting('paused', $event)">
					{{ t('social', 'Pause learning') }}
				</NcCheckboxRadioSwitch>
				<p class="interests-settings__hint">
					{{ t('social', 'For a day of reading something unusual. My interests keeps working with what it knows, and nothing new is learned or forgotten until you switch this back.') }}
				</p>
			</div>

			<NcNoteCard v-if="!settings.learning" type="info" class="interests-settings__note">
				{{ t('social', 'Learning is off, so this list is frozen and My interests is hidden. You can still change it, and it is all here when you turn learning back on.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="thin" type="info" class="interests-settings__note">
				{{ t('social', 'Still learning what you like. Until it knows more, My interests also shows hashtags you follow and what is trending here.') }}
			</NcNoteCard>

			<InterestCloud
				ref="cloud"
				:interests="interests"
				:frozen="!settings.learning"
				@update="apply">
				<template #end>
					<!-- The Add pill is the last word of the cloud, and turns
					     into the box it stands for. -->
					<button
						v-if="!adding"
						type="button"
						class="interests-settings__add-pill"
						@click="openAdd">
						<IconPlus :size="16" />
						{{ t('social', 'Add') }}
					</button>
					<form
						v-else
						class="interests-settings__add"
						@submit.prevent="submitAdd">
						<span class="interests-settings__add-hash" aria-hidden="true">#</span>
						<input
							ref="addInput"
							v-model="newTag"
							class="interests-settings__add-input"
							type="text"
							role="combobox"
							autocomplete="off"
							:aria-label="t('social', 'Hashtag to add to your interests')"
							:aria-expanded="suggestions.length > 0 ? 'true' : 'false'"
							:aria-controls="listId"
							:aria-activedescendant="activeSuggestion >= 0 ? `${listId}-${activeSuggestion}` : undefined"
							:aria-invalid="typedIsNotATag ? 'true' : 'false'"
							:placeholder="t('social', 'hashtag')"
							:maxlength="128"
							:disabled="busy"
							@input="onType"
							@keydown="onAddKeydown"
							@blur="onAddBlur">
						<button
							type="submit"
							class="interests-settings__add-go"
							:disabled="!canAdd"
							:aria-label="t('social', 'Add to your interests')">
							<IconCheck :size="16" />
						</button>
						<ul
							v-show="suggestions.length > 0"
							:id="listId"
							class="interests-settings__suggestions"
							role="listbox"
							:aria-label="t('social', 'Known hashtags')">
							<li
								v-for="(suggestion, index) in suggestions"
								:id="`${listId}-${index}`"
								:key="suggestion"
								role="option"
								class="interests-settings__suggestion"
								:class="{ 'interests-settings__suggestion--active': index === activeSuggestion }"
								:aria-selected="index === activeSuggestion ? 'true' : 'false'"
								@mousedown.prevent
								@click="add(suggestion)">
								{{ '#' + suggestion }}
							</li>
						</ul>
					</form>
				</template>
			</InterestCloud>
			<p v-if="typedIsNotATag" class="interests-settings__error">
				{{ t('social', 'A hashtag is letters, numbers and underscores — nothing else.') }}
			</p>

			<!-- Learned tags just under the line. Tapping one adds it by hand,
			     which is the quickest way to say "yes, that one". -->
			<div v-if="candidates.length > 0" class="interests-settings__candidates">
				<button
					type="button"
					class="interests-settings__disclosure"
					:aria-expanded="showCandidates ? 'true' : 'false'"
					aria-controls="interests-settings-candidates"
					@click="showCandidates = !showCandidates">
					<IconChevronDown
						class="interests-settings__disclosure-icon"
						:class="{ 'interests-settings__disclosure-icon--open': showCandidates }"
						:size="20" />
					{{ n('social', 'Show %n more candidate', 'Show %n more candidates', candidates.length) }}
				</button>
				<div v-show="showCandidates" id="interests-settings-candidates">
					<p class="interests-settings__hint">
						{{ t('social', 'Hashtags you have been reading that are not quite interests yet. Add one to keep it.') }}
					</p>
					<ul class="interests-settings__chips">
						<li v-for="candidate in candidates" :key="candidate.tag">
							<button
								type="button"
								class="interests-settings__chip"
								:disabled="busy"
								:aria-label="t('social', 'Add #{tag} to your interests', { tag: candidate.tag })"
								@click="add(candidate.tag)">
								<IconPlus :size="14" />
								<span>{{ '#' + candidate.tag }}</span>
							</button>
						</li>
					</ul>
				</div>
			</div>

			<div class="interests-settings__languages">
				<NcSelect
					:modelValue="chosenLanguages"
					class="interests-settings__language-select"
					:inputLabel="t('social', 'Languages for My interests')"
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
				<NcButton variant="error" :disabled="busy" @click="confirmingReset = true">
					<template #icon>
						<IconRestore :size="20" />
					</template>
					{{ t('social', 'Reset all interests') }}
				</NcButton>
				<p class="interests-settings__hint">
					{{ t('social', 'Forgets everything learned, and the hashtags you added, placed and pinned. Hashtags you follow stay followed.') }}
				</p>
			</div>

			<NcDialog
				:open="confirmingReset"
				:name="t('social', 'Reset all interests?')"
				:buttons="resetButtons"
				@update:open="confirmingReset = $event">
				<p class="interests-settings__hint">
					{{ t('social', 'Everything Social has learned about what you like goes, with the hashtags you added and the order you gave them. It starts learning again from nothing. This cannot be undone.') }}
				</p>
			</NcDialog>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import debounce from 'debounce'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconRestore from 'vue-material-design-icons/Restore.vue'
import InterestCloud from './InterestCloud.vue'
import logger from '../services/logger.js'
import {
	addInterest,
	fetchInterests,
	resetInterests,
	saveInterestSettings,
} from '../services/interests.js'
import { showError, showSuccess } from '../services/toast.js'
import { POST_LANGUAGES, languageName } from '../utils/postLanguage.js'

/**
 * A hashtag as the server takes one, for deciding whether the box holds one.
 * The server normalises and refuses for itself; this only enables a button.
 *
 * @param {string} value what was typed
 * @return {string} the tag, or '' for something that is not one
 */
function asHashtag(value) {
	const tag = String(value ?? '').trim().replace(/^#/, '').toLowerCase()

	return /^[\p{L}\p{N}_]+$/u.test(tag) && tag.length <= 127 ? tag : ''
}

/** the most suggestions the add box offers at once */
const MAX_SUGGESTIONS = 6

/**
 * Settings → Interests: the switches, the cloud, and the rest of the model.
 *
 * Every write answers the whole state, and the whole state replaces what is
 * held here. The list can reorder below any single change — a pin, an add, a
 * move — and only the server knows the result, so nothing is patched locally.
 * The two switches are the exception in how they *look*: they move at once
 * and move back on a failure, because a switch that waits for a round trip
 * reads as one that did not work.
 */
export default {
	name: 'InterestsSettings',

	components: {
		IconCheck,
		IconChevronDown,
		IconPlus,
		IconRestore,
		InterestCloud,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			/**
			 * The debounced search, made once in `created()`. Declared so it
			 * is part of the instance rather than appearing on it.
			 *
			 * @type {((...args: unknown[]) => void) & {clear?: () => void}|null}
			 */
			searchDebounced: null,
			/** @type {object|null} the state, as the last answer had it */
			state: null,
			/** a switch or the languages are being saved */
			saving: false,
			/** an add or a reset is in flight */
			busy: false,
			adding: false,
			newTag: '',
			/** @type {string[]} known hashtags matching what is typed */
			suggestions: [],
			activeSuggestion: -1,
			showCandidates: false,
			confirmingReset: false,
			listId: 'interests-settings-suggestions',
		}
	},

	computed: {
		/** @return {object} the reader's settings, with the defaults of a fresh account */
		settings() {
			return {
				learning: true,
				paused: false,
				languages: [],
				...(this.state?.settings ?? {}),
			}
		},

		/** @return {object[]} the listed interests, in rank order */
		interests() {
			return Array.isArray(this.state?.interests) ? this.state.interests : []
		},

		/** @return {object[]} learned tags just under the threshold */
		candidates() {
			return Array.isArray(this.state?.candidates) ? this.state.candidates : []
		},

		/** @return {boolean} learning has too little to go on yet */
		thin() {
			return this.state?.thin === true
		},

		/**
		 * The languages Nextcloud is translated into, named in the reader's
		 * language — the same list the composer offers a post in, so the two
		 * agree on what a language is here. A saved code outside it is kept.
		 *
		 * @return {object[]} `{ code, name }`, sorted by name
		 */
		languageOptions() {
			const codes = new Set([...POST_LANGUAGES, ...this.settings.languages])

			return [...codes]
				.map((code) => ({ code, name: languageName(code) }))
				.sort((a, b) => a.name.localeCompare(b.name))
		},

		/** @return {object[]} the chosen languages, as options */
		chosenLanguages() {
			return this.settings.languages.map((code) => this.languageOptions.find((option) => option.code === code) ?? { code, name: code })
		},

		/** @return {boolean} whether the box holds something that is not a hashtag */
		typedIsNotATag() {
			return this.newTag.trim() !== '' && this.newTag.trim() !== '#' && asHashtag(this.newTag) === ''
		},

		/** @return {boolean} */
		canAdd() {
			return !this.busy && asHashtag(this.newTag) !== ''
		},

		/** @return {object[]} the dialog's two buttons */
		resetButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.confirmingReset = false
					},
				},
				{
					label: t('social', 'Reset all interests'),
					variant: 'error',
					disabled: this.busy,
					callback: () => this.reset(),
				},
			]
		},
	},

	created() {
		this.searchDebounced = debounce(this.search, 200)
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		this.searchDebounced.clear?.()
	},

	methods: {
		t,
		n,

		async load() {
			try {
				this.apply(await fetchInterests())
			} catch (error) {
				logger.error('Could not load the interests', { error })
				showError(t('social', 'Could not load your interests'))
			} finally {
				this.loading = false
			}
		},

		/** @param {object} state the whole state, as any interests endpoint answers it */
		apply(state) {
			if (state && typeof state === 'object') {
				this.state = state
			}
		},

		/**
		 * @param {string} key `learning` or `paused`
		 * @param {boolean} value what the switch was moved to
		 */
		async saveSetting(key, value) {
			const previous = this.state
			this.state = { ...(this.state ?? {}), settings: { ...this.settings, [key]: value } }
			this.saving = true
			try {
				this.apply(await saveInterestSettings({ [key]: value }))
			} catch (error) {
				logger.error('Could not save the interests setting', { error })
				showError(t('social', 'Could not save that setting'))
				this.state = previous
			} finally {
				this.saving = false
			}
		},

		/** @param {object[]} options the languages now chosen */
		async saveLanguages(options) {
			const languages = (options ?? []).map((option) => option.code)
			const previous = this.state
			this.state = { ...(this.state ?? {}), settings: { ...this.settings, languages } }
			this.saving = true
			try {
				this.apply(await saveInterestSettings({ languages }))
			} catch (error) {
				logger.error('Could not save the interests languages', { error })
				showError(t('social', 'Could not save the languages'))
				this.state = previous
			} finally {
				this.saving = false
			}
		},

		openAdd() {
			this.adding = true
			this.$nextTick(() => /** @type {HTMLInputElement|undefined} */ (this.$refs.addInput)?.focus())
		},

		closeAdd() {
			this.adding = false
			this.newTag = ''
			this.suggestions = []
			this.activeSuggestion = -1
		},

		onType() {
			this.activeSuggestion = -1
			this.searchDebounced(this.newTag)
		},

		/**
		 * The same search the composer's `#` autocompletion asks.
		 *
		 * @param {string} text what is typed
		 */
		async search(text) {
			const query = String(text ?? '').trim().replace(/^#/, '')
			if (query === '') {
				this.suggestions = []
				return
			}

			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/global/tags/search'), { params: { search: query } })
				// stale: the box has moved on while this was asked
				if (String(this.newTag).trim().replace(/^#/, '') !== query) {
					return
				}
				const result = data?.result ?? {}
				const exact = result.exact && !Array.isArray(result.exact)
					? [typeof result.exact === 'string' ? result.exact : result.exact.hashtag]
					: []
				const found = (Array.isArray(result.tags) ? result.tags : []).map((tag) => tag?.hashtag)
				const listed = new Set(this.interests.map((entry) => entry.tag))
				this.suggestions = [...new Set([...exact, ...found])]
					.filter((tag) => typeof tag === 'string' && tag !== '' && !listed.has(tag))
					.slice(0, MAX_SUGGESTIONS)
			} catch (error) {
				// a failed suggestion costs nothing but the shortcut
				logger.debug('Could not search the hashtags', { error })
				this.suggestions = []
			}
		},

		/** @param {KeyboardEvent} event a key in the add box */
		onAddKeydown(event) {
			if (event.key === 'Escape') {
				event.preventDefault()
				this.closeAdd()
				return
			}
			if (this.suggestions.length === 0) {
				return
			}
			if (event.key === 'ArrowDown') {
				event.preventDefault()
				this.activeSuggestion = (this.activeSuggestion + 1) % this.suggestions.length
			} else if (event.key === 'ArrowUp') {
				event.preventDefault()
				this.activeSuggestion = this.activeSuggestion <= 0 ? this.suggestions.length - 1 : this.activeSuggestion - 1
			}
		},

		/** An emptied box closes back into the pill when it is left. */
		onAddBlur() {
			if (this.newTag.trim() === '' && !this.busy) {
				this.closeAdd()
			}
		},

		submitAdd() {
			if (this.activeSuggestion >= 0 && this.suggestions[this.activeSuggestion]) {
				this.add(this.suggestions[this.activeSuggestion])
				return
			}
			if (!this.canAdd) {
				return
			}
			this.add(this.newTag)
		},

		/**
		 * Adds a manual interest, then shows the reader where it went: it
		 * lands where its score puts it, which is usually low in the cloud.
		 *
		 * @param {string} value a suggestion, a candidate or what was typed
		 */
		async add(value) {
			const tag = asHashtag(value)
			if (tag === '' || this.busy) {
				return
			}

			this.busy = true
			try {
				this.apply(await addInterest(tag))
				this.newTag = ''
				this.suggestions = []
				this.activeSuggestion = -1
				// bound first: a cast's parenthesis opening a statement reads as a
				// call on the line above it
				const cloud = /** @type {{reveal?: (tag: string) => void}|undefined} */ (this.$refs.cloud)
				cloud?.reveal(tag)
				this.$nextTick(() => /** @type {HTMLInputElement|undefined} */ (this.$refs.addInput)?.focus())
			} catch (error) {
				logger.error('Could not add the interest', { error })
				const said = error?.response?.data?.error
				showError(typeof said === 'string' && said !== '' ? said : t('social', 'Could not add #{tag}', { tag }))
			} finally {
				this.busy = false
			}
		},

		async reset() {
			this.busy = true
			try {
				this.apply(await resetInterests())
				this.confirmingReset = false
				showSuccess(t('social', 'Your interests were reset'))
			} catch (error) {
				logger.error('Could not reset the interests', { error })
				showError(t('social', 'Could not reset your interests'))
			} finally {
				this.busy = false
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
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		max-width: 62ch;
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

	&__error {
		margin: -4px 0 0;
		color: var(--color-error-text, var(--color-error));
		font-size: 13px;
	}

	// the pill and the box it becomes, drawn as one more tag at the end of
	// the cloud. The section's class in front of the buttons outranks the
	// server's rules for bare buttons, hovered and focused ones included.
	& &__add-pill,
	&__add {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		min-height: 32px;
		margin: 0;
		border: 1px dashed color-mix(in srgb, var(--color-primary-element) 55%, var(--color-border));
		border-radius: var(--border-radius-pill, 999px);
		background: var(--color-main-background);
		color: var(--color-primary-element);
		font-size: 14px;
	}

	& &__add-pill {
		padding: 0 12px 0 8px;
		font-weight: 600;
		cursor: pointer;

		&:hover,
		&:focus-visible {
			border-style: solid;
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__add {
		position: relative;
		padding: 0 2px 0 10px;
		border-style: solid;
		border-color: var(--color-primary-element);

		&:focus-within {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__add-hash {
		color: var(--color-text-maxcontrast);
	}

	// the input's own border and outline are the pill's; the pill carries the
	// focus ring for both. The class is doubled to outweigh the server's own
	// `input:not(…):focus` rules, which would otherwise draw a second box
	&__add &__add-input.interests-settings__add-input {
		&,
		&:focus,
		&:focus-visible,
		&:hover {
			inline-size: 11em;
			max-inline-size: 50vw;
			min-height: 0;
			height: 28px;
			margin: 0;
			padding: 0;
			border: none;
			outline: 0 solid transparent;
			background: transparent;
			color: var(--color-main-text);
			font-size: 14px;
			box-shadow: none;
		}
	}

	& &__add-go {
		display: flex;
		align-items: center;
		justify-content: center;
		inline-size: 28px;
		block-size: 28px;
		min-height: 0;
		margin: 0;
		padding: 0;
		border: none;
		border-radius: 50%;
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
		cursor: pointer;

		&:disabled {
			background: var(--color-background-dark);
			color: var(--color-text-maxcontrast);
			cursor: default;
		}
	}

	&__suggestions {
		position: absolute;
		inset-block-start: calc(100% + 6px);
		inset-inline-start: 0;
		z-index: 10;
		min-inline-size: 100%;
		margin: 0;
		padding: 4px;
		list-style: none;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
		box-shadow: var(--social-elevation-raised);
	}

	&__suggestion {
		padding: 6px 10px;
		border-radius: var(--border-radius-element, var(--border-radius));
		color: var(--color-main-text);
		white-space: nowrap;
		cursor: pointer;

		&:hover,
		&--active {
			background: var(--color-background-hover);
		}

		&--active {
			color: var(--color-primary-element-light-text);
			background: var(--color-primary-element-light);
		}
	}

	&__candidates {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
	}

	& &__disclosure {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		min-height: 34px;
		margin: 0;
		padding: 0 10px 0 4px;
		border: none;
		border-radius: var(--border-radius-element, var(--border-radius-large));
		background: transparent;
		color: var(--color-main-text);
		font-weight: 600;
		cursor: pointer;

		&:hover,
		&:focus-visible {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__disclosure-icon {
		color: var(--color-text-maxcontrast);

		&--open {
			transform: rotate(180deg);
		}
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		margin: 8px 0 0;
		padding: 0;
		list-style: none;
	}

	& &__chip {
		display: inline-flex;
		align-items: center;
		gap: 2px;
		min-height: 28px;
		margin: 0;
		padding: 0 10px 0 6px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill, 999px);
		background: transparent;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		cursor: pointer;

		&:hover:not(:disabled),
		&:focus-visible {
			border-color: var(--color-primary-element);
			color: var(--color-primary-element);
			background: var(--color-primary-element-light);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		&:disabled {
			opacity: .5;
			cursor: default;
		}
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

// Nextcloud's rules for hovered, focused and pressed bare buttons are four
// pseudo-classes deep and paint a dark border and a white background; the
// doubled class outranks them
.interests-settings .interests-settings__add-pill.interests-settings__add-pill,
.interests-settings .interests-settings__chip.interests-settings__chip {
	&:hover:not(:disabled),
	&:focus {
		border-color: var(--color-primary-element);
	}

	&:active {
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}
}

.interests-settings .interests-settings__disclosure.interests-settings__disclosure:active {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.interests-settings .interests-settings__add-go.interests-settings__add-go:active:not(:disabled) {
	background: var(--color-primary-element-hover, var(--color-primary-element));
	color: var(--color-primary-element-text);
}

@media (prefers-reduced-motion: no-preference) {
	.interests-settings__disclosure-icon {
		transition: transform .2s ease;
	}
}

@media (prefers-reduced-motion: reduce) {
	.interests-settings__disclosure-icon {
		transition: none;
	}
}
</style>
