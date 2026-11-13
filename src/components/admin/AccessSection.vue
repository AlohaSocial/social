<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcSelect
			v-model="mode"
			class="access__mode"
			:inputLabel="t('social', 'Access mode')"
			:options="modes"
			:clearable="false"
			:searchable="false"
			label="label"
			@update:modelValue="saveMode" />

		<p v-if="shown.length === 0" class="social-admin__hint">
			{{ t('social', 'No instance is on the list.') }}
		</p>
		<!-- an address or a reason on this list came from a moderator, but it
		     is shown the same way everything else on this page is: interpolated -->
		<ul v-else-if="blockList" class="access__list">
			<li v-for="entry in entries" :key="entry.domain" class="access__entry">
				<div class="access__item">
					<span class="access__address">{{ entry.domain }}</span>
					<span class="access__tier" :class="`access__tier--${entry.severity}`">
						{{ entry.severity === 'silence' ? t('social', 'Silenced') : t('social', 'Blocked') }}
					</span>
					<NcButton
						size="small"
						variant="tertiary"
						:aria-label="t('social', 'Edit {address}', { address: entry.domain })"
						:disabled="busy"
						@click="edit(entry)">
						{{ t('social', 'Edit') }}
					</NcButton>
					<NcButton
						size="small"
						variant="tertiary"
						:aria-label="t('social', 'Remove {address}', { address: entry.domain })"
						:disabled="busy"
						@click="remove(entry.domain)">
						{{ t('social', 'Remove') }}
					</NcButton>
				</div>
				<p v-if="entry.public_comment" class="access__reason">
					{{ t('social', 'Public reason: {reason}', { reason: entry.public_comment }) }}
				</p>
				<p v-if="entry.private_comment" class="access__reason">
					{{ t('social', 'Note for moderators: {note}', { note: entry.private_comment }) }}
				</p>

				<form v-if="editing === entry.domain" class="access__form access__form--edit" @submit.prevent="save(entry)">
					<fieldset class="access__tiers">
						<legend>{{ t('social', 'What happens to it') }}</legend>
						<NcCheckboxRadioSwitch
							v-model="draft.severity"
							type="radio"
							value="suspend"
							:name="`access-tier-${entry.domain}`">
							{{ blockLabel }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="draft.severity"
							type="radio"
							value="silence"
							:name="`access-tier-${entry.domain}`">
							{{ silenceLabel }}
						</NcCheckboxRadioSwitch>
					</fieldset>
					<NcTextArea
						v-model="draft.publicComment"
						:label="t('social', 'Reason shown publicly')"
						:helperText="publicHelp"
						:maxlength="maxComment"
						rows="2" />
					<NcTextArea
						v-model="draft.privateComment"
						:label="t('social', 'Note for moderators')"
						:helperText="privateHelp"
						:maxlength="maxComment"
						rows="2" />
					<div class="access__actions">
						<NcButton type="submit" variant="primary" :disabled="busy">
							{{ t('social', 'Save') }}
						</NcButton>
						<NcButton :disabled="busy" @click="editing = null">
							{{ t('social', 'Cancel') }}
						</NcButton>
					</div>
				</form>
			</li>
		</ul>
		<ul v-else class="access__list">
			<li v-for="listed in list" :key="listed" class="access__item">
				<span class="access__address">{{ listed }}</span>
				<NcButton
					size="small"
					variant="tertiary"
					:aria-label="t('social', 'Remove {address}', { address: listed })"
					:disabled="busy"
					@click="remove(listed)">
					{{ t('social', 'Remove') }}
				</NcButton>
			</li>
		</ul>

		<form class="access__form" @submit.prevent="add">
			<NcTextField
				v-model="address"
				class="access__input"
				:label="t('social', 'Instance')"
				placeholder="instance.example" />
			<template v-if="blockList">
				<fieldset class="access__tiers">
					<legend>{{ t('social', 'What happens to it') }}</legend>
					<NcCheckboxRadioSwitch
						v-model="severity"
						type="radio"
						value="suspend"
						name="access-tier-new">
						{{ blockLabel }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="severity"
						type="radio"
						value="silence"
						name="access-tier-new">
						{{ silenceLabel }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<NcTextArea
					v-model="publicComment"
					:label="t('social', 'Reason shown publicly')"
					:helperText="publicHelp"
					:maxlength="maxComment"
					rows="2" />
				<NcTextArea
					v-model="privateComment"
					:label="t('social', 'Note for moderators')"
					:helperText="privateHelp"
					:maxlength="maxComment"
					rows="2" />
			</template>
			<div class="access__actions">
				<NcButton type="submit" variant="primary" :disabled="busy || address.trim() === ''">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Add instance') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { moderationUrl } from '../../services/adminApi.js'
import { errorOf } from '../../utils/serverError.js'
import { showError } from '../../services/toast.js'

/** The longest reason the server keeps, `AdminDomainBlock::MAX_COMMENT`. */
const MAX_COMMENT = 1000

/**
 * The instance-wide access list, and which way round it is read.
 *
 * The same lists `occ social:fediverse` manages, which is why the list is
 * redrawn from what the route answers rather than from what was typed. On a
 * block list each entry is blocked or silenced and may carry two reasons:
 * one for the moderators, and one the published list of blocked servers
 * shows.
 */
export default {
	name: 'AccessSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** 'all_but' (a block list) or 'none_but' (an allow list) */
		accessType: {
			type: String,
			default: 'all_but',
		},

		/** what is on the list now */
		addresses: {
			type: /** @type {import('vue').PropType<string[]>} */ (Array),
			required: true,
		},

		/** on a block list, each entry as `Admin::DomainBlock`: blocked or silenced, with its reasons */
		blocks: {
			type: /** @type {import('vue').PropType<object[]>} */ (Array),
			default: () => [],
		},
	},

	data() {
		return {
			mode: null,
			list: [...this.addresses],
			entries: [...this.blocks],
			address: '',
			severity: 'suspend',
			privateComment: '',
			publicComment: '',
			/** the domain whose entry is being edited, or null */
			editing: null,
			draft: { severity: 'suspend', privateComment: '', publicComment: '' },
			busy: false,
			maxComment: MAX_COMMENT,
		}
	},

	computed: {
		modes() {
			return [
				{
					id: 'all_but',
					label: t('social', 'Federate with every instance except the listed ones (blocklist)'),
				},
				{
					id: 'none_but',
					label: t('social', 'Federate only with the listed instances (allowlist)'),
				},
			]
		},

		/** @return {boolean} whether the list names the instances refused */
		blockList() {
			return this.mode?.id !== 'none_but'
		},

		/** @return {Array} what the list shows in the mode it is in */
		shown() {
			return this.blockList ? this.entries : this.list
		},

		blockLabel() {
			return t('social', 'Block: refuse everything from it, and delete what this server holds of it')
		},

		silenceLabel() {
			return t('social', 'Silence: keep it out of the public timelines; people who follow its accounts still see them')
		},

		publicHelp() {
			return t('social', 'Shown beside it when this server publishes its list of blocked servers.')
		},

		privateHelp() {
			return t('social', 'Only moderators of this server see it.')
		},
	},

	watch: {
		addresses(addresses) {
			this.list = [...addresses]
		},

		blocks(blocks) {
			this.entries = [...blocks]
		},
	},

	created() {
		this.mode = this.modes.find((mode) => mode.id === this.accessType) ?? this.modes[0]
	},

	methods: {
		t,

		/**
		 * Redraws the list from what a route answered.
		 *
		 * @param {{list?: string[], blocks?: object[]}} data the answer
		 */
		redraw(data) {
			if (Array.isArray(data?.list)) {
				this.list = data.list
			}
			if (Array.isArray(data?.blocks)) {
				this.entries = data.blocks
			}
		},

		/**
		 * Switches the list between an allow list and a block list.
		 *
		 * @param {object} mode the option chosen
		 * @return {Promise<void>}
		 */
		async saveMode(mode) {
			if (!mode) {
				return
			}

			try {
				const { data } = await axios.post(moderationUrl('/fediverse/access'), { type: mode.id })
				this.redraw(data)
			} catch {
				showError(t('social', 'Could not change the access mode'))
			}
		},

		/**
		 * Adds what the field holds to the list, at the tier chosen and with
		 * the reasons given.
		 *
		 * @return {Promise<void>}
		 */
		async add() {
			const address = this.address.trim()
			if (address === '') {
				return
			}

			const body = { address }
			if (this.blockList) {
				body.severity = this.severity
				body.privateComment = this.privateComment.trim()
				body.publicComment = this.publicComment.trim()
			}

			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/fediverse/add'), body)
				this.address = ''
				this.severity = 'suspend'
				this.privateComment = ''
				this.publicComment = ''
				this.redraw(data)
			} catch (error) {
				// the route refuses an address that is not one, and says so
				showError(errorOf(error, t('social', 'Could not add the instance')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Opens an entry for editing, with what it holds now.
		 *
		 * @param {object} entry the entry
		 */
		edit(entry) {
			this.draft = {
				severity: entry.severity,
				privateComment: entry.private_comment ?? '',
				publicComment: entry.public_comment ?? '',
			}
			this.editing = entry.domain
		},

		/**
		 * Writes the tier and both reasons of an entry; an emptied reason is
		 * cleared.
		 *
		 * @param {object} entry the entry being edited
		 * @return {Promise<void>}
		 */
		async save(entry) {
			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/fediverse/update'), {
					address: entry.domain,
					severity: this.draft.severity,
					privateComment: this.draft.privateComment.trim(),
					publicComment: this.draft.publicComment.trim(),
				})
				this.editing = null
				this.redraw(data)
			} catch (error) {
				showError(errorOf(error, t('social', 'Could not save the instance')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {string} address the instance to take off the list
		 * @return {Promise<void>}
		 */
		async remove(address) {
			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/fediverse/remove'), { address })
				if (this.editing === address) {
					this.editing = null
				}
				this.redraw(data)
			} catch {
				showError(t('social', 'Could not remove the instance'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.access {
	&__mode {
		max-width: 520px;
	}

	&__list {
		margin-block: calc(var(--default-grid-baseline) * 2);
	}

	&__entry {
		padding-block: var(--default-grid-baseline);
	}

	&__item {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: center;
		padding-block: var(--default-grid-baseline);
	}

	&__address {
		overflow-wrap: anywhere;
	}

	&__tier {
		padding-inline: calc(var(--default-grid-baseline) * 2);
		border: 1px solid currentcolor;
		border-radius: var(--border-radius-pill);
		color: var(--color-error-text, var(--color-error));
		font-size: var(--font-size-small, 13px);

		&--silence {
			color: var(--color-warning-text, var(--color-warning));
		}
	}

	&__reason {
		margin: 0;
		color: var(--color-text-maxcontrast);
		overflow-wrap: anywhere;
		white-space: pre-line;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		max-width: 520px;

		&--edit {
			margin-block: calc(var(--default-grid-baseline) * 2);
			padding-inline-start: calc(var(--default-grid-baseline) * 4);
			border-inline-start: 2px solid var(--color-border);
		}
	}

	&__input {
		max-width: 320px;
	}

	&__tiers {
		display: flex;
		flex-direction: column;
		margin: 0;
		padding: 0;
		border: 0;

		legend {
			font-weight: bold;
		}
	}

	&__actions {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
