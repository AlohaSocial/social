<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<form class="filter-form" @submit.prevent="submit">
		<h4 class="filter-form__heading">
			{{ isNew ? t('social', 'New filter') : t('social', 'Editing “{title}”', { title: filter.title }) }}
		</h4>

		<!-- An expired filter is still a row in the list and still opens here.
		     Saving it writes whatever the expiry box says, so a filter the
		     reader thinks is over would quietly start again without this. -->
		<NcNoteCard v-if="wasExpired" type="warning">
			{{ t('social', 'This filter has expired and is not doing anything. Saving it starts it again for as long as the expiry below says.') }}
		</NcNoteCard>

		<NcTextField
			v-model="title"
			class="filter-form__title"
			:label="t('social', 'Name of the filter')"
			:placeholder="t('social', 'What you are filtering, in your own words')"
			:showTrailingButton="false"
			maxlength="255" />

		<fieldset class="filter-form__section">
			<legend class="filter-form__legend">
				{{ t('social', 'Words to look for') }}
			</legend>
			<p class="filter-form__hint">
				{{ t('social', 'A post is filtered if any one of these appears in it: in the text, in the content warning, in the description of a picture or in the options of a poll. A boost is read as the post it boosts. Upper and lower case never matter.') }}
			</p>

			<div v-for="(entry, index) in keywords" :key="entry.key" class="filter-form__keyword">
				<NcTextField
					:modelValue="entry.keyword"
					class="filter-form__word"
					:label="t('social', 'Word or phrase')"
					:showTrailingButton="false"
					maxlength="255"
					@update:modelValue="(value) => setKeyword(index, value)" />
				<NcCheckboxRadioSwitch
					:modelValue="entry.wholeWord"
					class="filter-form__whole-word"
					@update:modelValue="(value) => setWholeWord(index, value)">
					{{ t('social', 'Whole word only') }}
				</NcCheckboxRadioSwitch>
				<NcButton
					variant="tertiary"
					:disabled="keywords.length === 1"
					:aria-label="t('social', 'Remove this word')"
					@click="removeKeyword(index)">
					<template #icon>
						<IconClose :size="20" />
					</template>
				</NcButton>
			</div>

			<p class="filter-form__hint">
				{{ t('social', '“Whole word only” is the difference between filtering “cat” and also filtering every “catalogue”. It is decided for each word on its own.') }}
			</p>
			<NcButton class="filter-form__add-word" variant="tertiary" @click="addKeyword">
				<template #icon>
					<IconPlus :size="20" />
				</template>
				{{ t('social', 'Add another word') }}
			</NcButton>
		</fieldset>

		<fieldset class="filter-form__section">
			<legend class="filter-form__legend">
				{{ t('social', 'Where it applies') }}
			</legend>
			<p class="filter-form__hint">
				{{ t('social', 'A filter only works in the places you tick. Somewhere you did not tick, a matching post arrives as usual.') }}
			</p>
			<NcCheckboxRadioSwitch
				v-for="context in contexts"
				:key="context.id"
				:modelValue="chosen[context.id]"
				class="filter-form__choice"
				@update:modelValue="(value) => setContext(context.id, value)">
				{{ context.label }}
				<span class="filter-form__hint filter-form__hint--inline">{{ context.hint }}</span>
			</NcCheckboxRadioSwitch>
		</fieldset>

		<fieldset class="filter-form__section">
			<legend class="filter-form__legend">
				{{ t('social', 'What happens to a post it matches') }}
			</legend>
			<NcCheckboxRadioSwitch
				v-model="action"
				class="filter-form__choice"
				type="radio"
				name="social-filter-action"
				value="warn">
				{{ t('social', 'Fold it away, with a way to read it anyway') }}
				<span class="filter-form__hint filter-form__hint--inline">
					{{ t('social', 'The post keeps its place in the timeline, folded behind the name of this filter, and nothing of it is on the page until you press “Show anyway”. Nothing is kept from you — you are asked whether you want it.') }}
				</span>
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="action"
				class="filter-form__choice"
				type="radio"
				name="social-filter-action"
				value="hide">
				{{ t('social', 'Take it out of the timeline') }}
				<span class="filter-form__hint filter-form__hint--inline">
					{{ t('social', 'The post is not sent to you at all, here or in any app, and a conversation containing one simply has a gap. Nothing is deleted and nobody is told: lift the filter and the post is back.') }}
				</span>
			</NcCheckboxRadioSwitch>
		</fieldset>

		<NcSelect
			v-model="expiry"
			class="filter-form__expiry"
			:inputLabel="t('social', 'Stops applying after')"
			:options="expiries"
			:clearable="false"
			:searchable="false"
			label="label" />

		<!-- Said rather than merely refused: the server answers 422 for each of
		     these, in English a translator never saw, and a disabled button on
		     its own gives the reader nothing to act on. -->
		<NcNoteCard v-if="problems.length > 0" type="info">
			<p v-for="problem in problems" :key="problem">
				{{ problem }}
			</p>
		</NcNoteCard>

		<div class="filter-form__actions">
			<NcButton type="submit" variant="primary" :disabled="busy || problems.length > 0">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ isNew ? t('social', 'Create filter') : t('social', 'Save') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('cancel')">
				{{ t('social', 'Cancel') }}
			</NcButton>
		</div>
	</form>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import { contextOptions, expiryOptions, isActive } from '../utils/filters.js'
