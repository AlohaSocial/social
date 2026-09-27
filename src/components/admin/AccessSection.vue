<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'Fediverse access')"
		:description="description">
		<NcSelect
			v-model="mode"
			class="access__mode"
			:inputLabel="t('social', 'Access mode')"
			:options="modes"
			:clearable="false"
			:searchable="false"
			label="label"
			@update:modelValue="saveMode" />

		<p v-if="list.length === 0" class="social-admin__hint">
			{{ t('social', 'No instance is on the list.') }}
		</p>
		<!-- an address on this list came from an administrator, but it is shown
		     the same way everything else on this page is: interpolated -->
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

		<form class="access__add" @submit.prevent="add">
			<NcTextField
				v-model="address"
				class="access__input"
				:label="t('social', 'Instance')"
				placeholder="instance.example" />
			<NcButton type="submit" variant="primary" :disabled="busy || address.trim() === ''">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Add instance') }}
			</NcButton>
		</form>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, moderationUrl } from '../../services/adminApi.js'
import { showError } from '../../services/toast.js'

/**
 * The instance-wide access list, and which way round it is read.
 *
 * The same list `occ social:fediverse` manages, which is why the list is
 * redrawn from what the route answers rather than from what was typed.
 */
export default {
	name: 'AccessSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcSettingsSection,
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
	},

	data() {
		return {
			mode: null,
			list: [...this.addresses],
			address: '',
			busy: false,
		}
	},

	computed: {
		description() {
			return t('social', 'Control which instances this server federates with. The same list is available through "occ social:fediverse".')
		},

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
	},

	created() {
		this.mode = this.modes.find((mode) => mode.id === this.accessType) ?? this.modes[0]
	},

	methods: {
		t,

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
				await axios.post(moderationUrl('/fediverse/access'), { type: mode.id })
			} catch {
				showError(t('social', 'Could not change the access mode'))
			}
		},

		/**
		 * Adds what the field holds to the list.
		 *
		 * @return {Promise<void>}
		 */
		async add() {
			const address = this.address.trim()
			if (address === '') {
				return
			}

			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/fediverse/add'), { address })
				this.address = ''
				this.list = data.list
			} catch (error) {
				// the route refuses an address that is not one, and says so
				showError(errorMessage(error, t('social', 'Could not add the instance')))
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
				this.list = data.list
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

	&__item {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: center;
		padding-block: var(--default-grid-baseline);
	}

	&__address {
		overflow-wrap: anywhere;
	}

	&__add {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-end;
	}

	&__input {
		max-width: 320px;
	}
}
</style>
