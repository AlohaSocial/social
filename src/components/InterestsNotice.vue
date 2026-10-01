<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- learning is on unless somebody turns it off, so the reader is told it
	     is happening before anything else is: once per account, and the
	     answer is kept on the server so another browser does not ask again -->
	<section class="interests-notice" :aria-label="t('social', 'About For you')">
		<TagHeart class="interests-notice__icon" :size="20" />
		<p class="interests-notice__text">
			{{ t('social', 'Aloha Social now learns which hashtags interest you from how you read, to build your For you feed. This stays on this server and is only visible to you.') }}
		</p>
		<div class="interests-notice__actions">
			<NcButton variant="tertiary" :to="{ name: 'settings', hash: '#interests' }">
				{{ t('social', 'Manage') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="saving" @click="turnOff">
				{{ t('social', 'Turn off') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="acknowledge">
				{{ t('social', 'Got it') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import TagHeart from 'vue-material-design-icons/TagHeart.vue'
import { saveInterestSettings } from '../services/interests.js'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { useSettingsStore } from '../store/settings.js'

/** What of the answered settings the page state carries. */
const PAGE_KEYS = ['enabled', 'learning', 'paused', 'noticeAcknowledged']

/**
 * The one-time notice that For you is learning.
 *
 * Whether it shows is the page's decision; what it does is change the page
 * state the decision is made from, so it goes away — and with Turn off, the
 * tracking and the feed tab go with it — without a reload.
 */
export default {
	name: 'InterestsNotice',

	components: {
		NcButton,
		TagHeart,
	},

	data() {
		return {
			saving: false,
		}
	},

	methods: {
		t,

		acknowledge() {
			return this.save({ noticeAcknowledged: true })
		},

		/**
		 * Opts out. Answering the notice this way is also having seen it, so
		 * turning learning back on later does not bring it back.
		 */
		async turnOff() {
			if (await this.save({ learning: false, noticeAcknowledged: true })) {
				// the feed this page may be showing is the one just turned off
				if (this.$route?.params?.type === 'interests') {
					this.$router.push({ name: 'timeline' })
				}
			}
		},

		/**
		 * @param {object} settings what to change
		 * @return {Promise<boolean>} whether the server took it
		 */
		async save(settings) {
			if (this.saving) {
				return false
			}

			this.saving = true
			try {
				const state = await saveInterestSettings(settings)
				const answered = Object.fromEntries(PAGE_KEYS
					.filter((key) => state?.settings?.[key] !== undefined)
					.map((key) => [key, state.settings[key]]))
				const settingsStore = useSettingsStore()
				settingsStore.setServerDataEntry({
					key: 'interests',
					value: { ...settingsStore.getServerData.interests, ...settings, ...answered },
				})

				return true
			} catch (error) {
				logger.error('Could not save the interests settings', { error })
				showError(t('social', 'Could not save your choice'))

				return false
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.interests-notice {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 8px 12px;
	margin: 0 calc(var(--default-grid-baseline) * 2) 12px;
	padding: 12px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);

	&__icon {
		flex-shrink: 0;
		color: var(--color-primary-element);
	}

	&__text {
		flex: 1 1 20em;
		margin: 0;
		color: var(--color-main-text);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 4px;
		margin-inline-start: auto;
	}
}
</style>
