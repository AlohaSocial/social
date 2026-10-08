<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="labelers" :aria-busy="saving !== '' || adding ? 'true' : undefined">
		<p v-if="loading" class="labelers__hint">
			{{ t('social', 'Loading…') }}
		</p>
		<p v-else-if="loadFailed" class="labelers__hint">
			{{ t('social', 'Could not read your labelers right now.') }}
		</p>
		<template v-else>
			<div class="labelers__list">
				<article v-for="labeler in labelers" :key="labeler.did" class="labelers__labeler">
					<header class="labelers__head">
						<div class="labelers__who">
							<span class="labelers__name">{{ labeler.name || labeler.did }}</span>
							<code v-if="labeler.name" class="labelers__did">{{ labeler.did }}</code>
						</div>
						<NcButton
							v-if="labeler.removable"
							variant="tertiary"
							:disabled="removing.includes(labeler.did)"
							@click="remove(labeler)">
							{{ t('social', 'Remove') }}
						</NcButton>
					</header>

					<p v-if="labeler.labels.length === 0" class="labelers__hint">
						{{ t('social', 'This labeler has not said which labels it uses.') }}
					</p>
					<fieldset
						v-for="label in labeler.labels"
						:key="label.value"
						class="labelers__label">
						<legend class="labelers__legend">
							{{ label.name || label.value }}
						</legend>
						<p v-if="label.description" class="labelers__description">
							{{ label.description }}
						</p>
						<div class="labelers__choices">
							<NcCheckboxRadioSwitch
								v-for="choice in choices"
								:key="choice.value"
								type="radio"
								:name="'social-labeler-' + labeler.did + '-' + label.value"
								:value="choice.value"
								:modelValue="shown(labeler, label)"
								:disabled="saving !== ''"
								@update:modelValue="decide(labeler, label, $event)">
								{{ choice.label }}
							</NcCheckboxRadioSwitch>
						</div>
					</fieldset>
				</article>
			</div>

			<form class="labelers__add" @submit.prevent="subscribe">
				<NcTextField
					v-model="draft"
					class="labelers__field"
					:label="t('social', 'Labeler handle or DID')"
					placeholder="xblock.aendra.dev"
					:disabled="adding" />
				<NcButton type="submit" :disabled="adding || draft.trim() === ''">
					{{ t('social', 'Add labeler') }}
				</NcButton>
			</form>
			<p v-if="addError !== ''" class="labelers__error" role="alert">
				{{ addError }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

/**
 * @typedef {object} Labeler one labeler the reader subscribes to
 * @property {string} did - the labeler's DID
 * @property {string} name - what it calls itself, '' when it does not say
 * @property {boolean} removable - false for Bluesky's own moderation service
 * @property {Array<{value: string, name: string, description: string, setting: 'ignore'|'warn'|'hide'}>} labels - its label values, each with the reader's setting
 */

const LABELERS_URL = '/apps/social/api/v1/social/bluesky/labelers'

/**
 * The Bluesky labelers the reader subscribes to, and what each of their
 * labels does: Settings → Blocking → Bluesky labelers.
 *
 * Every route answers the whole list, which replaces the one on the page.
 * A setting is saved as it is picked. A server with Bluesky switched off
 * answers 404, and the part tells its card with `unavailable`.
 */
export default {
	name: 'BlueskyLabelersSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextField,
	},

	emits: ['unavailable'],

	data() {
		return {
			loading: true,
			loadFailed: false,
			/** @type {Labeler[]} what the server last answered */
			labelers: [],
			/** @type {Record<string, string>} a setting on its way to the server, by `did label` */
			pending: {},
			/** the `did label` being saved, '' while nothing is */
			saving: '',
			/** @type {string[]} the DIDs being unsubscribed */
			removing: [],
			draft: '',
			adding: false,
			addError: '',
		}
	},

	computed: {
		/** @return {Array<{value: string, label: string}>} */
		choices() {
			return [
				{ value: 'ignore', label: t('social', 'Show') },
				{ value: 'warn', label: t('social', 'Warn') },
				{ value: 'hide', label: t('social', 'Hide') },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {Labeler} labeler whose label
		 * @param {{value: string, setting: string}} label which label
		 * @return {string} the setting drawn as picked
		 */
		shown(labeler, label) {
			return this.pending[labeler.did + ' ' + label.value] ?? label.setting
		},

		/**
		 * @param {{labelers?: Labeler[]}} answer what a route answered
		 */
		accept(answer) {
			this.labelers = Array.isArray(answer?.labelers) ? answer.labelers : []
		},

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl(LABELERS_URL))
				this.accept(data)
			} catch (error) {
				if (error?.response?.status === 404) {
					this.$emit('unavailable')
				} else {
					logger.debug('Could not read the Bluesky labelers', { error })
					this.loadFailed = true
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Subscribes to the labeler in the field, by handle or DID.
		 *
		 * @return {Promise<void>}
		 */
		async subscribe() {
			const labeler = this.draft.trim().replace(/^@/, '')
			if (labeler === '' || this.adding) {
				return
			}
			this.adding = true
			this.addError = ''
			try {
				const { data } = await axios.post(generateUrl(LABELERS_URL), { labeler })
				this.accept(data)
				this.draft = ''
			} catch (error) {
				logger.error('Could not add the Bluesky labeler', { error })
				this.addError = error?.response?.data?.error || t('social', 'Could not add that labeler')
			} finally {
				this.adding = false
			}
		},

		/**
		 * @param {Labeler} labeler the labeler to unsubscribe from
		 * @return {Promise<void>}
		 */
		async remove(labeler) {
			this.removing = [...this.removing, labeler.did]
			try {
				const { data } = await axios.delete(generateUrl(LABELERS_URL), { data: { did: labeler.did } })
				this.accept(data)
			} catch (error) {
				logger.error('Could not remove the Bluesky labeler', { error })
				showError(error?.response?.data?.error || t('social', 'Could not remove that labeler'))
			} finally {
				this.removing = this.removing.filter((did) => did !== labeler.did)
			}
		},

		/**
		 * Saves what one label does; on a refusal the choice goes back to
		 * what the server has.
		 *
		 * @param {Labeler} labeler whose label
		 * @param {{value: string, setting: string}} label which label
		 * @param {string} setting the choice picked
		 * @return {Promise<void>}
		 */
		async decide(labeler, label, setting) {
			const key = labeler.did + ' ' + label.value
			if (label.setting === setting || this.saving !== '') {
				return
			}
			this.pending = { ...this.pending, [key]: setting }
			this.saving = key
			try {
				const { data } = await axios.put(generateUrl(LABELERS_URL + '/setting'), {
					did: labeler.did,
					label: label.value,
					setting,
				})
				this.accept(data)
			} catch (error) {
				logger.error('Could not save the label setting', { error })
				showError(error?.response?.data?.error || t('social', 'Could not save that setting'))
			} finally {
				const rest = { ...this.pending }
				delete rest[key]
				this.pending = rest
				this.saving = ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.labelers {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: 10px;

	&__hint,
	&__description {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__labeler {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);

		/* a rule between labelers, as between the rows of the lists above */
		& + & {
			padding-top: calc(var(--default-grid-baseline) * 2);
			border-top: 1px solid var(--color-border);
		}
	}

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
	}

	&__who {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__name {
		font-weight: bold;
	}

	&__did {
		overflow: hidden;
		color: var(--color-text-maxcontrast);
		font-size: 12px;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__label {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
		margin: 0;
		padding: 0;
		border: 0;
	}

	&__legend {
		margin: 0;
		padding: 0;
		font-weight: 600;
	}

	&__choices {
		display: flex;
		flex-wrap: wrap;
		gap: 0 calc(var(--default-grid-baseline) * 4);
	}

	&__add {
		display: flex;
		align-items: flex-end;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 4px;
	}

	&__field {
		max-width: 320px;
	}

	&__error {
		margin: 0;
		color: var(--color-error-text, var(--color-error));
		font-size: 13px;
	}
}
</style>