import { fullDateTime } from '../utils/relativeTime.js'

/** Keys for the keyword rows, so removing one does not renumber the rest. */
let nextKey = 0

/**
 * The form behind one keyword filter, new or existing.
 *
 * It emits the body of the request rather than sending it: `FiltersSettings`
 * is where the routes are spelled, and it has to redraw the list afterwards
 * either way.
 *
 * The shape it emits is Mastodon's v2 one, which is what this app stores.
 * `keywords_attributes` edits the words in place — an entry with an `id`
 * changes that word, one with `_destroy` drops it, one without an id adds it —
 * so a word the reader never touched keeps its own id and does not come back
 * as a new row on the next read.
 */
export default {
	name: 'FilterForm',

	components: {
		IconClose,
		IconPlus,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** the filter being changed, or null for a new one */
		filter: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Filter|null>} */ (Object),
			default: null,
		},

		/** whether the request this form started is still in flight */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['submit', 'cancel'],

	data() {
		const filter = this.filter
		const applies = filter?.context ?? []
		const chosen = /** @type {Record<string, boolean>} */ ({})
		for (const { id } of contextOptions()) {
			chosen[id] = applies.includes(id)
		}

		const keywords = (filter?.keywords ?? []).map((keyword) => ({
			key: nextKey++,
			id: String(keyword.id ?? ''),
			keyword: String(keyword.keyword ?? ''),
			wholeWord: keyword.whole_word === true,
		}))

		return {
			title: String(filter?.title ?? ''),
			/** @type {Array<{key: number, id: string, keyword: string, wholeWord: boolean}>} */
			keywords: keywords.length > 0 ? keywords : [{ key: nextKey++, id: '', keyword: '', wholeWord: false }],
			/** @type {string[]} the ids of the words taken out, which the update has to name */
			dropped: [],
			/** @type {Record<string, boolean>} which contexts are ticked */
			chosen,
			action: filter?.filter_action === 'hide' ? 'hide' : 'warn',
			expiry: null,
			/** whether it had already expired when the form opened */
			wasExpired: filter !== null && !isActive(filter),
		}
	},

	computed: {
		isNew() {
			return this.filter === null
		},

		contexts() {
			return contextOptions()
		},

		/**
		 * The durations on offer.
		 *
		 * A filter that is still counting down gets one more, selected: an
		 * update that does not name `expires_in` leaves the expiry untouched,
		 * and without somewhere to say so, opening a filter to fix a typo
		 * would restart its week.
		 *
		 * @return {Array<{id: string, seconds: (number|null), label: string}>} the options
		 */
		expiries() {
			const options = expiryOptions()
			if (this.filter === null || !this.filter.expires_at || this.wasExpired) {
				return options
			}

			return [
				{
					id: 'keep',
					seconds: null,
					label: t('social', 'Leave as it is (until {date})', {
						date: fullDateTime(this.filter.expires_at),
					}),
				},
				...options,
			]
		},

		/**
		 * What is stopping this from being saved, in the reader's words.
		 *
		 * @return {string[]} one sentence per thing that is missing
		 */
		problems() {
			const problems = []
			if (this.title.trim() === '') {
				problems.push(t('social', 'Give the filter a name, so you know what it was for later.'))
			}
			if (this.keywords.some((entry) => entry.keyword.trim() === '')) {
				problems.push(t('social', 'One of the words is empty. Fill it in or take the row out — a filter with an empty word would match every post there is.'))
			}
			if (!Object.values(this.chosen).some(Boolean)) {
				problems.push(t('social', 'Tick at least one place for the filter to apply, or it does nothing anywhere.'))
			}

			return problems
		},
	},

	created() {
		this.expiry = this.expiries[0]
	},

	methods: {
		t,

		/**
		 * @param {number} index which row
		 * @param {string} value what is in the box now
		 */
		setKeyword(index, value) {
			this.keywords[index].keyword = value
		},

		/**
		 * @param {number} index which row
		 * @param {boolean} value whether it must match a whole word
		 */
		setWholeWord(index, value) {
			this.keywords[index].wholeWord = value === true
		},

		/**
		 * @param {string} id the context
		 * @param {boolean} value whether it is ticked
		 */
		setContext(id, value) {
			this.chosen = { ...this.chosen, [id]: value === true }
		},

		addKeyword() {
			this.keywords.push({ key: nextKey++, id: '', keyword: '', wholeWord: false })
		},

		/**
		 * Takes a row out. An existing word is remembered rather than
		 * forgotten: the update has to name it to remove it, and dropping it
		 * from this list alone would leave it filtering.
		 *
		 * @param {number} index which row
		 */
		removeKeyword(index) {
			const [removed] = this.keywords.splice(index, 1)
			if (removed?.id) {
				this.dropped.push(removed.id)
			}
		},

		/** Hands the parent the body of the request. */
		submit() {
			if (this.problems.length > 0 || this.busy) {
				return
			}

			const payload = {
				title: this.title.trim(),
				// in the server's own order, which is the order it stores and
				// answers with
				context: this.contexts.filter(({ id }) => this.chosen[id]).map(({ id }) => id),
				filter_action: this.action,
				keywords_attributes: [
					...this.keywords.map((entry) => ({
						...(entry.id === '' ? {} : { id: entry.id }),
						keyword: entry.keyword.trim(),
						whole_word: entry.wholeWord,
					})),
					...this.dropped.map((id) => ({ id, _destroy: true })),
				],
			}

			// `expires_in` is left out only to mean "leave the expiry alone",
			// which is what "keep" is; an empty string is how the API is told
			// that the filter should stop expiring at all
			if (this.expiry?.id !== 'keep') {
				payload.expires_in = this.expiry?.seconds ?? ''
			}

			this.$emit('submit', payload)
		},
	},
}
</script>

<style scoped lang="scss">
.filter-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3) 0;

	&__heading {
		margin: 0;
		font-size: 15px;
	}

	&__title {
		max-width: 420px;
	}

	&__section {
		border: 0;
		margin: 0;
		padding: 0;
	}

	&__legend {
		font-weight: bold;
		padding: 0;
		margin-bottom: 4px;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		margin: 0 0 8px;
		max-width: 60ch;

		&--inline {
			display: block;
			margin: 0;
		}
	}

	&__keyword {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		margin-bottom: 8px;
	}

	&__word {
		flex: 1 1 220px;
		max-width: 320px;
	}

	&__whole-word {
		flex: 0 0 auto;
	}

	&__choice {
		margin-bottom: 4px;
	}

	&__expiry {
		max-width: 420px;
	}

	&__actions {
		display: flex;
		gap: 8px;
		align-items: center;
	}
}
</style>
