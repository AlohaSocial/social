<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p class="social-admin__hint">
			{{ t('social', 'Give one a start and an end to show it only between them. Removing one takes it away from everybody, read or not.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="20" />

		<NcEmptyContent
			v-else-if="announcements.length === 0"
			:name="t('social', 'No announcements.')">
			<template #icon>
				<IconBullhorn :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="social-admin__scroll">
			<table class="social-admin__table">
				<thead>
					<tr>
						<!-- not "Announcement": `n()` uses that word as the
						     singular of a plural elsewhere, and gettext folds
						     the two into one plural entry, which `t()` cannot
						     look up. The column holds the text anyway. -->
						<th>{{ t('social', 'Text') }}</th>
						<th>{{ t('social', 'Shown from') }}</th>
						<th>{{ t('social', 'Until') }}</th>
						<th>{{ t('social', 'State') }}</th>
						<th />
					</tr>
				</thead>
				<!-- the text is the admin's own and the API sends it back
				     unchanged; it is interpolated all the same, so that what an
				     earlier admin typed cannot run in the next one's browser -->
				<tbody>
					<tr v-for="announcement in announcements" :key="announcement.id">
						<td>{{ announcement.text }}</td>
						<td>{{ formatDate(announcement.starts_at, announcement.all_day) }}</td>
						<td>{{ formatDate(announcement.ends_at, announcement.all_day) }}</td>
						<td>
							{{ announcement.active ? t('social', 'Shown now') : t('social', 'Not shown') }}
						</td>
						<td>
							<NcButton size="small" variant="tertiary" @click="askRemove(announcement)">
								{{ t('social', 'Remove') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<form class="announcements__form" @submit.prevent="add">
			<NcTextArea
				v-model="text"
				:label="t('social', 'New announcement')"
				:placeholder="t('social', 'This server will be down for maintenance on Sunday.')"
				rows="3" />

			<div class="announcements__window">
				<NcDateTimePickerNative
					v-model="startsAt"
					type="datetime-local"
					:label="t('social', 'From')" />
				<NcDateTimePickerNative
					v-model="endsAt"
					type="datetime-local"
					:label="t('social', 'Until')" />
				<NcCheckboxRadioSwitch v-model="allDay">
					{{ t('social', 'Whole days') }}
				</NcCheckboxRadioSwitch>
			</div>

			<NcButton type="submit" variant="primary" :disabled="posting || text.trim() === ''">
				<template v-if="posting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Post announcement') }}
			</NcButton>
		</form>

		<ConfirmDialog
			v-if="pending !== null"
			:open="true"
			:name="t('social', 'Remove this announcement?')"
			:message="t('social', 'Everybody stops seeing it, including the accounts that have already read it.')"
			:confirmLabel="t('social', 'Remove')"
			@update:open="pending = null"
			@confirm="remove(pending)" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import IconBullhorn from 'vue-material-design-icons/Bullhorn.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ConfirmDialog from './ConfirmDialog.vue'
import { announcementsUrl, errorMessage } from '../../services/adminApi.js'
import { showError } from '../../services/toast.js'

/**
 * What the instance is telling everybody.
 *
 * The section carries nothing from the server-rendered page: the list is read
 * from the same routes it writes through, so what is shown is what is stored
 * even when the server decided a window differently from the one typed.
 */
export default {
	name: 'AnnouncementsSection',

	components: {
		ConfirmDialog,
		IconBullhorn,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextArea,
	},

	data() {
		return {
			announcements: [],
			text: '',
			startsAt: null,
			endsAt: null,
			allDay: false,
			loading: true,
			posting: false,
			pending: null,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * A window as the table shows one: the date the admin gave, or a dash.
		 *
		 * @param {string|null} value an ISO 8601 datetime, or null for no bound
		 * @param {boolean} allDay whether the window is whole days
		 * @return {string} what the cell says
		 */
		formatDate(value, allDay) {
			if (!value) {
				return '—'
			}

			// the API dates in UTC; an all-day window is a date and showing its
			// time would say 22:00 to half of Europe
			return allDay ? value.slice(0, 10) : value.slice(0, 16).replace('T', ' ')
		},

		/**
		 * One bound of the window, as the endpoint has always been given it:
		 * the wall-clock time that was picked, not the instant behind it.
		 *
		 * @param {Date|null} date what the picker holds
		 * @return {string} `YYYY-MM-DDTHH:mm`, or '' for no bound
		 */
		bound(date) {
			if (!date) {
				return ''
			}

			const pad = (value) => String(value).padStart(2, '0')

			return date.getFullYear()
				+ '-' + pad(date.getMonth() + 1)
				+ '-' + pad(date.getDate())
				+ 'T' + pad(date.getHours())
				+ ':' + pad(date.getMinutes())
		},

		/**
		 * @return {Promise<void>} once the list is in
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(announcementsUrl())
				this.announcements = data.announcements
			} catch {
				showError(t('social', 'Could not read the announcements'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Posts what the form holds, and redraws from what came back rather
		 * than from what was typed: the server decides the window an all-day
		 * announcement ends up with.
		 *
		 * @return {Promise<void>}
		 */
		async add() {
			if (this.text.trim() === '') {
				return
			}

			this.posting = true
			try {
				const { data } = await axios.post(announcementsUrl(), {
					text: this.text,
					starts_at: this.bound(this.startsAt),
					ends_at: this.bound(this.endsAt),
					all_day: this.allDay,
				})
				this.text = ''
				this.startsAt = null
				this.endsAt = null
				this.allDay = false
				this.announcements = data.announcements
			} catch (error) {
				// a refused announcement says why: which bound it would not
				// take, or that a window needs both of them
				showError(t('social', 'Could not post the announcement')
					+ ': ' + errorMessage(error, t('social', 'request failed')))
			} finally {
				this.posting = false
			}
		},

		/**
		 * It is gone for everybody, including the accounts that have read it,
		 * so it asks.
		 *
		 * @param {object} announcement the row
		 */
		askRemove(announcement) {
			this.pending = announcement
		},

		/**
		 * @param {object} announcement the row to remove
		 * @return {Promise<void>}
		 */
		async remove(announcement) {
			this.pending = null
			try {
				const { data } = await axios.delete(announcementsUrl('/' + announcement.id))
				this.announcements = data.announcements
			} catch {
				showError(t('social', 'Could not remove the announcement'))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.announcements {
	&__form {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-start;
		max-width: 600px;
	}

	&__window {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 3);
		align-items: center;
	}
}
</style>
