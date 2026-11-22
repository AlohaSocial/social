<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="place-picker">
		<div class="place-picker__head">
			<span class="place-picker__title">{{ t('social', 'Place') }}</span>
			<!-- puts the panel away, and a post with the pin off has no place;
			     "Remove the place" below clears the choice and keeps the search -->
			<NcButton
				variant="tertiary"
				class="place-picker__close"
				:title="t('social', 'Close the place picker')"
				:ariaLabel="t('social', 'Close the place picker')"
				@click="$emit('close')">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>

		<div v-if="place" class="place-picker__chosen">
			<MapMarkerOutline :size="18" />
			<span class="place-picker__chosen-name">{{ label(place) }}</span>
			<NcButton
				variant="tertiary"
				:ariaLabel="t('social', 'Remove the place')"
				@click="clear">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>

		<template v-else>
			<NcTextField
				ref="search"
				v-model="query"
				class="place-picker__search"
				:label="t('social', 'Where was this taken?')"
				:placeholder="t('social', 'Name a place')"
				maxlength="255"
				@update:modelValue="onQuery" />
			<ul v-if="query.trim() !== ''" class="place-picker__results" role="listbox">
				<li v-for="candidate in results" :key="candidate.id">
					<button
						type="button"
						class="place-picker__result"
						role="option"
						@click="choose(candidate)">
						<MapMarkerOutline :size="16" />
						{{ label(candidate) }}
					</button>
				</li>
				<!-- a new name: it becomes a place when the post is published,
				     nothing is looked up -->
				<li class="place-picker__new">
					<NcTextField
						v-model="country"
						class="place-picker__country"
						:label="t('social', 'Country')"
						:placeholder="t('social', 'Optional')"
						maxlength="255" />
					<button
						type="button"
						class="place-picker__result place-picker__result--new"
						role="option"
						@click="chooseNew">
						<Plus :size="16" />
						{{ t('social', 'Use "{name}"', { name: query.trim() }) }}
					</button>
				</li>
			</ul>
			<p class="place-picker__hint">
				{{ t('social', 'Only places people here have posted from are suggested. Nothing is sent to a map service.') }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Close from 'vue-material-design-icons/Close.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import logger from '../../services/logger.js'

/** how long after the last keystroke the server is asked, in ms */
const DEBOUNCE = 250

/**
 * Says where a post was taken: a place people here have posted from, or a new
 * name (and country). Nothing is geocoded, so the location is never sent to a
 * map service.
 *
 * Emits `{ id }` for a known place or `{ name, country }` for a new one, which
 * `POST /api/v1/statuses` takes as `place_id` or `place_name` and `place_country`.
 */
export default {
	name: 'PlacePicker',

	components: {
		Close,
		MapMarkerOutline,
		NcButton,
		NcTextField,
		Plus,
	},

	props: {
		/** the chosen place, or null */
		place: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:place', 'close'],

	data() {
		return {
			query: '',
			country: '',
			/** @type {Array<object>} */
			results: [],
			timer: null,
		}
	},

	mounted() {
		// the button was pressed to search, so the field gets the focus
		this.$nextTick(() => /** @type {{$el?: HTMLElement}|undefined} */ (this.$refs.search)?.$el?.querySelector('input')?.focus())
	},

	beforeUnmount() {
		if (this.timer !== null) {
			window.clearTimeout(this.timer)
		}
	},

	methods: {
		t,

		onQuery() {
			if (this.timer !== null) {
				window.clearTimeout(this.timer)
			}
			const term = this.query.trim()
			if (term === '') {
				this.results = []

				return
			}
			this.timer = window.setTimeout(() => this.search(term), DEBOUNCE)
		},

		/**
		 * @param {string} term what was typed
		 * @return {Promise<void>}
		 */
		async search(term) {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/places/search'), { params: { q: term } })
				// the answer for what is in the box now, not for what was
				if (this.query.trim() === term) {
					this.results = Array.isArray(data) ? data : []
				}
			} catch (error) {
				logger.debug('could not search the places', { error })
				this.results = []
			}
		},

		/**
		 * @param {object} candidate a known place
		 */
		choose(candidate) {
			this.$emit('update:place', { id: candidate.id, name: candidate.name, country: candidate.country ?? '' })
		},

		chooseNew() {
			const name = this.query.trim()
			if (name === '') {
				return
			}
			this.$emit('update:place', { name, country: this.country.trim() })
		},

		clear() {
			this.$emit('update:place', null)
			this.query = ''
			this.country = ''
			this.results = []
		},

		/**
		 * @param {object} place a place
		 * @return {string} its name, with the country where one was given
		 */
		label(place) {
			return place.country ? `${place.name}, ${place.country}` : place.name
		},
	},
}
</script>

<style scoped lang="scss">
.place-picker {
	display: flex;
	flex-direction: column;
	gap: 6px;
	width: 100%;
	margin: 6px 0;
}

.place-picker__head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.place-picker__title {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: .04em;
}

.place-picker__close {
	margin-inline-start: auto;
}

.place-picker__chosen {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	align-self: flex-start;
	padding: 2px 4px 2px 10px;
	border-radius: var(--border-radius-pill, 100px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.place-picker__chosen-name {
	overflow-wrap: anywhere;
}

.place-picker__results {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.place-picker__result {
	display: flex;
	gap: 6px;
	align-items: center;
	width: 100%;
	padding: 6px 8px;
	border: 0;
	border-radius: var(--border-radius-large, 12px);
	background: none;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}

	&--new {
		color: var(--color-primary-element);
	}
}

.place-picker__new {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 12px;
	align-items: flex-end;
	margin-top: 4px;
}

.place-picker__country {
	flex: 1 1 160px;
}

.place-picker__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>
