<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:open="open"
		:name="t('social', 'Lists with {account}', { account: handle })"
		:buttons="buttons"
		class="list-membership"
		@update:open="$emit('update:open', $event)">
		<p v-if="loading" class="list-membership__hint">
			{{ t('social', 'Loading your lists …') }}
		</p>
		<p v-else-if="lists.length === 0" class="list-membership__hint">
			{{ t('social', 'You have no lists yet. Make one under Settings, then come back here to put people in it.') }}
		</p>
		<template v-else>
			<p class="list-membership__hint">
				{{ t('social', 'Tick a list to put them in it. Lists your Nextcloud groups give you are not here: the group decides who is in those.') }}
			</p>
			<NcCheckboxRadioSwitch
				v-for="list in lists"
				:key="list.id"
				:modelValue="memberOf.includes(String(list.id))"
				:disabled="busy.includes(String(list.id))"
				@update:modelValue="toggle(list, $event)">
				{{ list.title }}
			</NcCheckboxRadioSwitch>
		</template>
	</NcDialog>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

/**
 * Which of the reader's lists one account is in, as a row of boxes: "Add to
 * list" from a profile. Ticking and unticking write straight away, so there
 * is nothing to save and the one button closes the dialog.
 *
 * Only the reader's hand-made lists are offered. A group list's members are
 * the group's, and offering a box the server would refuse is worse than no
 * box.
 */
export default {
	name: 'ListMembershipDialog',

	components: {
		NcCheckboxRadioSwitch,
		NcDialog,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		account: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Account>} */ (Object),
			required: true,
		},
	},

	emits: ['update:open'],

	data() {
		return {
			loading: false,
			/** @type {object[]} the reader's own lists, group lists left out */
			lists: [],
			/** @type {string[]} ids of the lists the account is in */
			memberOf: [],
			/** @type {string[]} ids of the lists a write is in flight for */
			busy: [],
		}
	},

	computed: {
		handle() {
			return '@' + (this.account.acct ?? this.account.username ?? '')
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		buttons() {
			return [
				{
					label: t('social', 'Done'),
					variant: 'primary',
					callback: () => this.$emit('update:open', false),
				},
			]
		},
	},

	watch: {
		open: {
			immediate: true,
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,

		async load() {
			this.loading = true
			try {
				const [all, mine] = await Promise.all([
					axios.get(generateUrl('apps/social/api/v1/lists')),
					axios.get(generateUrl(`apps/social/api/v1/accounts/${this.account.id}/lists`)),
				])
				this.lists = (Array.isArray(all.data) ? all.data : []).filter((list) => !list.nextcloud_group)
				this.memberOf = (Array.isArray(mine.data) ? mine.data : []).map((list) => String(list.id))
			} catch (error) {
				logger.error('Failed to load the lists for the account', { error })
				showError(t('social', 'Could not load your lists'))
				this.lists = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} list the list ticked or unticked
		 * @param {boolean} member whether the account should now be in it
		 */
		async toggle(list, member) {
			const id = String(list.id)
			if (this.busy.includes(id)) {
				return
			}
			this.busy = [...this.busy, id]
			const url = generateUrl(`apps/social/api/v1/lists/${list.id}/accounts`)
			try {
				if (member) {
					await axios.post(url, { account_ids: [this.account.id] })
					this.memberOf = [...this.memberOf, id]
				} else {
					await axios.delete(url, { params: { account_ids: [this.account.id] } })
					this.memberOf = this.memberOf.filter((entry) => entry !== id)
				}
			} catch (error) {
				logger.error('Failed to change the list membership', { error })
				showError(error?.response?.status === 404
					? t('social', 'You can only add people you follow to a list')
					: (error?.response?.data?.error || t('social', 'Could not change the list')))
			} finally {
				this.busy = this.busy.filter((entry) => entry !== id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.list-membership__hint {
	margin-bottom: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
