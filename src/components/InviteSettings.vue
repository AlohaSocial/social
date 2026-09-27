<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="invite-settings">
		<form class="invite-settings__new" @submit.prevent="create">
			<NcTextField
				v-model="note"
				class="invite-settings__note"
				:label="t('social', 'Who is it for? (only you see this)')"
				:disabled="creating"
				:maxlength="255" />
			<NcButton type="submit" variant="primary" :disabled="creating">
				<template #icon>
					<NcLoadingIcon v-if="creating" :size="20" />
					<IconLinkPlus v-else :size="20" />
				</template>
				{{ t('social', 'Create an invitation link') }}
			</NcButton>
		</form>

		<p v-if="!loading && invites.length === 0" class="invite-settings__empty">
			{{ t('social', 'You have not invited anybody yet. A link works once and for a week.') }}
		</p>

		<ul v-else class="invite-settings__list">
			<li v-for="invite in invites" :key="invite.id" class="invite-settings__item">
				<div class="invite-settings__what">
					<span class="invite-settings__label">{{ invite.note || t('social', 'Invitation') }}</span>
					<span class="invite-settings__meta">{{ status(invite) }}</span>
				</div>
				<NcButton
					variant="tertiary"
					:aria-label="t('social', 'Copy the link')"
					:title="t('social', 'Copy the link')"
					:disabled="spent(invite)"
					@click="copy(invite)">
					<template #icon>
						<IconContentCopy :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:aria-label="t('social', 'Withdraw the invitation')"
					:title="t('social', 'Withdraw the invitation')"
					@click="revoke(invite)">
					<template #icon>
						<IconClose :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import IconLinkPlus from 'vue-material-design-icons/LinkPlus.vue'
import { invitesUrl } from '../services/externalApi.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'

/**
 * The invitation links a person has sent, where the administrator lets
 * people invite others to register on this server.
 *
 * Each link admits one registration and works for a week; the server holds
 * those limits (`ExternalSignupService::createInvite()`), this only shows
 * them.
 */
export default {
	name: 'InviteSettings',

	components: {
		IconClose,
		IconContentCopy,
		IconLinkPlus,
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			invites: [],
			note: '',
			loading: true,
			creating: false,
		}
	},

	async mounted() {
		try {
			const { data } = await axios.get(invitesUrl())
			this.invites = data?.invites ?? []
		} catch (error) {
			logger.debug('Could not read the invitations', { error })
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * @param {{uses: number, maxUses: number, expires: number}} invite an invitation
		 * @return {boolean} whether it admits nobody any more
		 */
		spent(invite) {
			return (invite.maxUses > 0 && invite.uses >= invite.maxUses)
				|| (invite.expires > 0 && invite.expires * 1000 < Date.now())
		},

		/**
		 * @param {{uses: number, maxUses: number, expires: number}} invite an invitation
		 * @return {string} what became of it
		 */
		status(invite) {
			if (invite.maxUses > 0 && invite.uses >= invite.maxUses) {
				return t('social', 'Used')
			}
			if (invite.expires > 0 && invite.expires * 1000 < Date.now()) {
				return t('social', 'Expired')
			}

			return invite.expires > 0
				? t('social', 'Works until {date}', { date: new Date(invite.expires * 1000).toLocaleDateString() })
				: t('social', 'Not used yet')
		},

		/** @return {Promise<void>} */
		async create() {
			this.creating = true
			try {
				const { data } = await axios.post(invitesUrl(), { note: this.note.trim() })
				this.invites = [data, ...this.invites]
				this.note = ''
				await this.copy(data)
			} catch (error) {
				logger.error('Could not create an invitation', { error })
				showError(error?.response?.data?.message || t('social', 'Could not create the invitation'))
			} finally {
				this.creating = false
			}
		},

		/**
		 * @param {{url: string}} invite the invitation whose link to copy
		 * @return {Promise<void>}
		 */
		async copy(invite) {
			try {
				await navigator.clipboard.writeText(invite.url)
				showSuccess(t('social', 'Link copied'))
			} catch {
				window.prompt(t('social', 'The invitation link'), invite.url)
			}
		},

		/**
		 * @param {{id: number}} invite the invitation to withdraw
		 * @return {Promise<void>}
		 */
		async revoke(invite) {
			try {
				await axios.delete(invitesUrl('/' + invite.id))
				this.invites = this.invites.filter((other) => other.id !== invite.id)
			} catch (error) {
				logger.error('Could not withdraw the invitation', { error })
				showError(t('social', 'Could not withdraw the invitation'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.invite-settings {
	max-width: 640px;

	&__new {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-end;
		flex-wrap: wrap;
	}

	&__note {
		flex: 1 1 240px;
	}

	&__empty {
		margin-block-start: calc(var(--default-grid-baseline) * 3);
		color: var(--color-text-maxcontrast);
	}

	&__list {
		margin-block-start: calc(var(--default-grid-baseline) * 3);
	}

	&__item {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 1);
		padding-block: calc(var(--default-grid-baseline) * 1);
		border-block-end: 1px solid var(--color-border);
	}

	&__what {
		display: flex;
		flex: 1;
		flex-direction: column;
		min-width: 0;
	}

	&__label {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}
</style>
